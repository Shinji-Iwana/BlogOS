<?php

namespace App\Services\Activity;

use App\Models\AffiliateProgram;
use App\Models\AiBatch;
use App\Models\AiCreditEntry;
use App\Models\AiGeneration;
use App\Models\AiPriceChange;
use App\Models\AiPriceCheck;
use App\Models\ArticleDraft;
use App\Models\ArticleEvaluation;
use App\Models\ArticleManagement;
use App\Models\ArticleManagementSuggestion;
use App\Models\ArticleMaterialReview;
use App\Models\Author;
use App\Models\Blog;
use App\Models\Category;
use App\Models\CategoryLaunch;
use App\Models\CustomContent;
use App\Models\CustomTerm;
use App\Models\GoogleFetchRun;
use App\Models\GoogleIndexStatus;
use App\Models\Image;
use App\Models\LoginHistory;
use App\Models\Material;
use App\Models\MaterialSuggestion;
use App\Models\Media;
use App\Models\Notice;
use App\Models\Page;
use App\Models\PageSpeedRun;
use App\Models\Post;
use App\Models\ScheduledTaskRun;
use App\Models\Status;
use App\Models\SyncIssue;
use App\Models\SyncRun;
use App\Models\Tag;
use App\Models\Taxonomy;
use App\Models\TopicSuggestion;
use App\Models\Type;
use App\Models\VoiceTurn;
use App\Models\WordPressComponent;
use App\Models\WordPressPushOperation;
use App\Support\HistoryPage;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * アクティビティログ（D-77）：BlogOS に残っている作業の記録の日時を、新しい順に並べる。
 *
 * 記録用の表は作らず、開くたびに各表の日時を UNION ALL でまとめる（今まで残っている作業もそのまま出す）。
 * 1行は「いつ・どの記録（source）の・どの出来事（event）か」。始めと終わりがある作業（同期・定期実行・AI の実行など）は、
 * 開始と終了を別の行にする（時系列で追えるように。利用者の判断）。
 * 変更の記録（*_histories）は、1回の変更（change_set_id）を1行にまとめる。
 * 記録を残していない操作（テーマの切替・設定の保存など）・削除した記録・古い記録の削除で消えた記録は出ない。
 * 記録の表を新しく作ったときは、ここ（sources と describe）にも足す。
 */
class ActivityLogService
{
    /**
     * 種類（絞り込みの単位）
     */
    public const KINDS = [
        'sync'       => '同期',
        'schedule'   => '定期実行',
        'ai'         => 'AI',
        'draft'      => '編集案・反映',
        'management' => '記事の管理情報',
        'wordpress'  => 'WordPressのデータの変更',
        'topic'      => '企画',
        'material'   => '教材・提携',
        'image'      => '画像',
        'google'     => 'Google',
        'pagespeed'  => 'PageSpeed',
        'openai'     => 'OpenAI',
        'wpinfo'     => 'WordPress情報',
        'blog'       => 'ブログ',
        'notice'     => 'お知らせ',
        'login'      => 'ログイン',
        'voice'      => '音声',
    ];

    /** WordPress 由来のデータ（変更の記録の表・DB確認画面のテーブル名・表示名） */
    protected const RECORDS = [
        'post'           => [Post::class, 'posts', '投稿'],
        'page'           => [Page::class, 'pages', '固定ページ'],
        'media'          => [Media::class, 'media', 'メディア'],
        'category'       => [Category::class, 'categories', 'カテゴリ'],
        'tag'            => [Tag::class, 'tags', 'タグ'],
        'author'         => [Author::class, 'authors', '投稿者'],
        'custom_content' => [CustomContent::class, 'custom_contents', 'カスタム投稿タイプの内容'],
        'custom_term'    => [CustomTerm::class, 'custom_terms', 'カスタムタクソノミーの項目'],
        'status'         => [Status::class, 'statuses', '投稿ステータスの定義'],
        'type'           => [Type::class, 'types', '投稿タイプの定義'],
        'taxonomy'       => [Taxonomy::class, 'taxonomies', 'タクソノミーの定義'],
    ];

