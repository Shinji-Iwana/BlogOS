<?php

namespace Tests\Feature\Topics;

use App\Enums\AiGenerationStatus;
use App\Enums\AiMode;
use App\Enums\SuggestionStatus;
use App\Models\AiGeneration;
use App\Models\ArticleDraft;
use App\Models\Blog;
use App\Models\Category;
use App\Models\CategoryLaunch;
use App\Models\CategoryLaunchChild;
use App\Models\TopicSuggestion;
use App\Models\User;
use App\Services\Ai\AiRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * カテゴリの立ち上げ（D-41）：①親カテゴリ → ②子カテゴリ → ③記事の企画 → ④記事の編集案 → ⑤子ロードマップの編集案。
 */
class CategoryLaunchTest extends TestCase
{
    use RefreshDatabase;

    protected Blog $blog;

    protected Category $java;

    protected Category $basic;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.openai.key' => 'sk-test-key', 'blogos.ai.api.monthly_budget_usd' => null]);
        $this->blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true, 'quality_profile' => 'si-note']);
        $this->java = Category::create(['blog_id' => $this->blog->id, 'wordpress_id' => 30, 'name' => 'Java', 'slug' => 'java']);
        $this->basic = Category::create(['blog_id' => $this->blog->id, 'wordpress_id' => 31, 'name' => '基礎', 'slug' => 'java-basic', 'parent_id' => $this->java->id]);
        $this->actingAs(User::factory()->create());
    }

    protected function selected(array $data = []): array
    {
        return array_merge(['selected_blog_id' => $this->blog->id], $data);
    }

    /**
     * 新規記事の API 実行を、記事の本文を返すように置き換えて、取り込む
     */
    protected function completeNewArticles(): void
    {
        Http::fake(['api.openai.com/v1/responses' => function (Request $request) {
            preg_match('/メインキーワード：([^\n]+)/u', $request['input'], $m);
            $roadmap = str_contains($request['input'], '子ロードマップ（学習ガイド）を作ってください');
            $text = "=== タイトル ===\n" . ($roadmap ? 'Java 基礎ロードマップ' : ($m[1] ?? '記事') . 'の記事') . "\n=== 本文 ===\n<h2>本文</h2>\n<p>本文</p>";

            return Http::response(['id' => 'resp', 'status' => 'completed', 'model' => $request['model'],
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $text]]]],
                'usage' => ['input_tokens' => 1000, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 500, 'output_tokens_details' => ['reasoning_tokens' => 0]]]);
        }]);
        foreach (AiGeneration::where('purpose', AiMode::NewArticle)->where('status', AiGenerationStatus::Running)->get() as $generation) {
            app(AiRunService::class)->runApi($generation);
        }
    }

    public function test_launch_flow_until_child_roadmap_draft(): void
    {
        Queue::fake();
        // ② 採用した子カテゴリの案（WordPress にはまだない）
        $newChild = TopicSuggestion::create(['blog_id' => $this->blog->id, 'type' => 'category', 'category_id' => $this->java->id, 'title' => 'オブジェクト指向', 'slug' => 'java-oop', 'scope' => 'クラスと継承', 'status' => SuggestionStatus::Accepted]);

        $this->get(route('launches.index', ['parent_id' => $this->java->id]))->assertOk()->assertSee('基礎（java-basic・0記事）')->assertSee('オブジェクト指向（java-oop）');
        $this->post(route('launches.store'), $this->selected(['parent_id' => $this->java->id, 'categories' => [$this->basic->id], 'suggestions' => [$newChild->id]]))->assertRedirect();
        $launch = CategoryLaunch::sole();
        $children = $launch->children()->orderBy('id')->get();
        $this->assertCount(2, $children);
        $oop = $children[1];
        $this->assertTrue($oop->isNew());

        // ③ 新しい子カテゴリの記事の企画（手動実行）。指示文は、親カテゴリの下で新しい子カテゴリを企画する
        $this->post(route('launches.children.plan', ['id' => $oop->id]), $this->selected(['execution_method' => 'manual']))->assertRedirect();
        $planning = AiGeneration::where('purpose', AiMode::TopicPlanning)->sole();
        $this->assertStringContainsString('新しい子カテゴリ：オブジェクト指向（スラッグ：java-oop）', $planning->input);
        $this->assertStringContainsString('10件ちょうど企画してください', $planning->input);

        $articles = array_map(fn ($i) => ['title' => "記事{$i}【Java入門】", 'main_keyword' => "Java 項目{$i}", 'article_type' => 'acquisition', 'article_subtype' => 'know', 'roadmap_step' => 'ステップ1'], [1, 2, 3]);
        $this->post(route('ai.generations.submit', ['id' => $planning->id]), $this->selected(['output' => json_encode(['articles' => $articles, 'categories' => []], JSON_UNESCAPED_UNICODE), 'model' => 'gpt-6-luna']))->assertSessionHasNoErrors();
        $suggestions = TopicSuggestion::where('launch_child_id', $oop->id)->orderBy('id')->get();
        $this->assertCount(3, $suggestions);
        $this->assertSame($this->java->id, $suggestions[0]->category_id);

        // 2件を採用、1件を見送り
        foreach ([[$suggestions[0], 'accept'], [$suggestions[1], 'accept'], [$suggestions[2], 'reject']] as [$suggestion, $action]) {
            $this->post(route('topics.review', ['id' => $suggestion->id]), $this->selected(['action' => $action]));
        }
        $this->get(route('launches.show', ['id' => $launch->id]))->assertOk()->assertSee('③ 記事の案を採用済み（次は記事の編集案）')->assertSee('採用した記事（2件）の編集案をまとめて作る');

        // ④ 記事の編集案をまとめて作る（API 実行）。二重に作らない
        $this->post(route('launches.children.articles', ['id' => $oop->id]), $this->selected())->assertSessionHas('status', fn ($m) => str_contains($m, '2件の記事の編集案を作り始めました'));
        $this->post(route('launches.children.articles', ['id' => $oop->id]), $this->selected())->assertSessionHas('status', fn ($m) => str_contains($m, '0件'));
        $first = AiGeneration::where('purpose', AiMode::NewArticle)->oldest('id')->first();
        $this->assertStringContainsString('カテゴリ：Java ＞ オブジェクト指向（新しい子カテゴリ）', $first->input);
        $this->assertStringContainsString('タイトル案：記事1【Java入門】', $first->input);

        // ⑤ は、編集案がそろう前は作れない
        $this->post(route('launches.children.roadmap', ['id' => $oop->id]), $this->selected())->assertSessionHasErrors('ai');

        $this->completeNewArticles();
        $drafts = ArticleDraft::where('category_launch_child_id', $oop->id)->orderBy('id')->get();
        $this->assertCount(2, $drafts);
        $this->assertSame($drafts[0]->id, $suggestions[0]->fresh()->article_draft_id);

        // ⑤ 子ロードマップ：記事の編集案を目印で指す（まだ公開していないため、タイトルだけになる）
        $this->post(route('launches.children.roadmap', ['id' => $oop->id]), $this->selected())->assertRedirect();
        $roadmapGeneration = AiGeneration::where('purpose', AiMode::NewArticle)->latest('id')->first();
        $this->assertStringContainsString("[[記事:下書き{$drafts[0]->id}]]", $roadmapGeneration->input);
        $this->assertStringContainsString('記事の種類：固定ページ', $roadmapGeneration->input);

        $this->completeNewArticles();
        $oop->refresh();
        $this->assertNotNull($oop->roadmap_draft_id);
        $this->assertSame('page', ArticleDraft::find($oop->roadmap_draft_id)->target_type->value);
        $this->get(route('launches.show', ['id' => $launch->id]))->assertOk()->assertSee('⑤ 子ロードマップの編集案まで完了');
    }
}
