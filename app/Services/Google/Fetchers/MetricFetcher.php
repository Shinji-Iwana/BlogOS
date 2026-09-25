<?php

namespace App\Services\Google\Fetchers;

use App\Clients\Google\GoogleApiClient;
use App\Clients\Google\GoogleApiException;
use App\Enums\GoogleService;
use App\Models\BlogGoogleProperty;
use Illuminate\Support\Carbon;

/**
 * 1つのGoogleのサービスから、期間の指標を取得して保存する（BLOGOS_DATABASE.md 12-2）。
 */
interface MetricFetcher
{
    public function service(): GoogleService;

    /**
     * 取得の範囲を決めるときに、保存済みの最後の日を調べる表（ブログ×日の表）
     */
    public function siteTable(): string;

    /**
     * 記事と対応付けるページ単位の表
     *
     * @return array<int, string>
     */
    public function pageTables(): array;

    /**
     * 期間（両端を含む）の指標を取得し、その期間の行を置き換える
     *
     * @return array{rows: int, notes: array<int, string>}
     *
     * @throws GoogleApiException
     */
    public function fetch(GoogleApiClient $client, BlogGoogleProperty $property, Carbon $from, Carbon $to): array;
}
