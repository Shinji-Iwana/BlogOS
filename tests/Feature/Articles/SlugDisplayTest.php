<?php

namespace Tests\Feature\Articles;

use App\Models\Blog;
use App\Models\Post;
use App\Models\User;
use App\Services\Articles\DraftService;
use App\Services\Push\ArticlePushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 日本語のスラッグ（WordPress が符号化した形）の表示と、反映の比較（D-29）。
 */
class SlugDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_japanese_slug_is_readable_and_not_treated_as_a_change(): void
    {
        $user = User::factory()->create();
        $blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true]);
        $post = Post::create([
            'blog_id' => $blog->id, 'wordpress_id' => 100, 'title_raw' => 'データベースとは', 'status' => 'publish',
            'slug' => '%e3%83%87%e3%83%bc%e3%82%bf%e3%83%99%e3%83%bc%e3%82%b9', 'content_raw' => '<p>本文</p>',
            'link' => 'https://blog.example.test/db/100.html',
        ]);
        $this->actingAs($user);

        $this->get(route('articles.show', ['type' => 'posts', 'id' => $post->id]))->assertOk()->assertSee('データベース');

        // 編集案には読める形で写す。変えていなければ、反映で送らない
        $draft = app(DraftService::class)->createFromArticle($post, null, $user->id);
        $this->assertSame('データベース', $draft->slug);
        $this->assertArrayNotHasKey('slug', app(ArticlePushService::class)->payload($draft->fresh()));
        $this->get(route('drafts.edit', ['id' => $draft->id]))->assertOk()->assertSee('value="データベース"', false);

        // 変えたら送る
        $draft->update(['slug' => 'database']);
        $this->assertSame('database', app(ArticlePushService::class)->payload($draft->fresh())['slug']);
    }
}
