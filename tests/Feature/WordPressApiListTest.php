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
}
