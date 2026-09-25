<?php

namespace Tests\Feature\Google;

use App\Enums\GoogleService;
use App\Enums\SyncIssueType;
use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Models\Blog;
use App\Models\BlogGoogleProperty;
use App\Models\GoogleAccount;
use App\Models\GoogleFetchRun;
use App\Models\Page;
use App\Models\Post;
use App\Models\SyncIssue;
use App\Models\User;
use App\Repositories\GoogleAccountRepository;
use App\Services\Google\GoogleFetchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Google連携（BLOGOS_DATABASE.md 12章、D-21-01〜D-21-07）。Google APIは偽の応答に置き換える。
 */
class GoogleIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Blog $blog;

    /** Google APIの要求（URL と本文） */
    protected array $requests = [];

    /** AdSenseがページ単位の集計を受け付けるか */
    protected bool $adsensePageSupported = true;

    /** GA4 が失敗を返すか */
    protected bool $ga4Fails = false;

    /** トークンの更新が失敗するか（更新用のトークンの失効） */
    protected bool $tokenFails = false;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_id'     => 'client-id',
            'services.google.client_secret' => 'client-secret',
            'services.google.redirect_uri'  => 'http://localhost:8000/google/oauth/callback',
        ]);

        $this->user = User::factory()->create();
        $this->blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true]);

        Http::fake(fn (Request $request) => $this->respond($request));
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function respond(Request $request)
    {
        $url = $request->url();
        $this->requests[] = ['url' => $url, 'body' => $request->data()];

        if (str_starts_with($url, 'https://oauth2.googleapis.com/token')) {
            if ($this->tokenFails) {
                return Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400);
            }

            return Http::response(['access_token' => 'new-access-token', 'expires_in' => 3600, 'refresh_token' => 'refresh-token', 'scope' => 'openid https://www.googleapis.com/auth/analytics.readonly https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/adsense.readonly https://www.googleapis.com/auth/userinfo.email']);
        }
        if (str_starts_with($url, 'https://openidconnect.googleapis.com/v1/userinfo')) {
            return Http::response(['email' => 'owner@example.com']);
        }
        if (str_contains($url, 'accountSummaries')) {
            return Http::response(['accountSummaries' => [['displayName' => 'アカウント', 'propertySummaries' => [['property' => 'properties/123', 'displayName' => 'si-note']]]]]);
        }
        if (str_ends_with($url, '/webmasters/v3/sites')) {
            return Http::response(['siteEntry' => [['siteUrl' => 'sc-domain:blog.example.test', 'permissionLevel' => 'siteOwner']]]);
        }
        if (str_ends_with($url, 'adsense.googleapis.com/v2/accounts')) {
            return Http::response(['accounts' => [['name' => 'accounts/pub-111', 'displayName' => 'AdSense']]]);
        }
        if (str_contains($url, '/sites?') || str_ends_with($url, 'pub-111/sites')) {
            return Http::response(['sites' => [['domain' => 'blog.example.test']]]);
        }

        // GA4 runReport：日付は期間の始まりの日だけを返す
        if (str_contains($url, ':runReport')) {
            if ($this->ga4Fails) {
                return Http::response(['error' => ['code' => 403, 'message' => 'Google Analytics Data API has not been used']], 403);
            }
            $body = $request->data();
            $date = str_replace('-', '', $body['dateRanges'][0]['startDate']);
            $dimensions = array_column($body['dimensions'], 'name');
            $metrics = array_map(fn () => ['value' => '10'], $body['metrics']);
            $rows = match ($dimensions) {
                ['date'] => [['dimensionValues' => [['value' => $date]], 'metricValues' => $metrics]],
                ['date', 'pagePath'] => [
                    ['dimensionValues' => [['value' => $date], ['value' => '/post-100/']], 'metricValues' => $metrics],
                    ['dimensionValues' => [['value' => $date], ['value' => '/old-slug/']], 'metricValues' => $metrics],
                    ['dimensionValues' => [['value' => $date], ['value' => '/']], 'metricValues' => $metrics],
                ],
                default => [
                    ['dimensionValues' => [['value' => $date], ['value' => '/post-100/'], ['value' => 'Organic Search']], 'metricValues' => $metrics],
                    ['dimensionValues' => [['value' => $date], ['value' => '/post-100/'], ['value' => 'Direct']], 'metricValues' => $metrics],
                ],
            };

            return Http::response(['rows' => $rows, 'rowCount' => count($rows)]);
        }

        // Search Console
        if (str_contains($url, '/searchAnalytics/query')) {
            $body = $request->data();
            $keys = match ($body['dimensions']) {
                ['date']                  => [[$body['startDate']]],
                ['date', 'page']          => [[$body['startDate'], 'https://blog.example.test/post-100/']],
                default                   => [[$body['startDate'], 'https://blog.example.test/post-100/', 'php 入門'], [$body['startDate'], 'https://blog.example.test/post-100/', 'php とは']],
            };

            return Http::response(['rows' => array_map(fn ($k) => ['keys' => $k, 'clicks' => 3, 'impressions' => 100, 'ctr' => 0.03, 'position' => 4.5], $keys)]);
        }

        // AdSense reports:generate
        if (str_contains($url, 'reports:generate')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $date = sprintf('%04d-%02d-%02d', $query['startDate_year'], $query['startDate_month'], $query['startDate_day']);
            $withPage = str_contains($url, 'dimensions=PAGE_URL');
            if ($withPage && ! $this->adsensePageSupported) {
                return Http::response(['error' => ['code' => 400, 'message' => 'Invalid dimension']], 400);
            }
            $headers = array_merge(
                [['name' => 'DATE', 'type' => 'DIMENSION']],
                $withPage ? [['name' => 'PAGE_URL', 'type' => 'DIMENSION']] : [],
                [['name' => 'ESTIMATED_EARNINGS', 'type' => 'METRIC_CURRENCY', 'currencyCode' => 'JPY'], ['name' => 'PAGE_VIEWS'], ['name' => 'IMPRESSIONS'], ['name' => 'CLICKS']]
            );
            $cells = array_merge([['value' => $date]], $withPage ? [['value' => 'https://blog.example.test/post-100/']] : [], [['value' => '12.5'], ['value' => '100'], ['value' => '300'], ['value' => '2']]);

            return Http::response(['headers' => $headers, 'rows' => [['cells' => $cells]]]);
        }

        return Http::response(['error' => ['message' => "unexpected {$url}"]], 404);
    }

    protected function connectAccount(array $attributes = []): GoogleAccount
    {
        return GoogleAccount::create(array_merge([
            'email'            => 'owner@example.com',
            'access_token'     => 'valid-token',
            'refresh_token'    => 'refresh-token',
            'token_expires_at' => now()->addHour(),
        ], $attributes));
    }

    protected function configureAll(GoogleAccount $account): void
    {
        $repository = app(GoogleAccountRepository::class);
        $repository->saveProperty($this->blog->id, GoogleService::Ga4, $account->id, 'properties/123', 'si-note', null);
        $repository->saveProperty($this->blog->id, GoogleService::SearchConsole, $account->id, 'sc-domain:blog.example.test', null, null);
        $repository->saveProperty($this->blog->id, GoogleService::Adsense, $account->id, 'accounts/pub-111', null, 'blog.example.test');
    }

    protected function createArticles(): Post
    {
        $post = Post::create(['blog_id' => $this->blog->id, 'wordpress_id' => 100, 'title_raw' => '記事100', 'status' => 'publish', 'link' => 'https://blog.example.test/post-100/', 'normalized_path' => '/post-100']);
        $page = Page::create(['blog_id' => $this->blog->id, 'wordpress_id' => 50, 'title_raw' => '固定50', 'status' => 'publish', 'link' => 'https://blog.example.test/new-slug/', 'normalized_path' => '/new-slug']);

        // スラッグを変更した記事（過去の link が履歴に残っている）
        DB::table('page_histories')->insert([
            'blog_id' => $this->blog->id, 'page_id' => $page->id, 'change_set_id' => (string) \Illuminate\Support\Str::uuid(),
            'field' => 'link', 'old_value' => 'https://blog.example.test/old-slug/', 'new_value' => 'https://blog.example.test/new-slug/',
            'source' => 'wp_sync', 'changed_at' => now(),
        ]);

        return $post;
    }

    public function test_oauth_flow_stores_encrypted_tokens(): void
    {
        $response = $this->actingAs($this->user)->get(route('google.oauth.redirect'));
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $location);
        $this->assertStringContainsString('access_type=offline', $location);
        $this->assertStringContainsString(urlencode('https://www.googleapis.com/auth/adsense.readonly'), $location);

        parse_str(parse_url($location, PHP_URL_QUERY), $params);

        // state が一致しなければ接続しない
        $this->actingAs($this->user)->get(route('google.oauth.callback', ['state' => 'wrong', 'code' => 'x']))->assertSessionHasErrors('google');
        $this->assertSame(0, GoogleAccount::count());

        $response = $this->actingAs($this->user)->get('/google/oauth/redirect');
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $params);

        // 試作のときに登録したリダイレクトURIでも受け付ける
        $this->actingAs($this->user)->get('/adsense/oauth/callback?' . http_build_query(['state' => $params['state'], 'code' => 'auth-code']))
            ->assertRedirect(route('google.settings'));

        $account = GoogleAccount::sole();
        $this->assertSame('owner@example.com', $account->email);
        $this->assertSame('refresh-token', $account->refresh_token);
        $this->assertNotSame('refresh-token', DB::table('google_accounts')->value('refresh_token'));
        $this->assertArrayNotHasKey('refresh_token', $account->toArray());
    }

    public function test_expired_token_is_refreshed(): void
    {
        $account = $this->connectAccount(['token_expires_at' => now()->subMinute(), 'access_token' => 'old']);
        $this->configureAll($account);
        $this->createArticles();

        app(GoogleFetchService::class)->run($this->blog, SyncTrigger::Manual, null, [GoogleService::Ga4], Carbon::parse('2026-09-10'), Carbon::parse('2026-09-10'));

        $this->assertSame('new-access-token', $account->fresh()->access_token);
    }

    public function test_fetch_stores_metrics_and_resolves_articles(): void
    {
        $this->configureAll($this->connectAccount());
        $post = $this->createArticles();

        $results = app(GoogleFetchService::class)->run($this->blog, SyncTrigger::Manual, null, null, Carbon::parse('2026-09-10'), Carbon::parse('2026-09-10'));

        $this->assertSame(SyncStatus::Succeeded, $results['ga4']['status']);
        $this->assertSame(SyncStatus::Succeeded, $results['search_console']['status']);
        $this->assertSame(SyncStatus::Succeeded, $results['adsense']['status']);

        // GA4：現在のパスと、過去の link（履歴）で記事に対応付ける。トップページは対応しない
        $this->assertSame($post->id, DB::table('google_analytics_page_daily')->where('page_path', '/post-100/')->value('post_id'));
        $this->assertSame(Page::sole()->id, DB::table('google_analytics_page_daily')->where('page_path', '/old-slug/')->value('page_id'));
        $this->assertNull(DB::table('google_analytics_page_daily')->where('page_path', '/')->value('post_id'));
        $this->assertSame(2, DB::table('google_analytics_page_channel_daily')->where('post_id', $post->id)->count());
        $this->assertSame(10, (int) DB::table('google_analytics_site_daily')->value('new_users'));

        // Search Console：ページ×日・ページ×クエリ×日
        $this->assertSame($post->id, DB::table('google_search_console_page_daily')->value('post_id'));
        $this->assertSame(2, DB::table('google_search_console_query_daily')->where('post_id', $post->id)->count());

        // AdSense：ドメインで絞り込み、ページ単位も保存する
        $this->assertSame('12.5000', DB::table('google_adsense_site_daily')->value('estimated_earnings'));
        $this->assertSame('JPY', DB::table('google_adsense_site_daily')->value('currency_code'));
        $this->assertSame($post->id, DB::table('google_adsense_page_daily')->value('post_id'));
        $adsenseUrl = collect($this->requests)->first(fn ($r) => str_contains($r['url'], 'reports:generate'))['url'];
        $this->assertStringContainsString('filters=' . rawurlencode('DOMAIN_NAME==blog.example.test'), $adsenseUrl);

        // 同じ期間を取り直しても、行は増えない（置き換える）
        app(GoogleFetchService::class)->run($this->blog, SyncTrigger::Manual, null, null, Carbon::parse('2026-09-10'), Carbon::parse('2026-09-10'));
        $this->assertSame(3, DB::table('google_analytics_page_daily')->count());
        $this->assertSame(2, DB::table('google_search_console_query_daily')->count());
    }

    public function test_initial_range_and_refetch_range(): void
    {
        $this->configureAll($this->connectAccount());

        // 初めての取得：16か月前から昨日まで（31日ごとに分けて取得する）
        app(GoogleFetchService::class)->run($this->blog, SyncTrigger::Initial, null, [GoogleService::SearchConsole]);
        $run = GoogleFetchRun::sole();
        $this->assertSame('2025-05-14', $run->date_from->toDateString());
        $this->assertSame('2026-09-14', $run->date_to->toDateString());
        $windows = collect($this->requests)->filter(fn ($r) => str_contains($r['url'], 'searchAnalytics') && $r['body']['dimensions'] === ['date'])->count();
        $this->assertSame(16, $windows);

        // 2回目：直近の4日を取り直す（偽の応答は期間の始まりの日だけを返すため、昨日までの行がある状態にする）
        DB::table('google_search_console_site_daily')->insert(['blog_id' => $this->blog->id, 'date' => '2026-09-14', 'clicks' => 1, 'impressions' => 1, 'ctr' => 1, 'position' => 1]);
        Carbon::setTestNow(Carbon::parse('2026-09-16 12:00:00', 'UTC'));
        app(GoogleFetchService::class)->run($this->blog, SyncTrigger::Scheduled, null, [GoogleService::SearchConsole]);
        $run = GoogleFetchRun::latest('id')->first();
        $this->assertSame('2026-09-12', $run->date_from->toDateString());
        $this->assertSame('2026-09-15', $run->date_to->toDateString());
    }

    public function test_adsense_without_page_dimension_keeps_site_data(): void
    {
        $this->adsensePageSupported = false;
        $this->configureAll($this->connectAccount());

        $results = app(GoogleFetchService::class)->run($this->blog, SyncTrigger::Manual, null, [GoogleService::Adsense], Carbon::parse('2026-09-10'), Carbon::parse('2026-09-10'));

        $this->assertSame(SyncStatus::Succeeded, $results['adsense']['status']);
        $this->assertStringContainsString('ページ単位', $results['adsense']['message']);
        $this->assertSame(1, DB::table('google_adsense_site_daily')->count());
        $this->assertSame(0, DB::table('google_adsense_page_daily')->count());
    }

    public function test_failure_is_recorded_and_other_services_continue(): void
    {
        $this->ga4Fails = true;
        $this->configureAll($this->connectAccount());

        $results = app(GoogleFetchService::class)->run($this->blog, SyncTrigger::Manual, null, null, Carbon::parse('2026-09-10'), Carbon::parse('2026-09-10'));

        $this->assertSame(SyncStatus::Failed, $results['ga4']['status']);
        $this->assertSame(SyncStatus::Succeeded, $results['search_console']['status']);

        $issue = SyncIssue::where('issue_type', SyncIssueType::FetchError)->sole();
        $this->assertSame('google_ga4', $issue->resource_type);
        $this->assertSame(403, $issue->error_status);
        $this->assertStringContainsString('has not been used', $issue->error_body);
    }

    public function test_invalid_refresh_token_marks_account(): void
    {
        $this->tokenFails = true;
        $account = $this->connectAccount(['token_expires_at' => now()->subMinute()]);
        $this->configureAll($account);

        $results = app(GoogleFetchService::class)->run($this->blog, SyncTrigger::Manual, null, [GoogleService::Ga4], Carbon::parse('2026-09-10'), Carbon::parse('2026-09-10'));

        $this->assertSame(SyncStatus::Failed, $results['ga4']['status']);
        $this->assertStringContainsString('接続し直してください', $account->fresh()->last_error);
    }

    public function test_settings_screen_candidates_and_save(): void
    {
        $account = $this->connectAccount();

        $this->actingAs($this->user)->get(route('google.settings'))->assertOk()->assertSee('owner@example.com')->assertDontSee('valid-token');
        $this->actingAs($this->user)->get(route('google.settings', ['candidates' => $account->id]))
            ->assertOk()
            ->assertSee('properties/123')
            ->assertSee('sc-domain:blog.example.test')
            ->assertSee('accounts/pub-111');

        $this->actingAs($this->user)->put(route('google.properties.update', ['service' => 'ga4']), [
            'selected_blog_id' => $this->blog->id, 'google_account_id' => $account->id, 'resource_name' => '123',
        ])->assertSessionHasErrors('resource_name');

        $this->actingAs($this->user)->put(route('google.properties.update', ['service' => 'ga4']), [
            'selected_blog_id' => $this->blog->id, 'google_account_id' => $account->id, 'resource_name' => 'properties/123', 'display_name' => 'si-note',
        ])->assertSessionHasNoErrors();
        $this->assertSame('properties/123', BlogGoogleProperty::sole()->resource_name);

        // 接続の解除で、対応先の設定も解除する
        $this->actingAs($this->user)->delete(route('google.accounts.destroy', ['id' => $account->id]), ['selected_blog_id' => $this->blog->id])
            ->assertRedirect(route('google.settings'));
        $this->assertSame(0, GoogleAccount::count());
        $this->assertSame(0, BlogGoogleProperty::count());
    }

    public function test_analytics_and_article_screens_show_metrics(): void
    {
        $this->configureAll($this->connectAccount());
        $post = $this->createArticles();
        app(GoogleFetchService::class)->run($this->blog, SyncTrigger::Manual, null, null, Carbon::parse('2026-09-10'), Carbon::parse('2026-09-10'));

        $this->actingAs($this->user)->get(route('analytics.index'))->assertOk()->assertSee('記事100')->assertSee('12.50 JPY');
        $this->actingAs($this->user)->get(route('analytics.index', ['days' => 7, 'sort' => 'earnings']))->assertOk();
        $this->actingAs($this->user)->get(route('articles.show', ['type' => 'posts', 'id' => $post->id]))
            ->assertOk()
            ->assertSee('php 入門')
            ->assertSee('Organic Search');
    }

    public function test_manual_fetch_is_queued(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $this->configureAll($this->connectAccount());

        $this->actingAs($this->user)->post(route('google.fetch'), ['selected_blog_id' => $this->blog->id])->assertRedirect(route('google.settings'));

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\GoogleFetchJob::class, fn ($job) => $job->blogId === $this->blog->id && $job->trigger === SyncTrigger::Manual);
    }
}