    /** 変更の元（ChangeSource） */
    protected const CHANGE_SOURCES = [
        'wp_initial_sync' => 'ブログ登録時の初回の取り込み',
        'wp_sync'         => '同期で取り込み',
        'blogos_push'     => 'BlogOS から反映',
        'blogos_recovery' => '反映の記録からの回復',
        'blogos_manual'   => 'BlogOS の画面で編集',
        'ai'              => 'AI',
        'system'          => 'BlogOS の内部の処理',
    ];

    protected const LOGIN_EVENTS = [
        'login_succeeded' => 'ログイン',
        'login_failed'    => 'ログインに失敗',
        'login_locked'    => 'ログインを一時的に止めた（失敗の回数が多い）',
        'logout'          => 'ログアウト',
    ];

    /**
     * 1ページ分の行（新しい順）。filters：blog_id（選択中のブログ。null ならブログに関係のない作業だけ）・from・to（日本時間の日付 Y-m-d）・kind（種類。'' は全て）。
     * ブログに関係のない作業（定期実行・ログイン・OpenAI など。blog_id が null）は、いつも出す（D-77-06）
     *
     * @param  array{blog_id: int|null, from: string, to: string, kind?: string}  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $kind = $filters['kind'] ?? '';
        $rows = $this->scoped($filters)
            ->when(array_key_exists($kind, self::KINDS), fn ($query) => $query->where('kind', $kind))
            ->orderByDesc('at')
            ->orderByDesc('seq')
            ->orderByDesc('ref_id')
            ->paginate(HistoryPage::PER_PAGE)
            ->withQueryString();

        $rows->setCollection($this->describe($rows->getCollection()));

        return $rows;
    }

    /**
     * 同じブログ・期間に記録がある種類（絞り込みの種類の候補。選んで0件にならないように。KINDS の順）
     *
     * @param  array{blog_id: int|null, from: string, to: string}  $filters
     * @return array<string, string>
     */
    public function kinds(array $filters): array
    {
        $found = $this->scoped($filters)->distinct()->pluck('kind')->all();

        return array_filter(self::KINDS, fn ($kind) => in_array($kind, $found, true), ARRAY_FILTER_USE_KEY);
    }

    /**
     * 全ての種類の行を、ブログ（選択中のブログと、ブログに関係のない作業）と期間で絞った問い合わせ
     *
     * @param  array{blog_id: int|null, from: string, to: string}  $filters
     */
    protected function scoped(array $filters): Builder
    {
        $union = null;
        foreach ($this->sources() as [, $query]) {
            $union = $union === null ? $query : $union->unionAll($query);
        }

        $zone = config('blogos.display_timezone', 'Asia/Tokyo');

        return DB::query()->fromSub($union, 'activities')
            ->where(fn ($query) => $query->whereNull('blog_id')->when($filters['blog_id'], fn ($query, $blogId) => $query->orWhere('blog_id', $blogId)))
            ->where('at', '>=', Carbon::parse($filters['from'], $zone)->startOfDay()->utc())
            ->where('at', '<=', Carbon::parse($filters['to'], $zone)->endOfDay()->utc());
    }

