<?php

namespace App\Services\Google;

use App\Clients\Google\GoogleApiClient;
use App\Enums\GoogleService;
use App\Models\Blog;
use App\Models\BlogGoogleProperty;
use App\Repositories\GoogleAccountRepository;
use App\Services\Google\Fetchers\AdsenseFetcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 画面「AdSense」のカードの数値（D-67）。AdSense の画面のホームと同じ見方にする。
 *
 * ・本日の数値と、残高・前回の支払い：画面を開いたときに AdSense API に問い合わせる（結果は LIVE_MINUTES 分、使い回す）
 * ・それ以外（昨日・過去7日間・今月の昨日まで・前年・パフォーマンス・広告ユニットなど）：毎日の同期で保存した数値
 *   （google_adsense_site_daily・google_adsense_dimension_daily）から計算する
 * 日付は、表示用のタイムゾーン（日本時間）。過去7日間は、AdSense と同じく昨日までの7日間
 */
class AdsenseReportService
{
    /**
     * 本日の数値と残高を使い回す時間（分）
     */
    public const LIVE_MINUTES = 30;

    public function __construct(
        protected GoogleAccountRepository $accounts,
        protected GoogleTokenService $tokens,
    ) {
    }

    public function property(Blog $blog): ?BlogGoogleProperty
    {
        return $this->accounts->propertiesForBlog($blog->id)->get(GoogleService::Adsense->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(Blog $blog, BlogGoogleProperty $property, bool $refresh = false): array
    {
        $today = Carbon::now(config('blogos.display_timezone'))->startOfDay();
        $yesterday = $today->copy()->subDay();
        $weekFrom = $yesterday->copy()->subDays(6);
        $previousWeekFrom = $weekFrom->copy()->subDays(7);
        $previousWeekTo = $weekFrom->copy()->subDay();
        $monthFrom = $today->copy()->startOfMonth();

        $live = $this->live($property, $refresh);
        $todayValues = $live['today'] ?? null;

        // 今月：昨日までの保存した数値 ＋ 本日（問い合わせ）。前年同期：前年の同じ月の1日から、同じ日まで
        $monthUntilYesterday = $monthFrom->lessThan($today) ? $this->siteTotals($blog, $monthFrom, $yesterday) : $this->emptyTotals();
        $lastYearFrom = $monthFrom->copy()->subYear();
        $lastYearTo = $today->copy()->subYear();

        $week = $this->siteTotals($blog, $weekFrom, $yesterday);
        $previousWeek = $this->siteTotals($blog, $previousWeekFrom, $previousWeekTo);

        return [
            'currency'    => $this->currency($blog) ?? ($todayValues['currency'] ?? null),
            'lastDate'    => DB::table('google_adsense_site_daily')->where('blog_id', $blog->id)->max('date'),
            'live'        => $live,
            'earnings'    => [
                'today'     => $todayValues['estimated_earnings'] ?? null,
                'yesterday' => ['value' => $this->siteTotals($blog, $yesterday, $yesterday)['estimated_earnings'], 'compare' => $this->siteTotals($blog, $yesterday->copy()->subDays(7), $yesterday->copy()->subDays(7))['estimated_earnings'], 'label' => '先週の同じ曜日との比較'],
                'week'      => ['value' => $week['estimated_earnings'], 'compare' => $previousWeek['estimated_earnings'], 'label' => '前の7日間との比較'],
                'month'     => [
                    'value'   => $monthUntilYesterday['estimated_earnings'] + (float) ($todayValues['estimated_earnings'] ?? 0),
                    'compare' => $this->hasData($blog, $lastYearFrom) ? $this->siteTotals($blog, $lastYearFrom, $lastYearTo)['estimated_earnings'] : null,
                    'label'   => '前年同期との比較',
                ],
            ],
            'performance' => [
                'week'     => $this->performance($week),
                'previous' => $this->performance($previousWeek),
                'daily'    => $this->dailySeries($blog, $weekFrom, $yesterday),
            ],
            'dimensions'  => collect(AdsenseFetcher::DIMENSIONS)->mapWithKeys(fn ($dimension) => [
                $dimension => $this->dimensionRows($blog, $dimension, $weekFrom, $yesterday, $previousWeekFrom, $previousWeekTo),
            ])->all(),
            'period'      => ['weekFrom' => $weekFrom, 'weekTo' => $yesterday],
        ];
    }

    /**
     * 本日の数値と、残高・前回の支払い（AdSense API に問い合わせる。失敗しても画面は出す）
     *
     * @return array{today: array<string, mixed>|null, balance: string|null, lastPayment: array{amount: string, date: string|null}|null, fetchedAt: string, error: string|null}
     */
    public function live(BlogGoogleProperty $property, bool $refresh = false): array
    {
        $key = "blogos:adsense:live:{$property->id}";
        if ($refresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, now()->addMinutes(self::LIVE_MINUTES), function () use ($property) {
            $result = ['today' => null, 'balance' => null, 'lastPayment' => null, 'fetchedAt' => now()->toIso8601String(), 'error' => null];

            try {
                $client = $this->tokens->clientFor($property->account);
                $result['today'] = $this->today($client, $property);
                [$result['balance'], $result['lastPayment']] = $this->payments($client, $property);
            } catch (Throwable $e) {
                Log::warning('AdSense：本日の数値・残高を取得できませんでした。', ['property_id' => $property->id, 'message' => $e->getMessage()]);
                $result['error'] = $e->getMessage();
            }

            return $result;
        });
    }

    /**
     * 本日の合計（推定収益額・ページビュー・表示回数・クリック数）
     *
     * @return array<string, mixed>
     */
    protected function today(GoogleApiClient $client, BlogGoogleProperty $property): array
    {
        $params = ['dateRange=TODAY', 'reportingTimeZone=ACCOUNT_TIME_ZONE', 'metrics=ESTIMATED_EARNINGS', 'metrics=PAGE_VIEWS', 'metrics=IMPRESSIONS', 'metrics=CLICKS'];
        if (filled($property->adsense_domain)) {
            $params[] = 'filters=' . rawurlencode(AdsenseFetcher::DOMAIN_DIMENSION . '==' . $property->adsense_domain);
        }
        $data = $client->get(GoogleApiClient::ADSENSE . "/{$property->resource_name}/reports:generate", implode('&', $params));

        $columns = ['ESTIMATED_EARNINGS' => 'estimated_earnings', 'PAGE_VIEWS' => 'page_views', 'IMPRESSIONS' => 'impressions', 'CLICKS' => 'clicks'];
        $cells = $data['totals']['cells'] ?? $data['rows'][0]['cells'] ?? [];
        $values = ['currency' => collect($data['headers'] ?? [])->firstWhere('name', 'ESTIMATED_EARNINGS')['currencyCode'] ?? null];
        foreach ($data['headers'] ?? [] as $i => $header) {
            if (isset($columns[$header['name'] ?? ''])) {
                $column = $columns[$header['name']];
                $values[$column] = $column === 'estimated_earnings' ? (float) ($cells[$i]['value'] ?? 0) : (int) ($cells[$i]['value'] ?? 0);
            }
        }

        return $values + ['estimated_earnings' => 0.0, 'page_views' => 0, 'impressions' => 0, 'clicks' => 0];
    }

    /**
     * 残高（まだ支払われていない収益）と、前回の支払い。AdSense のアカウント全体の値（ドメインで分けられない）
     *
     * @return array{0: string|null, 1: array{amount: string, date: string|null}|null}
     */
    protected function payments(GoogleApiClient $client, BlogGoogleProperty $property): array
    {
        $balance = null;
        $last = null;
        foreach ($client->get(GoogleApiClient::ADSENSE . "/{$property->resource_name}/payments")['payments'] ?? [] as $payment) {
            if (str_ends_with((string) ($payment['name'] ?? ''), '/unpaid')) {
                $balance = (string) ($payment['amount'] ?? '');

                continue;
            }
            $date = isset($payment['date']['year']) ? sprintf('%04d-%02d-%02d', $payment['date']['year'], $payment['date']['month'], $payment['date']['day']) : null;
            if ($last === null || ($date !== null && $date > ($last['date'] ?? ''))) {
                $last = ['amount' => (string) ($payment['amount'] ?? ''), 'date' => $date];
            }
        }

        return [$balance, $last];
    }

    /**
     * @return array{estimated_earnings: float, page_views: int, impressions: int, clicks: int}
     */
    protected function siteTotals(Blog $blog, Carbon $from, Carbon $to): array
    {
        $row = DB::table('google_adsense_site_daily')->where('blog_id', $blog->id)->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('SUM(estimated_earnings) as estimated_earnings, SUM(page_views) as page_views, SUM(impressions) as impressions, SUM(clicks) as clicks')
            ->first();

        return [
            'estimated_earnings' => (float) ($row->estimated_earnings ?? 0),
            'page_views'         => (int) ($row->page_views ?? 0),
            'impressions'        => (int) ($row->impressions ?? 0),
            'clicks'             => (int) ($row->clicks ?? 0),
        ];
    }

    /**
     * @return array{estimated_earnings: float, page_views: int, impressions: int, clicks: int}
     */
    protected function emptyTotals(): array
    {
        return ['estimated_earnings' => 0.0, 'page_views' => 0, 'impressions' => 0, 'clicks' => 0];
    }

    /**
     * その日より前の数値を保存しているか（前年同期と比べられるか）
     */
    protected function hasData(Blog $blog, Carbon $date): bool
    {
        return DB::table('google_adsense_site_daily')->where('blog_id', $blog->id)->where('date', '<=', $date->toDateString())->exists();
    }

    protected function currency(Blog $blog): ?string
    {
        return DB::table('google_adsense_site_daily')->where('blog_id', $blog->id)->whereNotNull('currency_code')->orderByDesc('date')->value('currency_code');
    }

    /**
     * パフォーマンス（AdSense の画面と同じ指標。ページのインプレッション収益＝1,000ページビューあたりの収益）
     *
     * @param array{estimated_earnings: float, page_views: int, impressions: int, clicks: int} $totals
     * @return array<string, float|int|null>
     */
    public function performance(array $totals): array
    {
        return [
            'page_views'  => $totals['page_views'],
            'page_rpm'    => $totals['page_views'] > 0 ? $totals['estimated_earnings'] / $totals['page_views'] * 1000 : null,
            'impressions' => $totals['impressions'],
            'clicks'      => $totals['clicks'],
            'cpc'         => $totals['clicks'] > 0 ? $totals['estimated_earnings'] / $totals['clicks'] : null,
            'page_ctr'    => $totals['page_views'] > 0 ? $totals['clicks'] / $totals['page_views'] * 100 : null,
        ];
    }

    /**
     * 日ごとのパフォーマンス（カードの小さな線のグラフ。保存していない日は0）
     *
     * @return list<array<string, mixed>>
     */
    protected function dailySeries(Blog $blog, Carbon $from, Carbon $to): array
    {
        $rows = DB::table('google_adsense_site_daily')->where('blog_id', $blog->id)->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get()->keyBy('date');

        $series = [];
        for ($date = $from->copy(); $date->lessThanOrEqualTo($to); $date->addDay()) {
            $row = $rows->get($date->toDateString());
            $series[] = ['date' => $date->toDateString()] + $this->performance([
                'estimated_earnings' => (float) ($row->estimated_earnings ?? 0),
                'page_views'         => (int) ($row->page_views ?? 0),
                'impressions'        => (int) ($row->impressions ?? 0),
                'clicks'             => (int) ($row->clicks ?? 0),
            ]);
        }

        return $series;
    }

    /**
     * 広告ユニットなどごとの、過去7日間と、その前の7日間（推定収益額の多い順）
     *
     * @return list<array{value: string, estimated_earnings: float, previous: float, impressions: int, clicks: int, share: float}>
     */
    protected function dimensionRows(Blog $blog, string $dimension, Carbon $from, Carbon $to, Carbon $previousFrom, Carbon $previousTo): array
    {
        $sum = fn (Carbon $start, Carbon $end) => DB::table('google_adsense_dimension_daily')->where('blog_id', $blog->id)->where('dimension', $dimension)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('value')
            ->selectRaw('value, SUM(estimated_earnings) as estimated_earnings, SUM(impressions) as impressions, SUM(clicks) as clicks')
            ->get()->keyBy('value');

        $current = $sum($from, $to);
        $previous = $sum($previousFrom, $previousTo);
        $total = (float) $current->sum('estimated_earnings');

        return $current->map(fn ($row) => [
            'value'              => (string) $row->value,
            'estimated_earnings' => (float) $row->estimated_earnings,
            'previous'           => (float) ($previous->get($row->value)->estimated_earnings ?? 0),
            'impressions'        => (int) $row->impressions,
            'clicks'             => (int) $row->clicks,
            'share'              => $total > 0 ? (float) $row->estimated_earnings / $total * 100 : 0.0,
        ])->sortByDesc('estimated_earnings')->values()->all();
    }
}
