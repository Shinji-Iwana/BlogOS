<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * WordPress API情報の一覧の画面（D-72-13）：初めは ID の昇順、データの種類ごとの列
 */
class WordPressApiListTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_is_fetched_in_id_order_with_resource_columns(): void
    {
        $this->actingAs(User::factory()->create());
        Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true]);
        Http::fake([
            'https://blog.example.test/wp-json/wp/v2/media*' => Http::response([
                ['id' => 3, 'slug' => 'image', 'status' => 'inherit', 'title' => ['rendered' => '画像'], 'mime_type' => 'image/png'],
            ], 200, ['X-WP-Total' => '1', 'X-WP-TotalPages' => '1']),
        ]);

        $html = $this->get(route('wp-api.resources.index', ['resource' => 'media']))->assertOk()
            ->assertSee('image/png')
            ->assertSee('<select name="orderby" id="api-orderby">', false)
            ->assertSee('条件に合う全ての件数')
            ->getContent();
        // 列：ID・status・名前・タイトル・mime_type
        $this->assertMatchesRegularExpression('/<thead>\s*<tr>\s*<th[^>]*>ID<\/th>\s*<th[^>]*>status<\/th>\s*<th[^>]*>名前・タイトル<\/th>\s*<th[^>]*>mime_type<\/th>/u', $html);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'orderby=id') && str_contains($request->url(), 'order=asc'));
    }

    /**
     * サイト内検索は、WordPress API情報の一覧から開く API確認画面の1つ（D-115-01）
     */
    public function test_site_search_is_an_api_inspection_screen(): void
    {
        $this->actingAs(User::factory()->create());
        Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true]);
        Http::fake([
            'https://blog.example.test/wp-json/wp/v2/search*' => Http::response([
                ['id' => 12, 'title' => 'CORSとは', 'url' => 'https://blog.example.test/cors.html', 'type' => 'post', 'subtype' => 'post'],
                ['id' => 7, 'title' => 'JavaScript', 'url' => 'https://blog.example.test/category/js', 'type' => 'term', 'subtype' => 'category'],
                ['id' => 3, 'title' => '動画', 'url' => 'https://blog.example.test/type/video', 'type' => 'post-format', 'subtype' => 'video'],
            ], 200, ['X-WP-Total' => '3', 'X-WP-TotalPages' => '1']),
        ]);

        // WordPress API情報の一覧の最後に、ほかと同じ書き方で出す。トップの管理パネルには出さない
        $this->get(route('wp-api.home'))->assertOk()
            ->assertSeeInOrder(['タクソノミー', '<a href="' . route('wp-api.resources.index', 'search') . '">サイト内検索</a>', '（/wp-json/wp/v2/search）'], false);
        $this->get(route('home'))->assertOk()->assertDontSee(route('wp-api.resources.index', 'search'), false);
        $this->assertNotContains('サイト内検索', array_column(array_merge(...array_column(\App\Support\DashboardLinks::GROUPS, 'links')), 'label'));

        $html = $this->get(route('wp-api.resources.index', ['resource' => 'search', 'search' => 'CORS', 'type' => 'post']))->assertOk()
            ->assertSee('<h1>WordPress API情報：サイト内検索</h1>', false)
            ->assertSee('WordPress API情報に戻る')
            ->assertSee('<select name="type" id="api-type">', false)
            ->assertSee('<input type="text" name="subtype" id="api-subtype"', false)
            ->assertDontSee('<option value="edit"', false)
            ->assertDontSee('id="api-status"', false)
            ->assertSee('https://blog.example.test/wp-json/wp/v2/search')
            // ID は、結果の種類の詳細の画面へのリンク（詳細の画面がない種類はリンクにしない）。url は別のタブで開く
            ->assertSee('<a href="' . route('wp-api.resources.show', ['posts', 12]) . '">12</a>', false)
            ->assertSee('<a href="' . route('wp-api.resources.show', ['categories', 7]) . '">7</a>', false)
            ->assertDontSee(route('wp-api.resources.show', ['search', 3]), false)
            ->assertSee('<a href="https://blog.example.test/cors.html" target="_blank" rel="noopener">', false)
            ->getContent();
        // 列：ID・type・subtype・名前・タイトル・url
        $this->assertMatchesRegularExpression('/<thead>\s*<tr>\s*<th[^>]*>ID<\/th>\s*<th[^>]*>type<\/th>\s*<th[^>]*>subtype<\/th>\s*<th[^>]*>名前・タイトル<\/th>\s*<th[^>]*>url<\/th>/u', $html);

        // ほかと同じく、指定したページを1回だけ取得する（初めは20件）
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/wp/v2/search?') && str_contains($request->url(), 'search=CORS') && str_contains($request->url(), 'type=post') && str_contains($request->url(), 'per_page=20'));

        // 詳細の画面はない。前の URL もない
        $this->get(route('wp-api.resources.show', ['search', 12]))->assertNotFound();
        $this->get('/api/site-search')->assertNotFound();
    }
}
