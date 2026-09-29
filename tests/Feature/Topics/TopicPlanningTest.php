<?php

namespace Tests\Feature\Topics;

use App\Enums\AiGenerationStatus;
use App\Enums\KeywordType;
use App\Enums\SuggestionStatus;
use App\Models\AiGeneration;
use App\Models\ArticleKeyword;
use App\Models\Blog;
use App\Models\Category;
use App\Models\Page;
use App\Models\Post;
use App\Models\TopicSuggestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 記事の企画（D-40）。
 */
class TopicPlanningTest extends TestCase
{
    use RefreshDatabase;

    protected Blog $blog;

    protected Category $js;

    protected Category $basic;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.openai.key' => null]);
        $this->blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true, 'quality_profile' => 'si-note']);
        $this->js = Category::create(['blog_id' => $this->blog->id, 'wordpress_id' => 10, 'name' => 'JavaScript', 'slug' => 'js']);
        $this->basic = Category::create(['blog_id' => $this->blog->id, 'wordpress_id' => 11, 'name' => '基礎', 'slug' => 'js-basic', 'parent_id' => $this->js->id]);
        Category::create(['blog_id' => $this->blog->id, 'wordpress_id' => 12, 'name' => 'Event（イベント操作）', 'slug' => 'js-event', 'parent_id' => $this->js->id]);

        $post = Post::create(['blog_id' => $this->blog->id, 'wordpress_id' => 24, 'title_raw' => 'JavaScriptとは？【JavaScript入門】', 'status' => 'publish', 'link' => 'https://blog.example.test/js/js-basic/24.html', 'normalized_path' => '/js/js-basic/24.html']);
        $post->categories()->attach($this->basic->id);
        ArticleKeyword::create(['blog_id' => $this->blog->id, 'post_id' => $post->id, 'keyword' => 'JavaScript とは', 'keyword_type' => KeywordType::Main->value]);
        Page::create(['blog_id' => $this->blog->id, 'wordpress_id' => 2063, 'title_raw' => 'JavaScript 基礎ロードマップ', 'status' => 'publish', 'link' => 'https://blog.example.test/js/js-basic.html', 'normalized_path' => '/js/js-basic.html',
            'content_raw' => '<div class="roadmap-step"><h3>ステップ1：導入</h3></div><div class="roadmap-step"><h3>ステップ2：文法</h3></div>']);

        foreach ([['javascript 変数', 30, 25.0], ['javascript とは', 10, 5.0]] as [$query, $impressions, $position]) {
            DB::table('google_search_console_query_daily')->insert([
                'blog_id' => $this->blog->id, 'date' => now()->subDays(5)->toDateString(), 'page_url' => $post->link, 'page_url_hash' => sha1($post->link),
                'post_id' => $post->id, 'query' => $query, 'query_hash' => sha1($query), 'clicks' => 0, 'impressions' => $impressions, 'position' => $position,
            ]);
        }

        $this->actingAs(User::factory()->create());
    }

    public function test_article_planning_creates_suggestions_with_duplicate_notes(): void
    {
        $this->get(route('topics.index'))->assertOk()
            ->assertSee('Event（イベント操作）：0記事')   // 記事の少ないカテゴリ
            ->assertSee('javascript 変数');              // 記事が合っていない検索語句（25位）

        $this->post(route('topics.store'), ['selected_blog_id' => $this->blog->id, 'category_id' => $this->basic->id, 'unit' => 'articles', 'execution_method' => 'manual'])->assertRedirect();
        $generation = AiGeneration::sole();
        $this->assertStringContainsString('子カテゴリの、まだ記事にしていない内容', $generation->input);
        $this->assertStringContainsString('JavaScriptとは？【JavaScript入門】（メインキーワード：JavaScript とは）', $generation->input);
        $this->assertStringContainsString('ステップ2：文法', $generation->input);
        $this->assertStringContainsString('javascript 変数：30／0／25', $generation->input);
        $this->assertStringContainsString('- 基礎（js-basic）：1記事', $generation->input);

        $output = "```json\n" . json_encode(['summary' => '文法の抜けを埋める', 'categories' => [], 'articles' => [
            ['title' => '変数とは？値を入れる箱【JavaScript入門】', 'main_keyword' => 'JavaScript 変数', 'sub_keywords' => ['let'], 'search_intent' => '変数の意味を知りたい', 'article_type' => 'acquisition', 'article_subtype' => 'know', 'roadmap_step' => 'ステップ2：文法', 'priority' => 'high', 'reason' => '検索語句「javascript 変数」の順位が低い', 'sources' => ['https://developer.mozilla.org/ja/docs/Web/JavaScript']],
            ['title' => 'JavaScriptとは何か', 'main_keyword' => 'JavaScript とは', 'priority' => 'low', 'reason' => '重複'],
        ]], JSON_UNESCAPED_UNICODE) . "\n```";
        $this->post(route('ai.generations.submit', ['id' => $generation->id]), ['selected_blog_id' => $this->blog->id, 'output' => $output, 'model' => 'gpt-6-luna'])->assertSessionHasNoErrors();
        $this->assertSame(AiGenerationStatus::Succeeded, $generation->fresh()->status);

        $suggestions = TopicSuggestion::orderBy('id')->get();
        $this->assertCount(2, $suggestions);
        $this->assertSame($this->basic->id, $suggestions[0]->category_id);
        $this->assertNull($suggestions[0]->duplicate_note);
        $this->assertStringContainsString('メインキーワードが同じ記事があります', (string) $suggestions[1]->duplicate_note);

        $this->get(route('topics.index'))->assertOk()->assertSee('変数とは？値を入れる箱')->assertSee('重複の可能性');

        // 採用すると、新規記事の画面に値を入れて開ける
        $this->post(route('topics.review', ['id' => $suggestions[0]->id]), ['selected_blog_id' => $this->blog->id, 'action' => 'accept'])->assertRedirect();
        $this->assertSame(SuggestionStatus::Accepted, $suggestions[0]->fresh()->status);
        $this->get(route('ai.generations.create', \App\Support\TopicLinks::newArticle($suggestions[0]->fresh())))->assertOk()
            ->assertSee('value="JavaScript 変数"', false)
            ->assertSee('記事の企画の案：変数とは？値を入れる箱');
    }

    public function test_category_planning_creates_category_and_first_article_suggestions(): void
    {
        $this->post(route('topics.store'), ['selected_blog_id' => $this->blog->id, 'category_id' => $this->js->id, 'unit' => 'categories', 'execution_method' => 'manual'])->assertRedirect();
        $generation = AiGeneration::sole();
        $this->assertStringContainsString('親カテゴリの、足りない子カテゴリ', $generation->input);

        $output = json_encode(['summary' => 'ok', 'articles' => [], 'categories' => [
            ['name' => 'Array（配列操作）', 'slug' => 'js-array', 'scope' => '配列の操作', 'position' => '基礎の後', 'priority' => 'high', 'reason' => '配列は必須',
                'first_articles' => [['title' => '配列とは？【JavaScript入門】', 'main_keyword' => 'JavaScript 配列'], ['title' => 'mapの使い方【JavaScript入門】', 'main_keyword' => 'JavaScript map']]],
            ['name' => 'Event（イベント操作）', 'slug' => 'js-event', 'first_articles' => []],
        ]], JSON_UNESCAPED_UNICODE);
        $this->post(route('ai.generations.submit', ['id' => $generation->id]), ['selected_blog_id' => $this->blog->id, 'output' => $output, 'model' => 'gpt-6-luna'])->assertSessionHasNoErrors();

        $category = TopicSuggestion::where('type', 'category')->where('slug', 'js-array')->sole();
        $this->assertSame(2, $category->articles()->count());
        $this->assertSame('同じ名前・スラッグのカテゴリが既にあります。', TopicSuggestion::where('slug', 'js-event')->sole()->duplicate_note);

        // 子カテゴリの案を見送ると、その記事の案も見送る
        $this->post(route('topics.review', ['id' => $category->id]), ['selected_blog_id' => $this->blog->id, 'action' => 'reject'])->assertRedirect();
        $this->assertSame(0, $category->articles()->where('status', SuggestionStatus::Pending->value)->count());

        // 企画し直すと、同じカテゴリ・同じ種類の確認待ちの案は置き換える
        $this->post(route('topics.store'), ['selected_blog_id' => $this->blog->id, 'category_id' => $this->js->id, 'unit' => 'categories', 'execution_method' => 'manual']);
        $second = AiGeneration::latest('id')->first();
        $this->post(route('ai.generations.submit', ['id' => $second->id]), ['selected_blog_id' => $this->blog->id, 'output' => $output, 'model' => 'gpt-6-luna']);
        $this->assertSame(SuggestionStatus::Superseded, TopicSuggestion::where('slug', 'js-event')->oldest('id')->first()->status);
    }
}
