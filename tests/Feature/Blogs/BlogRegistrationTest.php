<?php

namespace Tests\Feature\Blogs;

use App\Enums\ChangeSource;
use App\Models\Blog;
use App\Models\BlogCredential;
use App\Models\BlogHistory;
use App\Models\BlogSetting;
use App\Models\BlogSettingHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ブログの登録（BLOGOS_WORDPRESS_API.md 29章）と認証情報の保存（D-03-02）。
 */
class BlogRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    protected function fakeWordPress(int $usersMeStatus = 200, bool $rootAtWpJson = true): void
    {
        $routes = [];
        foreach (\App\Services\Blogs\WordPressSiteInspector::REQUIRED_ROUTES as $route) {
            $routes[$route] = [];
        }

        $root = [
            'name'            => 'Example Blog',
            'home'            => 'https://blog.example.test',
            'url'             => 'http://blog.example.test',
            'gmt_offset'      => 9,
            'timezone_string' => 'Asia/Tokyo',
            'namespaces'      => ['oembed/1.0', 'wp/v2'],
            'routes'          => $routes,
        ];

        Http::fake([
            'https://blog.example.test/wp-json/wp/v2/users/me*' => Http::response(
                $usersMeStatus === 200 ? ['id' => 1, 'name' => 'admin', 'roles' => ['administrator']] : ['code' => 'rest_not_logged_in'],
                $usersMeStatus
            ),
            'https://blog.example.test/wp-json/wp/v2/posts*' => Http::response([
                ['id' => 10, 'meta' => ['_blogos_draft_id' => '']],
            ]),
            'https://blog.example.test/wp-json/wp/v2/settings' => Http::response([
                'title'          => 'Example Blog',
                'description'    => 'IT blog',
                'url'            => 'http://blog.example.test',
                'email'          => 'admin@example.test',
                'posts_per_page' => 10,
            ]),
            'https://blog.example.test/wp-json/' => $rootAtWpJson ? Http::response($root) : Http::response('', 404),
            'https://blog.example.test' => Http::response(
                '<html><head><link rel="https://api.w.org/" href="https://blog.example.test/index.php?rest_route=/"></head></html>'
            ),
            'https://blog.example.test/index.php*' => Http::response($root),
        ]);
    }

    protected function register(array $overrides = [])
    {
        return $this->actingAs($this->user)->post(route('blogs.store'), array_merge([
            'url'                  => 'https://blog.example.test/',
            'username'             => 'admin',
            'application_password' => 'abcd efgh ijkl mnop',
            'quality_profile'      => 'si-note',
        ], $overrides));
    }

    public function test_blog_is_registered_with_settings_and_encrypted_credential(): void
    {
        $this->fakeWordPress();

        $response = $this->register();

        $blog = Blog::sole();
        $response->assertRedirect(route('database-blog-detail', ['id' => $blog->id]));

        $this->assertSame('https://blog.example.test', $blog->home);
        $this->assertSame('Example Blog', $blog->display_name);
        $this->assertSame('si-note', $blog->quality_profile);
        $this->assertTrue($blog->is_selected);

        // 認証情報は暗号化して保存し、配列化しても出さない
        $credential = BlogCredential::sole();
        $this->assertSame('abcd efgh ijkl mnop', $credential->secret);
        $raw = DB::table('blog_credentials')->value('secret');
        $this->assertStringNotContainsString('abcd', $raw);
        $this->assertArrayNotHasKey('secret', $credential->toArray());

        // サイト設定は決めたキーだけを保存する（email は保存しない）
        $settings = BlogSetting::pluck('value', 'key');
        $this->assertSame('Example Blog', $settings['title']);
        $this->assertSame('Asia/Tokyo', $settings['timezone_string']);
        $this->assertSame('https://blog.example.test', $settings['home']);
        $this->assertArrayNotHasKey('email', $settings->all());

        // 初回取得は __created の1行ずつ（D-13-04）
        $this->assertTrue(BlogSettingHistory::where('field', '__created')->where('source', ChangeSource::WpInitialSync)->exists());
        $this->assertSame(0, BlogSettingHistory::where('field', '!=', '__created')->count());
        $this->assertSame('__created', BlogHistory::sole()->field);
        $this->assertSame($this->user->id, BlogHistory::sole()->user_id);
    }

    public function test_api_root_is_discovered_from_html_link(): void
    {
        $this->fakeWordPress(rootAtWpJson: false);

        $this->register()->assertSessionHasNoErrors();

        $this->assertSame(1, Blog::count());
    }

    public function test_same_blog_with_different_scheme_is_rejected(): void
    {
        $this->fakeWordPress();
        Blog::create(['home' => 'http://BLOG.example.test/', 'display_name' => 'existing']);

        $this->register()->assertSessionHasErrors('url');

        $this->assertSame(1, Blog::count());
    }

    public function test_wrong_credentials_are_rejected_and_nothing_is_saved(): void
    {
        $this->fakeWordPress(usersMeStatus: 401);

        $this->register()->assertSessionHasErrors('url');

        $this->assertSame(0, Blog::count());
        $this->assertSame(0, BlogCredential::count());
    }

    public function test_password_is_not_kept_in_old_input(): void
    {
        $this->fakeWordPress(usersMeStatus: 401);

        $this->register();

        $this->assertNull(session()->getOldInput('application_password'));
    }
}
