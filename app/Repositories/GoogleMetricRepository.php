<?php

namespace App\Repositories;

use App\Support\ArticlePath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Googleの指標の表（BLOGOS_DATABASE.md 12-2）。
 *
 * Googleの数値は数日のあいだ更新されるため、取得した期間の行を置き換える。
 * ページ単位の行は、正規化したパスで記事（投稿・固定ページ）と対応付ける。過去のURL（履歴の link）でも照合する（D-08-05）。
 */
class GoogleMetricRepository
{
    public function __construct(
        protected ArticlePathRepository $paths,
    ) {
    }

    /**
     * ページ単位の表と、URL（またはパス）の列
     */
    public const PAGE_TABLES = [
        'google_analytics_page_daily'         => 'page_path',
        'google_analytics_page_channel_daily' => 'page_path',
        'google_search_console_page_daily'    => 'page_url',
        'google_search_console_query_daily'   => 'page_url',
        'google_adsense_page_daily'           => 'page_url',
    ];

    /**
     * 期間の行を置き換える
     *
     * @param array<int, array<string, mixed>> $rows 列の値（blog_id・日時の列は含めない）
     * @return int 保存した行数
     */
    public function replaceRange(string $table, int $blogId, Carbon $from, Carbon $to, array $rows): int
    {
        $now = now();
        $urlColumn = self::PAGE_TABLES[$table] ?? null;

        $rows = array_map(function (array $row) use ($blogId, $now, $urlColumn) {
            if ($urlColumn !== null) {
                $row["{$urlColumn}_hash"] = sha1((string) $row[$urlColumn]);
                $row['normalized_path'] = ArticlePath::fromUrl((string) $row[$urlColumn]);
            }
            if (array_key_exists('query', $row)) {
                $row['query_hash'] = sha1((string) $row['query']);
            }

            return $row + ['blog_id' => $blogId, 'fetched_at' => $now, 'created_at' => $now, 'updated_at' => $now];
        }, $rows);

        DB::transaction(function () use ($table, $blogId, $from, $to, $rows) {
            DB::table($table)
                ->where('blog_id', $blogId)
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        });

        return count($rows);
    }

    /**
     * 保存している最後の日（まだ取得していなければ NULL）
     */
    public function lastDate(string $table, int $blogId): ?Carbon
    {
        $date = DB::table($table)->where('blog_id', $blogId)->max('date');

        return $date === null ? null : Carbon::parse($date);
    }

    /**
     * 記事が未解決の行を照合する（照合の規則は ArticlePathRepository）
     *
     * @return int 解決できた行数
     */
    public function resolveArticles(int $blogId, string $table): int
    {
        $paths = DB::table($table)
            ->where('blog_id', $blogId)
            ->whereNull('post_id')
            ->whereNull('page_id')
            ->whereNotNull('normalized_path')
            ->distinct()
            ->pluck('normalized_path');

        if ($paths->isEmpty()) {
            return 0;
        }

        $resolved = 0;

        foreach ($paths as $path) {
            $match = $this->paths->find($blogId, $path);
            if ($match === null) {
                continue;
            }

            $resolved += DB::table($table)
                ->where('blog_id', $blogId)
                ->where('normalized_path', $path)
                ->whereNull('post_id')
                ->whereNull('page_id')
                ->update([$match[0] => $match[1]]);
        }

        return $resolved;
    }

    /*
    |--------------------------------------------------------------------------
    | 画面の集計
    |--------------------------------------------------------------------------
    |
    | 率・平均（CTR・掲載順位）は、表示回数で重み付けして集計する。
    | ユーザー数は日をまたいで足し合わせられないため、期間の集計には含めない（DATABASE 12-2）。
    */

    /**
     * ブログ全体の期間の合計
     *
     * @return array{ga4: object|null, search_console: object|null, adsense: object|null}
     */
    public function siteTotals(int $blogId, Carbon $from, Carbon $to): array
    {
        $range = [$from->toDateString(), $to->toDateString()];

        $ga4 = DB::table('google_analytics_site_daily')->where('blog_id', $blogId)->whereBetween('date', $range)
            ->selectRaw('count(*) as days, sum(screen_page_views) as views, sum(sessions) as sessions, sum(engaged_sessions) as engaged_sessions, sum(user_engagement_duration) as engagement_duration')
            ->first();

        $gsc = DB::table('google_search_console_site_daily')->where('blog_id', $blogId)->whereBetween('date', $range)
            ->selectRaw('count(*) as days, sum(clicks) as clicks, sum(impressions) as impressions, sum(position * impressions) / nullif(sum(impressions), 0) as position')
            ->first();

        $adsense = DB::table('google_adsense_site_daily')->where('blog_id', $blogId)->whereBetween('date', $range)
            ->selectRaw('count(*) as days, sum(estimated_earnings) as earnings, sum(page_views) as page_views, sum(impressions) as impressions, sum(clicks) as clicks, max(currency_code) as currency_code')
            ->first();

        return [
            'ga4'            => ($ga4->days ?? 0) > 0 ? $ga4 : null,
            'search_console' => ($gsc->days ?? 0) > 0 ? $gsc : null,
            'adsense'        => ($adsense->days ?? 0) > 0 ? $adsense : null,
        ];
    }

