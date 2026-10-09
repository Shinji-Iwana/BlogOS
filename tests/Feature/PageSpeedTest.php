<?php

namespace Tests\Feature;

use App\Jobs\MeasurePageSpeedJob;
use App\Models\Blog;
use App\Models\PageSpeedResponse;
use App\Models\PageSpeedRun;
use App\Models\Post;
use App\Models\User;
use App\Services\PageSpeed\PageSpeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * PageSpeed Insights の測定（D-78）
 */
class PageSpeedTest extends TestCase
{
    use RefreshDatabase;

    protected Blog $blog;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.pagespeed.key' => 'test-key', 'blogos.pagespeed.pause_seconds' => 0]);
        $this->blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true]);
    }

    protected function article(int $wordpressId, string $modified = '2026-09-01 00:00:00'): Post
    {
        return Post::create([
            'blog_id' => $this->blog->id, 'wordpress_id' => $wordpressId, 'title_raw' => "記事{$wordpressId}", 'status' => 'publish',
            'link' => "https://blog.example.test/{$wordpressId}.html", 'normalized_path' => "/{$wordpressId}.html", 'wordpress_modified_gmt' => $modified,
        ]);
    }

    /**
     * PageSpeed Insights API の応答（必要な部分だけ）
     */
    protected function response(): array
    {
        return [
            'lighthouseResult' => [
                'lighthouseVersion' => '12.0.0',
                'categories'        => [
                    'performance'    => ['score' => 0.72, 'auditRefs' => [['id' => 'largest-contentful-paint'], ['id' => 'render-blocking-resources']]],
                    'accessibility'  => ['score' => 0.95, 'auditRefs' => [['id' => 'image-alt']]],
                    'best-practices' => ['score' => 1, 'auditRefs' => []],
                    'seo'            => ['score' => 0.92, 'auditRefs' => [['id' => 'meta-description'], ['id' => 'image-alt']]],
                ],
                'audits' => [
                    'first-contentful-paint'    => ['numericValue' => 1800.4],
                    'largest-contentful-paint'  => ['numericValue' => 3200.6, 'score' => 0.4, 'title' => 'Largest Contentful Paint', 'displayValue' => '3.2 秒', 'scoreDisplayMode' => 'numeric'],
                    'total-blocking-time'       => ['numericValue' => 150],
                    'speed-index'               => ['numericValue' => 2500],
                    'cumulative-layout-shift'   => ['numericValue' => 0.0512],
                    'render-blocking-resources' => ['score' => 0.5, 'title' => 'レンダリングを妨げるリソースの除外', 'scoreDisplayMode' => 'metricSavings'],
                    'image-alt'                 => ['score' => 0, 'title' => '画像要素に [alt] 属性が指定されていません', 'scoreDisplayMode' => 'binary'],
                    'meta-description'          => ['score' => 1, 'title' => 'メタディスクリプションがあります', 'scoreDisplayMode' => 'binary'],
                ],
            ],
            'loadingExperience'       => ['overall_category' => 'AVERAGE', 'metrics' => ['LARGEST_CONTENTFUL_PAINT_MS' => ['percentile' => 2900], 'INTERACTION_TO_NEXT_PAINT' => ['percentile' => 180], 'CUMULATIVE_LAYOUT_SHIFT_SCORE' => ['percentile' => 5]]],
            'originLoadingExperience' => ['overall_category' => 'FAST', 'metrics' => ['LARGEST_CONTENTFUL_PAINT_MS' => ['percentile' => 2100]]],
        ];
    }

    public function test_command_queues_home_and_due_articles_for_both_strategies(): void
    {
        Queue::fake();
        $measured = $this->article(1);
        $this->article(2);
        $updated = $this->article(3, '2026-10-05 00:00:00');
        // 1・3 は測定済み（3 は測定の後に更新された）
        PageSpeedRun::create(['blog_id' => $this->blog->id, 'post_id' => $measured->id, 'url' => $measured->link, 'strategy' => 'mobile', 'trigger' => 'scheduled', 'status' => 'succeeded', 'started_at' => '2026-10-01 00:00:00']);
        PageSpeedRun::create(['blog_id' => $this->blog->id, 'post_id' => $updated->id, 'url' => $updated->link, 'strategy' => 'mobile', 'trigger' => 'scheduled', 'status' => 'succeeded', 'started_at' => '2026-10-01 00:00:00']);

        // 上限2件：トップページ（毎回）＋ まだ測っていない記事2 → 更新された記事3（測定済みで更新のない記事1は次回）
        $this->artisan('pagespeed:measure', ['--limit' => 2])->assertSuccessful();

        $pushed = Queue::pushed(MeasurePageSpeedJob::class);
        $this->assertCount(6, $pushed);
        $this->assertSame(
            ['https://blog.example.test/', 'https://blog.example.test/', 'https://blog.example.test/2.html', 'https://blog.example.test/2.html', 'https://blog.example.test/3.html', 'https://blog.example.test/3.html'],
            $pushed->map(fn ($job) => $job->url)->values()->all(),
        );
        $this->assertSame(['mobile', 'desktop'], $pushed->take(2)->map(fn ($job) => $job->strategy)->values()->all());
        // 同期・AI の処理を先に動かすため、別の Queue に登録する
        Queue::assertPushedOn('pagespeed', MeasurePageSpeedJob::class);
    }

    public function test_command_fails_without_api_key(): void
    {
        Queue::fake();
        config(['services.pagespeed.key' => null]);

        $this->artisan('pagespeed:measure')->assertFailed();
        Queue::assertNothingPushed();
    }

    public function test_measure_saves_summary_and_latest_response(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response($this->response())]);
        $post = $this->article(1);

        $run = app(PageSpeedService::class)->measure($this->blog->id, $post->link, $post->id, null, 'mobile', 'manual');

        $this->assertSame('succeeded', $run->status);
        $this->assertSame([72, 95, 100, 92], [$run->performance_score, $run->accessibility_score, $run->best_practices_score, $run->seo_score]);
        $this->assertSame([1800, 3201, 150, 2500], [$run->fcp_ms, $run->lcp_ms, $run->tbt_ms, $run->si_ms]);
        $this->assertSame(0.051, $run->cls);
        $this->assertSame([2900, 180, 0.05, 'AVERAGE'], [$run->field_lcp_ms, $run->field_inp_ms, $run->field_cls, $run->field_category]);
        $this->assertSame([2100, 'FAST'], [$run->origin_lcp_ms, $run->origin_category]);
        // 合格しなかった項目（両方の区分にある項目は1つにまとめる。合格した項目は残さない）
        $this->assertSame(['largest-contentful-paint', 'render-blocking-resources', 'image-alt'], array_column($run->failed_audits, 'id'));
        $this->assertSame(['accessibility', 'seo'], $run->failed_audits[2]['categories']);

        // 4つの区分を、日本語で、APIキーを付けて呼ぶ
        Http::assertSent(fn ($request) => str_contains($request->url(), 'strategy=mobile') && str_contains($request->url(), 'locale=ja')
            && str_contains($request->url(), 'category=BEST_PRACTICES') && str_contains($request->url(), 'key=test-key'));

        // 応答の全部は最新の1回だけ（測り直したら置き換える）
        app(PageSpeedService::class)->measure($this->blog->id, $post->link, $post->id, null, 'mobile', 'manual');
        $this->assertSame(1, PageSpeedResponse::count());
        $this->assertSame('12.0.0', PageSpeedResponse::first()->unpack()['lighthouseResult']['lighthouseVersion']);
    }

    public function test_measure_records_failure_without_leaking_key(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response(['error' => ['message' => 'Lighthouse returned error: NO_FCP']], 500)]);

        $run = app(PageSpeedService::class)->measure($this->blog->id, 'https://blog.example.test/', null, null, 'desktop', 'scheduled');

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('NO_FCP', $run->error);
        $this->assertStringNotContainsString('test-key', $run->error);
        $this->assertSame(0, PageSpeedResponse::count());
    }

    public function test_runs_page_lists_measurements(): void
    {
        $this->actingAs(User::factory()->create());
        $post = $this->article(1);
        PageSpeedRun::create(['blog_id' => $this->blog->id, 'post_id' => $post->id, 'url' => $post->link, 'strategy' => 'mobile', 'trigger' => 'scheduled', 'status' => 'succeeded',
            'performance_score' => 72, 'seo_score' => 92, 'lcp_ms' => 3200, 'field_category' => 'AVERAGE', 'started_at' => now(), 'finished_at' => now(),
            'failed_audits' => [['id' => 'image-alt', 'categories' => ['seo'], 'title' => '画像要素に [alt] 属性が指定されていません', 'score' => 0, 'display_value' => null]]]);

        $this->get(route('pagespeed.runs.index'))->assertOk()
            ->assertSee('<h1>PageSpeed Insightsとの同期履歴', false)
            ->assertSee('記事1')
            ->assertSee('携帯')
            ->assertSee('3.2秒')
            ->assertSee('改善が必要')
            ->assertSee('合格しなかった項目（1件）')
            // アクティビティログにも、測定の開始と終了を出す
            ->assertSee(route('pagespeed.runs.index'));

        $this->get(route('activities.index'))->assertOk()
            ->assertSeeInOrder(['PageSpeed Insights の測定を終了：携帯：成功『記事1』（パフォーマンス 72・SEO 92）', 'PageSpeed Insights の測定を開始：携帯『記事1』']);
    }
}
