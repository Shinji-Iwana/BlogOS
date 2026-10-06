<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\BlogCredential;
use App\Models\User;
use App\Models\WordPressComponent;
use App\Services\WordPress\WordPressUpdateCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * WordPress 本体・プラグイン・テーマの更新の確認（D-38）。
 */
class WordPressUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_updates_and_closed_plugins_are_detected(): void
    {
        $blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true, 'quality_profile' => 'si-note']);
        BlogCredential::create(['blog_id' => $blog->id, 'username' => 'admin', 'secret' => 'secret']);
        $this->actingAs(User::factory()->create());

        $plugins = [
            ['plugin' => 'akismet/akismet', 'name' => 'Akismet', 'status' => 'active', 'version' => '5.0'],
            ['plugin' => 'html-on-pages/html-on-pages', 'name' => '.html on PAGES', 'status' => 'active', 'version' => '1.1'],
            ['plugin' => 'my-plugin/my-plugin', 'name' => '自作', 'status' => 'inactive', 'version' => '1.0'],
        ];
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (&$plugins) {
            $url = $request->url();
            $slug = $request->data()['request']['slug'] ?? null;

            return match (true) {
                str_starts_with($url, 'https://blog.example.test/wp-json/wp/v2/plugins') => Http::response($plugins),
                str_starts_with($url, 'https://blog.example.test/wp-json/wp/v2/themes')  => Http::response([['stylesheet' => 'Theme-SI-Note', 'name' => ['raw' => 'Theme-SI-Note'], 'status' => 'active', 'version' => '1.0.0']]),
                str_starts_with($url, 'https://blog.example.test/feed/')                 => Http::response('<rss><channel><generator>https://wordpress.org/?v=7.1.1</generator></channel></rss>'),
                str_starts_with($url, 'https://api.wordpress.org/core/')                 => Http::response(['offers' => [['current' => '7.1.2', 'php_version' => '7.4']]]),
                str_starts_with($url, 'https://api.wordpress.org/plugins/') && str_contains($url, 'akismet')       => Http::response(['version' => '5.7.2', 'requires_php' => '7.2', 'requires' => '6.0']),
                str_starts_with($url, 'https://api.wordpress.org/plugins/') && str_contains($url, 'html-on-pages') => Http::response(['error' => 'closed', 'closed' => true, 'closed_date' => '2023-07-24', 'reason_text' => 'Guideline Violation'], 404),
                str_starts_with($url, 'https://api.wordpress.org/')                     => Http::response(['error' => 'Plugin not found.'], 404),
                default                                                                  => Http::response(['unexpected' => $url, 'slug' => $slug], 500),
            };
        });

        // 確認は、定期実行（メニューの「設定 → 即時実行」も同じ）で行う。画面の「今すぐ確認する」はなくした（D-63-10）
        $result = app(WordPressUpdateCheckService::class)->check($blog);
        $this->assertSame([2, 1], [$result['updates'], $result['closed']]);

        $rows = WordPressComponent::all()->keyBy('slug');
        $this->assertTrue($rows['akismet/akismet']->update_available);
        $this->assertSame('5.7.2', $rows['akismet/akismet']->latest_version);
        $this->assertSame('closed', $rows['html-on-pages/html-on-pages']->wporg_state);
        $this->assertStringContainsString('2023-07-24', $rows['html-on-pages/html-on-pages']->wporg_note);
        $this->assertSame('not_found', $rows['my-plugin/my-plugin']->wporg_state);
        $this->assertTrue($rows['wordpress']->update_available);
        $this->assertSame('7.1.1', $rows['wordpress']->installed_version);

        $this->get(route('wordpress-updates.index'))->assertOk()
            ->assertDontSee('今すぐ確認する')
            ->assertSee('WordPress.org で公開停止になっています')
            ->assertSee('無効のまま残っています');
        $this->get(route('home'))->assertOk()->assertSee('更新が2件あります')->assertSee('公開停止になったプラグインが1件あります')
            ->assertSee('<a href="' . route('wordpress-updates.index') . '">WordPress情報</a>', false);

        // 削除したプラグインは、次の確認で消える
        array_pop($plugins);
        app(WordPressUpdateCheckService::class)->check($blog);
        $this->assertFalse(WordPressComponent::where('slug', 'my-plugin/my-plugin')->exists());
    }
}
