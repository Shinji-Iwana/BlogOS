<?php

namespace Tests\Feature\Sync;

use App\Enums\ChangeSource;
use App\Enums\DraftState;
use App\Enums\PushResourceType;
use App\Enums\SyncIssueType;
use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Models\ArticleMedia;
use App\Models\Author;
use App\Models\CustomContent;
use App\Models\CustomTerm;
use App\Models\Histories\CustomContentHistory;
use App\Models\InternalLink;
use App\Repositories\ArticleDraftRepository;
use App\Models\Blog;
use App\Models\BlogCredential;
use App\Models\Category;
use App\Models\Histories\PostHistory;
use App\Models\Media;
use App\Models\Page;
use App\Models\Post;
use App\Models\SyncIssue;
use App\Models\SyncRun;
use App\Services\Sync\SyncAlreadyRunningException;
use App\Services\Sync\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeWordPress;
use Tests\TestCase;

/**
 * 同期の仕組み（BLOGOS_WORDPRESS_API.md 第III部、BLOGOS_DECISIONS.md D-04・D-09・D-13）。
 */
class SyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Blog $blog;

    protected FakeWordPress $wp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true]);
        BlogCredential::create(['blog_id' => $this->blog->id, 'username' => 'admin', 'secret' => 'secret']);

        $this->wp = new FakeWordPress();
        $this->wp->lists['users'] = [FakeWordPress::user(1)];
        $this->wp->lists['categories'] = [FakeWordPress::term(10), FakeWordPress::term(11, ['parent' => 10])];
        $this->wp->lists['tags'] = [FakeWordPress::term(20)];
        $this->wp->lists['media'] = [FakeWordPress::media(30, ['post' => 100])];
        $this->wp->lists['pages'] = [FakeWordPress::page(50), FakeWordPress::page(51, ['parent' => 50])];
        $this->wp->lists['posts'] = [
            FakeWordPress::post(100, ['categories' => [10, 11], 'tags' => [20], 'featured_media' => 30]),
            FakeWordPress::post(101, ['categories' => [10]]),
        ];
        $this->wp->install();
    }

    protected function sync(SyncTrigger $trigger = SyncTrigger::Scheduled): SyncRun
    {
        return app(SyncService::class)->run($this->blog->fresh(), $trigger);
    }

    public function test_initial_sync_saves_all_resources_and_references(): void
    {
        $run = $this->sync(SyncTrigger::Initial);

        $this->assertSame(SyncStatus::Succeeded, $run->status);
        $this->assertSame(2, Post::count());
        $this->assertSame(2, Page::count());
        $this->assertSame(1, Media::count());

        // 参照先の解決
        $post = Post::where('wordpress_id', 100)->sole();
        $this->assertSame(Author::sole()->id, $post->author_id);
        $this->assertSame(Media::sole()->id, $post->featured_media_id);
        $this->assertEqualsCanonicalizing([10, 11], $post->categories()->pluck('wordpress_id')->all());
        $this->assertSame(Category::where('wordpress_id', 10)->value('id'), Category::where('wordpress_id', 11)->value('parent_id'));
        $this->assertSame(Page::where('wordpress_id', 50)->value('id'), Page::where('wordpress_id', 51)->value('parent_id'));
        $this->assertSame($post->id, Media::sole()->post_id);
        $this->assertSame('/post-100', $post->normalized_path);

        // 新規は __created の1行だけ（D-13-04）
        $histories = PostHistory::where('post_id', $post->id)->get();
        $this->assertCount(1, $histories);
        $this->assertSame('__created', $histories[0]->field);
        $this->assertSame(ChangeSource::WpInitialSync, $histories[0]->source);
        $this->assertSame($run->id, $histories[0]->sync_run_id);

        $this->assertSame(0, SyncIssue::count());
    }

    public function test_second_sync_fetches_details_only_for_changed_posts(): void
    {
        $this->sync(SyncTrigger::Initial);

        $this->wp->lists['posts'][0] = FakeWordPress::post(100, [
            'categories'     => [10],
            'tags'           => [20],
            'featured_media' => 30,
            'modified_gmt'   => '2026-09-10T03:00:00',
            'title'          => ['raw' => 'New Title', 'rendered' => 'New Title'],
        ]);
        $this->wp->requests = [];

        $run = $this->sync();

        $this->assertSame(SyncStatus::Succeeded, $run->status);

        // 詳細（include）は変更のあった投稿だけ
        $includes = array_values(array_filter(array_column($this->wp->requestsTo('posts'), 'include')));
        $this->assertSame(['100'], $includes);

        $post = Post::where('wordpress_id', 100)->sole();
        $changes = PostHistory::where('post_id', $post->id)->where('sync_run_id', $run->id)->pluck('new_value', 'field');
        $this->assertSame('New Title', $changes['title_raw']);
        $this->assertSame('[10]', $changes['categories']);
        $this->assertFalse($changes->has('tags'));

        // 1回の変更は同じ change_set_id（D-05-09）
        $this->assertSame(1, PostHistory::where('post_id', $post->id)->where('sync_run_id', $run->id)->distinct()->count('change_set_id'));

        $resource = $run->resources()->where('resource_type', 'posts')->sole();
        $this->assertSame(2, $resource->fetched_count);
        $this->assertSame(1, $resource->updated_count);
        $this->assertSame(1, $resource->unchanged_count);
    }

    public function test_sync_without_changes_writes_no_history(): void
    {
        $this->sync(SyncTrigger::Initial);
        $before = PostHistory::count();

        $this->sync();

        $this->assertSame($before, PostHistory::count());
    }

    public function test_missing_post_is_recorded_as_deleted(): void
    {
        $this->wp->lists['posts'][] = FakeWordPress::post(102);
        $this->sync(SyncTrigger::Initial);

        array_pop($this->wp->lists['posts']);
        $this->sync();

        $post = Post::where('wordpress_id', 102)->sole();
        $this->assertNotNull($post->wordpress_deleted_at);
        $this->assertTrue(PostHistory::where('post_id', $post->id)->where('field', '__deleted')->exists());
        $this->assertSame(1, SyncIssue::where('issue_type', SyncIssueType::DeletedDetected)->where('post_id', $post->id)->count());

        // 再び一覧に現れたら、復活として記録する
        $this->wp->lists['posts'][] = FakeWordPress::post(102);
        $this->sync();

        $this->assertNull($post->fresh()->wordpress_deleted_at);
        $this->assertTrue(PostHistory::where('post_id', $post->id)->where('field', '__restored')->exists());
    }

    public function test_mass_deletion_is_not_applied(): void
    {
        $this->sync(SyncTrigger::Initial);

        $this->wp->lists['posts'] = [];
        $this->sync();

        $this->assertSame(0, Post::whereNotNull('wordpress_deleted_at')->count());
        $this->assertSame(1, SyncIssue::where('issue_type', SyncIssueType::MassDeletionSuspected)->where('resource_type', 'posts')->count());
    }

    public function test_deleted_category_forces_linked_posts_to_be_refetched(): void
    {
        $this->wp->lists['categories'][] = FakeWordPress::term(12);
        $this->wp->lists['categories'][] = FakeWordPress::term(13);
        $this->wp->lists['posts'][1] = FakeWordPress::post(101, ['categories' => [10, 12]]);
        $this->sync(SyncTrigger::Initial);

        // カテゴリ12を削除。WordPressは投稿の modified_gmt を変えない
        $this->wp->lists['categories'] = array_values(array_filter($this->wp->lists['categories'], fn ($c) => $c['id'] !== 12));
        $this->wp->lists['posts'][1] = FakeWordPress::post(101, ['categories' => [10]]);
        $this->wp->requests = [];

        $this->sync();

        $includes = array_values(array_filter(array_column($this->wp->requestsTo('posts'), 'include')));
        $this->assertSame(['101'], $includes);
        $this->assertSame([10], Post::where('wordpress_id', 101)->sole()->categories()->pluck('wordpress_id')->all());
    }

    public function test_fetch_error_is_recorded_and_run_becomes_partial(): void
    {
        $this->wp->failures['tags'] = 500;

        $run = $this->sync(SyncTrigger::Initial);

        $this->assertSame(SyncStatus::Partial, $run->status);
        $issue = SyncIssue::where('issue_type', SyncIssueType::FetchError)->sole();
        $this->assertSame('tags', $issue->resource_type);
        $this->assertSame(500, $issue->error_status);
        $this->assertStringContainsString('tags failed', $issue->error_body);

        // 他のリソースは保存されている
        $this->assertSame(2, Post::count());
    }

    public function test_repeated_issue_is_not_duplicated(): void
    {
        $this->wp->failures['tags'] = 500;

        $this->sync(SyncTrigger::Initial);
        $this->sync();

        $this->assertSame(1, SyncIssue::where('issue_type', SyncIssueType::FetchError)->count());
    }

    public function test_unresolved_author_is_recorded(): void
    {
        $this->wp->lists['posts'][1] = FakeWordPress::post(101, ['author' => 99]);

        $this->sync(SyncTrigger::Initial);

        $this->assertNull(Post::where('wordpress_id', 101)->sole()->author_id);
        $this->assertSame(1, SyncIssue::where('issue_type', SyncIssueType::UnresolvedReference)->where('resource_key', '101:投稿者')->count());
    }

    public function test_changed_home_is_recorded_without_changing_blog(): void
    {
        $this->wp->home = 'https://moved.example.test';

        $this->sync(SyncTrigger::Initial);

        $this->assertSame('https://blog.example.test', $this->blog->fresh()->home);
        $this->assertSame(1, SyncIssue::where('issue_type', SyncIssueType::HomeChanged)->count());
    }

    public function test_internal_links_and_media_are_extracted_and_resolved(): void
    {
        $this->wp->lists['posts'][0] = FakeWordPress::post(100, [
            'categories' => [10], 'featured_media' => 30,
            'content'    => ['raw' => '<a href="https://blog.example.test/post-101/">次へ</a><a href="/page-50/">固定</a><a href="/post-999/">未作成</a><img class="wp-image-30" src="/wp-content/uploads/30.png">', 'rendered' => ''],
        ]);

        $this->sync(SyncTrigger::Initial);

        $post = Post::where('wordpress_id', 100)->sole();
        $links = InternalLink::where('post_id', $post->id)->orderBy('id')->get();
        $this->assertCount(3, $links);
        $this->assertSame(Post::where('wordpress_id', 101)->value('id'), $links[0]->target_post_id);
        $this->assertSame(Page::where('wordpress_id', 50)->value('id'), $links[1]->target_page_id);
        $this->assertNull($links[2]->target_post_id);
        $this->assertSame(Media::sole()->id, ArticleMedia::where('post_id', $post->id)->sole()->media_id);

        // リンク先の記事が後から作成されたら、次の同期で解決する（D-15-09）
        $this->wp->lists['posts'][] = FakeWordPress::post(999);
        $this->sync();
        $this->assertSame(Post::where('wordpress_id', 999)->value('id'), $links[2]->fresh()->target_post_id);
    }

    public function test_wordpress_change_is_held_when_article_has_active_draft(): void
    {
        $this->sync(SyncTrigger::Initial);
        $post = Post::where('wordpress_id', 100)->sole();

        $draft = app(ArticleDraftRepository::class)->create([
            'blog_id'                     => $this->blog->id,
            'post_id'                     => $post->id,
            'target_type'                 => PushResourceType::Post,
            'base_wordpress_modified_gmt' => $post->wordpress_modified_gmt,
            'title_raw'                   => '編集中のタイトル',
        ], ChangeSource::BlogosManual, null);

        $this->wp->lists['posts'][0] = FakeWordPress::post(100, [
            'categories' => [10, 11], 'tags' => [20], 'featured_media' => 30,
            'modified_gmt' => '2026-09-20T03:00:00',
            'title' => ['raw' => 'WordPressで変更', 'rendered' => 'WordPressで変更'],
        ]);

        $this->sync();

        // DBは更新しない
        $this->assertSame('Title 100', $post->fresh()->title_raw);
        $issue = SyncIssue::where('issue_type', SyncIssueType::Conflict)->sole();
        $this->assertSame($post->id, $issue->post_id);
        $this->assertSame($draft->id, $issue->article_draft_id);

        // 編集案を破棄すれば、次の同期で取り込む
        app(ArticleDraftRepository::class)->changeState($draft, DraftState::Discarded, ChangeSource::BlogosManual, null);
        $this->sync();
        $this->assertSame('WordPressで変更', $post->fresh()->title_raw);
    }

    public function test_custom_post_types_and_taxonomies_are_synced(): void
    {
        $this->wp->definitions['types']['book'] = ['name' => '本', 'description' => '', 'hierarchical' => false, 'rest_base' => 'books', 'rest_namespace' => 'wp/v2', 'taxonomies' => ['genre']];
        $this->wp->definitions['types']['wp_block'] = ['name' => 'ブロック', 'description' => '', 'hierarchical' => false, 'rest_base' => 'blocks', 'rest_namespace' => 'wp/v2', 'taxonomies' => []];
        $this->wp->definitions['taxonomies']['genre'] = ['name' => 'ジャンル', 'description' => '', 'hierarchical' => true, 'rest_base' => 'genres', 'rest_namespace' => 'wp/v2', 'types' => ['book']];
        $this->wp->lists['genres'] = [FakeWordPress::term(70, ['taxonomy' => 'genre']), FakeWordPress::term(71, ['taxonomy' => 'genre', 'parent' => 70])];
        $this->wp->lists['books'] = [FakeWordPress::post(200, ['type' => 'book', 'genres' => [70, 71]])];

        $run = $this->sync(SyncTrigger::Initial);

        $this->assertSame(SyncStatus::Succeeded, $run->status);
        $book = CustomContent::sole();
        $this->assertSame('book', $book->type);
        $this->assertEqualsCanonicalizing([70, 71], $book->terms()->pluck('wordpress_id')->all());
        $this->assertSame(CustomTerm::where('wordpress_id', 70)->value('id'), CustomTerm::where('wordpress_id', 71)->value('parent_id'));
        $this->assertSame('genre', CustomTerm::where('wordpress_id', 70)->value('taxonomy'));

        // WordPressの内部用の投稿タイプ（wp_ で始まるもの）は取得しない
        $this->assertSame([], $this->wp->requestsTo('blocks'));

        // 関連の付け替えは、履歴に terms として記録する
        $this->wp->lists['books'][0]['genres'] = [70];
        $this->wp->lists['books'][0]['modified_gmt'] = '2026-09-10T03:00:00';
        $this->sync();
        $this->assertSame('[70]', CustomContentHistory::where('custom_content_id', $book->id)->where('field', 'terms')->value('new_value'));
    }

    public function test_sync_is_rejected_while_another_sync_is_running(): void
    {
        $lock = Cache::lock("blogos:sync:blog:{$this->blog->id}", 60);
        $lock->get();

        try {
            $this->expectException(SyncAlreadyRunningException::class);
            $this->sync();
        } finally {
            $lock->release();
        }
    }
}
