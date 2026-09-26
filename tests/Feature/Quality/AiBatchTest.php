<?php

namespace Tests\Feature\Quality;

use App\Enums\AiBatchItemStatus;
use App\Enums\AiBatchStatus;
use App\Enums\AiBatchTrigger;
use App\Enums\ChangeSource;
use App\Enums\PushResourceType;
use App\Repositories\ArticleDraftRepository;
use App\Repositories\ArticleManagementRepository;
use App\Enums\RevisionScope;
use App\Enums\SuggestionStatus;
use App\Models\ArticleManagementSuggestion;
use App\Enums\ReevaluationReason;
use App\Jobs\RunAiBatchItemJob;
use App\Models\AiBatch;
use App\Models\AiBatchItem;
use App\Models\AiGeneration;
use App\Models\ArticleDraft;
use App\Models\ArticleEvaluation;
use App\Models\Blog;
use App\Models\BlogAiSetting;
use App\Models\InternalLink;
use App\Models\Post;
use App\Models\User;
use App\Services\Ai\AiBatchService;
use App\Services\Quality\QualityStandardLoader;
use App\Services\Quality\ReevaluationDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * まとめて実行と、条件による自動の再評価（D-25）。
 */
class AiBatchTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Blog $blog;

    /** @var array<int, Post> */
    protected array $posts = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.openai.key' => 'sk-test-key', 'blogos.ai.api.monthly_budget_usd' => 10]);
        Http::preventStrayRequests();

        $this->user = User::factory()->create();
        $this->blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true, 'quality_profile' => 'si-note']);

        foreach ([1, 2, 3] as $number) {
            $this->posts[$number] = Post::create([
                'blog_id' => $this->blog->id, 'wordpress_id' => $number, 'title_raw' => "記事{$number}", 'status' => 'publish',
                'content_raw' => "<p>本文{$number}</p>", 'link' => "https://blog.example.test/{$number}.html", 'normalized_path' => "/{$number}.html",
                'wordpress_modified_gmt' => '2026-09-01 00:00:00',
            ]);
        }
        // 下書きは対象にしない
        Post::create(['blog_id' => $this->blog->id, 'wordpress_id' => 9, 'title_raw' => '下書き', 'status' => 'draft', 'content_raw' => '', 'link' => 'https://blog.example.test/?p=9']);

        $this->actingAs($this->user);
    }

    protected function selected(array $data = []): array
    {
        return array_merge(['selected_blog_id' => $this->blog->id], $data);
    }

    /**
     * 自動の再評価の後の編集案の作成を無効にする設定
     */
    protected function noRevision(): array
    {
        return ['auto_revision_enabled' => 0, 'auto_revision_model' => 'gpt-6-luna', 'auto_revision_reasoning_effort' => 'medium'];
    }

    protected function fakeOpenAi(): void
    {
        $standard = app(QualityStandardLoader::class)->load('si-note');
        $judgments = fn (array $keys) => array_map(fn () => ['judgment' => '△', 'comment' => '一部不足'], array_flip($keys));
        $diagnosis = json_encode(['required' => $judgments(array_keys($standard->required)), 'items' => $judgments(array_keys($standard->items)), 'summary' => '要改善'], JSON_UNESCAPED_UNICODE);

        Http::fake(['api.openai.com/v1/responses' => function (Request $request) use ($diagnosis) {
            $text = match (true) {
                str_contains($request['input'], '=== 本文 ===') => "=== タイトル ===\n改修した記事\n=== 本文 ===\n<p>改修した本文</p>",
                str_contains($request['input'], '管理情報（記事種類・細分類・キーワード・検索意図）の案') => "```json\n" . json_encode([
                    'article_type' => 'acquisition', 'article_subtype' => 'unknown_subtype', 'main_keyword' => 'PHP 入門',
                    'sub_keywords' => ['PHP 基本', 'PHP 書き方', null], 'main_search_intent' => 'PHPの基本を知りたい',
                    'sub_search_intents' => ['PHPを始めたい'], 'reason' => '初心者向けの解説のため。',
                ], JSON_UNESCAPED_UNICODE) . "\n```",
                default => $diagnosis,
            };

            return Http::response([
                'id' => 'resp_1', 'status' => 'completed', 'model' => $request['model'],
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $text]]]],
                'usage' => ['input_tokens' => 20000, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 10000, 'output_tokens_details' => ['reasoning_tokens' => 1000]],
            ]);
        }]);
    }

    protected function evaluate(Post $post, array $attributes = []): ArticleEvaluation
    {
        $standard = app(QualityStandardLoader::class)->load('si-note');

        return ArticleEvaluation::create($attributes + [
            'blog_id' => $this->blog->id, 'post_id' => $post->id, 'evaluator_type' => 'ai',
            'evaluated_wordpress_modified_gmt' => $post->wordpress_modified_gmt, 'inbound_link_count' => 0,
            'quality_common_version' => $standard->commonVersion, 'quality_profile' => 'si-note', 'quality_profile_version' => $standard->profileVersion,
            'score' => 80,
        ]);
    }

    public function test_quality_diagnosis_for_all_articles_with_luna_by_default(): void
    {
        $this->fakeOpenAi();

        $this->get(route('ai.batches.create', ['mode' => 'quality_diagnosis']))
            ->assertOk()
            ->assertSee('対象：3件')
            ->assertSee('<option value="gpt-6-luna" data-efforts="none,low,medium,high" selected>', false)
            ->assertDontSee('下書き');

        $this->post(route('ai.batches.store'), $this->selected([
            'mode' => 'quality_diagnosis', 'target' => 'all', 'model' => 'gpt-6-luna', 'reasoning_effort' => 'medium',
        ]))->assertRedirect();

        $batch = AiBatch::sole();
        $this->assertSame(AiBatchTrigger::Manual, $batch->trigger);
        $this->assertSame(AiBatchStatus::Completed, $batch->status);
        $this->assertSame(3, $batch->total_count);
        $this->assertSame(3, AiBatchItem::where('status', AiBatchItemStatus::Succeeded)->count());
        $this->assertSame(3, ArticleEvaluation::count());
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => $request['model'] === 'gpt-6-luna' && $request['reasoning'] === ['effort' => 'medium']);

        // 評価した時点の「この記事へのリンク」の数を記録する
        $this->assertSame(0, ArticleEvaluation::first()->inbound_link_count);

        $this->get(route('ai.batches.show', ['id' => $batch->id]))->assertOk()->assertSee('完了 3件')->assertSee('50.0点');
        $this->get(route('ai.batches.index'))->assertOk()->assertSee('3 / 3');

        // 評価した記事は「まだ評価していない記事」に含まれない
        $this->assertSame([], app(AiBatchService::class)->targets($this->blog, \App\Enums\AiMode::QualityDiagnosis, \App\Enums\AiBatchTarget::Unevaluated));
    }

    public function test_drafts_are_created_after_diagnosis_for_articles_below_score(): void
    {
        $this->fakeOpenAi();

        // 記事3には作業中の編集案がある（人の作業を上書きしない）
        $existing = app(ArticleDraftRepository::class)->create([
            'blog_id' => $this->blog->id, 'post_id' => $this->posts[3]->id, 'target_type' => PushResourceType::Post, 'title_raw' => '人が編集中',
        ], ChangeSource::BlogosManual, $this->user->id);

        $this->get(route('ai.batches.create', ['mode' => 'quality_diagnosis']))->assertOk()->assertSee('基準に満たない記事の編集案を自動で作る');

        $this->post(route('ai.batches.store'), $this->selected([
            'mode' => 'quality_diagnosis', 'target' => 'all', 'model' => 'gpt-6-luna', 'reasoning_effort' => 'medium',
            'follow_up_revision' => 1, 'follow_up_below_score' => 90, 'follow_up_model' => 'gpt-6-sol', 'follow_up_reasoning_effort' => 'low', 'revision_scope' => 'minor',
        ]))->assertRedirect();

        $diagnosis = AiBatch::where('purpose', 'quality_diagnosis')->sole();
        $revision = AiBatch::where('purpose', 'revision')->sole();
        $this->assertSame(AiBatchStatus::Completed, $diagnosis->status);
        $this->assertSame($diagnosis->id, $revision->parent_batch_id);
        $this->assertSame(\App\Enums\AiBatchTarget::AfterDiagnosis, $revision->target);
        $this->assertSame(AiBatchTrigger::Manual, $revision->trigger);
        $this->assertSame('gpt-6-sol', $revision->model);
        $this->assertSame(3, $revision->total_count);
        $this->assertSame(AiBatchStatus::Completed, $revision->status);
        Http::assertSent(fn (Request $request) => $request['model'] === 'gpt-6-sol' && $request['reasoning'] === ['effort' => 'low']);

        // 記事1・2は編集案を作り、記事3は作業中の編集案をそのままにする
        $this->assertSame(2, $revision->items()->where('status', AiBatchItemStatus::Succeeded)->count());
        $skipped = $revision->items()->where('status', AiBatchItemStatus::Skipped)->sole();
        $this->assertSame($this->posts[3]->id, $skipped->post_id);
        $this->assertStringContainsString('作業中の編集案', $skipped->message);
        $this->assertSame('人が編集中', $existing->fresh()->title_raw);
        $this->assertSame(2, ArticleDraft::where('title_raw', '改修した記事')->count());

        // 改修の後に、できた編集案を品質診断し、改修前後の点数を記録する（D-27-01）
        $item = $revision->items()->where('status', AiBatchItemStatus::Succeeded)->first();
        $this->assertSame(RevisionScope::Minor, $item->revision_scope);
        $this->assertSame(50.0, $item->score_before);
        $this->assertSame(50.0, $item->score_after);
        $this->assertNotNull($item->diagnosis_generation_id);
        $draft = ArticleDraft::where('title_raw', '改修した記事')->first();
        $this->assertSame(1, ArticleEvaluation::where('article_draft_id', $draft->id)->count());
        // 診断3回・改修2回・編集案の診断2回
        Http::assertSentCount(7);
        $this->get(route('drafts.edit', ['id' => $draft->id]))->assertOk()->assertSee('改修前（記事の最新の評価）')->assertSee('この編集案の評価');

        $this->get(route('ai.batches.show', ['id' => $diagnosis->id]))->assertOk()->assertSee("まとめて実行 #{$revision->id}（3件・完了）");
        $this->get(route('ai.batches.show', ['id' => $revision->id]))->assertOk()->assertSee('元の品質診断')->assertSee('編集案 #');
    }

    public function test_no_drafts_when_scores_meet_the_standard_or_follow_up_is_off(): void
    {
        $this->fakeOpenAi();

        // 基準を50点にすると、診断の結果（50点）は基準未満ではない。件数の上限で1件だけ実行する
        $this->get(route('ai.batches.create', ['mode' => 'quality_diagnosis', 'limit' => 1]))->assertSee('対象：1件');
        $this->post(route('ai.batches.store'), $this->selected([
            'mode' => 'quality_diagnosis', 'target' => 'all', 'model' => 'gpt-6-luna', 'reasoning_effort' => 'medium',
            'follow_up_revision' => 1, 'follow_up_below_score' => 50, 'limit' => 1,
        ]));
        $this->assertSame(1, AiBatch::count());
        $this->assertSame(1, AiBatch::sole()->total_count);

        $this->post(route('ai.batches.store'), $this->selected([
            'mode' => 'quality_diagnosis', 'target' => 'all', 'model' => 'gpt-6-luna', 'reasoning_effort' => 'medium', 'follow_up_revision' => 0,
        ]));
        $this->assertSame(2, AiBatch::count());
        $this->assertSame(0, ArticleDraft::count());
    }

    public function test_revision_scope_by_score(): void
    {
        $this->assertSame(RevisionScope::Minor, RevisionScope::byScore(null));
        $this->assertSame(RevisionScope::Minor, RevisionScope::byScore(70.0));
        $this->assertSame(RevisionScope::Restructure, RevisionScope::byScore(69.9));
        $this->assertSame(RevisionScope::Restructure, RevisionScope::byScore(40.0));
        $this->assertSame(RevisionScope::Full, RevisionScope::byScore(39.9));

        // AIの設定で、自動の再評価の後の改修範囲を選べる
        $this->put(route('ai.settings.update'), $this->selected(['auto_reevaluation_enabled' => 1, 'auto_model' => 'gpt-6-luna', 'auto_reasoning_effort' => 'medium', 'auto_revision_scope' => 'full'] + $this->noRevision()))
            ->assertSessionHasNoErrors();
        $this->assertSame('full', BlogAiSetting::sole()->auto_revision_scope);
        $this->put(route('ai.settings.update'), $this->selected(['auto_reevaluation_enabled' => 1, 'auto_model' => 'gpt-6-luna', 'auto_reasoning_effort' => 'medium', 'auto_revision_scope' => 'huge'] + $this->noRevision()))
            ->assertSessionHasErrors('auto_revision_scope');
    }

    public function test_management_suggestions_are_created_and_reviewed(): void
    {
        $this->fakeOpenAi();

        // 記事3は、管理情報を登録済み（記事種類とメインキーワード）
        app(ArticleManagementRepository::class)->save($this->posts[3], ['article_type' => 'acquisition'], '登録済み', [], ChangeSource::BlogosManual, $this->user->id);

        $this->get(route('ai.batches.create', ['mode' => 'management_suggestion']))->assertOk()->assertSee('対象：2件')->assertDontSee('記事3');

        $this->post(route('ai.batches.store'), $this->selected([
            'mode' => 'management_suggestion', 'target' => 'unmanaged', 'model' => 'gpt-6-luna', 'reasoning_effort' => 'medium',
        ]))->assertRedirect();

        $this->assertSame(AiBatchStatus::Completed, AiBatch::sole()->status);
        $suggestions = ArticleManagementSuggestion::orderBy('id')->get();
        $this->assertCount(2, $suggestions);
        $first = $suggestions[0];
        $this->assertSame('acquisition', $first->article_type);
        // 定義にない細分類は空にし、理由に残す
        $this->assertNull($first->article_subtype);
        $this->assertStringContainsString('unknown_subtype', $first->reason);
        $this->assertSame(['PHP 基本', 'PHP 書き方'], $first->sub_keywords);
        $this->assertSame(SuggestionStatus::Pending, $first->status);
        // 登録するまで、管理情報は変わらない
        $this->assertNull(app(ArticleManagementRepository::class)->findFor($this->posts[1]));

        // 確認待ちの案がある記事は、次の対象にしない
        $this->get(route('ai.batches.create', ['mode' => 'management_suggestion']))->assertSee('対象：0件');

        $this->get(route('management-suggestions.index'))->assertOk()->assertSee('管理情報の案の確認')->assertSee('PHP 入門')->assertSee('初心者向けの解説のため。');

        // 1件目は直してから登録し、2件目は不採用にする
        $this->post(route('management-suggestions.review'), $this->selected([
            'action' => 'accept', 'selected' => [$first->id],
            'items' => [$first->id => [
                'article_type' => 'acquisition', 'article_subtype' => 'know', 'main_keyword' => 'PHP 入門 初心者',
                'sub_keywords' => "PHP 基本\nPHP 書き方", 'main_search_intent' => 'PHPの基本を知りたい', 'sub_search_intents' => "PHPを始めたい\n環境を作りたい",
            ]],
        ]))->assertSessionHasNoErrors();
        $this->post(route('management-suggestions.review'), $this->selected(['action' => 'reject', 'selected' => [$suggestions[1]->id]]))->assertSessionHasNoErrors();

        $management = app(ArticleManagementRepository::class)->findFor($this->posts[1]);
        $this->assertSame('acquisition', $management->article_type);
        $this->assertSame('know', $management->article_subtype);
        $this->assertSame(['PHPを始めたい', '環境を作りたい'], $management->sub_search_intents);
        $keywords = app(ArticleManagementRepository::class)->keywordsFor($this->posts[1]);
        $this->assertSame(['PHP 入門 初心者', 'PHP 基本', 'PHP 書き方'], $keywords->pluck('keyword')->all());
        $this->assertTrue($management->histories()->where('source', ChangeSource::Ai->value)->where('user_id', $this->user->id)->exists());

        $this->assertSame(SuggestionStatus::Accepted, $first->fresh()->status);
        $this->assertSame(SuggestionStatus::Rejected, $suggestions[1]->fresh()->status);
        $this->assertNull(app(ArticleManagementRepository::class)->findFor($this->posts[2]));

        // 定義にない記事種類は登録できない
        $this->post(route('management-suggestions.review'), $this->selected([
            'action' => 'accept', 'selected' => [$first->id], 'items' => [$first->id => ['article_type' => 'nonexistent']],
        ]))->assertSessionHasErrors('items.' . $first->id . '.article_type');

        // 1記事ずつも作れる（編集案ではなく記事が対象）
        $this->post(route('ai.generations.store'), $this->selected([
            'mode' => 'management_suggestion', 'target' => "posts:{$this->posts[2]->id}", 'execution_method' => 'api',
        ]))->assertRedirect();
        $this->assertSame(1, ArticleManagementSuggestion::where('post_id', $this->posts[2]->id)->where('status', SuggestionStatus::Pending)->count());
    }

    public function test_revision_for_articles_below_score(): void
    {
        $this->fakeOpenAi();
        $this->evaluate($this->posts[1], ['score' => 70]);
        $this->evaluate($this->posts[2], ['score' => 95]);

        $this->get(route('ai.batches.create', ['mode' => 'revision', 'below_score' => 90]))->assertOk()->assertSee('対象：1件')->assertSee('記事1');

        $this->post(route('ai.batches.store'), $this->selected([
            'mode' => 'revision', 'target' => 'below_score', 'below_score' => 90, 'model' => 'gpt-6-luna', 'reasoning_effort' => 'medium', 'revision_scope' => 'minor',
        ]))->assertRedirect();

        $draft = ArticleDraft::sole();
        $this->assertSame($this->posts[1]->id, $draft->post_id);
        $this->assertSame('改修した記事', $draft->title_raw);
        $this->assertSame(AiBatchStatus::Completed, AiBatch::sole()->status);

        // 改修は、評価の結果をもとにする（全ての記事は選べない）
        $this->post(route('ai.batches.store'), $this->selected([
            'mode' => 'revision', 'target' => 'all', 'model' => 'gpt-6-luna', 'reasoning_effort' => 'medium',
        ]))->assertSessionHasErrors('ai');
    }

    public function test_batch_stops_at_monthly_budget_and_can_be_cancelled(): void
    {
        $this->fakeOpenAi();
        // 1記事目の後に「今月の費用＋次の最大の費用」が上限を超える
        config(['blogos.ai.api.monthly_budget_usd' => 0.03]);

        $this->post(route('ai.batches.store'), $this->selected([
            'mode' => 'quality_diagnosis', 'target' => 'all', 'model' => 'gpt-6-luna', 'reasoning_effort' => 'medium',
        ]));

        $batch = AiBatch::sole();
        $this->assertSame(AiBatchStatus::Stopped, $batch->status);
        $this->assertStringContainsString('月の費用の上限', $batch->stop_reason);
        $this->assertSame(1, AiBatchItem::where('status', AiBatchItemStatus::Succeeded)->count());
        $this->assertSame(2, AiBatchItem::where('status', AiBatchItemStatus::Skipped)->count());
        Http::assertSentCount(1);

        // 取り消すと、待機中の記事は実行しない
        config(['blogos.ai.api.monthly_budget_usd' => 10]);
        Queue::fake();
        $this->post(route('ai.batches.store'), $this->selected([
            'mode' => 'quality_diagnosis', 'target' => 'unevaluated', 'model' => 'gpt-6-luna', 'reasoning_effort' => 'medium',
        ]));
        $second = AiBatch::latest('id')->first();
        $this->assertSame(2, $second->total_count);
        Queue::assertPushed(RunAiBatchItemJob::class, 2);

        // 待機中の記事は、別のまとめて実行の対象にしない
        $this->get(route('ai.batches.create', ['mode' => 'quality_diagnosis', 'target' => 'unevaluated']))->assertSee('対象：0件');

        $this->post(route('ai.batches.cancel', ['id' => $second->id]), $this->selected())->assertRedirect();
        (new RunAiBatchItemJob($second->items()->first()->id))->handle(app(\App\Repositories\AiBatchRepository::class), app(AiBatchService::class));

        $this->assertSame(AiBatchStatus::Cancelled, $second->fresh()->status);
        $this->assertSame(2, $second->items()->where('status', AiBatchItemStatus::Skipped)->count());
        Http::assertSentCount(1);
    }

    public function test_reevaluation_reasons(): void
    {
        $post4 = Post::create(['blog_id' => $this->blog->id, 'wordpress_id' => 4, 'title_raw' => '記事4', 'status' => 'publish', 'content_raw' => '', 'link' => 'https://blog.example.test/4.html', 'wordpress_modified_gmt' => '2026-09-01 00:00:00']);
        $post5 = Post::create(['blog_id' => $this->blog->id, 'wordpress_id' => 5, 'title_raw' => '記事5', 'status' => 'publish', 'content_raw' => '', 'link' => 'https://blog.example.test/5.html', 'wordpress_modified_gmt' => '2026-09-01 00:00:00']);
        $post6 = Post::create(['blog_id' => $this->blog->id, 'wordpress_id' => 6, 'title_raw' => '記事6', 'status' => 'publish', 'content_raw' => '', 'link' => 'https://blog.example.test/6.html', 'wordpress_modified_gmt' => '2026-09-01 00:00:00']);
        $post7 = Post::create(['blog_id' => $this->blog->id, 'wordpress_id' => 7, 'title_raw' => '記事7', 'status' => 'publish', 'content_raw' => '', 'link' => 'https://blog.example.test/7.html', 'wordpress_modified_gmt' => '2026-09-01 00:00:00']);

        // 記事1：未評価
        // 記事2：評価した後に更新された（古い評価が先にあっても、最新の評価で判定する）
        $this->evaluate($this->posts[2], ['evaluated_wordpress_modified_gmt' => '2026-08-01 00:00:00']);
        // 記事3：品質基準のバージョンが変わった
        $this->evaluate($this->posts[3], ['quality_common_version' => '0.9.0']);
        // 記事4：この記事へのリンクが増えた
        $this->evaluate($post4);
        InternalLink::create(['blog_id' => $this->blog->id, 'post_id' => $post5->id, 'target_url' => $post4->link, 'target_post_id' => $post4->id]);
        // 記事5：アクセスが落ちた（前回の評価から28日以上たっている）
        $this->evaluate($post5, ['created_at' => now()->subDays(40)]);
        $today = Carbon::now(config('blogos.display_timezone'))->startOfDay();
        foreach ([[$today->copy()->subDays(40), 50], [$today->copy()->subDays(10), 10]] as [$date, $clicks]) {
            DB::table('google_search_console_page_daily')->insert([
                'blog_id' => $this->blog->id, 'date' => $date->toDateString(), 'page_url' => $post5->link, 'page_url_hash' => sha1($post5->link . $date),
                'post_id' => $post5->id, 'clicks' => $clicks, 'impressions' => 100, 'ctr' => 0, 'position' => 5,
            ]);
        }
        // 記事6：前回の評価から90日が過ぎた
        $this->evaluate($post6, ['created_at' => now()->subDays(100)]);
        // 記事7：再評価は不要
        $this->evaluate($post7);

        $reasons = collect(app(ReevaluationDetector::class)->detect($this->blog))
            ->mapWithKeys(fn ($row) => [$row['article']->title_raw => $row['reason']])
            ->all();

        $this->assertSame([
            '記事1' => ReevaluationReason::Unevaluated,
            '記事2' => ReevaluationReason::Changed,
            '記事3' => ReevaluationReason::Version,
            '記事4' => ReevaluationReason::Links,
            '記事5' => ReevaluationReason::Traffic,
            '記事6' => ReevaluationReason::Periodic,
        ], $reasons);
    }

    public function test_auto_reevaluation_settings_and_command(): void
    {
        $this->fakeOpenAi();

        // 初期値は無効・gpt-6-luna・medium
        $this->get(route('ai.settings.edit'))->assertOk()->assertSee('対象になる記事：3件')->assertSee('name="auto_reevaluation_enabled" value="1" >', false);
        $this->artisan('ai:auto-reevaluate')->assertSuccessful();
        $this->assertSame(0, AiBatch::count());
        $this->artisan('ai:auto-reevaluate', ['--dry-run' => true])->expectsOutputToContain('[未評価] 記事1')->assertSuccessful();

        // gpt-6-astra は none に対応していない
        $this->put(route('ai.settings.update'), $this->selected(['auto_reevaluation_enabled' => 1, 'auto_model' => 'gpt-6-astra', 'auto_reasoning_effort' => 'none'] + $this->noRevision()))
            ->assertSessionHasErrors('ai');

        $this->put(route('ai.settings.update'), $this->selected(['auto_reevaluation_enabled' => 1, 'auto_model' => 'gpt-6-sol', 'auto_reasoning_effort' => 'low'] + $this->noRevision()))
            ->assertSessionHasNoErrors();
        $setting = BlogAiSetting::sole();
        $this->assertTrue($setting->auto_reevaluation_enabled);
        $this->assertSame($this->user->id, $setting->updated_by);

        // 1日の上限（2件）まで実行し、残りは翌日に回す
        config(['blogos.ai.auto_reevaluation.daily_limit' => 2]);
        $this->artisan('ai:auto-reevaluate')->assertSuccessful();

        $batch = AiBatch::sole();
        $this->assertSame(AiBatchTrigger::Auto, $batch->trigger);
        $this->assertSame('gpt-6-sol', $batch->model);
        $this->assertSame(2, $batch->total_count);
        $this->assertSame(ReevaluationReason::Unevaluated, $batch->items()->first()->reason);
        $this->assertNull(AiGeneration::first()->requested_by);
        Http::assertSent(fn (Request $request) => $request['model'] === 'gpt-6-sol' && $request['reasoning'] === ['effort' => 'low']);

        $this->artisan('ai:auto-reevaluate')->assertSuccessful();
        $this->assertSame(1, AiBatch::count());

        // 翌日は残りの1件
        $this->travel(1)->days();
        $this->artisan('ai:auto-reevaluate')->assertSuccessful();
        $this->assertSame(1, AiBatch::latest('id')->first()->total_count);

        // 評価した直後は、再評価しない
        $this->travel(1)->days();
        $this->artisan('ai:auto-reevaluate')->assertSuccessful();
        $this->assertSame(2, AiBatch::count());

        // 無効にすると実行しない（記事1本を更新しても）
        $this->put(route('ai.settings.update'), $this->selected(['auto_reevaluation_enabled' => 0, 'auto_model' => 'gpt-6-luna', 'auto_reasoning_effort' => 'medium'] + $this->noRevision()));
        $this->posts[1]->update(['wordpress_modified_gmt' => now()]);
        $this->artisan('ai:auto-reevaluate')->assertSuccessful();
        $this->assertSame(2, AiBatch::count());
        $this->assertFalse(BlogAiSetting::sole()->auto_reevaluation_enabled);
    }

    public function test_auto_reevaluation_creates_drafts_by_default(): void
    {
        $this->fakeOpenAi();

        // 初期値は、診断の後の編集案の作成が有効
        $this->get(route('ai.settings.edit'))->assertSee('name="auto_revision_enabled" value="1" checked', false);

        $this->put(route('ai.settings.update'), $this->selected([
            'auto_reevaluation_enabled' => 1, 'auto_model' => 'gpt-6-luna', 'auto_reasoning_effort' => 'medium',
            'auto_revision_enabled' => 1, 'auto_revision_model' => 'gpt-6-luna', 'auto_revision_reasoning_effort' => 'high',
        ]))->assertSessionHasNoErrors();

        $this->artisan('ai:auto-reevaluate')->assertSuccessful();

        $revision = AiBatch::where('purpose', 'revision')->sole();
        $this->assertSame(AiBatchTrigger::Auto, $revision->trigger);
        $this->assertSame('high', $revision->reasoning_effort);
        // 改修範囲の初期値は「点数で自動判別」：50点は構成の見直し（D-27-02）
        $this->assertSame('auto', $revision->target_parameters['revision_scope']);
        $this->assertSame(RevisionScope::Restructure, $revision->items()->first()->revision_scope);
        $this->assertSame(RevisionScope::Restructure, AiGeneration::where('purpose', 'revision')->first()->revision_scope);
        $this->get(route('ai.batches.show', ['id' => $revision->id]))->assertOk()->assertSee('構成の見直し')->assertSee('50.0');
        $this->assertSame(3, ArticleDraft::count());
        $this->assertSame(3, ArticleDraft::where('ai_generation_id', '!=', null)->count());
    }
}