    /**
     * 記事ごとの期間の合計（表示回数・クリック・収益）
     *
     * @return Collection<int, object{post_id: int|null, page_id: int|null, views: int, clicks: int, impressions: int, position: float|null, earnings: float}>
     */
    public function articleTotals(int $blogId, Carbon $from, Carbon $to): Collection
    {
        $range = [$from->toDateString(), $to->toDateString()];
        $result = [];

        $add = function ($rows, array $fields) use (&$result) {
            foreach ($rows as $row) {
                $key = $row->post_id ? "post:{$row->post_id}" : "page:{$row->page_id}";
                $result[$key] ??= (object) ['post_id' => $row->post_id, 'page_id' => $row->page_id, 'views' => 0, 'clicks' => 0, 'impressions' => 0, 'position' => null, 'earnings' => 0.0];
                foreach ($fields as $field) {
                    $result[$key]->{$field} = $row->{$field};
                }
            }
        };

        $articleRows = fn (string $table, string $select) => DB::table($table)
            ->where('blog_id', $blogId)
            ->whereBetween('date', $range)
            ->where(fn ($q) => $q->whereNotNull('post_id')->orWhereNotNull('page_id'))
            ->groupBy('post_id', 'page_id')
            ->selectRaw("post_id, page_id, {$select}")
            ->get();

        $add($articleRows('google_analytics_page_daily', 'sum(screen_page_views) as views'), ['views']);
        $add($articleRows('google_search_console_page_daily', 'sum(clicks) as clicks, sum(impressions) as impressions, sum(position * impressions) / nullif(sum(impressions), 0) as position'), ['clicks', 'impressions', 'position']);
        $add($articleRows('google_adsense_page_daily', 'sum(estimated_earnings) as earnings'), ['earnings']);

        return collect(array_values($result));
    }

    /**
     * 記事の期間の指標
     *
     * @param string $column post_id / page_id
     */
    public function articleSummary(string $column, int $articleId, Carbon $from, Carbon $to): array
    {
        $range = [$from->toDateString(), $to->toDateString()];

        return [
            'ga4' => DB::table('google_analytics_page_daily')->where($column, $articleId)->whereBetween('date', $range)
                ->selectRaw('count(*) as days, sum(screen_page_views) as views, sum(sessions) as sessions, sum(engaged_sessions) as engaged_sessions, sum(user_engagement_duration) as engagement_duration')
                ->first(),
            'channels' => DB::table('google_analytics_page_channel_daily')->where($column, $articleId)->whereBetween('date', $range)
                ->groupBy('channel_group')
                ->selectRaw('channel_group, sum(screen_page_views) as views, sum(sessions) as sessions')
                ->orderByDesc('views')
                ->get(),
            'search_console' => DB::table('google_search_console_page_daily')->where($column, $articleId)->whereBetween('date', $range)
                ->selectRaw('count(*) as days, sum(clicks) as clicks, sum(impressions) as impressions, sum(position * impressions) / nullif(sum(impressions), 0) as position')
                ->first(),
            'queries' => DB::table('google_search_console_query_daily')->where($column, $articleId)->whereBetween('date', $range)
                ->groupBy('query_hash', 'query')
                ->selectRaw('query, sum(clicks) as clicks, sum(impressions) as impressions, sum(position * impressions) / nullif(sum(impressions), 0) as position')
                ->orderByDesc('clicks')
                ->orderByDesc('impressions')
                ->limit(50)
                ->get(),
            'adsense' => DB::table('google_adsense_page_daily')->where($column, $articleId)->whereBetween('date', $range)
                ->selectRaw('count(*) as days, sum(estimated_earnings) as earnings, sum(page_views) as page_views, max(currency_code) as currency_code')
                ->first(),
        ];
    }

    /**
     * 記事に対応付けられなかったURLのうち、指標の大きいもの（照合の漏れや、記事以外のページを確認するため）
     *
     * @return Collection<int, object{url: string, value: int}>
     */
    public function unresolvedUrls(int $blogId, Carbon $from, Carbon $to, int $limit = 30): Collection
    {
        return DB::table('google_analytics_page_daily')
            ->where('blog_id', $blogId)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->whereNull('post_id')
            ->whereNull('page_id')
            ->groupBy('page_path_hash', 'page_path')
            ->selectRaw('page_path as url, sum(screen_page_views) as value')
            ->orderByDesc('value')
            ->limit($limit)
            ->get();
    }

    /**
     * 表ごとの行数（DB確認画面。量の確認のため。D-21-05）
     *
     * @return array<string, int>
     */
    public function counts(int $blogId): array
    {
        $counts = [];
        foreach ([
            'google_analytics_site_daily', 'google_analytics_page_daily', 'google_analytics_page_channel_daily',
            'google_search_console_site_daily', 'google_search_console_page_daily', 'google_search_console_query_daily',
            'google_adsense_site_daily', 'google_adsense_page_daily',
        ] as $table) {
            $counts[$table] = DB::table($table)->where('blog_id', $blogId)->count();
        }

        return $counts;
    }
}
