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

    /** AdSenseが受け付けない分け方（例：[['TRAFFIC_SOURCE_NAME']]） */
    protected array $adsenseUnsupported = [];

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

        // AdSense の支払い（残高と、前回の支払い。D-67）
        if (str_ends_with($url, 'pub-111/payments')) {
            return Http::response(['payments' => [
                ['name' => 'accounts/pub-111/payments/unpaid', 'amount' => '¥602'],
                ['name' => 'accounts/pub-111/payments/2026-08-21', 'amount' => '¥8,040', 'date' => ['year' => 2026, 'month' => 8, 'day' => 21]],
                ['name' => 'accounts/pub-111/payments/2026-07-21', 'amount' => '¥8,500', 'date' => ['year' => 2026, 'month' => 7, 'day' => 21]],
            ]]);
        }

        // AdSense reports:generate
        if (str_contains($url, 'reports:generate')) {
            preg_match_all('/metrics=([A-Z_]+)/', $url, $metricNames);
            $metricHeaders = array_map(fn ($name) => $name === 'ESTIMATED_EARNINGS' ? ['name' => $name, 'type' => 'METRIC_CURRENCY', 'currencyCode' => 'JPY'] : ['name' => $name], $metricNames[1]);
            $metricValues = ['ESTIMATED_EARNINGS' => '12.5', 'PAGE_VIEWS' => '100', 'IMPRESSIONS' => '300', 'CLICKS' => '2'];
            $metricCells = array_map(fn ($name) => ['value' => $metricValues[$name]], $metricNames[1]);

            // 本日（期間の指定なしの合計）
            if (str_contains($url, 'dateRange=TODAY')) {
                return Http::response(['headers' => $metricHeaders, 'totals' => ['cells' => array_map(fn ($name) => ['value' => $name === 'ESTIMATED_EARNINGS' ? '3' : '10'], $metricNames[1])]]);
            }

            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $date = sprintf('%04d-%02d-%02d', $query['startDate_year'], $query['startDate_month'], $query['startDate_day']);
            preg_match_all('/dimensions=([A-Z_]+)/', $url, $dimensionNames);
            $extra = array_values(array_diff($dimensionNames[1], ['DATE']));
            if (($extra === ['PAGE_URL'] && ! $this->adsensePageSupported) || in_array($extra, $this->adsenseUnsupported, true)) {
                return Http::response(['error' => ['code' => 400, 'message' => 'Invalid dimension']], 400);
            }
            $extraValues = ['PAGE_URL' => 'https://blog.example.test/post-100/', 'AD_UNIT_NAME' => 'rectangle-top', 'COUNTRY_NAME' => '日本', 'BID_TYPE_NAME' => 'CPM', 'TRAFFIC_SOURCE_NAME' => 'Google'];
            $headers = array_merge(
                [['name' => 'DATE', 'type' => 'DIMENSION']],
                array_map(fn ($name) => ['name' => $name, 'type' => 'DIMENSION'], $extra),
                $metricHeaders
            );
            $cells = array_merge([['value' => $date]], array_map(fn ($name) => ['value' => $extraValues[$name]], $extra), $metricCells);

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

        // 接続を始めた画面（ここでは分析の画面）に戻り、アカウントのポップアップを開く（D-63-19）
        $response = $this->actingAs($this->user)->from(route('analytics.index'))->get('/google/oauth/redirect');
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $params);

        // 試作のときに登録したリダイレクトURIでも受け付ける
        $this->actingAs($this->user)->get('/adsense/oauth/callback?' . http_build_query(['state' => $params['state'], 'code' => 'auth-code']))
            ->assertRedirect(route('analytics.index'))
            ->assertSessionHas('google_modal', 'google-account-modal');

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

    public function test_adsense_dimensions_are_stored_and_shown_with_today_and_balance(): void
    {
        // トラフィックソースは受け付けない場合も、ほかの分け方は保存する
        $this->adsenseUnsupported = [['TRAFFIC_SOURCE_NAME']];
        $this->configureAll($this->connectAccount());

        $results = app(GoogleFetchService::class)->run($this->blog, SyncTrigger::Manual, null, [GoogleService::Adsense], Carbon::parse('2026-09-14'), Carbon::parse('2026-09-14'));

        // 広告ユニット・国・入札方法ごと×日（直近28日を取り直す。偽の応答は期間の始まりの日だけを返す）
        $this->assertSame(SyncStatus::Succeeded, $results['adsense']['status']);
        $this->assertStringContainsString('TRAFFIC_SOURCE_NAME', $results['adsense']['message']);
        $this->assertSame(['ad_unit', 'bid_type', 'country'], DB::table('google_adsense_dimension_daily')->orderBy('dimension')->pluck('dimension')->all());
        $this->assertSame('2026-08-18', DB::table('google_adsense_dimension_daily')->value('date'));
        $this->assertSame('rectangle-top', DB::table('google_adsense_dimension_daily')->where('dimension', 'ad_unit')->value('value'));

        // 画面：過去7日間（9/8〜9/14）・昨日（9/14）の数値、本日（問い合わせ）、残高と前回の支払い
        DB::table('google_adsense_site_daily')->insert([
            ['blog_id' => $this->blog->id, 'date' => '2026-09-07', 'currency_code' => 'JPY', 'estimated_earnings' => 5, 'page_views' => 50, 'impressions' => 100, 'clicks' => 0],
            ['blog_id' => $this->blog->id, 'date' => '2026-09-01', 'currency_code' => 'JPY', 'estimated_earnings' => 8, 'page_views' => 80, 'impressions' => 200, 'clicks' => 1],
        ]);
        DB::table('google_adsense_dimension_daily')->insert(['blog_id' => $this->blog->id, 'date' => '2026-09-14', 'dimension' => 'ad_unit', 'value' => 'rectangle-middle', 'currency_code' => 'JPY', 'estimated_earnings' => 4, 'impressions' => 40, 'clicks' => 0]);

        $html = $this->actingAs($this->user)->get(route('analytics.adsense'))->assertOk()
            ->assertSee('推定収益額')
            ->assertSee('¥3')                 // 本日
            ->assertSee('¥602')               // 残高
            ->assertSee('前回の支払い：¥8,040（2026-08-21）')
            ->assertSee('rectangle-middle')
            ->assertSee('ページのインプレッション収益')
            ->getContent();
        // 昨日（9/14）¥12.5 と、先週の同じ曜日（9/7）¥5 の比較：+¥7.5（+150%）
        $this->assertStringContainsString('▲ +¥8（+150%）', $html);
        // 過去7日間（9/8〜9/14）¥12.5 と、その前の7日間（9/1〜9/7）¥13：-¥0.5（-4%）
        $this->assertStringContainsString('▼ -¥1（-4%）', $html);

        // 本日・残高は30分使い回す（2回目は問い合わせない）
        $before = collect($this->requests)->filter(fn ($r) => str_ends_with($r['url'], '/payments'))->count();
        $this->actingAs($this->user)->get(route('analytics.adsense'))->assertOk();
        $this->assertSame($before, collect($this->requests)->filter(fn ($r) => str_ends_with($r['url'], '/payments'))->count());
    }

    public function test_adsense_screen_without_connection(): void
    {
        $this->actingAs($this->user)->get(route('analytics.adsense'))->assertOk()->assertSee('AdSense がつながっていません');
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

        // メニューの「設定 → Google」の下に、アカウント・GA4・Search Console・AdSense のポップアップ（D-63-19）
        $html = $this->actingAs($this->user)->get(route('scheduled-tasks.runs'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<summary>ブログ<\/summary>.*?<summary>Google<\/summary>.*?data-modal-open="google-account-modal" >アカウント<\/a>.*?data-modal-open="google-ga4-modal" >Google Analytics 4<\/a>.*?data-modal-open="google-search-console-modal" >Search Console<\/a>.*?data-modal-open="google-adsense-modal" >AdSense<\/a>.*?<summary>OpenAI<\/summary>/s', $html);
        $this->assertStringContainsString('owner@example.com', $html);
        $this->assertStringNotContainsString('valid-token', $html);
        foreach (['google-account-modal', 'google-ga4-modal', 'google-search-console-modal', 'google-adsense-modal'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $html);
        }

        // 候補は、ポップアップの「候補を読み込む」で、サービスごとに読む
        $this->actingAs($this->user)->getJson(route('google.candidates', ['service' => 'ga4', 'account' => $account->id]))
            ->assertOk()->assertJsonPath('items.0.resource_name', 'properties/123')->assertJsonPath('error', null);
        $this->actingAs($this->user)->getJson(route('google.candidates', ['service' => 'search_console', 'account' => $account->id]))
            ->assertOk()->assertJsonFragment(['resource_name' => 'sc-domain:blog.example.test']);
        $this->actingAs($this->user)->getJson(route('google.candidates', ['service' => 'adsense', 'account' => $account->id]))
            ->assertOk()->assertJsonPath('items.0.resource_name', 'accounts/pub-111')->assertJsonPath('items.0.domain', 'blog.example.test');

        // 入力の誤りは、開いていた画面に戻り、ポップアップを開いて誤りを出す
        $this->actingAs($this->user)->from(route('analytics.index'))->put(route('google.properties.update', ['service' => 'ga4']), [
            'selected_blog_id' => $this->blog->id, '_form' => 'google-ga4-modal', 'google_account_id' => $account->id, 'resource_name' => '123',
        ])->assertRedirect(route('analytics.index'))->assertSessionHasErrors('resource_name');
        $this->assertMatchesRegularExpression('/id="google-ga4-modal"\s+class="[^"]*"\s+data-modal-autoopen/', $this->actingAs($this->user)->get(route('analytics.index'))->getContent());

        $this->actingAs($this->user)->from(route('analytics.index'))->put(route('google.properties.update', ['service' => 'ga4']), [
            'selected_blog_id' => $this->blog->id, 'google_account_id' => $account->id, 'resource_name' => 'properties/123', 'display_name' => 'si-note',
        ])->assertSessionHasNoErrors()->assertRedirect(route('analytics.index'))->assertSessionHas('google_modal', 'google-ga4-modal');
        $this->assertSame('properties/123', BlogGoogleProperty::sole()->resource_name);
        $after = $this->actingAs($this->user)->get(route('analytics.index'))->getContent();
        $this->assertMatchesRegularExpression('/id="google-ga4-modal"\s+class="[^"]*"\s+data-modal-autoopen.*?Google Analytics 4の対応先を保存しました/s', $after);

        // 接続の解除で、対応先の設定も解除する（ブログを選んでいなくてもできる）
        $this->actingAs($this->user)->from(route('scheduled-tasks.runs'))->delete(route('google.accounts.destroy', ['id' => $account->id]))
            ->assertRedirect(route('scheduled-tasks.runs'))->assertSessionHas('google_modal', 'google-account-modal');
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

    public function test_fetch_from_run_now_is_recorded_as_manual(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $this->configureAll($this->connectAccount());

        // 取得の欄はない（メニューの「設定 → 即時実行 → Googleとの同期」で取得する。D-63-18）。Googleとの同期履歴は、取得の記録と行数だけ（D-63-19）
        $this->actingAs($this->user)->get(route('google.fetch-runs.index'))->assertOk()
            ->assertSee('<h1>Googleとの同期履歴 <span class="tip"', false)->assertSee('取得の記録')->assertDontSee('今すぐ取得する')->assertDontSee('このブログの対応先');
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('google.fetch'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('google.settings'));

        // メニューの即時実行は、取得の記録の契機が手動（送った人も残す）。定期実行は定期
        $recorder = app(\App\Services\Schedule\ScheduledTaskService::class);
        $recorder->run('google:fetch', 'manual', $this->user->id);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\GoogleFetchJob::class, fn ($job) => $job->blogId === $this->blog->id && $job->trigger === SyncTrigger::Manual && $job->userId === $this->user->id);
        $recorder->run('google:fetch', 'scheduled');
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\GoogleFetchJob::class, fn ($job) => $job->trigger === SyncTrigger::Scheduled && $job->userId === null);

        // 設定のポップアップの説明に、取得の範囲を出す
        $this->actingAs($this->user)->get(route('home'))->assertSee('直近の数日は毎回取得し直します。初めての取得では、約16か月前から取得します。');
    }

    public function test_sync_from_run_now_is_recorded_as_manual(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $recorder = app(\App\Services\Schedule\ScheduledTaskService::class);
        $recorder->run('blogs:sync', 'manual', $this->user->id);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SyncBlogJob::class, fn ($job) => $job->blogId === $this->blog->id && $job->trigger === SyncTrigger::Manual && $job->userId === $this->user->id);
        $recorder->run('blogs:sync', 'scheduled');
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SyncBlogJob::class, fn ($job) => $job->trigger === SyncTrigger::Scheduled);
    }
}
