<?php

namespace Tests\Feature\Sync;

use App\Enums\SyncIssueType;
use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Jobs\SyncBlogJob;
use App\Models\Blog;
use App\Models\BlogCredential;
use App\Models\Post;
use App\Models\SyncIssue;
use App\Models\SyncRun;
use App\Models\User;
use App\Repositories\SyncIssueRepository;
use App\Services\Sync\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeWordPress;
use Tests\TestCase;

/**
 * 「今すぐ同期」・同期の状態・同期の問題・DB確認画面（D-01-03、D-01-05、D-15-08）。
 */
class SyncScreensTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Blog $blog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true]);
        BlogCredential::create(['blog_id' => $this->blog->id, 'username' => 'admin', 'secret' => 'secret']);
    }

    public function test_manual_sync_is_queued_and_shown_as_waiting(): void
    {
        Queue::fake();

        $this->actingAs($this->user)
            ->post(route('sync.runs.store'), ['selected_blog_id' => $this->blog->id])
            ->assertSessionHasNoErrors();

        Queue::assertPushed(SyncBlogJob::class, fn (SyncBlogJob $job) => $job->blogId === $this->blog->id
            && $job->trigger === SyncTrigger::Manual
            && $job->userId === $this->user->id);

        $this->actingAs($this->user)
            ->getJson(route('api.sync.status'))
            ->assertOk()
            ->assertJson(['blog_id' => $this->blog->id, 'state' => 'queued', 'latest_run' => null]);

        // 開始待ちの間は、重ねて登録しない
        $this->actingAs($this->user)
            ->post(route('sync.runs.store'), ['selected_blog_id' => $this->blog->id])
            ->assertSessionHasErrors('sync');
        Queue::assertPushed(SyncBlogJob::class, 1);
    }

    public function test_manual_sync_is_rejected_while_running(): void
    {
        Queue::fake();
        SyncRun::create(['blog_id' => $this->blog->id, 'trigger' => SyncTrigger::Scheduled, 'status' => SyncStatus::Running, 'started_at' => now()]);

        $this->actingAs($this->user)
            ->post(route('sync.runs.store'), ['selected_blog_id' => $this->blog->id])
            ->assertSessionHasErrors('sync');

        Queue::assertNothingPushed();
    }

    public function test_manual_sync_is_rejected_when_selected_blog_changed(): void
    {
        Queue::fake();
        $other = Blog::create(['home' => 'https://other.example.test', 'display_name' => 'Other']);

        $this->actingAs($this->user)
            ->post(route('sync.runs.store'), ['selected_blog_id' => $other->id])
            ->assertSessionHasErrors('selected_blog_id');

        Queue::assertNothingPushed();
    }

    public function test_job_clears_waiting_state_and_dashboard_shows_result(): void
    {
        $wp = new FakeWordPress();
        $wp->lists['users'] = [FakeWordPress::user(1)];
        $wp->lists['posts'] = [FakeWordPress::post(100)];
        $wp->install();

        // Queueの接続は sync（テスト環境）のため、登録と同時に実行される
        $this->actingAs($this->user)
            ->post(route('sync.runs.store'), ['selected_blog_id' => $this->blog->id])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->user)
            ->getJson(route('api.sync.status'))
            ->assertOk()
            ->assertJsonPath('state', 'idle')
            ->assertJsonPath('latest_run.status', 'succeeded')
            ->assertJsonPath('latest_run.trigger', 'manual');

        $this->actingAs($this->user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('手動同期')
            ->assertSee('今すぐ同期');
    }

    public function test_issue_can_be_resolved(): void
    {
        $issue = app(SyncIssueRepository::class)->record($this->blog->id, SyncIssueType::FetchError, 'tags', null, [
            'message' => 'tags failed', 'error_status' => 500, 'error_body' => '{"code":"error"}',
        ]);

        $this->actingAs($this->user)
            ->get(route('sync.issues.index'))
            ->assertOk()
            ->assertSee('tags failed');

        $this->actingAs($this->user)
            ->post(route('sync.issues.resolve', ['id' => $issue->id]), [
                'selected_blog_id' => $this->blog->id,
                'resolution'       => '一時的なエラー。次の同期で成功を確認',
            ])
            ->assertRedirect(route('sync.issues.index'));

        $issue->refresh();
        $this->assertNotNull($issue->resolved_at);
        $this->assertSame($this->user->id, $issue->resolved_by);

        $this->actingAs($this->user)
            ->get(route('sync.issues.index', ['resolved' => 1]))
            ->assertOk()
            ->assertSee('一時的なエラー');
    }

    public function test_issue_of_other_blog_cannot_be_resolved(): void
    {
        $other = Blog::create(['home' => 'https://other.example.test', 'display_name' => 'Other']);
        $issue = app(SyncIssueRepository::class)->record($other->id, SyncIssueType::FetchError, 'tags', null, ['message' => 'x']);

        $this->actingAs($this->user)
            ->post(route('sync.issues.resolve', ['id' => $issue->id]), [
                'selected_blog_id' => $this->blog->id,
                'resolution'       => 'x',
            ])
            ->assertNotFound();

        $this->assertNull($issue->fresh()->resolved_at);
    }

    public static function databaseTables(): array
    {
        return array_map(fn ($table) => [$table], [
            'posts', 'pages', 'media', 'categories', 'tags', 'authors', 'statuses', 'types', 'taxonomies',
        ]);
    }

    #[DataProvider('databaseTables')]
    public function test_database_screens_render(string $table): void
    {
        $wp = new FakeWordPress();
        $wp->lists['users'] = [FakeWordPress::user(1)];
        $wp->lists['categories'] = [FakeWordPress::term(10)];
        $wp->lists['tags'] = [FakeWordPress::term(20)];
        $wp->lists['media'] = [FakeWordPress::media(30)];
        $wp->lists['pages'] = [FakeWordPress::page(50)];
        $wp->lists['posts'] = [FakeWordPress::post(100, ['categories' => [10], 'tags' => [20]])];
        $wp->install();
        app(SyncService::class)->run($this->blog, SyncTrigger::Initial);

        $this->actingAs($this->user)->get(route('database.wordpress-records.tables'))->assertOk();
        $this->actingAs($this->user)->get(route('database.sync-runs.index'))->assertOk()->assertSee('初回取得');

        $response = $this->actingAs($this->user)->get(route('database.wordpress-records.index', ['table' => $table]))->assertOk();

        $model = [
            'posts' => \App\Models\Post::class, 'pages' => \App\Models\Page::class, 'media' => \App\Models\Media::class,
            'categories' => \App\Models\Category::class, 'tags' => \App\Models\Tag::class, 'authors' => \App\Models\Author::class,
            'statuses' => \App\Models\Status::class, 'types' => \App\Models\Type::class, 'taxonomies' => \App\Models\Taxonomy::class,
        ][$table];
        $record = $model::firstOrFail();

        $this->actingAs($this->user)
            ->get(route('database.wordpress-records.show', ['table' => $table, 'id' => $record->id]))
            ->assertOk()
            ->assertSee('__created');
    }

    public function test_unknown_table_is_not_found(): void
    {
        $this->actingAs($this->user)
            ->get(route('database.wordpress-records.index', ['table' => 'users']))
            ->assertNotFound();
    }

    public function test_post_search_filters_by_title(): void
    {
        $wp = new FakeWordPress();
        $wp->lists['posts'] = [
            FakeWordPress::post(100, ['title' => ['raw' => 'Laravel入門', 'rendered' => 'Laravel入門']]),
            FakeWordPress::post(101, ['title' => ['raw' => 'PHPの基本', 'rendered' => 'PHPの基本']]),
        ];
        $wp->install();
        app(SyncService::class)->run($this->blog, SyncTrigger::Initial);
        $this->assertSame(2, Post::count());

        $this->actingAs($this->user)
            ->get(route('database.wordpress-records.index', ['table' => 'posts', 'q' => 'Laravel']))
            ->assertOk()
            ->assertSee('Laravel入門')
            ->assertDontSee('PHPの基本');
    }
}
