<?php

namespace Tests\Feature\Articles;

use App\Enums\AiMode;
use App\Enums\DraftState;
use App\Enums\MaterialKind;
use App\Enums\MaterialStatus;
use App\Models\ArticleDraft;
use App\Models\Blog;
use App\Models\Category;
use App\Models\Material;
use App\Models\Post;
use App\Models\RevenueArticleLink;
use App\Models\TopicSuggestion;
use App\Models\User;
use App\Services\Ai\PromptBuilder;
use App\Services\Articles\LinkSwitchService;
use App\Services\Articles\RevenueLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 収益記事に教材を渡し、集客記事とつなぐ（D-93）。
 */
class RevenueLinkTest extends TestCase
{
    use RefreshDatabase;

    protected Blog $blog;

    protected Category $js;

    protected Category $event;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.openai.key' => null]);
        $this->blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true, 'quality_profile' => 'si-note']);
        $this->js = Category::create(['blog_id' => $this->blog->id, 'wordpress_id' => 30, 'name' => 'JavaScript', 'slug' => 'js']);
        $this->event = Category::create(['blog_id' => $this->blog->id, 'wordpress_id' => 31, 'name' => 'Event', 'slug' => 'js-event', 'parent_id' => $this->js->id]);
        $this->actingAs(User::factory()->create());
    }

    protected function article(int $wordpressId, string $title, string $type, Category $category, string $content = '<p>本文</p>', string $status = 'publish'): Post
    {
        $post = Post::create([
            'blog_id' => $this->blog->id, 'wordpress_id' => $wordpressId, 'title_raw' => $title, 'status' => $status, 'content_raw' => $content,
            'link' => "https://blog.example.test/js/{$wordpressId}.html", 'normalized_path' => "/js/{$wordpressId}.html", 'wordpress_modified_gmt' => '2026-09-01 00:00:00',
        ]);
        $post->categories()->attach($category->id);
        DB::table('article_managements')->insert(['blog_id' => $this->blog->id, 'post_id' => $post->id, 'article_type' => $type, 'created_at' => now(), 'updated_at' => now()]);

        return $post;
    }

    protected function box(string $name): string
    {
        return "<h2>まとめ前</h2>\n<div class=\"book-box\">\n  <h2>おすすめの学習書籍</h2>\n  <h3>{$name}</h3>\n  <div class=\"book-links\">\n    <a href=\"https://example.test/a\" class=\"amazon-btn\">Amazonで見る</a>\n  </div>\n</div>\n<div class=\"faq-box\">\n  <p>質問</p>\n</div>";
    }

    public function test_link_is_added_to_acquisition_articles_when_revenue_article_is_published(): void
    {
        $withBox = $this->article(101, 'addEventListenerの使い方', 'acquisition', $this->event, $this->box('入門書A'));
        $noBox = $this->article(102, 'clickイベントとは', 'acquisition', $this->event);
        $withDraft = $this->article(103, 'onsubmitの使い方', 'acquisition', $this->event, $this->box('入門書B'));
        $otherCategory = $this->article(104, 'JavaScriptとは', 'acquisition', $this->js, $this->box('入門書C'));
        // 別のカテゴリだが、企画の案がリンクを置く記事に選んだ記事
        $chosen = $this->article(105, '変数とは', 'acquisition', $this->js, $this->box('入門書D'));
        $userDraft = ArticleDraft::create(['uuid' => (string) Str::uuid(), 'blog_id' => $this->blog->id, 'post_id' => $withDraft->id, 'target_type' => 'post', 'title_raw' => 'onsubmitの使い方', 'content_raw' => $this->box('入門書B（作業中）'), 'state' => DraftState::Editing]);

        // まだ公開していない収益記事には、何もしない
        $revenue = $this->article(200, 'JavaScriptイベントの入門書のおすすめ｜選び方', 'revenue', $this->event, '<p>比較</p>', 'draft');
        $revenueDraft = ArticleDraft::create(['uuid' => (string) Str::uuid(), 'blog_id' => $this->blog->id, 'post_id' => $revenue->id, 'target_type' => 'post', 'title_raw' => $revenue->title_raw, 'content_raw' => '<p>比較</p>', 'state' => DraftState::Pushed]);
        TopicSuggestion::create(['blog_id' => $this->blog->id, 'type' => 'article', 'title' => $revenue->title_raw, 'article_type' => 'revenue', 'status' => 'accepted', 'article_draft_id' => $revenueDraft->id, 'link_post_ids' => [$chosen->id]]);
        $this->assertSame(0, app(RevenueLinkService::class)->apply($this->blog));

        // 公開されたら（同期・反映の後のリンクの切り替えと同じ時）
        $revenue->update(['status' => 'publish']);
        app(LinkSwitchService::class)->createDrafts($this->blog);

        $sentence = '<p>ほかの教材と比べて選びたい人は、<a href="/js/200.html">JavaScriptイベントの入門書のおすすめ｜選び方</a>で比べています。</p>';
        // 作業中の編集案がない記事：この1文だけを足した編集案を作る。最後の教材の紹介の枠の最後に入れる
        $created = ArticleDraft::where('post_id', $withBox->id)->sole();
        $this->assertSame(RevenueLinkService::REASON, $created->auto_reason);
        $this->assertStringContainsString("  </div>\n  {$sentence}\n</div>\n<div class=\"faq-box\">", $created->content_raw);
        $this->assertContains('収益記事「JavaScriptイベントの入門書のおすすめ｜選び方」へのリンクを、教材の紹介の最後に足しました。', $created->finish_notes);
        // 作業中の編集案がある記事：その編集案に足す（新しい編集案は作らない）
        $this->assertSame(1, ArticleDraft::where('post_id', $withDraft->id)->count());
        $this->assertStringContainsString($sentence, $userDraft->fresh()->content_raw);
        $this->assertStringContainsString('入門書B（作業中）', $userDraft->fresh()->content_raw);
        $this->assertNull($userDraft->fresh()->auto_reason);
        // 教材の紹介の枠がない記事：入れずに記録する
        $this->assertSame(0, ArticleDraft::where('post_id', $noBox->id)->count());
        $this->assertSame(RevenueArticleLink::NO_BOX, RevenueArticleLink::where('post_id', $noBox->id)->value('status'));
        // 別のカテゴリの記事には入れない。企画の案が選んだ記事には入れる
        $this->assertSame(0, ArticleDraft::where('post_id', $otherCategory->id)->count());
        $this->assertSame(1, ArticleDraft::where('post_id', $chosen->id)->where('auto_reason', RevenueLinkService::REASON)->count());

        // もう一度処理しても、二重に入れない
        app(RevenueLinkService::class)->apply($this->blog);
        $this->assertSame(1, substr_count($userDraft->fresh()->content_raw, $sentence));
        $this->assertSame(1, ArticleDraft::where('post_id', $withBox->id)->count());

        // リンクの切り替えの画面：まとめて反映する一覧に出し、作業中の編集案に足した記事と、枠がない記事を知らせる
        $this->get(route('drafts.link-switch'))->assertOk()
            ->assertSee('addEventListenerの使い方')
            ->assertSee('収益記事へのリンク')
            ->assertSee('教材の紹介の枠がないため、入れていません')
            ->assertSee('作業中の編集案に足しました');
    }

    public function test_prompts_tell_same_category_revenue_articles(): void
    {
        $acquisition = $this->article(101, 'addEventListenerの使い方', 'acquisition', $this->event);
        $this->article(200, 'JavaScriptイベントの入門書のおすすめ', 'revenue', $this->event);
        // 親のカテゴリの収益記事も伝える。別のカテゴリ・未公開の収益記事は伝えない
        $this->article(201, 'JavaScript入門書のおすすめ', 'revenue', $this->js);
        $other = Category::create(['blog_id' => $this->blog->id, 'wordpress_id' => 40, 'name' => 'AWS', 'slug' => 'aws']);
        $this->article(202, 'AWSの問題集のおすすめ', 'revenue', $other);
        $this->article(203, '未公開の収益記事', 'revenue', $this->event, '<p>x</p>', 'draft');

        $prompt = app(PromptBuilder::class)->build(AiMode::Revision, $this->blog, $acquisition, null, [], null)['prompt'];
        $section = substr($prompt, strpos($prompt, '# 同じカテゴリの収益記事'));
        $this->assertStringContainsString('[[記事:200]] JavaScriptイベントの入門書のおすすめ', $section);
        $this->assertStringContainsString('[[記事:201]] JavaScript入門書のおすすめ', $section);
        $this->assertStringNotContainsString('AWSの問題集', $section);
        $this->assertStringNotContainsString('未公開の収益記事', $section);

        // 新規記事の作成：カテゴリから
        $prompt = app(PromptBuilder::class)->build(AiMode::NewArticle, $this->blog, null, null, ['メインキーワード' => 'click イベント', 'カテゴリの値' => (string) $this->event->id], null)['prompt'];
        $this->assertStringContainsString('[[記事:200]]', substr($prompt, strpos($prompt, '# 同じカテゴリの収益記事')));
    }

    public function test_revenue_article_compares_chosen_materials(): void
    {
        $materials = collect(['入門書A', '入門書B', '入門書C', '情報を調べていない本'])->map(fn ($name) => Material::create([
            'blog_id' => $this->blog->id, 'kind' => MaterialKind::Book, 'status' => MaterialStatus::Active, 'name' => $name,
            'topics' => ['JavaScript'], 'summary' => $name === '情報を調べていない本' ? null : "{$name}の説明",
        ]));
        $suggestion = TopicSuggestion::create([
            'blog_id' => $this->blog->id, 'type' => 'article', 'title' => 'JavaScript入門書のおすすめ', 'article_type' => 'revenue', 'status' => 'accepted',
            'category_id' => $this->event->id, 'main_keyword' => 'JavaScript 入門書 初心者', 'material_ids' => [$materials[0]->id, $materials[1]->id],
        ]);

        // 企画の案から開くと、案の教材を選んでおく（情報を調べていない教材は選べない）
        $this->get(route('ai.generations.create', \App\Support\TopicLinks::newArticle($suggestion)))->assertOk()
            ->assertSee('比べる教材（記事種類が「収益記事」のときだけ使います）')
            ->assertSee('value="' . $materials[0]->id . '" checked', false)
            ->assertSee('入門書C')
            ->assertDontSee('情報を調べていない本')
            ->assertSee('name="topic_suggestion_id" value="' . $suggestion->id . '"', false);

        // 実行：選んだ教材だけを、比べる教材として渡す
        $this->post(route('ai.generations.store'), [
            'mode' => 'new_article', 'article_type' => 'revenue', 'category_id' => $this->event->id, 'main_keyword' => 'JavaScript 入門書 初心者',
            'materials' => [$materials[0]->id, $materials[2]->id, $materials[3]->id], 'topic_suggestion_id' => $suggestion->id,
            'execution_method' => 'manual', 'selected_blog_id' => $this->blog->id,
        ])->assertRedirect();

        $generation = \App\Models\AiGeneration::latest('id')->first();
        $this->assertSame($materials[0]->id . ',' . $materials[2]->id, $generation->parameters['比べる教材の値']);
        $this->assertSame((string) $suggestion->id, $generation->parameters['記事の企画の値']);
        $prompt = (string) $generation->input;
        $candidates = substr($prompt, strpos($prompt, '# 紹介してよい教材の候補'));
        $this->assertStringContainsString('収益記事で比べる教材です。人が選びました', $candidates);
        $this->assertStringContainsString('入門書A', $candidates);
        $this->assertStringContainsString('入門書C', $candidates);
        $this->assertStringNotContainsString('入門書B', substr($candidates, 0, strpos($candidates, '# 同じカテゴリの収益記事')));
        // 隠す値は、人が提供した情報に出さない
        $this->assertStringNotContainsString('比べる教材の値', $prompt);
        $this->assertStringContainsString('- 比べる教材：入門書A、入門書C', $prompt);
    }
}
