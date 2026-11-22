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
            ->assertSee('<h1>教材一覧</h1>', false)
            ->assertSee('表示件数：50件（種類・名前の順、最大50件。全51件をページに分けて表示）')
            ->assertSee('教本50')->assertDontSee('教本51');
        $this->get(route('materials.index', ['page' => 2]))->assertOk()
            ->assertSee('表示件数：1件（種類・名前の順、最大50件。全51件をページに分けて表示）')
            ->assertSee('教本51')->assertDontSee('教本50');
    }

    public function test_image_list_is_paged_by_fifty(): void
    {
        $blog = \App\Models\Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true]);
        foreach (range(1, 51) as $n) {
            \App\Models\Image::create(['blog_id' => $blog->id, 'kind' => \App\Enums\ImageKind::Illustration, 'status' => \App\Enums\ImageStatus::Draft, 'title' => sprintf('図%02d', $n)]);
        }
        $this->actingAs(\App\Models\User::factory()->create());

        // 新しい順。1ページ目は50件（図51〜図02）、2ページ目に残りの1件（図01）
        $this->get(route('images.index'))->assertOk()
            ->assertSee('<h1>画像一覧</h1>', false)
            ->assertSee('表示件数：50件（新しい順、最大50件。全51件をページに分けて表示）')
            ->assertSee('図51')->assertDontSee('図01');
        $this->get(route('images.index', ['page' => 2]))->assertOk()
            ->assertSee('表示件数：1件（新しい順、最大50件。全51件をページに分けて表示）')
            ->assertSee('図01')->assertDontSee('図02');
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

    /**
     * 表のセルの日時は、日付で1行・時刻で1行の2行にし、折り返さない決まった幅で出す（D-111-01）。
     * 全画面の表（table.data）に効かせるため、画面ごとではなく、共通の JS・CSS で行う
     */
    public function test_date_time_cells_are_split_into_two_lines_with_fixed_width(): void
    {
        $js = file_get_contents(public_path('js/blogos.js'));
        // 中身が日時・日付・時刻だけのセルを見つけ、日付と時刻を分ける
        $this->assertStringContainsString('tables.forEach(dateTimes);', $js);
        $this->assertMatchesRegularExpression('/const DATE_TIME = .+;\s+const DATE_ONLY = .+;\s+const TIME_ONLY = .+;/', $js);
        $this->assertStringContainsString("line.className = parts.length === 2 ? (i === 0 ? 'dt-date' : 'dt-time') : 'dt-line';", $js);
        // 幅は、一番長い行の文字数で決める
        $this->assertStringContainsString("box.style.width = (Math.max(...parts.map((part) => part.length)) + 0.3) + 'ch';", $js);
        // 日時のある表は、長い文章のセルが細くなりすぎたら、カードの形にする
        $this->assertStringContainsString("if (!table.querySelector('.cell-dt')) {", $js);

        $css = file_get_contents(public_path('css/blogos.css'));
        $this->assertMatchesRegularExpression('/table\.data > \* > tr > \.cell-dt \{\s+white-space: nowrap;\s+overflow-wrap: normal;\s+\}/', $css);
        $this->assertMatchesRegularExpression('/\.dt \{[^}]*display: inline-block;[^}]*white-space: nowrap;[^}]*font-variant-numeric: tabular-nums;[^}]*\}/', $css);
        $this->assertMatchesRegularExpression('/\.dt > span \{\s+display: block;\s+\}/', $css);
    }
}
