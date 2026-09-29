<?php

namespace Tests\Feature\Articles;

use App\Enums\KeywordType;
use App\Models\ArticleKeyword;
use App\Models\Blog;
use App\Models\Post;
use App\Models\User;
use App\Services\Articles\ArticleTitleChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * タイトル・メタディスクリプションの確認と、改善の候補の一覧（D-36）。
 */
class ArticleTitleTest extends TestCase
{
    use RefreshDatabase;

    protected Blog $blog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true, 'quality_profile' => 'si-note']);
        $this->actingAs(User::factory()->create());
    }

    protected function createPost(int $wordpressId, string $title, ?string $meta): Post
    {
        return Post::create([
            'blog_id' => $this->blog->id, 'wordpress_id' => $wordpressId, 'title_raw' => $title, 'status' => 'publish', 'content_raw' => '<p>本文</p>',
            'meta_description_raw' => $meta, 'link' => "https://blog.example.test/js/{$wordpressId}.html", 'normalized_path' => "/js/{$wordpressId}.html",
        ]);
    }

    public function test_checker_reports_rule_violations(): void
    {
        $checker = app(ArticleTitleChecker::class);
        $goodMeta = str_repeat('あ', 30) . 'onsubmit で送信前に入力をチェックする方法を、JavaScript を初めて学ぶ人向けに、例を使って説明します。' . str_repeat('い', 20);

        // ルールどおり：先頭にキーワード、【JavaScript入門】は最後（JavaScript は最後の【…】にあればよい）
        $this->assertSame([], $checker->check($this->blog, 'onsubmitの使い方｜送信前に入力をチェック【JavaScript入門】', $goodMeta, 'JavaScript onsubmit'));

        $issues = implode("\n", $checker->check($this->blog, '【JavaScript入門】iframeのcontentWindow・contentDocumentの使い方｜内部ページの操作方法を初心者向けに解説', null, 'iframe contentDocument'));
        $this->assertStringContainsString('タイトルが長すぎます', $issues);
        $this->assertStringContainsString('タイトルの先頭に【…】があります', $issues);
        $this->assertStringContainsString('メインキーワード（contentDocument）が、タイトルの先頭28文字の中にありません', $issues);
        $this->assertStringContainsString('メタディスクリプションが未設定です', $issues);

        $this->assertStringContainsString('メタディスクリプションが20文字です', implode("\n", $checker->check($this->blog, 'DOMとは？【JavaScript入門】', str_repeat('説', 20), null)));

        // ほかの記事との重複（自分自身は除く）
        $post = $this->createPost(10, 'DOMとは？【JavaScript入門】', $goodMeta);
        $this->assertSame([], app(ArticleTitleChecker::class)->check($this->blog, $post->title_raw, $goodMeta, null, $post));
        $this->assertStringContainsString('ほかの記事と同じタイトルです', implode("\n", app(ArticleTitleChecker::class)->check($this->blog, 'DOMとは？【JavaScript入門】', $goodMeta, null)));
    }

    public function test_improvement_list_orders_by_missed_clicks(): void
    {
        $post = $this->createPost(24, '【JavaScript入門】JavaScriptとは？初心者向けに特徴・できること・基本の仕組みを解説', null);
        ArticleKeyword::create(['blog_id' => $this->blog->id, 'post_id' => $post->id, 'keyword' => 'JavaScript とは', 'keyword_type' => KeywordType::Main->value]);
        $this->createPost(25, 'DOMとは？ページを操作する仕組み【JavaScript入門】', null);

        $this->get(route('articles.titles'))->assertOk()
            ->assertSee('ルールに合わない点がある記事は 2件')
            ->assertSee('タイトルの先頭に【…】があります')
            ->assertSee('JavaScript とは');
    }
}
