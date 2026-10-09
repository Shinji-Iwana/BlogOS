<?php

namespace App\Services\Voice;

use App\Enums\DraftState;
use App\Enums\GoogleIndexCategory;
use App\Models\ArticleDraft;
use App\Models\Blog;
use App\Models\GoogleIndexStatus;
use App\Models\Page;
use App\Models\Post;
use App\Repositories\ArticleEvaluationRepository;
use App\Services\Ai\AiApiPolicy;
use App\Services\Ai\AiCreditService;
use App\Services\Dashboard\DashboardDataService;
use App\Services\Dashboard\DashboardStatusService;
use App\Support\DashboardLinks;

/**
 * 音声で使える道具（D-58）。
 *
 * 文章の AI に「道具」（function）として渡し、AI が選んで呼ぶ。方式 c・d・e で共通。
 * 段階1：見るだけの道具。段階2：確認つきの操作（同期の開始・品質診断のまとめて実行。VoiceActions）。
 * WordPress への反映・削除・承認は声ではしない。
 * 画面を開く道具は、開く URL（navigate）を返す。ブラウザが、画面を移らずに画面のパネルに開く。'close' はパネルを閉じる（D-59）。
 */
class VoiceTools
{
    public function __construct(
        protected DashboardDataService $dashboard,
        protected DashboardStatusService $status,
        protected AiCreditService $credits,
        protected AiApiPolicy $policy,
        protected ArticleEvaluationRepository $evaluations,
        protected VoiceActions $actions,
    ) {
    }

    /** 今の発言の利用者と、記録（voice_turns）の ID（確認つきの操作で、同じ発言の中の確認を断るため） */
    protected ?int $userId = null;

    protected int $turnId = 0;

    public function withContext(?int $userId, int $turnId): static
    {
        $this->userId = $userId;
        $this->turnId = $turnId;

        return $this;
    }

    /**
     * 開ける画面（キー => [名前, URL]）。トップページと、トップページの入口（DashboardLinks。設定は「管理」にある）
     *
     * @return array<string, array{label: string, url: string}>
     */
    public function screens(bool $hasSelectedBlog): array
    {
        $screens = [
            'home' => ['label' => 'トップページ', 'url' => route('home')],
        ];
        foreach (DashboardLinks::GROUPS as $group) {
            foreach ($group['links'] as $link) {
                if ($link['blog'] && ! $hasSelectedBlog) {
                    continue;
                }
                $key = $link['route'] . (isset($link['params']) ? ':' . implode(',', $link['params']) : '');
                $screens[$key] = ['label' => $group['label'] . '：' . $link['label'], 'url' => route($link['route'], $link['params'] ?? [])];
            }
        }

        return $screens;
    }

