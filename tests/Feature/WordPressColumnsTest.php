<?php

namespace Tests\Feature;

use App\Support\WordPressColumns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 取り込んだ WordPress のデータの詳細の画面：全ての列に、何を保持する列かの説明がある（D-72-08）
 */
class WordPressColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_column_has_a_description(): void
    {
        $missing = [];
        foreach (['posts', 'pages', 'media', 'categories', 'tags', 'authors', 'custom_contents', 'custom_terms', 'statuses', 'types', 'taxonomies'] as $table) {
            foreach (Schema::getColumnListing($table) as $column) {
                if (WordPressColumns::describe($table, $column) === null) {
                    $missing[] = "{$table}.{$column}";
                }
            }
        }

        $this->assertSame([], $missing, '説明のない列があります。App\Support\WordPressColumns に足してください。');
    }

    public function test_detail_page_shows_column_descriptions(): void
    {
        $this->actingAs(\App\Models\User::factory()->create());
        $blog = \App\Models\Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true]);
        $category = \App\Models\Category::create(['blog_id' => $blog->id, 'wordpress_id' => 5, 'name' => 'JavaScript', 'slug' => 'javascript']);

        $this->get(route('database.wordpress-records.show', ['table' => 'categories', 'id' => $category->id]))->assertOk()
            ->assertSee('data-tip="カテゴリの名前。"', false)
            ->assertSee('data-tip="WordPress の中での ID（WordPress の管理画面や API で使う番号）。"', false);
    }
}
