<?php

namespace Tests\Feature\Push;

use App\Enums\ChangeSource;
use App\Enums\DraftState;
use App\Enums\PushOperationType;
use App\Enums\PushResourceType;
use App\Enums\PushState;
use App\Enums\SyncIssueType;
use App\Enums\SyncTrigger;
use App\Models\ArticleDraft;
use App\Models\Blog;
use App\Models\BlogCredential;
use App\Models\Histories\PostHistory;
use App\Models\Post;
use App\Models\SyncIssue;
use App\Models\User;
use App\Repositories\ArticleDraftRepository;
use App\Services\Push\ArticlePushService;
use App\Services\Push\PushException;
use App\Services\Push\PushRecoveryService;
use App\Services\Sync\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeWordPress;
use Tests\TestCase;

/**
 * 記事の反映（ARCHITECTURE 13-5・13-6、WORDPRESS_API 第IV部）。
 */
class ArticlePushServiceTest extends TestCase
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
        $this->wp->lists['users'] = [FakeWordPress::user(1)];
        $this->wp->lists['categories'] = [FakeWordPress::term(10), FakeWordPress::term(11)];
        $this->wp->lists['posts'] = [FakeWordPress::post(100, ['categories' => [10], 'status' => 'draft'])];
        $this->wp->install();

        app(SyncService::class)->run($this->blog, SyncTrigger::Initial);
    }

    protected function service(): ArticlePushService
    {
        return app(ArticlePushService::class);
    }

    protected function draftFor(Post $post, array $attributes = []): ArticleDraft
    {
        return app(ArticleDraftRepository::class)->create(array_merge([
            'blog_id'                     => $this->blog->id,
            'post_id'                     => $post->id,
            'target_type'                 => PushResourceType::Post,
            'base_wordpress_modified_gmt' => $post->wordpress_modified_gmt,
            'title_raw'                   => $post->title_raw,
            'content_raw'                 => $post->content_raw,
            'excerpt_raw'                 => $post->excerpt_raw,
            'slug'                        => $post->slug,
            'status'                      => $post->status,
            'wordpress_category_ids'      => [10],
            'wordpress_tag_ids'           => [],
        ], $attributes), ChangeSource::BlogosManual, $this->user->id);
    }

    public function test_update_sends_only_changed_fields_and_updates_db_from_response(): void
    {
        $post = Post::sole();
        $draft = $this->draftFor($post, ['title_raw' => '新しいタイトル', 'wordpress_category_ids' => [10, 11]]);

        $this->assertSame(['title' => '新しいタイトル', 'categories' => [10, 11]], $this->service()->payload($draft));

        $operation = $this->service()->push($draft, $this->user->id);

        $this->assertSame(PushState::Completed, $operation->state);
        $this->assertSame(PushOperationType::Update, $operation->operation);

        // DBはWordPressの返却値で更新する
        $post->refresh();
        $this->assertSame('新しいタイトル', $post->title_raw);
        $this->assertSame([10, 11], $post->categories()->orderBy('wordpress_id')->pluck('wordpress_id')->all());
        $this->assertSame($operation->response_modified_gmt->format('Y-m-d H:i:s'), $post->wordpress_modified_gmt->format('Y-m-d H:i:s'));

        // 履歴は変更元 blogos_push、反映記録と承認した利用者を記録する
        $history = PostHistory::where('post_id', $post->id)->where('field', 'title_raw')->sole();
        $this->assertSame(ChangeSource::BlogosPush, $history->source);
        $this->assertSame($operation->id, $history->wordpress_push_operation_id);
        $this->assertSame($this->user->id, $history->user_id);

        $this->assertSame(DraftState::Pushed, $draft->fresh()->state);

        // 反映の直後の同期では、差分として検出しない
        $before = PostHistory::count();
        app(SyncService::class)->run($this->blog, SyncTrigger::Scheduled);
        $this->assertSame($before, PostHistory::count());
        $this->assertSame(0, SyncIssue::count());
    }

    public function test_meta_description_is_synced_and_pushed_via_aioseo(): void
    {
        $post = Post::sole();

        // 同期：説明が未設定の記事は、設定値が空で、自動の説明を記録する
        $this->assertNull($post->meta_description_raw);
        $this->assertSame('Body 100 の自動の説明', $post->meta_description_rendered);

        $draft = $this->draftFor($post, ['meta_description' => 'PHPの基本を初心者向けに解説します。']);
        $this->assertSame(['meta_description' => 'PHPの基本を初心者向けに解説します。'], $this->service()->payload($draft));

        $operation = $this->service()->push($draft, $this->user->id);

        $this->assertSame(PushState::Completed, $operation->state);
        // WordPressには AIOSEO の項目で送り、DBは返却値で更新する
        $this->assertSame(['description' => 'PHPの基本を初心者向けに解説します。'], $this->wp->lists['posts'][0]['aioseo_meta_data']);
        $post->refresh();
        $this->assertSame('PHPの基本を初心者向けに解説します。', $post->meta_description_raw);
        $this->assertSame('PHPの基本を初心者向けに解説します。', $post->meta_description_rendered);
        $this->assertSame(ChangeSource::BlogosPush, PostHistory::where('post_id', $post->id)->where('field', 'meta_description_raw')->sole()->source);
        // rendered（出力された説明）は保存するが、差分と履歴には使わない（D-05-07）
        $this->assertFalse(PostHistory::where('post_id', $post->id)->where('field', 'like', '%_rendered')->exists());

        // 新しい編集案には、記事に設定した説明が写る
        $next = app(\App\Services\Articles\DraftService::class)->createFromArticle($post, null, $this->user->id);
        $this->assertSame('PHPの基本を初心者向けに解説します。', $next->meta_description);
        $this->assertSame([], $this->service()->payload($next));
    }

    public function test_new_article_is_created_and_linked_to_draft(): void
    {
        $draft = app(ArticleDraftRepository::class)->create([
            'blog_id'     => $this->blog->id,
            'target_type' => PushResourceType::Post,
            'title_raw'   => '新規記事',
            'content_raw' => '<p>本文</p>',
        ], ChangeSource::BlogosManual, $this->user->id);

        $operation = $this->service()->push($draft, $this->user->id);

        $this->assertSame(PushState::Completed, $operation->state);
        $post = Post::where('title_raw', '新規記事')->sole();
        $this->assertSame('draft', $post->status);
        $this->assertSame($post->id, $draft->fresh()->post_id);
        $this->assertSame($post->id, $operation->post_id);
        $this->assertSame('__created', PostHistory::where('post_id', $post->id)->sole()->field);
    }

    public function test_push_is_stopped_when_wordpress_changed_after_draft_was_created(): void
    {
        $post = Post::sole();
        $draft = $this->draftFor($post, ['title_raw' => '編集案']);

        // 編集案を作った後に、WordPress側で変更された
        $this->wp->lists['posts'][0]['modified_gmt'] = '2026-09-30T00:00:00';

        $operation = $this->service()->push($draft, $this->user->id);

        $this->assertSame(PushState::Failed, $operation->state);
        $this->assertSame('Title 100', $post->fresh()->title_raw);
        $issue = SyncIssue::where('issue_type', SyncIssueType::Conflict)->sole();
        $this->assertSame($draft->id, $issue->article_draft_id);
        $this->assertSame(DraftState::Editing, $draft->fresh()->state);
    }

    public function test_timeout_becomes_unknown_and_locks_draft(): void
    {
        $post = Post::sole();
        $draft = $this->draftFor($post, ['title_raw' => '送信中に切断']);
        $this->wp->writeFailure = 'timeout';

        $operation = $this->service()->push($draft, $this->user->id);

        $this->assertSame(PushState::Unknown, $operation->state);
        $this->assertTrue($draft->fresh()->isLocked());
        $this->assertSame(1, SyncIssue::where('issue_type', SyncIssueType::PushUnknown)->count());

        $this->expectException(PushException::class);
        $this->service()->push($draft->fresh(), $this->user->id);
    }

    public function test_unknown_update_can_be_confirmed_as_applied(): void
    {
        $post = Post::sole();
        $draft = $this->draftFor($post, ['title_raw' => '確認後に完了']);
        $this->wp->writeFailure = 'timeout';
        $operation = $this->service()->push($draft, $this->user->id);

        // 実際にはWordPressに反映されていた
        $this->wp->lists['posts'][0]['title'] = ['raw' => '確認後に完了', 'rendered' => '確認後に完了'];
        $this->wp->lists['posts'][0]['modified_gmt'] = '2026-10-05T00:00:00';

        app(PushRecoveryService::class)->resolveAsApplied($operation, null, $this->user->id);

        $this->assertSame(PushState::Completed, $operation->fresh()->state);
        $this->assertSame('確認後に完了', $post->fresh()->title_raw);
        $this->assertSame(ChangeSource::BlogosRecovery, PostHistory::where('field', 'title_raw')->sole()->source);
        $this->assertSame(0, SyncIssue::unresolved()->count());
    }

    public function test_unknown_can_be_marked_as_not_applied_to_unlock_draft(): void
    {
        $draft = $this->draftFor(Post::sole(), ['title_raw' => 'x']);
        $this->wp->writeFailure = 500;
        $operation = $this->service()->push($draft, $this->user->id);
        $this->assertSame(PushState::Unknown, $operation->state);

        app(PushRecoveryService::class)->resolveAsNotApplied($operation, $this->user->id);

        $this->assertSame(PushState::Failed, $operation->fresh()->state);
        $this->assertFalse($draft->fresh()->isLocked());
    }

    public function test_client_error_fails_without_lock(): void
    {
        $draft = $this->draftFor(Post::sole(), ['title_raw' => 'x']);
        $this->wp->writeFailure = 400;

        $operation = $this->service()->push($draft, $this->user->id);

        $this->assertSame(PushState::Failed, $operation->state);
        $this->assertSame(400, $operation->error_status);
        $this->assertStringContainsString('write failed', $operation->error_body);
        $this->assertFalse($draft->fresh()->isLocked());
    }

    public function test_recovery_completes_operation_left_in_wp_succeeded(): void
    {
        $post = Post::sole();
        $draft = $this->draftFor($post, ['title_raw' => '回復で完了']);

        // DBの更新の直前で止まった状態を作る
        $operation = app(\App\Repositories\WordPressPushOperationRepository::class)->create(
            $this->blog->id, PushResourceType::Post, PushOperationType::Update,
            ['article_draft_id' => $draft->id, 'post_id' => $post->id], ['title' => '回復で完了'], null, $this->user->id
        );
        $this->wp->lists['posts'][0]['title'] = ['raw' => '回復で完了', 'rendered' => '回復で完了'];
        $this->wp->lists['posts'][0]['modified_gmt'] = '2026-10-06T00:00:00';
        app(\App\Repositories\WordPressPushOperationRepository::class)->markWpSucceeded($operation, 100, '2026-10-06T00:00:00');

        app(SyncService::class)->run($this->blog, SyncTrigger::Scheduled);

        $this->assertSame(PushState::Completed, $operation->fresh()->state);
        $this->assertSame(DraftState::Pushed, $draft->fresh()->state);
        $this->assertSame('回復で完了', $post->fresh()->title_raw);
        $this->assertSame(ChangeSource::BlogosRecovery, PostHistory::where('field', 'title_raw')->sole()->source);
        // 回復で完了した記事は、同期で競合として扱わない
        $this->assertSame(0, SyncIssue::where('issue_type', SyncIssueType::Conflict)->count());
    }

    public function test_unknown_create_is_matched_by_connector_meta(): void
    {
        BlogCredential::where('blog_id', $this->blog->id)->update(['connector_extension' => true]);
        $draft = app(ArticleDraftRepository::class)->create([
            'blog_id' => $this->blog->id, 'target_type' => PushResourceType::Post, 'title_raw' => '照合される記事',
        ], ChangeSource::BlogosManual, $this->user->id);

        $this->wp->writeFailure = 'timeout';
        $operation = $this->service()->push($draft, $this->user->id);
        $this->assertSame(PushState::Unknown, $operation->state);

        // 実際には作成されていた（メタに編集案のIDがある）
        $this->wp->lists['posts'][] = FakeWordPress::post(500, [
            'title' => ['raw' => '照合される記事', 'rendered' => '照合される記事'],
            'status' => 'draft', 'modified_gmt' => now('UTC')->format('Y-m-d\TH:i:s'),
            'meta' => ['_blogos_draft_id' => $draft->uuid],
        ]);
        $this->wp->writeFailure = null;

        app(SyncService::class)->run($this->blog, SyncTrigger::Scheduled);

        $this->assertSame(PushState::Completed, $operation->fresh()->state);
        $this->assertSame(Post::where('wordpress_id', 500)->value('id'), $draft->fresh()->post_id);
    }

    public function test_trash_and_force_delete(): void
    {
        $post = Post::sole();

        $operation = $this->service()->trash($post, false, $post->wordpress_modified_gmt, $this->user->id);
        $this->assertSame(PushState::Completed, $operation->state);
        $this->assertSame('trash', $post->fresh()->status);

        $post->refresh();
        $operation = $this->service()->trash($post, true, $post->wordpress_modified_gmt, $this->user->id);
        $this->assertSame(PushState::Completed, $operation->state);
        $this->assertNotNull($post->fresh()->wordpress_deleted_at);
        $this->assertTrue(PostHistory::where('post_id', $post->id)->where('field', '__deleted')->where('source', ChangeSource::BlogosPush->value)->exists());
    }

    public function test_trash_is_rejected_when_active_draft_exists(): void
    {
        $post = Post::sole();
        $this->draftFor($post);

        $this->expectException(PushException::class);
        $this->service()->trash($post, false, $post->wordpress_modified_gmt, $this->user->id);
    }

    public function test_push_is_rejected_while_sync_is_running(): void
    {
        $draft = $this->draftFor(Post::sole(), ['title_raw' => 'x']);
        $lock = Cache::lock(SyncService::lockKey($this->blog->id), 60);
        $lock->get();

        try {
            $this->expectException(PushException::class);
            $this->service()->push($draft, $this->user->id);
        } finally {
            $lock->release();
        }
    }

    public function test_publishing_requires_confirmation(): void
    {
        $post = Post::sole();
        $this->assertFalse($this->service()->willBePublic($this->draftFor($post)));
        $this->assertTrue($this->service()->willBePublic(ArticleDraft::make(['status' => 'publish', 'target_type' => PushResourceType::Post])));
    }
}
