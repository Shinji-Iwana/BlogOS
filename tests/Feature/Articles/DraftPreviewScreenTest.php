<?php

namespace Tests\Feature\Articles;

use App\Enums\ChangeSource;
use App\Enums\PushResourceType;
use App\Models\Blog;
use App\Models\BlogCredential;
use App\Models\Post;
use App\Models\User;
use App\Repositories\ArticleDraftRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 編集案のプレビュー（変更前と編集案を、実際のサイトの表示で比べる。D-28）。
 */
class DraftPreviewScreenTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Blog $blog;

    protected Post $post;

    /** @var array<int, array<string, mixed>> WordPress に送ったプレビューの内容 */
    protected array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->user = User::factory()->create();
        $this->blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true]);
        BlogCredential::create(['blog_id' => $this->blog->id, 'username' => 'admin', 'secret' => 'very-secret-password', 'connector_extension' => true]);
        $this->post = Post::create([
            'blog_id' => $this->blog->id, 'wordpress_id' => 100, 'title_raw' => 'PHP入門', 'status' => 'publish',
            'content_raw' => '<p>元の本文</p>', 'link' => 'https://blog.example.test/php/100.html',
        ]);

        $this->actingAs($this->user);
    }

    protected function fakeWordPress(int $previewStatus = 200): void
    {
        Http::fake(function (Request $request) use ($previewStatus) {
            if (str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/wp-json/blogos/v1/preview')) {
                $this->sent[] = $request->data();

                return $previewStatus === 404
                    ? Http::response(['code' => 'rest_no_route', 'message' => 'No route'], 404)
                    : Http::response(['url' => 'https://blog.example.test/?p=100&blogos_preview=token' . count($this->sent), 'shell_post_id' => 100]);
            }

            // プレビューのページ（WordPress が表示したもの）
            $agent = $request->header('User-Agent')[0] ?? '';

            return Http::response('<!DOCTYPE html><html><head><title>t</title>'
                . '<script async src="https://analytics.example/tag.js"></script>'
                . '<script>window.adQueue = [];</script>'
                . '<link rel="stylesheet" href="/wp-content/themes/Theme-SI-Note/style.css">'
                . '</head><body><a href="/x.html" onclick="track()">x</a><p>' . (str_contains($agent, 'iPhone') ? 'スマホ表示' : 'PC表示') . '</p>'
                . '<noscript><img src="https://pixel.example/p.gif"></noscript></body></html>');
        });
    }

    protected function draft(): int
    {
        return app(ArticleDraftRepository::class)->create([
            'blog_id' => $this->blog->id, 'post_id' => $this->post->id, 'target_type' => PushResourceType::Post,
            'title_raw' => 'PHP入門【改訂】', 'content_raw' => '<p>新しい本文</p>', 'excerpt_raw' => '要約',
        ], ChangeSource::BlogosManual, $this->user->id)->id;
    }

    public function test_before_and_after_are_rendered_without_scripts(): void
    {
        $this->fakeWordPress();
        $id = $this->draft();

        $this->get(route('drafts.edit', ['id' => $id]))->assertOk()->assertSee('プレビュー（変更前と比較）');
        $this->get(route('drafts.preview', ['id' => $id]))->assertOk()->assertSee('変更前（WordPressの今の記事）')->assertSee('side=after', false);

        // 変更前：記事のIDだけを送る（差し替えない）
        $before = $this->get(route('drafts.preview.frame', ['id' => $id, 'side' => 'before']));
        $before->assertOk()
            ->assertHeader('Content-Security-Policy', "script-src 'none'; object-src 'none'; frame-ancestors 'self'")
            ->assertSee('PC表示');
        $this->assertSame(['post_id' => 100, 'post_type' => 'post'], $this->sent[0]);

        // スクリプト・noscript・イベント属性を取り除き、相対URLはブログを基準にする
        $html = $before->getContent();
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('analytics.example', $html);
        $this->assertStringNotContainsString('adQueue', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('pixel.example', $html);
        $this->assertStringContainsString('<base href="https://blog.example.test/" target="_blank">', $html);
        $this->assertStringContainsString('/wp-content/themes/Theme-SI-Note/style.css', $html);

        // 編集案：タイトル・本文・抜粋を送る。スマホ表示は、スマホのブラウザとして取得する
        $this->get(route('drafts.preview.frame', ['id' => $id, 'side' => 'after', 'device' => 'mobile']))->assertOk()->assertSee('スマホ表示');
        $this->assertSame(['post_id' => 100, 'post_type' => 'post', 'title' => 'PHP入門【改訂】', 'content' => '<p>新しい本文</p>', 'excerpt' => '要約'], $this->sent[1]);
    }

    public function test_messages_when_preview_is_not_possible(): void
    {
        $this->fakeWordPress(404);
        $id = $this->draft();

        // WordPress 側にプレビューの機能がない（テーマが古い）
        $this->get(route('drafts.preview.frame', ['id' => $id, 'side' => 'after']))->assertOk()->assertSee('テーマ（Theme-SI-Original）を更新してください');

        // 新規記事には変更前がない
        $new = app(ArticleDraftRepository::class)->create([
            'blog_id' => $this->blog->id, 'target_type' => PushResourceType::Post, 'title_raw' => '新しい記事', 'content_raw' => '<p>本文</p>',
        ], ChangeSource::BlogosManual, $this->user->id);
        $this->get(route('drafts.preview.frame', ['id' => $new->id, 'side' => 'before']))->assertOk()->assertSee('新規記事のため、変更前の記事はありません');
        $this->get(route('drafts.preview', ['id' => $new->id]))->assertOk()->assertSee('最新の公開済みの記事を土台にして');

        // WordPress 側の拡張が無効
        BlogCredential::query()->update(['connector_extension' => false]);
        $this->get(route('drafts.preview.frame', ['id' => $id, 'side' => 'after']))->assertOk()->assertSee('拡張（テーマの BlogOS 連携）が無効');

        // 入力の誤り・別のブログの編集案
        $this->get(route('drafts.preview.frame', ['id' => $id, 'side' => 'other']))->assertSessionHasErrors('side');
        $this->get(route('drafts.preview.frame', ['id' => 9999, 'side' => 'after']))->assertNotFound();
    }

    public function test_preview_url_must_be_on_the_blog(): void
    {
        Http::fake(['blog.example.test/wp-json/blogos/v1/preview' => Http::response(['url' => 'https://other-site.example/page'])]);
        $id = $this->draft();

        $this->get(route('drafts.preview.frame', ['id' => $id, 'side' => 'after']))->assertOk()->assertSee('ブログのURLではありません');
        Http::assertSentCount(1);
    }
}
