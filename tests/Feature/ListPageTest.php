<?php

namespace Tests\Feature;

use App\Support\ListPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Paginator;
use Tests\TestCase;

/**
 * 表の一覧のページ送り（D-98-01）と、表示件数の行（D-98-02）
 */
class ListPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_items_are_split_into_pages_of_fifty_keeping_keys(): void
    {
        $items = array_combine(range(101, 220), range(1, 120));

        Paginator::currentPageResolver(fn () => 3);
        $page = ListPage::of($items);

        $this->assertSame(50, $page->perPage());
        $this->assertSame(120, $page->total());
        $this->assertSame(20, $page->count());
        // フォームの行の番号に使う画面があるため、キーは元のまま
        $this->assertSame(201, $page->getCollection()->keys()->first());
    }

    public function test_material_list_is_paged_by_fifty(): void
    {
        $blog = \App\Models\Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true]);
        foreach (range(1, 51) as $n) {
            \App\Models\Material::create(['blog_id' => $blog->id, 'kind' => \App\Enums\MaterialKind::Book, 'status' => \App\Enums\MaterialStatus::Active, 'name' => sprintf('教本%02d', $n)]);
        }
        $this->actingAs(\App\Models\User::factory()->create());

        // 1ページ目は50件。件数は全件。2ページ目に残りの1件
        $this->get(route('materials.index'))->assertOk()
            ->assertSee('<h1>教材：51件</h1>', false)
            ->assertSee('表示件数：50件（種類・名前の順、最大50件。全51件をページに分けて表示）')
            ->assertSee('教本50')->assertDontSee('教本51');
        $this->get(route('materials.index', ['page' => 2]))->assertOk()
            ->assertSee('表示件数：1件（種類・名前の順、最大50件。全51件をページに分けて表示）')
            ->assertSee('教本51')->assertDontSee('教本50');
    }

    public function test_count_line_is_short_below_fifty_and_full_from_fifty(): void
    {
        Paginator::currentPageResolver(fn () => 1);

        $render = fn (int $total, ?string $order = null) => trim(view('partials.history-count', ['paginator' => ListPage::of($total > 0 ? range(1, $total) : [])] + ($order ? ['order' => $order] : []))->render());

        $this->assertSame('<p>表示件数：49件（新しい順）</p>', $render(49));
        $this->assertSame('<p>表示件数：0件（取りこぼしの多い順）</p>', $render(0, '取りこぼしの多い順'));
        $this->assertSame('<p>表示件数：50件（新しい順、最大50件。全50件をページに分けて表示）</p>', $render(50));
        $this->assertSame('<p>表示件数：50件（新しい順、最大50件。全1,648件をページに分けて表示）</p>', $render(1648));
    }
}