    /**
     * 文章の AI に渡す道具の定義（Responses API の function）
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(bool $hasSelectedBlog): array
    {
        $screens = $this->screens($hasSelectedBlog);
        $screenList = implode('、', array_map(fn ($key, $screen) => "{$key}（{$screen['label']}）", array_keys($screens), $screens));

        return [
            $this->function('open_screen', 'BlogOS の画面を開く。使える画面：' . $screenList, [
                'screen' => ['type' => 'string', 'enum' => array_keys($screens), 'description' => '開く画面のキー'],
            ], ['screen']),
            $this->function('close_screen', '画面のパネル（開いた画面）を閉じて、トップページに戻る', [], []),
            $this->function('get_status', '今の BlogOS の状況（同期・定期実行・WordPress・AI の残高・内部リンク・教材と提携の状態と、全体の状態）を調べる', [], []),
            $this->function('get_ai_credit', 'OpenAI の残高の見込みと、今月の AI の費用を調べる', [], []),
            $this->function('count_articles', '記事の件数を調べる（例の記事のタイトルも返す）。not_indexed：インデックス未登録、low_score：最新の評価が threshold 点未満、unevaluated：まだ評価していない公開中の記事、editing_drafts：作業中・確認待ちの編集案', [
                'kind'      => ['type' => 'string', 'enum' => ['not_indexed', 'low_score', 'unevaluated', 'editing_drafts']],
                'threshold' => ['type' => ['integer', 'null'], 'description' => 'low_score のときの点数（指定がなければ 70）'],
            ], ['kind', 'threshold']),
            $this->function('find_articles', '記事（投稿・固定ページ）を、タイトルやスラッグの言葉で探す（最大5件）', [
                'query' => ['type' => 'string', 'description' => '探す言葉'],
            ], ['query']),
            $this->function('open_article', '記事の詳細の画面を開く（find_articles で見つけた記事）', [
                'type' => ['type' => 'string', 'enum' => ['posts', 'pages']],
                'id'   => ['type' => 'integer', 'description' => 'find_articles が返した id'],
            ], ['type', 'id']),

            // 確認つきの操作（段階2）：呼んだ時点では実行しない。返ってきた summary を伝えて、実行してよいか尋ねる
            $this->function('start_sync', '【確認が必要な操作】選択中のブログの WordPress との同期を始める準備をする（まだ実行しない）', [], []),
            $this->function('run_quality_diagnosis', '【確認が必要な操作】記事の品質診断（AI。費用がかかる）をまとめて実行する準備をする（まだ実行しない）。target：below_score（threshold 点未満）・unevaluated（未評価）・needs_reevaluation（再評価の条件に当てはまる）・not_indexed（インデックス未登録）', [
                'target'    => ['type' => 'string', 'enum' => VoiceActions::DIAGNOSIS_TARGETS],
                'threshold' => ['type' => ['integer', 'null'], 'description' => 'below_score のときの点数（指定がなければ 70）'],
                'limit'     => ['type' => 'integer', 'description' => '件数（最大 ' . VoiceActions::MAX_DIAGNOSIS . '）'],
            ], ['target', 'threshold', 'limit']),
            $this->function('confirm_action', '確認待ちの操作を実行する。利用者が、前の返事で伝えた操作の内容に、この発言で同意した（はい・実行して など）ときだけ呼ぶ', [], []),
            $this->function('cancel_action', '確認待ちの操作をやめる（利用者が、いいえ・やめて などと言ったとき）', [], []),
        ];
    }

    /**
     * 道具を実行する
     *
     * @return array{result: array<string, mixed>, navigate: string|null}
     */
    public function call(string $name, array $arguments, ?Blog $blog): array
    {
        return match ($name) {
            'open_screen'    => $this->openScreen((string) ($arguments['screen'] ?? ''), $blog),
            'close_screen'   => ['result' => ['closed' => true], 'navigate' => 'close'],
            'get_status'     => ['result' => $this->getStatus(), 'navigate' => null],
            'get_ai_credit'  => ['result' => $this->getAiCredit(), 'navigate' => null],
            'count_articles' => ['result' => $this->countArticles((string) ($arguments['kind'] ?? ''), (int) ($arguments['threshold'] ?? 70) ?: 70, $blog), 'navigate' => null],
            'find_articles'  => ['result' => $this->findArticles((string) ($arguments['query'] ?? ''), $blog), 'navigate' => null],
            'open_article'   => $this->openArticle((string) ($arguments['type'] ?? ''), (int) ($arguments['id'] ?? 0), $blog),
            'start_sync'     => ['result' => $this->actions->prepareSync($blog, $this->turnId), 'navigate' => null],
            'run_quality_diagnosis' => ['result' => $this->actions->prepareDiagnosis($blog, $this->turnId, (string) ($arguments['target'] ?? ''), isset($arguments['threshold']) ? (int) $arguments['threshold'] : null, (int) ($arguments['limit'] ?? 10)), 'navigate' => null],
            'confirm_action' => $this->actions->confirm($blog, $this->turnId, $this->userId),
            'cancel_action'  => ['result' => $this->actions->cancel(), 'navigate' => null],
            default          => ['result' => ['error' => "「{$name}」という道具はありません。"], 'navigate' => null],
        };
    }

    protected function openScreen(string $screen, ?Blog $blog): array
    {
        $screens = $this->screens($blog !== null);
        if (! isset($screens[$screen])) {
            return ['result' => ['error' => 'その画面は開けません（ブログを選んでいないか、ない画面です）。'], 'navigate' => null];
        }

        return ['result' => ['opened' => $screens[$screen]['label']], 'navigate' => $screens[$screen]['url']];
    }

    protected function getStatus(): array
    {
        $notices = $this->dashboard->notices();
        $panels = $this->dashboard->panels($notices);
        $states = ['ok' => '問題なし', 'warn' => '注意', 'error' => '要対応', 'none' => '判定しない'];

        return [
            'overall' => ['normal' => 'すべて正常', 'warning' => '注意あり', 'critical' => '要対応あり'][$this->status->overall($panels)],
            'busy'    => $this->status->busy($notices) ? 'AIの実行中（同期・定期実行を含む）' : null,
            'panels'  => array_map(fn ($panel) => [
                'area'  => $panel['label'],
                'state' => $states[$panel['state']],
                'value' => $panel['value'],
                'notes' => $panel['lines'],
            ], $panels),
        ];
    }

