<?php

namespace Tests\Feature\Google;

use App\Enums\AiBatchTarget;
use App\Enums\AiMode;
use App\Enums\GoogleIndexCategory;
use App\Enums\GoogleService;
use App\Jobs\InspectGoogleIndexJob;
use App\Models\Blog;
use App\Models\GoogleAccount;
use App\Models\GoogleIndexStatus;
use App\Models\Post;
use App\Models\User;
use App\Repositories\GoogleAccountRepository;
use App\Services\Ai\AiBatchService;
use App\Services\Ai\PromptBuilder;
use App\Services\Google\GoogleIndexInspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 記事のインデックスの登録状態（Search Console の URL 検査 API。D-37）。
 */
class GoogleIndexTest extends TestCase
{
    use RefreshDatabase;

    protected Blog $blog;

    /**
     * @var array<string, string> URL => coverageState
     */
    protected array $coverage = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true, 'quality_profile' => 'si-note']);
        $account = GoogleAccount::create(['email' => 'owner@example.com', 'access_token' => 'valid-token', 'refresh_token' => 'refresh-token', 'token_expires_at' => now()->addHour()]);
        app(GoogleAccountRepository::class)->saveProperty($this->blog->id, GoogleService::SearchConsole, $account->id, 'sc-domain:blog.example.test', null, null);

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if ($request->url() !== GoogleIndexInspectionService::ENDPOINT) {
                return Http::response(['error' => ['message' => 'unexpected']], 404);
            }
            $this->assertSame('sc-domain:blog.example.test', $request['siteUrl']);
            $state = $this->coverage[$request['inspectionUrl']] ?? null;

            return $state === null
                ? Http::response(['error' => ['message' => 'Quota exceeded']], 429)
                : Http::response(['inspectionResult' => ['indexStatusResult' => [
                    'verdict' => str_contains($state, 'not indexed') ? 'NEUTRAL' : 'PASS', 'coverageState' => $state,
                    'lastCrawlTime' => '2026-09-20T01:02:03Z', 'pageFetchState' => 'SUCCESSFUL',
                ]]]);
        });

        $this->actingAs(User::factory()->create());
    }

    protected function createPost(int $wordpressId): Post
    {
        return Post::create([
            'blog_id' => $this->blog->id, 'wordpress_id' => $wordpressId, 'title_raw' => "記事{$wordpressId}", 'status' => 'publish', 'content_raw' => '<p>本文</p>',
            'link' => "https://blog.example.test/js/{$wordpressId}.html", 'normalized_path' => "/js/{$wordpressId}.html", 'wordpress_modified_gmt' => '2026-09-01 00:00:00',
        ]);
    }

    public function test_statuses_are_inspected_categorized_and_used_for_revision(): void
    {
        $indexed = $this->createPost(1);
        $crawled = $this->createPost(2);
        $discovered = $this->createPost(3);
        $this->coverage = [
            $indexed->link    => 'Submitted and indexed',
            $crawled->link    => 'Crawled - currently not indexed',
            $discovered->link => 'Discovered - currently not indexed',
        ];

        $result = app(GoogleIndexInspectionService::class)->inspect($this->blog);
        $this->assertSame(3, $result['inspected']);

        $statuses = GoogleIndexStatus::all()->keyBy('post_id');
        $this->assertSame(GoogleIndexCategory::Indexed, $statuses[$indexed->id]->category);
        $this->assertSame(GoogleIndexCategory::Crawled, $statuses[$crawled->id]->category);
        $this->assertSame(GoogleIndexCategory::Discovered, $statuses[$discovered->id]->category);
        $this->assertSame('2026-09-20', $statuses[$crawled->id]->last_crawl_at->toDateString());

        // 調べたばかりの記事は、日数が過ぎるか、反映で変わるまで調べ直さない
        $this->assertSame(0, app(GoogleIndexInspectionService::class)->due($this->blog)->count());
        $crawled->update(['wordpress_modified_gmt' => now()->addMinute()]);
        $this->assertSame([$crawled->id], app(GoogleIndexInspectionService::class)->due($this->blog)->pluck('id')->all());

        // 分類が変わったら、前の分類と日時を残す
        $this->coverage[$crawled->link] = 'Submitted and indexed';
        app(GoogleIndexInspectionService::class)->inspect($this->blog);
        $status = GoogleIndexStatus::where('post_id', $crawled->id)->sole();
        $this->assertSame(GoogleIndexCategory::Indexed, $status->category);
        $this->assertSame(GoogleIndexCategory::Crawled, $status->previous_category);
        $this->assertNotNull($status->category_changed_at);

        // 画面：登録済み以外の記事と、分類ごとの改修の方針
        $this->get(route('google.index-status'))->assertOk()
            ->assertSee('検出 - インデックス未登録')
            ->assertSee('内部リンクを増やし')
            ->assertSee('記事3');

        // 改修の指示文に、登録状態と方針を入れる
        $built = app(PromptBuilder::class)->build(AiMode::Revision, $this->blog, $discovered, null, [], null);
        $this->assertStringContainsString('Google のインデックス：検出 - インデックス未登録（最後に Google が読んだ日：2026-09-20）。改修の方針：', $built['prompt']);

        // まとめて改修：インデックスに登録されていない記事、公開中の全ての記事
        $targets = app(AiBatchService::class)->targets($this->blog, AiMode::Revision, AiBatchTarget::NotIndexed);
        $this->assertSame([$discovered->id], array_map(fn ($row) => $row['article']->id, $targets));
        $this->assertCount(3, app(AiBatchService::class)->targets($this->blog, AiMode::Revision, AiBatchTarget::All));
    }

    public function test_quota_error_stops_and_button_queues_job(): void
    {
        $this->createPost(1);
        $this->createPost(2);

        // 1日の上限（429）で止める
        $result = app(GoogleIndexInspectionService::class)->inspect($this->blog);
        $this->assertSame(0, $result['inspected']);
        $this->assertStringContainsString('Quota exceeded', (string) $result['stopped']);

        Queue::fake();
        $this->post(route('google.index-status.run'), ['selected_blog_id' => $this->blog->id])->assertRedirect(route('google.index-status'));
        Queue::assertPushed(InspectGoogleIndexJob::class, fn ($job) => $job->blogId === $this->blog->id);
    }
}