    /**
     * 各記録の出来事の行（[種類, 行の問い合わせ]）。列：at（日時）・seq（同じ日時の並び。終わりを上）・kind・source・event・ref_id・blog_id・cnt
     *
     * @return list<array{0: string, 1: Builder}>
     */
    protected function sources(): array
    {
        $sources = [
            // 同期
            ['sync', $this->rows('sync_runs', 'sync_runs', 'start', 'started_at')],
            ['sync', $this->rows('sync_runs', 'sync_runs', 'end', 'finished_at')],
            ['sync', $this->rows('sync_issues', 'sync_issues', 'detected', 'first_detected_at')],
            ['sync', $this->rows('sync_issues', 'sync_issues', 'resolved', 'resolved_at')],
            // 定期実行
            ['schedule', $this->rows('scheduled_task_runs', 'scheduled_task_runs', 'start', 'started_at', false)],
            ['schedule', $this->rows('scheduled_task_runs', 'scheduled_task_runs', 'end', 'finished_at', false)],
            // AI
            ['ai', $this->rows('ai_generations', 'ai_generations', 'start', 'COALESCE(started_at, created_at)')],
            ['ai', $this->rows('ai_generations', 'ai_generations', 'end', 'completed_at')],
            ['ai', $this->rows('ai_batches', 'ai_batches', 'start', 'created_at')],
            ['ai', $this->rows('ai_batches', 'ai_batches', 'end', 'completed_at')],
            ['ai', $this->rows('article_evaluations', 'article_evaluations', 'created', 'created_at')],
            ['ai', $this->rows('article_evaluations', 'article_evaluations', 'confirmed', 'confirmed_at')],
            ['ai', $this->rows('article_management_suggestions', 'article_management_suggestions', 'created', 'created_at')],
            ['ai', $this->rows('article_management_suggestions', 'article_management_suggestions', 'reviewed', 'reviewed_at')],
            // 編集案・反映
            ['draft', $this->changes('article_draft_histories', 'article_draft_histories')],
            ['draft', $this->rows('wordpress_push_operations', 'wordpress_push_operations', 'sent', 'sent_at')],
            ['draft', $this->rows('wordpress_push_operations', 'wordpress_push_operations', 'completed', 'completed_at')],
            ['draft', $this->rows('wordpress_push_operations', 'wordpress_push_operations', 'failed', 'failed_at')],
            // 記事の管理情報
            ['management', $this->changes('article_management_histories', 'article_management_histories')],
            // 企画
            ['topic', $this->rows('topic_suggestions', 'topic_suggestions', 'created', 'created_at')],
            ['topic', $this->rows('topic_suggestions', 'topic_suggestions', 'reviewed', 'reviewed_at')],
            ['topic', $this->rows('category_launches', 'category_launches', 'created', 'created_at')],
            // 教材・提携
            ['material', $this->rows('materials', 'materials', 'created', 'created_at')],
            ['material', $this->rows('materials', 'materials', 'researched', 'researched_at')],
            ['material', $this->rows('material_suggestions', 'material_suggestions', 'created', 'created_at')],
            ['material', $this->rows('material_suggestions', 'material_suggestions', 'reviewed', 'reviewed_at')],
            ['material', $this->rows('article_material_reviews', 'article_material_reviews', 'created', 'created_at')],
            ['material', $this->rows('article_material_reviews', 'article_material_reviews', 'reviewed', 'reviewed_at')],
            ['material', $this->rows('affiliate_programs', 'affiliate_programs', 'created', 'created_at')],
            ['material', $this->rows('affiliate_programs', 'affiliate_programs', 'checked', 'checked_at')],
            // 画像
            ['image', $this->rows('images', 'images', 'created', 'created_at')],
            // Google
            ['google', $this->rows('google_fetch_runs', 'google_fetch_runs', 'start', 'started_at')],
            ['google', $this->rows('google_fetch_runs', 'google_fetch_runs', 'end', 'finished_at')],
            ['google', $this->rows('google_index_statuses', 'google_index_statuses', 'changed', 'category_changed_at')],
            // PageSpeed（D-78）
            ['pagespeed', $this->rows('pagespeed_runs', 'pagespeed_runs', 'start', 'started_at')],
            ['pagespeed', $this->rows('pagespeed_runs', 'pagespeed_runs', 'end', 'finished_at')],
            // OpenAI
            ['openai', $this->rows('ai_price_checks', 'ai_price_checks', 'checked', 'created_at', false)],
            ['openai', $this->rows('ai_price_changes', 'ai_price_changes', 'created', 'created_at', false)],
            ['openai', $this->rows('ai_price_changes', 'ai_price_changes', 'decided', 'decided_at', false)],
            ['openai', $this->rows('ai_credit_entries', 'ai_credit_entries', 'created', 'occurred_at', false)],
            // WordPress情報（1回の確認を1行にする）
            ['wpinfo', DB::table('wordpress_components')
                ->selectRaw("checked_at as at, 0 as seq, 'wpinfo' as kind, 'wordpress_components' as source, 'checked' as event, MIN(id) as ref_id, blog_id, COUNT(*) as cnt")
                ->whereNotNull('checked_at')
                ->groupBy('blog_id', 'checked_at')],
            // ブログ
            ['blog', $this->changes('blog_histories', 'blog_histories')],
            ['blog', $this->changes('blog_setting_histories', 'blog_setting_histories')],
            // お知らせ
            ['notice', $this->rows('notices', 'notices', 'occurred', 'occurred_at')],
            ['notice', $this->rows('notices', 'notices', 'resolved', 'resolved_at')],
            ['notice', $this->rows('notices', 'notices', 'confirmed', 'confirmed_at')],
            // ログイン
            ['login', $this->rows('login_histories', 'login_histories', 'occurred', 'occurred_at', false)],
            // 音声
            ['voice', $this->rows('voice_turns', 'voice_turns', 'created', 'created_at')],
        ];

        // WordPress のデータの変更（投稿・固定ページ・カテゴリなど）
        foreach (array_keys(self::RECORDS) as $record) {
            $sources[] = ['wordpress', $this->changes($record . '_histories', 'record:' . $record)];
        }

        return $sources;
    }

