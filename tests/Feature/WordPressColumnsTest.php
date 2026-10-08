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

    public function test_every_history_column_has_a_description(): void
    {
        $models = [\App\Models\Post::class, \App\Models\Page::class, \App\Models\Media::class, \App\Models\Category::class, \App\Models\Tag::class, \App\Models\Author::class,
            \App\Models\CustomContent::class, \App\Models\CustomTerm::class, \App\Models\Status::class, \App\Models\Type::class, \App\Models\Taxonomy::class];
        $missing = [];
        foreach ($models as $model) {
            $historyTable = (new ($model::historyClass()))->getTable();
            foreach (Schema::getColumnListing($historyTable) as $column) {
                if (WordPressColumns::describeHistory($column, $model::historyForeignKey()) === null) {
                    $missing[] = "{$historyTable}.{$column}";
                }
            }
        }

        $this->assertSame([], $missing, '説明のない履歴の列があります。App\Support\WordPressColumns の HISTORY に足してください。');
    }

    public function test_detail_page_shows_column_descriptions(): void
    {
        $this->actingAs(\App\Models\User::factory()->create());
        $blog = \App\Models\Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true]);
        $category = \App\Models\Category::create(['blog_id' => $blog->id, 'wordpress_id' => 5, 'name' => 'JavaScript', 'slug' => 'javascript']);

        $child = \App\Models\Category::create(['blog_id' => $blog->id, 'wordpress_id' => 6, 'name' => 'DOM', 'slug' => 'dom', 'parent_id' => $category->id, 'wordpress_parent_id' => 5]);

        $this->get(route('database.wordpress-records.show', ['table' => 'categories', 'id' => $child->id]))->assertOk()
            ->assertSee('data-tip="カテゴリの名前。"', false)
            ->assertSee('data-tip="WordPress の中での ID（WordPress の管理画面や API で使う番号）。"', false)
            // 詳細・関連・変更履歴（D-72-09）
            ->assertSee('<h2 data-code="DETAIL">詳細</h2>', false)
            ->assertSee('<h2 data-code="RELATION">関連</h2>', false)
            ->assertSee('<h2 data-code="HISTORY">変更履歴</h2>', false)
            ->assertSee('Example Blog')
            ->assertSee('<a href="' . route('database.wordpress-records.show', ['table' => 'categories', 'id' => $category->id]) . '">JavaScript #' . $category->id . '</a>', false)
            ->assertSee('（新しい順、最大50件。');
    }
}
