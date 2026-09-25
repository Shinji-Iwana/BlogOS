<?php

namespace Tests\Feature\Push;

use App\Enums\ChangeSource;
use App\Enums\PushState;
use App\Enums\SyncIssueType;
use App\Enums\SyncTrigger;
use App\Models\Blog;
use App\Models\BlogCredential;
use App\Models\BlogSetting;
use App\Models\Category;
use App\Models\Histories\CategoryHistory;
use App\Models\Histories\PostHistory;
use App\Models\Media;
use App\Models\Post;
use App\Models\SyncIssue;
use App\Models\User;
use App\Services\Push\PushException;
use App\Services\Push\TermPushService;
use App\Services\Sync\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeWordPress;
use Tests\TestCase;

/**
 * カテゴリ・タグ・メディアの反映（WORDPRESS_API 21-3・22章、D-15-05）。
 */
class TermPushServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Blog $blog;

    protected User $user;

    protected FakeWordPress $wp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true]);
        BlogCredential::create(['blog_id' => $this->blog->id, 'username' => 'admin', 'secret' => 'secret']);

        $this->wp = new FakeWordPress();
        $this->wp->settings['default_category'] = 1;
        $this->wp->lists['users'] = [FakeWordPress::user(1)];
        $this->wp->lists['categories'] = [FakeWordPress::term(1, ['name' => '未分類']), FakeWordPress::term(10), FakeWordPress::term(11)];
        $this->wp->lists['media'] = [FakeWordPress::media(30)];
        $this->wp->lists['posts'] = [FakeWordPress::post(100, ['categories' => [10, 11]])];
        $this->wp->install();

        app(SyncService::class)->run($this->blog, SyncTrigger::Initial);
    }

    protected function service(): TermPushService
    {
        return app(TermPushService::class);
    }

    public function test_category_update_is_pushed_and_recorded(): void
    {
        $category = Category::where('wordpress_id', 10)->sole();
        $base = $this->service()->currentValues($category);

        $operation = $this->service()->update($category, ['name' => '新しい名前', 'slug' => $base['slug'], 'description' => '', 'parent' => 11], $base, $this->user->id);

        $this->assertSame(PushState::Completed, $operation->state);
        $this->assertSame(['name' => '新しい名前', 'parent' => 11], $operation->request_summary);
        $category->refresh();
        $this->assertSame('新しい名前', $category->name);
        $this->assertSame(Category::where('wordpress_id', 11)->value('id'), $category->parent_id);

        $history = CategoryHistory::where('category_id', $category->id)->where('field', 'name')->sole();
        $this->assertSame(ChangeSource::BlogosPush, $history->source);
        $this->assertSame($operation->id, $history->wordpress_push_operation_id);
    }

    public function test_conflict_is_detected_by_values(): void
    {
        $category = Category::where('wordpress_id', 10)->sole();
        $base = $this->service()->currentValues($category);

        // 画面を開いた後に、WordPress側で名前が変更された
        $this->wp->lists['categories'][1]['name'] = 'WordPressで変更';

        $operation = $this->service()->update($category, ['name' => 'BlogOSで変更'] + $base, $base, $this->user->id);

        $this->assertSame(PushState::Failed, $operation->state);
        $this->assertSame(1, SyncIssue::where('issue_type', SyncIssueType::Conflict)->where('resource_type', 'categories')->count());
        $this->assertSame('WordPressで変更', $this->wp->lists['categories'][1]['name']);
    }

    public function test_change_in_other_field_is_not_a_conflict(): void
    {
        $category = Category::where('wordpress_id', 10)->sole();
        $base = $this->service()->currentValues($category);

        // 変更しない項目（説明）がWordPress側で変わっていても、競合にしない
        $this->wp->lists['categories'][1]['description'] = '別の説明';

        $operation = $this->service()->update($category, ['name' => 'BlogOSで変更'] + $base, $base, $this->user->id);

        $this->assertSame(PushState::Completed, $operation->state);
        $this->assertSame('別の説明', $category->fresh()->description);
    }

    public function test_deleting_category_refetches_linked_posts(): void
    {
        $category = Category::where('wordpress_id', 11)->sole();

        // WordPressは、削除したカテゴリを投稿から外す（投稿の更新日時は変わらない）
        $this->wp->lists['posts'][0]['categories'] = [10];

        $operation = $this->service()->delete($category, $this->user->id);

        $this->assertSame(PushState::Completed, $operation->state);
        $this->assertNotNull($category->fresh()->wordpress_deleted_at);

        $post = Post::sole();
        $this->assertSame([10], $post->categories()->pluck('wordpress_id')->all());
        $this->assertSame($operation->id, PostHistory::where('post_id', $post->id)->where('field', 'categories')->sole()->wordpress_push_operation_id);
    }

    public function test_default_category_cannot_be_deleted(): void
    {
        $this->assertSame('1', BlogSetting::where('key', 'default_category')->value('value'));

        $this->expectException(PushException::class);
        $this->service()->delete(Category::where('wordpress_id', 1)->sole(), $this->user->id);
    }

    public function test_media_alt_text_update(): void
    {
        $media = Media::sole();
        $base = $this->service()->currentValues($media);

        $operation = $this->service()->update($media, ['alt_text' => '画像の説明'] + $base, $base, $this->user->id);

        $this->assertSame(PushState::Completed, $operation->state);
        $this->assertSame('画像の説明', $media->fresh()->alt_text);
    }

    public function test_term_screens(): void
    {
        $this->actingAs($this->user);
        $category = Category::where('wordpress_id', 10)->sole();

        $this->get(route('terms.edit', ['type' => 'categories', 'id' => $category->id]))->assertOk()->assertSee('関連している投稿：1件');
        $this->get(route('terms.edit', ['type' => 'media', 'id' => Media::sole()->id]))->assertOk();

        $base = $this->service()->currentValues($category);
        $this->put(route('terms.update', ['type' => 'categories', 'id' => $category->id]), [
            'selected_blog_id' => $this->blog->id,
            'values'           => ['name' => '画面から変更'] + $base,
            'base'             => $base,
            'approved'         => 1,
        ])->assertRedirect();
        $this->assertSame('画面から変更', $category->fresh()->name);

        // 削除は名前の入力が必要
        $this->delete(route('terms.destroy', ['type' => 'categories', 'id' => $category->id]), [
            'selected_blog_id' => $this->blog->id, 'confirm_name' => '違う名前', 'confirmed' => 1,
        ])->assertSessionHasErrors('confirm_name');
        $this->assertNull($category->fresh()->wordpress_deleted_at);

        $this->get(route('push-operations.index'))->assertOk()->assertSee('画面から変更');
    }
}