    /**
     * 1件の記録の1つの日時を1行にする
     */
    protected function rows(string $table, string $source, string $event, string $column, bool $hasBlog = true): Builder
    {
        $kind = $this->kindOf($source);
        $seq = in_array($event, ['end', 'completed', 'failed', 'resolved', 'reviewed', 'decided', 'confirmed'], true) ? 1 : 0;

        return DB::table($table)
            ->selectRaw("{$column} as at, {$seq} as seq, ? as kind, ? as source, ? as event, id as ref_id, " . ($hasBlog ? 'blog_id' : 'NULL as blog_id') . ', 1 as cnt', [$kind, $source, $event])
            ->whereRaw("{$column} IS NOT NULL");
    }

    /**
     * 変更の記録は、1回の変更（change_set_id）を1行にする（cnt は変えた項目の数）
     */
    protected function changes(string $table, string $source): Builder
    {
        return DB::table($table)
            ->selectRaw('MAX(changed_at) as at, 0 as seq, ? as kind, ? as source, ? as event, MIN(id) as ref_id, MAX(blog_id) as blog_id, COUNT(*) as cnt', [$this->kindOf($source), $source, 'changed'])
            ->groupBy('change_set_id');
    }

    protected function kindOf(string $source): string
    {
        foreach ($this->kindMap() as $kind => $sources) {
            if (in_array($source, $sources, true) || (str_starts_with($source, 'record:') && $kind === 'wordpress')) {
                return $kind;
            }
        }

        return 'other';
    }

    /**
     * @return array<string, list<string>>
     */
    protected function kindMap(): array
    {
        return [
            'sync'       => ['sync_runs', 'sync_issues'],
            'schedule'   => ['scheduled_task_runs'],
            'ai'         => ['ai_generations', 'ai_batches', 'article_evaluations', 'article_management_suggestions'],
            'draft'      => ['article_draft_histories', 'wordpress_push_operations'],
            'management' => ['article_management_histories'],
            'wordpress'  => [],
            'topic'      => ['topic_suggestions', 'category_launches'],
            'material'   => ['materials', 'material_suggestions', 'article_material_reviews', 'affiliate_programs'],
            'image'      => ['images'],
            'google'     => ['google_fetch_runs', 'google_index_statuses'],
            'pagespeed'  => ['pagespeed_runs'],
            'openai'     => ['ai_price_checks', 'ai_price_changes', 'ai_credit_entries'],
            'wpinfo'     => ['wordpress_components'],
            'blog'       => ['blog_histories', 'blog_setting_histories'],
            'notice'     => ['notices'],
            'login'      => ['login_histories'],
            'voice'      => ['voice_turns'],
        ];
    }

    /**
     * 行に、表示する内容（kind_label・text・url）を付ける。記録は種類ごとにまとめて読む
     *
     * @param  Collection<int, object>  $rows
     * @return Collection<int, object>
     */
    protected function describe(Collection $rows): Collection
    {
        $models = [];
        foreach ($rows->groupBy('source') as $source => $group) {
            $models[$source] = $this->load($source, $group->pluck('ref_id')->unique()->values()->all());
        }

        return $rows->map(function (object $row) use ($models) {
            $model = $models[$row->source][$row->ref_id] ?? null;
            [$text, $url] = $model === null ? ['（記録が見つかりません）', null] : $this->line($row, $model);
            $row->at = Carbon::parse($row->at, 'UTC');
            $row->kind_label = self::KINDS[$row->kind] ?? $row->kind;
            $row->text = $text;
            $row->url = $url;

            return $row;
        });
    }

