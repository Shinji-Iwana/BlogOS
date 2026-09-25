<?php

namespace Tests\Feature\Blogs;

use App\Enums\ChangeSource;
use App\Models\Blog;
use App\Models\BlogCredential;
use App\Models\BlogSetting;
use App\Models\BlogSettingHistory;
use App\Models\User;
use App\Services\Blogs\BlogSettingsSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 認証情報の更新・接続確認（D-03-02、D-03-03）、選択中ブログの照合（D-02-05）、サイト設定の同期。
 */
class BlogCredentialAndSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Blog $blog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->blog = Blog::create([
            'home'         => 'https://blog.example.test',
            'display_name' => 'Example Blog',
            'is_selected'  => true,
        ]);
    }

    public function test_credential_is_updated_after_successful_check(): void
    {
        Http::fake(['https://blog.example.test/wp-json/wp/v2/users/me*' => Http::response(['name' => 'admin'])]);

        $this->actingAs($this->user)
            ->put(route('blogs.credentials.update'), [
                'selected_blog_id'     => $this->blog->id,
                'username'             => 'admin',
                'application_password' => 'new secret',
            ])
            ->assertRedirect(route('blogs.credentials.edit'));

        $this->assertSame('new secret', BlogCredential::sole()->secret);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization'));
    }

    public function test_edit_page_does_not_show_the_secret(): void
    {
        BlogCredential::create([
            'blog_id' => $this->blog->id, 'username' => 'admin', 'secret' => 'visible-secret-value',
        ]);

        $this->actingAs($this->user)
            ->get(route('blogs.credentials.edit'))
            ->assertOk()
            ->assertDontSee('visible-secret-value');
    }

    public function test_update_is_rejected_when_selected_blog_changed(): void
    {
        $other = Blog::create(['home' => 'https://other.example.test', 'display_name' => 'Other']);

        $this->actingAs($this->user)
            ->put(route('blogs.credentials.update'), [
                'selected_blog_id'     => $other->id,
                'username'             => 'admin',
                'application_password' => 'x',
            ])
            ->assertRedirect(route('home'))
            ->assertSessionHasErrors('selected_blog_id');

        $this->assertSame(0, BlogCredential::count());
    }

    public function test_failed_verification_is_recorded_without_secret(): void
    {
        BlogCredential::create([
            'blog_id' => $this->blog->id, 'username' => 'admin', 'secret' => 'the-secret',
        ]);
        Http::fake(['https://blog.example.test/wp-json/wp/v2/users/me*' => Http::response([], 401)]);

        $this->actingAs($this->user)
            ->post(route('blogs.credentials.verify'), ['selected_blog_id' => $this->blog->id])
            ->assertSessionHasErrors('verify');

        $credential = BlogCredential::sole();
        $this->assertNotNull($credential->last_failed_at);
        $this->assertStringNotContainsString('the-secret', (string) $credential->last_error);
    }

    public function test_settings_sync_records_only_changes(): void
    {
        BlogSetting::create(['blog_id' => $this->blog->id, 'key' => 'title', 'value' => 'Old Title']);
        BlogSetting::create(['blog_id' => $this->blog->id, 'key' => 'description', 'value' => 'same']);

        Http::fake([
            'https://blog.example.test/wp-json/wp/v2/settings' => Http::response([
                'title' => 'New Title', 'description' => 'same',
            ]),
            'https://blog.example.test/wp-json/' => Http::response([
                'home' => 'https://blog.example.test', 'namespaces' => ['wp/v2'],
                'timezone_string' => 'Asia/Tokyo', 'gmt_offset' => 9,
            ]),
        ]);

        app(BlogSettingsSyncService::class)->sync($this->blog->fresh());

        $this->assertSame('New Title', BlogSetting::where('key', 'title')->value('value'));

        $changes = BlogSettingHistory::where('field', '!=', '__created')->get();
        $this->assertCount(1, $changes);
        $this->assertSame('title', $changes[0]->field);
        $this->assertSame('Old Title', $changes[0]->old_value);
        $this->assertSame(ChangeSource::WpSync, $changes[0]->source);

        // 2回目は差分がないため、履歴は増えない
        app(BlogSettingsSyncService::class)->sync($this->blog->fresh());
        $this->assertSame(1, BlogSettingHistory::where('field', '!=', '__created')->count());
    }

    public function test_archived_blog_is_not_synced(): void
    {
        $this->blog->update(['archived_at' => now()]);
        Http::fake();

        $this->artisan('blogs:sync', ['--now' => true])->assertSuccessful();

        Http::assertNothingSent();
    }
}
