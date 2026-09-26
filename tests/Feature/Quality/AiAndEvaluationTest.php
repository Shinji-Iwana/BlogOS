<?php

namespace Tests\Feature\Quality;

use App\Enums\AiGenerationStatus;
use App\Enums\ChangeSource;
use App\Enums\DraftOrigin;
use App\Enums\EvaluatorType;
use App\Enums\Judgment;
use App\Enums\PushResourceType;
use App\Models\AiGeneration;
use App\Models\ArticleDraft;
use App\Models\ArticleEvaluation;
use App\Models\Blog;
use App\Models\BlogCredential;
use App\Models\Histories\ArticleDraftHistory;
use App\Models\Post;
use App\Models\User;
use App\Repositories\ArticleDraftRepository;
use App\Services\Quality\QualityStandardLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 品質評価とBlogOSのAI機能（手動実行）。D-06-02、D-07-01〜D-07-07。
 */
class AiAndEvaluationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Blog $blog;

    protected Post $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true, 'quality_profile' => 'si-note']);
        BlogCredential::create(['blog_id' => $this->blog->id, 'username' => 'admin', 'secret' => 'very-secret-password']);

        $this->post = Post::create([
            'blog_id' => $this->blog->id, 'wordpress_id' => 100, 'title_raw' => 'PHP入門', 'status' => 'publish',
            'content_raw' => "<!-- wp:paragraph -->\n<p>行1</p>\n<!-- /wp:paragraph -->\n<p>行2</p>\n<p>行3</p>",
            'link' => 'https://blog.example.test/php/100.html', 'normalized_path' => '/php/100.html',
            'wordpress_modified_gmt' => '2026-09-01 00:00:00',
        ]);

        $this->actingAs($this->user);
    }

    protected function selected(array $data = []): array
    {
        return array_merge(['selected_blog_id' => $this->blog->id], $data);
    }

    protected function allJudgments(string $value = 'good'): array
    {
        $standard = app(QualityStandardLoader::class)->load('si-note');

        return array_fill_keys(array_merge(array_keys($standard->items), array_keys($standard->required)), $value);
    }

    public function test_human_evaluation_and_confirmation(): void
    {
        $this->get(route('evaluations.create', ['target' => "posts:{$this->post->id}"]))
            ->assertOk()
            ->assertSee('intent.main')
            ->assertSee('req.not_orphan');

        // 全て判定していないと確定できない
        $this->post(route('evaluations.store'), $this->selected([
            'target' => "posts:{$this->post->id}", 'judgments' => ['intent.main' => 'good'], 'confirm' => 1,
        ]))->assertSessionHasErrors('evaluation');

        $judgments = $this->allJudgments();
        $judgments['intent.main'] = 'partial';

        $this->post(route('evaluations.store'), $this->selected([
            'target' => "posts:{$this->post->id}", 'judgments' => $judgments, 'confirm' => 1, 'summary' => '良い記事',
        ]))->assertRedirect();

        $evaluation = ArticleEvaluation::sole();
        $this->assertSame(EvaluatorType::Human, $evaluation->evaluator_type);
        $this->assertTrue($evaluation->is_confirmed);
        $this->assertSame(97.5, $evaluation->score);
        $this->assertTrue($evaluation->required_conditions_passed);
        $this->assertSame('1.0.1', $evaluation->quality_common_version);
        $this->assertSame('si-note', $evaluation->quality_profile);
        $this->assertSame(2.5, $evaluation->details()->where('item_key', 'intent.main')->value('points'));

        $this->get(route('evaluations.show', ['id' => $evaluation->id]))->assertOk()->assertSee('公開可');
        $this->get(route('articles.show', ['type' => 'posts', 'id' => $this->post->id]))->assertOk()->assertSee('97.5点');
    }

    public function test_ai_diagnosis_manual_flow(): void
    {
        $this->post(route('ai.generations.store'), $this->selected(['mode' => 'quality_diagnosis', 'target' => "posts:{$this->post->id}"]))
            ->assertRedirect();

        $generation = AiGeneration::sole();
        $this->assertSame(AiGenerationStatus::WaitingOutput, $generation->status);
        $this->assertSame('quality_diagnosis', $generation->template_key);
        $this->assertSame('1.0.1', $generation->template_version);

        // 指示文：記事・採点表・品質基準を含み、認証情報を含まない
        $this->assertStringContainsString('PHP入門', $generation->input);
        $this->assertStringContainsString('| intent.main |', $generation->input);
        $this->assertStringContainsString('resources/quality/common/scoring.md', $generation->input);
        $this->assertStringContainsString('この記事へのリンク：0件', $generation->input);
        $this->assertStringNotContainsString('very-secret-password', $generation->input);
        $this->assertStringNotContainsString('{{', $generation->input);

        $this->get(route('ai.generations.show', ['id' => $generation->id]))->assertOk()->assertSee('指示文をコピー');

        // AIの回答（判定者が人の項目も○にしているが、要人間確認として扱う）
        $items = array_map(fn () => ['judgment' => '○', 'comment' => 'OK'], $this->allJudgments());
        $items['reader.terms'] = ['judgment' => '△', 'comment' => '用語の説明が不足'];
        $output = "診断しました。\n```json\n" . json_encode([
            'required' => array_intersect_key($items, array_flip(['req.no_misinformation', 'req.no_speculation', 'req.verified', 'req.title_match', 'req.not_orphan'])),
            'items'    => array_diff_key($items, array_flip(['req.no_misinformation', 'req.no_speculation', 'req.verified', 'req.title_match', 'req.not_orphan'])),
            'summary'  => '全体的に良い',
            'improvements' => ['用語の説明を追加する'],
        ], JSON_UNESCAPED_UNICODE) . "\n```";

        // モデルの記録は必須
        $this->post(route('ai.generations.submit', ['id' => $generation->id]), $this->selected(['output' => $output]))->assertSessionHasErrors('model');

        $this->post(route('ai.generations.submit', ['id' => $generation->id]), $this->selected([
            'output' => $output, 'service_plan' => 'ChatGPT（無料）', 'model' => 'gpt-6-sol', 'reasoning_effort' => '中',
        ]))->assertSessionHasNoErrors();

        $generation->refresh();
        $this->assertSame(AiGenerationStatus::Succeeded, $generation->status);
        $this->assertSame('gpt-6-sol', $generation->model);

        $evaluation = ArticleEvaluation::sole();
        $this->assertSame(EvaluatorType::Ai, $evaluation->evaluator_type);
        $this->assertFalse($evaluation->is_confirmed);
        $this->assertSame($generation->id, $evaluation->ai_generation_id);
        $this->assertSame(Judgment::NeedsHuman, $evaluation->details()->where('item_key', 'intent.competitors')->first()->judgment);
        $this->assertSame(Judgment::NeedsHuman, $evaluation->details()->where('item_key', 'req.verified')->first()->judgment);
        $this->assertNull($evaluation->required_conditions_passed);
        $this->assertStringContainsString('用語の説明を追加する', $evaluation->summary);

        // AIの評価を元に、人が評価する画面（AIの判定が初期値）
        $this->get(route('evaluations.create', ['target' => "posts:{$this->post->id}", 'from' => $evaluation->id]))
            ->assertOk()
            ->assertSee('AIの評価を初期値にしています');
    }

    public function test_ai_revision_creates_draft_and_tracks_human_edits(): void
    {
        $this->post(route('ai.generations.store'), $this->selected([
            'mode' => 'revision', 'target' => "posts:{$this->post->id}", 'revision_scope' => 'minor', 'notes' => '実際に PHP 8.4 で確認した',
        ]))->assertRedirect();
        $generation = AiGeneration::sole();
        $this->assertStringContainsString('実際に PHP 8.4 で確認した', $generation->input);
        $this->assertStringContainsString('軽微な改善', $generation->input);
        $this->assertStringContainsString('PHP入門：https://blog.example.test/php/100.html', $generation->input);

        // メタディスクリプションが未設定であることを、AIに伝える
        $this->assertStringContainsString('メタディスクリプション：未設定', $generation->input);
        $this->assertStringContainsString('=== メタディスクリプション ===', $generation->input);
        $this->assertSame('1.1.0', $generation->template_version);

        $aiContent = "<p>行1</p>\n<p>行2（改善）</p>\n<p>行3</p>\n<p>行4</p>";
        $output = "=== タイトル ===\nPHP入門【改訂】\n=== メタディスクリプション ===\nPHPの基本を、初心者向けに例を使って解説します。\n=== 抜粋 ===\n\n=== 本文 ===\n```html\n{$aiContent}\n```\n=== 変更点 ===\n- 行2を改善\n=== 自己評価 ===\nなし\n=== 確認が必要な点 ===\nなし";

        $this->post(route('ai.generations.submit', ['id' => $generation->id]), $this->selected(['output' => $output, 'model' => 'gpt-6-sol']))
            ->assertSessionHasNoErrors();

        $draft = ArticleDraft::sole();
        $this->assertSame(DraftOrigin::Ai, $draft->origin);
        $this->assertSame($generation->id, $draft->ai_generation_id);
        $this->assertSame('PHP入門【改訂】', $draft->title_raw);
        $this->assertSame('PHPの基本を、初心者向けに例を使って解説します。', $draft->meta_description);
        $this->assertSame($aiContent, $draft->content_raw);
        $this->assertFalse($draft->human_edited);
        $this->assertTrue(ArticleDraftHistory::where('article_draft_id', $draft->id)->where('source', ChangeSource::Ai->value)->where('field', 'content_raw')->exists());

        // 人が修正すると、修正したことと修正の量を記録する
        $this->put(route('drafts.update', ['id' => $draft->id]), $this->selected([
            'title_raw' => $draft->title_raw, 'content_raw' => "<p>行1</p>\n<p>行2（人が修正）</p>\n<p>行3</p>\n<p>行4</p>",
            'excerpt_raw' => '', 'slug' => 'php', 'status' => 'publish', 'revision_scope' => 'minor',
        ]))->assertSessionHasNoErrors();
        $draft->refresh();
        $this->assertTrue($draft->human_edited);
        $this->assertGreaterThan(0, $draft->edit_ratio);
        $this->assertLessThan(1, $draft->edit_ratio);

        // 作業中の編集案がある記事の改修は、その編集案を対象にする
        $this->post(route('ai.generations.store'), $this->selected(['mode' => 'revision', 'target' => "posts:{$this->post->id}"]));
        $second = AiGeneration::latest('id')->first();
        $this->assertSame($draft->id, $second->article_draft_id);
        $this->assertStringContainsString('行2（人が修正）', $second->input);
    }

    public function test_new_article_and_invalid_output(): void
    {
        $this->post(route('ai.generations.store'), $this->selected(['mode' => 'new_article']))->assertSessionHasErrors('main_keyword');

        $this->post(route('ai.generations.store'), $this->selected([
            'mode' => 'new_article', 'target_type' => '投稿', 'article_type' => 'acquisition', 'article_subtype' => 'know', 'main_keyword' => 'PHP 変数',
        ]))->assertRedirect();
        $generation = AiGeneration::sole();
        $this->assertStringContainsString('集客記事', $generation->input);

        // 形式どおりでない回答は取り込めず、貼り付け直せる
        $this->post(route('ai.generations.submit', ['id' => $generation->id]), $this->selected(['output' => '記事を書きました！', 'model' => 'gpt-6-sol']))
            ->assertSessionHasErrors('ai');
        $this->assertSame(AiGenerationStatus::Failed, $generation->fresh()->status);
        $this->assertSame(0, ArticleDraft::count());

        $output = "=== タイトル ===\nPHPの変数とは\n=== スラッグ ===\nPHP Variables!\n=== 抜粋 ===\n変数の基本\n=== 本文 ===\n<p>本文</p>\n=== 変更点 ===\n- 構成\n=== 自己評価 ===\nなし\n=== 確認が必要な点 ===\nなし";
        $this->post(route('ai.generations.submit', ['id' => $generation->id]), $this->selected(['output' => $output, 'model' => 'gpt-6-sol']))
            ->assertSessionHasNoErrors();

        $draft = ArticleDraft::sole();
        $this->assertTrue($draft->isNewArticle());
        $this->assertSame(PushResourceType::Post, $draft->target_type);
        $this->assertSame('phpvariables', $draft->slug);
        $this->assertSame('draft', $draft->status);
        $this->get(route('ai.generations.show', ['id' => $generation->id]))->assertOk()->assertSee('編集案に取り込みました');
    }

    public function test_seo_analysis_output_is_only_recorded(): void
    {
        $this->post(route('ai.generations.store'), $this->selected(['mode' => 'seo_analysis', 'target' => "posts:{$this->post->id}", 'main_keyword' => 'PHP']));
        $generation = AiGeneration::sole();

        $this->post(route('ai.generations.submit', ['id' => $generation->id]), $this->selected(['output' => "## 検索意図\n初心者", 'model' => 'gpt-6-luna']))
            ->assertSessionHasNoErrors();

        $this->assertSame(AiGenerationStatus::Succeeded, $generation->fresh()->status);
        $this->assertSame(0, ArticleDraft::count());
        $this->assertSame(0, ArticleEvaluation::count());
        $this->get(route('ai.generations.index'))->assertOk()->assertSee('SEO分析');
    }

    public function test_revision_is_rejected_for_locked_or_closed_draft(): void
    {
        $draft = app(ArticleDraftRepository::class)->create([
            'blog_id' => $this->blog->id, 'post_id' => $this->post->id, 'target_type' => PushResourceType::Post, 'title_raw' => 'x',
        ], ChangeSource::BlogosManual, $this->user->id);
        $draft->update(['state' => 'discarded']);

        $this->post(route('ai.generations.store'), $this->selected(['mode' => 'revision', 'target' => "drafts:{$draft->id}"]))
            ->assertSessionHasErrors('ai');
    }
}