    /**
     * 記録を読む（[ref_id => 記録]）。変更の記録は、同じ change_set_id の行をまとめた物（rows・target）にする
     *
     * @param  list<int>  $ids
     * @return array<int, mixed>
     */
    protected function load(string $source, array $ids): array
    {
        $models = [
            'sync_runs'                      => fn () => SyncRun::whereIn('id', $ids)->get(),
            'sync_issues'                    => fn () => SyncIssue::whereIn('id', $ids)->get(),
            'scheduled_task_runs'            => fn () => ScheduledTaskRun::whereIn('id', $ids)->get(),
            'ai_generations'                 => fn () => AiGeneration::with(['post', 'page', 'material', 'image', 'draft'])->whereIn('id', $ids)->get(),
            'ai_batches'                     => fn () => AiBatch::whereIn('id', $ids)->get(),
            'article_evaluations'            => fn () => ArticleEvaluation::with(['post', 'page', 'draft'])->whereIn('id', $ids)->get(),
            'article_management_suggestions' => fn () => ArticleManagementSuggestion::with(['post', 'page'])->whereIn('id', $ids)->get(),
            'wordpress_push_operations'      => fn () => WordPressPushOperation::with(['draft', 'post', 'page', 'category', 'tag', 'media'])->whereIn('id', $ids)->get(),
            'topic_suggestions'              => fn () => TopicSuggestion::whereIn('id', $ids)->get(),
            'category_launches'              => fn () => CategoryLaunch::with('parentCategory')->whereIn('id', $ids)->get(),
            'materials'                      => fn () => Material::whereIn('id', $ids)->get(),
            'material_suggestions'           => fn () => MaterialSuggestion::with('material')->whereIn('id', $ids)->get(),
            'article_material_reviews'       => fn () => ArticleMaterialReview::with(['post', 'page'])->whereIn('id', $ids)->get(),
            'affiliate_programs'             => fn () => AffiliateProgram::whereIn('id', $ids)->get(),
            'images'                         => fn () => Image::whereIn('id', $ids)->get(),
            'google_fetch_runs'              => fn () => GoogleFetchRun::whereIn('id', $ids)->get(),
            'google_index_statuses'          => fn () => GoogleIndexStatus::whereIn('id', $ids)->get(),
            'pagespeed_runs'                 => fn () => PageSpeedRun::with(['post:id,title_raw', 'page:id,title_raw'])->whereIn('id', $ids)->get(),
            'ai_price_checks'                => fn () => AiPriceCheck::whereIn('id', $ids)->get(),
            'ai_price_changes'               => fn () => AiPriceChange::whereIn('id', $ids)->get(),
            'ai_credit_entries'              => fn () => AiCreditEntry::whereIn('id', $ids)->get(),
            'wordpress_components'           => fn () => WordPressComponent::whereIn('id', $ids)->get(),
            'notices'                        => fn () => Notice::whereIn('id', $ids)->get(),
            'login_histories'                => fn () => LoginHistory::whereIn('id', $ids)->get(),
            'voice_turns'                    => fn () => VoiceTurn::whereIn('id', $ids)->get(),
        ];
        if (isset($models[$source])) {
            return $models[$source]()->keyBy('id')->all();
        }

        // 変更の記録（同じ change_set_id の行をまとめる）
        $table = str_starts_with($source, 'record:') ? substr($source, 7) . '_histories' : $source;
        $sets = DB::table($table)->whereIn('id', $ids)->pluck('change_set_id', 'id');
        $changes = DB::table($table)->whereIn('change_set_id', $sets->values()->all())->orderBy('id')->get()->groupBy('change_set_id');
        $targets = $this->changeTargets($source, $changes->flatten(1));

        return $sets->map(fn ($set) => (object) ['rows' => $changes[$set] ?? collect(), 'targets' => $targets])->all();
    }

    /**
     * 変更の記録の対象（[id => 記録]）
     *
     * @param  Collection<int, object>  $changes
     * @return array<int, mixed>
     */
    protected function changeTargets(string $source, Collection $changes): array
    {
        [$class, $column, $with] = match (true) {
            $source === 'article_draft_histories'      => [ArticleDraft::class, 'article_draft_id', []],
            $source === 'article_management_histories' => [ArticleManagement::class, 'article_management_id', ['post', 'page']],
            $source === 'blog_histories',
            $source === 'blog_setting_histories'       => [Blog::class, 'blog_id', []],
            str_starts_with($source, 'record:')        => [self::RECORDS[substr($source, 7)][0], substr($source, 7) . '_id', []],
        };

        return $class::with($with)->whereIn('id', $changes->pluck($column)->unique()->all())->get()->keyBy('id')->all();
    }

