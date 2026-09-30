<?php

namespace Tests\Feature\Materials;

use App\Enums\MaterialKind;
use App\Enums\MaterialStatus;
use App\Models\Blog;
use App\Models\Category;
use App\Models\Material;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 教材のカテゴリでの絞り込みと、カテゴリごとのそろい具合（D-45）。
 */
class MaterialCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_materials_can_be_filtered_by_category_including_parent_and_show_usability(): void
    {
        $blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true]);
        $this->actingAs(User::factory()->create());
        $js = Category::create(['blog_id' => $blog->id, 'wordpress_id' => 30, 'name' => 'JavaScript', 'slug' => 'js']);
        $event = Category::create(['blog_id' => $blog->id, 'wordpress_id' => 31, 'name' => 'Event', 'slug' => 'js-event', 'parent_id' => $js->id]);
        $aws = Category::create(['blog_id' => $blog->id, 'wordpress_id' => 40, 'name' => 'AWS', 'slug' => 'aws']);
        $post = Post::create(['blog_id' => $blog->id, 'wordpress_id' => 10, 'title_raw' => '記事', 'status' => 'publish', 'link' => 'https://blog.example.test/10.html', 'wordpress_modified_gmt' => '2026-09-01 00:00:00']);
        $post->categories()->attach($event->id);

        // 親カテゴリに登録した、調べてある書籍（Event の記事でも使える）
        $book = Material::create(['blog_id' => $blog->id, 'kind' => MaterialKind::Book, 'status' => MaterialStatus::Active, 'name' => 'JavaScript入門', 'summary' => '基本を学べる']);
        $book->categories()->sync([$js->id]);
        // Event に登録した、まだ調べていない Udemy（使えない）
        $udemy = Material::create(['blog_id' => $blog->id, 'kind' => MaterialKind::Udemy, 'status' => MaterialStatus::Active, 'name' => 'イベント講座']);
        $udemy->categories()->sync([$event->id]);
        // ほかのカテゴリの教材
        $other = Material::create(['blog_id' => $blog->id, 'kind' => MaterialKind::Book, 'status' => MaterialStatus::Active, 'name' => 'AWS入門', 'summary' => 'AWSの基本']);
        $other->categories()->sync([$aws->id]);

        $response = $this->get(route('materials.index', ['category' => $event->id]))->assertOk();
        $response->assertSee('JavaScript入門')->assertSee('イベント講座')->assertDontSee('AWS入門')
            ->assertSee('（親カテゴリに登録）')
            ->assertSee('使えない：情報を調べていない')
            ->assertSee('カテゴリ「Event」</strong>（公開中の記事 1件）', false)->assertSee('書籍 1件')
            ->assertSee(route('materials.discover.create', ['category' => $event->id]), false);

        // 種類とカテゴリの絞り込みは一緒に使える
        $this->get(route('materials.index', ['category' => $event->id, 'kind' => 'udemy']))->assertOk()->assertSee('イベント講座')->assertDontSee('JavaScript入門</a>', false);

        // そろい具合：Event は書籍が親から1件（+1）、Udemy は使える教材が0件（記事があるため赤字）、使えない教材が1件
        $coverage = app(\App\Services\Materials\MaterialCoverageService::class)->coverage($blog->id, Material::with('categories')->get(), Category::all());
        $this->assertSame(['posts' => 1, 'book' => 0, 'inherited_book' => 1, 'udemy' => 0, 'unusable' => 1], [
            'posts' => $coverage[$event->id]['posts'], 'book' => $coverage[$event->id]['own']['book'], 'inherited_book' => $coverage[$event->id]['inherited']['book'],
            'udemy' => $coverage[$event->id]['own']['udemy'], 'unusable' => $coverage[$event->id]['unusable'],
        ]);
        $this->assertSame(1, $coverage[$js->id]['own']['book']);
        $this->assertSame(0, $coverage[$aws->id]['posts']);
    }
}