    protected function getAiCredit(): array
    {
        $status = $this->credits->status();

        return [
            'balance_usd'     => $status['balance'],
            'level'           => ['ok' => '十分', 'warning' => '少なくなっている', 'critical' => '止まる見込み', 'unknown' => '残高が未登録'][$status['level']] ?? $status['level'],
            'this_month_usd'  => round($this->policy->spentThisMonth(), 2),
        ];
    }

    protected function countArticles(string $kind, int $threshold, ?Blog $blog): array
    {
        if ($blog === null) {
            return ['error' => 'ブログが選ばれていません。'];
        }

        $published = Post::where('blog_id', $blog->id)->whereNull('wordpress_deleted_at')->where('status', 'publish');

        switch ($kind) {
            case 'not_indexed':
                $ids = GoogleIndexStatus::where('blog_id', $blog->id)->whereNotNull('post_id')->whereNotNull('category')
                    ->whereNotIn('category', [GoogleIndexCategory::Indexed->value, GoogleIndexCategory::Unknown->value])->pluck('post_id');
                $posts = (clone $published)->whereIn('id', $ids);
                $screen = 'google.index-status';
                break;
            case 'low_score':
            case 'unevaluated':
                $latest = $this->evaluations->latestForArticles($blog->id)['posts'];
                $low = array_keys(array_filter($latest, fn ($evaluation) => $evaluation->score !== null && (float) $evaluation->score < $threshold));
                $posts = $kind === 'low_score' ? (clone $published)->whereIn('id', $low) : (clone $published)->whereNotIn('id', array_keys($latest));
                $screen = 'ai.batches.index';
                break;
            case 'editing_drafts':
                $drafts = ArticleDraft::where('blog_id', $blog->id)->whereIn('state', [DraftState::Editing->value, DraftState::Review->value]);

                return ['count' => (clone $drafts)->count(), 'examples' => (clone $drafts)->latest('id')->limit(3)->pluck('title_raw')->all(), 'screen' => 'drafts.index'];
            default:
                return ['error' => "「{$kind}」は数えられません。"];
        }

        return ['count' => (clone $posts)->count(), 'examples' => (clone $posts)->orderBy('id')->limit(3)->pluck('title_raw')->all(), 'screen' => $screen];
    }

    protected function findArticles(string $query, ?Blog $blog): array
    {
        if ($blog === null) {
            return ['error' => 'ブログが選ばれていません。'];
        }
        $query = trim($query);
        if ($query === '') {
            return ['error' => '探す言葉がありません。'];
        }

        $found = [];
        foreach (['posts' => Post::class, 'pages' => Page::class] as $type => $model) {
            $model::where('blog_id', $blog->id)->whereNull('wordpress_deleted_at')
                ->where(fn ($q) => $q->where('title_raw', 'like', "%{$query}%")->orWhere('slug', 'like', "%{$query}%"))
                ->orderByDesc('id')->limit(5)->get(['id', 'title_raw', 'status'])
                ->each(function ($article) use (&$found, $type) {
                    $found[] = ['type' => $type, 'id' => $article->id, 'title' => $article->title_raw, 'status' => $article->status];
                });
        }

        return ['count' => count($found), 'articles' => array_slice($found, 0, 5)];
    }

    protected function openArticle(string $type, int $id, ?Blog $blog): array
    {
        $model = ['posts' => Post::class, 'pages' => Page::class][$type] ?? null;
        $article = ($model !== null && $blog !== null) ? $model::where('blog_id', $blog->id)->find($id) : null;
        if ($article === null) {
            return ['result' => ['error' => 'その記事は見つかりません。'], 'navigate' => null];
        }

        return ['result' => ['opened' => $article->title_raw], 'navigate' => route('articles.show', ['type' => $type, 'id' => $article->id])];
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @param  list<string>  $required
     */
    protected function function(string $name, string $description, array $properties, array $required): array
    {
        return [
            'type'        => 'function',
            'name'        => $name,
            'description' => $description,
            'parameters'  => ['type' => 'object', 'properties' => (object) $properties, 'required' => $required, 'additionalProperties' => false],
            'strict'      => true,
        ];
    }
}