    /**
     * 1行の内容と、詳しく見る画面（[文, URL|null]）
     *
     * @return array{0: string, 1: string|null}
     */
    protected function line(object $row, mixed $m): array
    {
        $e = $row->event;

        return match ($row->source) {
            'sync_runs' => $e === 'start'
                ? ["WordPressとの同期を開始（{$this->label($m->trigger)}）", route('database.sync-runs.index')]
                : ["WordPressとの同期を終了：{$this->label($m->status)}" . $this->took($m->started_at, $m->finished_at) . $this->note($m->message), route('database.sync-runs.index')],
            'sync_issues' => $e === 'detected'
                ? ["同期の問題を検出：{$this->label($m->issue_type)}" . $this->note($m->message), route('sync.issues.index')]
                : ['同期の問題を解決済みにした：' . $this->label($m->issue_type), route('sync.issues.index')],
            'scheduled_task_runs' => $e === 'start'
                ? ["定期実行を開始：{$m->label()}（" . (ScheduledTaskRun::TRIGGERS[$m->trigger] ?? $m->trigger) . '）', route('scheduled-tasks.runs')]
                : ["定期実行を終了：{$m->label()}：{$m->statusLabel()}" . $this->took($m->started_at, $m->finished_at) . ($m->processed_count !== null ? "・処理 {$m->processed_count}件" : ''), route('scheduled-tasks.runs')],
            'ai_generations' => $e === 'start'
                ? ["AIの実行を開始：{$this->label($m->purpose)}{$this->generationTarget($m)}（#{$m->id}）", route('ai.generations.show', ['id' => $m->id])]
                : ["AIの実行を終了：{$this->label($m->purpose)}：{$this->label($m->status)}{$this->generationTarget($m)}（#{$m->id}）" . $this->took($m->started_at ?? $m->created_at, $m->completed_at), route('ai.generations.show', ['id' => $m->id])],
            'ai_batches' => $e === 'start'
                ? ["AIのまとめて実行を開始：{$this->label($m->purpose)}（{$m->total_count}件）", route('ai.batches.show', ['id' => $m->id])]
                : ["AIのまとめて実行を終了：{$this->label($m->purpose)}：{$this->label($m->status)}" . $this->took($m->created_at, $m->completed_at), route('ai.batches.show', ['id' => $m->id])],
            'article_evaluations' => $e === 'created'
                ? ['記事の評価を保存' . $this->title($m->post ?? $m->page ?? $m->draft) . ($m->score !== null ? "：{$m->score}点" : ''), route('evaluations.show', ['id' => $m->id])]
                : ['記事の評価を確定' . $this->title($m->post ?? $m->page ?? $m->draft), route('evaluations.show', ['id' => $m->id])],
            'article_management_suggestions' => $e === 'created'
                ? ['管理情報の案を作成' . $this->title($m->post ?? $m->page), route('management-suggestions.index')]
                : ["管理情報の案を「{$this->label($m->status)}」にした" . $this->title($m->post ?? $m->page), route('management-suggestions.index')],
            'article_draft_histories' => $this->changeLine('編集案を変更', $m, 'article_draft_id', fn ($draft) => [$this->title($draft), route('drafts.edit', ['id' => $draft->id])]),
            'wordpress_push_operations' => [
                ['sent' => 'WordPressへの反映を送信', 'completed' => 'WordPressへの反映が完了', 'failed' => 'WordPressへの反映が失敗'][$e]
                    . "：{$this->label($m->resource_type)}の{$this->label($m->operation)}" . $this->quote($m->targetLabel()) . ($e === 'failed' ? $this->note($m->message) : ''),
                route('push-operations.show', ['id' => $m->id]),
            ],
            'article_management_histories' => $this->changeLine('記事の管理情報を変更', $m, 'article_management_id', fn ($management) => [
                $this->title($management->post ?? $management->page),
                $management->post_id ? route('articles.show', ['type' => 'posts', 'id' => $management->post_id]) : ($management->page_id ? route('articles.show', ['type' => 'pages', 'id' => $management->page_id]) : null),
            ]),
            'topic_suggestions' => $e === 'created'
                ? ['記事の企画の案を作成' . $this->quote($m->title), route('topics.index')]
                : ["記事の企画の案を「{$this->label($m->status)}」にした" . $this->quote($m->title), route('topics.index')],
            'category_launches' => ['カテゴリの立ち上げを開始' . $this->quote($m->parentCategory?->name), route('launches.show', ['id' => $m->id])],
            'materials' => $e === 'created'
                ? ['教材を登録' . $this->quote($m->name), route('materials.edit', ['id' => $m->id])]
                : ['教材をAIで調べた' . $this->quote($m->name), route('materials.edit', ['id' => $m->id])],
            'material_suggestions' => $e === 'created'
                ? ["教材の案を作成：{$this->label($m->type)}" . $this->quote($m->name ?? $m->material?->name), route('materials.suggestions.show', ['id' => $m->id])]
                : ["教材の案を「{$this->label($m->status)}」にした" . $this->quote($m->name ?? $m->material?->name), route('materials.suggestions.show', ['id' => $m->id])],
            'article_material_reviews' => $e === 'created'
                ? ['記事の教材の見直しの結果' . $this->title($m->post ?? $m->page), route('materials.reviews.index')]
                : ["記事の教材の見直しの結果を「{$this->label($m->status)}」にした" . $this->title($m->post ?? $m->page), route('materials.reviews.index')],
            'affiliate_programs' => $e === 'created'
                ? ['アフィリエイトのプログラムを登録' . $this->quote($m->name), route('materials.programs.index')]
                : ['アフィリエイトのリンクを確認' . $this->quote($m->name) . ($m->check_result ? "：{$this->label($m->check_result)}" : ''), route('materials.programs.index')],
            'images' => ["画像を作成：{$this->label($m->kind)}" . $this->quote($m->title), route('images.show', ['id' => $m->id])],
            'google_fetch_runs' => $e === 'start'
                ? ["Googleとの同期を開始：{$this->label($m->service)}", route('google.fetch-runs.index')]
                : ["Googleとの同期を終了：{$this->label($m->service)}：{$this->label($m->status)}" . $this->took($m->started_at, $m->finished_at) . ($m->row_count !== null ? "・{$m->row_count}行" : ''), route('google.fetch-runs.index')],
            'google_index_statuses' => ['インデックスの状態が変わった：' . ($m->previous_category ? $this->label($m->previous_category) . ' → ' : '') . $this->label($m->category) . $this->note($m->url), route('google.index-status')],
            'pagespeed_runs' => $e === 'start'
                ? ["PageSpeed Insights の測定を開始：{$m->strategyLabel()}" . $this->quote($m->targetLabel()), route('pagespeed.runs.index')]
                : ["PageSpeed Insights の測定を終了：{$m->strategyLabel()}：{$m->statusLabel()}" . $this->quote($m->targetLabel())
                    . ($m->status === 'succeeded' ? "（パフォーマンス {$m->performance_score}・SEO {$m->seo_score}）" : $this->note($m->error)) . $this->took($m->started_at, $m->finished_at), route('pagespeed.runs.index')],
            'ai_price_checks' => ['OpenAI API料金表を確認：' . (['succeeded' => '成功', 'failed' => '失敗'][$this->value($m->status)] ?? $this->value($m->status)) . "（反映 {$m->applied_count}件・確認待ち {$m->pending_count}件）", route('ai.prices.history')],
            'ai_price_changes' => $e === 'created'
                ? ["料金の変更を見つけた：{$m->price_key} の {$m->field}（{$m->old_value} → {$m->new_value}）", route('ai.prices.history')]
                : ["料金の変更を「{$this->label($m->status)}」にした：{$m->price_key} の {$m->field}", route('ai.prices.history')],
            'ai_credit_entries' => ["{$this->label($m->type)}を登録：$" . number_format((float) $m->amount, 2), route('ai.credits.index')],
            'wordpress_components' => ["WordPress の本体・プラグイン・テーマの更新を確認（{$row->cnt}件）", route('wordpress-updates.index')],
            'blog_histories' => $this->changeLine('ブログの情報を変更', $m, 'blog_id', fn ($blog) => ['', route('database-blog-detail', ['id' => $blog->id])]),
            'blog_setting_histories' => $this->changeLine('ブログの設定を変更', $m, 'blog_id', fn ($blog) => ['', route('database-blog-detail', ['id' => $blog->id])]),
            'notices' => [
                ['occurred' => 'お知らせ', 'resolved' => 'お知らせが解消', 'confirmed' => 'お知らせを確認済みにした'][$e]
                    . '（' . ($m->level === 'error' ? '要対応' : '注意') . "）：{$m->message}",
                route('notices.index'),
            ],
            'login_histories' => [(self::LOGIN_EVENTS[$this->value($m->event)] ?? $this->value($m->event)) . "（{$m->email}・{$m->ip_address}）", route('database.login-histories.index')],
            'voice_turns' => ['音声の会話：「' . Str::limit((string) $m->transcript, 60) . '」' . ($m->error ? '（失敗）' : ''), null],
            default => $this->recordLine($row, $m),
        };
    }

