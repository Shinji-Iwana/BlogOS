<?php

namespace Tests\Feature\Materials;

use App\Enums\MaterialKind;
use App\Enums\MaterialStatus;
use App\Models\ArticleMaterial;
use App\Models\Blog;
use App\Models\Category;
use App\Models\Material;
use App\Models\Post;
use App\Services\Materials\MaterialCoverageService;
use App\Services\Materials\MaterialMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 集客記事に必ず教材を1件紹介するための、カテゴリの基礎の教材の候補（D-91）。
 */
class MaterialMatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_basic_materials_are_always_offered_with_usage_counts(): void
    {
        config(['blogos.materials.max_candidates' => 3]);
        $blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true, 'quality_profile' => 'si-note']);
        $js = Category::create(['blog_id' => $blog->id, 'wordpress_id' => 30, 'name' => 'JavaScript', 'slug' => 'js']);
        $event = Category::create(['blog_id' => $blog->id, 'wordpress_id' => 31, 'name' => 'Event', 'slug' => 'js-event', 'parent_id' => $js->id]);
        $post = $this->article($blog, 10, 'addEventListener の使い方');
        $post->categories()->attach($event->id);

        // 記事の語句に合う教材（点数が高い）で、候補の上限（3件）が埋まる
        foreach ([1, 2, 3, 4] as $n) {
            $this->material($blog, MaterialKind::Udemy, "イベント講座{$n}", [$event->id], ['addEventListener'], ['hands_on']);
        }
        // 親カテゴリに登録した、基礎の教材（基礎から体系的に学びたい）
        $usedBook = $this->material($blog, MaterialKind::Book, 'よく使う入門書', [$js->id], [], ['systematic']);
        $freshBook = $this->material($blog, MaterialKind::Book, '新しい入門書', [$js->id], [], ['systematic']);
        $basicUdemy = $this->material($blog, MaterialKind::Udemy, '基礎講座', [$js->id], [], ['systematic']);
        // 基礎の教材でない、親カテゴリの教材
        $this->material($blog, MaterialKind::Book, '応用の本', [$js->id], [], ['practical']);

        // よく使う入門書は、ほかの記事2件で紹介している
        foreach ([20, 21] as $wordpressId) {
            ArticleMaterial::create(['blog_id' => $blog->id, 'post_id' => $this->article($blog, $wordpressId, "別の記事{$wordpressId}")->id, 'material_id' => $usedBook->id, 'source' => 'detected']);
        }

        $matcher = app(MaterialMatcher::class);
        $rows = $matcher->candidates($blog, $post);
        $names = array_map(fn ($row) => $row['material']->name, $rows);

        // 上位3件（語句に合う教材）のあとに、基礎の教材を2件（書籍を先に、紹介している記事の少ない順）加える
        $this->assertCount(5, $rows);
        $this->assertSame(['新しい入門書', 'よく使う入門書'], array_slice($names, 3));
        $this->assertNotContains($basicUdemy->name, $names);
        $this->assertNotContains('応用の本', $names);
        $this->assertSame([false, false, false, true, true], array_column($rows, 'basic'));
        $this->assertSame(2, $rows[4]['usage']);
        $this->assertSame($freshBook->id, $rows[3]['material']->id);

        $text = MaterialMatcher::describe($rows);
        $this->assertStringContainsString("「よく使う入門書」", $text);
        $this->assertStringContainsString('カテゴリの基礎の教材（記事に合う教材がないときに、この中から1件を紹介する。tech-content.md 5-6）', $text);
        $this->assertStringContainsString("  - 紹介している記事：2件", $text);

        $this->assertSame(['count' => 5, 'basic' => 2], $matcher->candidateCounts($blog, $post));

        // 教材の画面のそろい具合：Event の基礎の教材は、親カテゴリの3件
        $coverage = app(MaterialCoverageService::class)->coverage($blog->id, Material::with('categories')->get(), Category::all());
        $this->assertSame(3, $coverage[$event->id]['basic']);
    }

    public function test_basic_materials_are_not_added_when_already_in_the_top_candidates(): void
    {
        $blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true, 'quality_profile' => 'si-note']);
        $excel = Category::create(['blog_id' => $blog->id, 'wordpress_id' => 50, 'name' => 'Excel', 'slug' => 'excel']);
        $post = $this->article($blog, 10, 'VLOOKUP の使い方');
        $post->categories()->attach($excel->id);

        // 候補がない記事
        $this->assertSame(['count' => 0, 'basic' => 0], app(MaterialMatcher::class)->candidateCounts($blog, $post));

        $this->material($blog, MaterialKind::Book, 'Excel入門', [$excel->id], [], ['systematic']);
        $rows = app(MaterialMatcher::class)->candidates($blog, $post);
        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['basic']);
        $this->assertSame(0, $rows[0]['usage']);
    }

    protected function article(Blog $blog, int $wordpressId, string $title): Post
    {
        return Post::create([
            'blog_id' => $blog->id, 'wordpress_id' => $wordpressId, 'title_raw' => $title, 'status' => 'publish',
            'content_raw' => '<p>本文</p>', 'link' => "https://blog.example.test/{$wordpressId}.html", 'wordpress_modified_gmt' => '2026-09-01 00:00:00',
        ]);
    }

    /**
     * @param list<int> $categoryIds
     * @param list<string> $topics
     * @param list<string> $scenes
     */
    protected function material(Blog $blog, MaterialKind $kind, string $name, array $categoryIds, array $topics, array $scenes): Material
    {
        $material = Material::create([
            'blog_id' => $blog->id, 'kind' => $kind, 'status' => MaterialStatus::Active, 'name' => $name,
            'summary' => "{$name}で学べる内容", 'topics' => $topics, 'scenes' => $scenes,
        ]);
        $material->categories()->sync($categoryIds);

        return $material;
    }
}