    /**
     * WordPress のデータの変更の行
     *
     * @return array{0: string, 1: string|null}
     */
    protected function recordLine(object $row, object $m): array
    {
        $key = substr($row->source, 7);
        [, $table, $label] = self::RECORDS[$key];

        return $this->changeLine("{$label}の変更", $m, $key . '_id', fn ($record) => [
            $this->quote($record->title_raw ?? $record->name ?? $record->slug ?? null),
            route('database.wordpress-records.show', ['table' => $table, 'id' => $record->id]),
        ]);
    }

    /**
     * 変更の記録の行：「名前：『対象』（変えた項目）［変更の元］」
     *
     * @param  callable(mixed): array{0: string, 1: string|null}  $target
     * @return array{0: string, 1: string|null}
     */
    protected function changeLine(string $name, object $set, string $column, callable $target): array
    {
        $first = $set->rows->first();
        $model = $first ? ($set->targets[$first->{$column}] ?? null) : null;
        [$title, $url] = $model ? $target($model) : ['', null];
        // 作成（__created）・削除（__deleted）の印は、「変更」の言葉で表す。ほかの項目は、列の名前のまま出す
        $fields = $set->rows->pluck('field')->unique()->values();
        if ($fields->contains('__deleted')) {
            $name = str_replace('変更', '削除', $name);
        } elseif ($fields->contains('__created')) {
            $name = str_replace('変更', '作成', $name);
        }
        $fields = $fields->reject(fn ($field) => str_starts_with($field, '__'))->values();
        $fieldText = $fields->isEmpty() ? '' : '（' . $fields->take(5)->implode('・') . ($fields->count() > 5 ? ' ほか' . ($fields->count() - 5) . '項目' : '') . '）';
        $source = $first ? (self::CHANGE_SOURCES[$first->source] ?? $first->source) : '';

        return ["{$name}{$title}{$fieldText}［{$source}］", $url];
    }

    protected function generationTarget(AiGeneration $m): string
    {
        $target = $m->post ?? $m->page ?? $m->draft;
        if ($target) {
            return $this->title($target);
        }

        return $this->quote($m->material?->name ?? $m->image?->title);
    }

    protected function title(mixed $article): string
    {
        return $article ? $this->quote($article->title_raw ?? null) : '';
    }

    protected function quote(?string $text): string
    {
        return $text === null || $text === '' ? '' : '『' . Str::limit($text, 60) . '』';
    }

    protected function note(?string $text): string
    {
        return $text === null || $text === '' ? '' : '：' . Str::limit($text, 80);
    }

    /**
     * かかった時間（「（3分12秒）」）
     */
    protected function took(mixed $from, mixed $to): string
    {
        if (! $from instanceof CarbonInterface || ! $to instanceof CarbonInterface) {
            return '';
        }
        $seconds = max(0, (int) $from->diffInSeconds($to));

        return '（' . ($seconds >= 3600 ? intdiv($seconds, 3600) . '時間' : '') . ($seconds >= 60 ? intdiv($seconds % 3600, 60) . '分' : '') . ($seconds % 60) . '秒）';
    }

    protected function label(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            return method_exists($value, 'label') ? $value->label() : (string) $value->value;
        }

        return (string) $value;
    }

    protected function value(mixed $value): string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
    }
}
