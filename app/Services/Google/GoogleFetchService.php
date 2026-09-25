<?php

namespace App\Services\Google;

use App\Clients\Google\GoogleApiException;
use App\Enums\GoogleService;
use App\Enums\SyncIssueType;
use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Models\Blog;
use App\Models\BlogGoogleProperty;
use App\Repositories\GoogleAccountRepository;
use App\Repositories\GoogleFetchRunRepository;
use App\Repositories\GoogleMetricRepository;
use App\Repositories\SyncIssueRepository;
use App\Services\Google\Fetchers\AdsenseFetcher;
use App\Services\Google\Fetchers\Ga4Fetcher;
use App\Services\Google\Fetchers\MetricFetcher;
use App\Services\Google\Fetchers\SearchConsoleFetcher;
use App\Services\Sync\SyncAlreadyRunningException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 1つのブログのGoogleのデータの取得（D-21-07）。
 *
 * サービスごとに、取得する期間を決め、31日ごとに分けて取得して行を置き換え、記事と対応付ける。
 * 失敗したサービスは実行記録と同期の問題に記録し、他のサービスの取得は続ける。
 */
class GoogleFetchService
{
    /**
     * 1回に取得して置き換える日数（大きな期間を一度に扱わないため）
     */
    protected const WINDOW_DAYS = 31;

    public const LOCK_SECONDS = 3600;

    public function __construct(
        protected GoogleAccountRepository $accounts,
        protected GoogleFetchRunRepository $runs,
        protected GoogleMetricRepository $metrics,
        protected SyncIssueRepository $issues,
        protected GoogleTokenService $tokens,
    ) {
    }

    /**
     * @param array<int, GoogleService>|null $services 対象のサービス（省略時は設定済みの全サービス）
     * @return array<string, array{status: SyncStatus, rows: int, message: string|null}>
     *
     * @throws SyncAlreadyRunningException
     */
    public function run(Blog $blog, SyncTrigger $trigger, ?int $userId = null, ?array $services = null, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $lock = Cache::lock("blogos:google:blog:{$blog->id}", self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw new SyncAlreadyRunningException('このブログのGoogleのデータの取得は既に実行中です。');
        }

        try {
            $results = [];

            foreach ($this->accounts->propertiesForBlog($blog->id) as $property) {
                if ($services !== null && ! in_array($property->service, $services, true)) {
                    continue;
                }

                $results[$property->service->value] = $this->fetchService($blog, $property, $trigger, $userId, $from, $to);
            }

            return $results;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{status: SyncStatus, rows: int, message: string|null}
     */
    protected function fetchService(Blog $blog, BlogGoogleProperty $property, SyncTrigger $trigger, ?int $userId, ?Carbon $from, ?Carbon $to): array
    {
        $fetcher = $this->fetcher($property->service);

        [$rangeFrom, $rangeTo] = $this->range($fetcher, $blog->id, $from, $to);

        if ($rangeFrom->greaterThan($rangeTo)) {
            return ['status' => SyncStatus::Succeeded, 'rows' => 0, 'message' => '取得する期間がありません。'];
        }

        $run = $this->runs->start($blog->id, $property->service, $trigger, $rangeFrom, $rangeTo, $userId);
        $rows = 0;
        $notes = [];

        try {
            $client = $this->tokens->clientFor($property->account);

            for ($windowFrom = $rangeFrom->copy(); $windowFrom->lessThanOrEqualTo($rangeTo); $windowFrom->addDays(self::WINDOW_DAYS)) {
                $windowTo = $windowFrom->copy()->addDays(self::WINDOW_DAYS - 1)->min($rangeTo);

                $result = $fetcher->fetch($client, $property, $windowFrom->copy(), $windowTo);
                $rows += $result['rows'];
                array_push($notes, ...$result['notes']);
            }

            foreach ($fetcher->pageTables() as $table) {
                $this->metrics->resolveArticles($blog->id, $table);
            }
        } catch (GoogleApiException $e) {
            $this->fail($blog, $property, $run, $rows, $e->getMessage(), $e->status, $e->body);

            return ['status' => SyncStatus::Failed, 'rows' => $rows, 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            Log::error('Google：取得に失敗しました。', ['blog_id' => $blog->id, 'service' => $property->service->value, 'message' => $e->getMessage()]);
            $this->fail($blog, $property, $run, $rows, $e->getMessage(), null, null);

            return ['status' => SyncStatus::Failed, 'rows' => $rows, 'message' => $e->getMessage()];
        }

        $message = $notes === [] ? null : implode(' ', array_unique($notes));
        $this->runs->finish($run, SyncStatus::Succeeded, $rows, $message);

        return ['status' => SyncStatus::Succeeded, 'rows' => $rows, 'message' => $message];
    }

    /**
     * 取得する期間。
     *
     * * 終わり：昨日（表示用のタイムゾーンでの日付。今日の値は途中のため）
     * * 始まり：初めてなら一定の月数前から。取得済みなら、保存済みの最後の日の翌日と、直近の数日前の早い方から
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function range(MetricFetcher $fetcher, int $blogId, ?Carbon $from, ?Carbon $to): array
    {
        $to ??= Carbon::now(config('blogos.display_timezone'))->subDay()->startOfDay();
        $to = Carbon::parse($to->toDateString());

        if ($from === null) {
            $last = $this->metrics->lastDate($fetcher->siteTable(), $blogId);
            $refetchFrom = $to->copy()->subDays((int) config('blogos.google.refetch_days', 4) - 1);

            $from = $last === null
                ? $to->copy()->subMonths((int) config('blogos.google.initial_months', 16))
                : $last->copy()->addDay()->min($refetchFrom);
        }

        return [Carbon::parse($from->toDateString()), $to];
    }

    protected function fail(Blog $blog, BlogGoogleProperty $property, $run, int $rows, string $message, ?int $status, ?string $body): void
    {
        $this->runs->finish($run, SyncStatus::Failed, $rows, $message, $status, $body);

        $this->issues->record($blog->id, SyncIssueType::FetchError, $property->service->issueResourceType(), null, [
            'message'      => "{$property->service->label()}のデータを取得できませんでした：{$message}",
            'error_status' => $status,
            'error_body'   => $body,
        ]);
    }

    protected function fetcher(GoogleService $service): MetricFetcher
    {
        return app(match ($service) {
            GoogleService::Ga4           => Ga4Fetcher::class,
            GoogleService::SearchConsole => SearchConsoleFetcher::class,
            GoogleService::Adsense       => AdsenseFetcher::class,
        });
    }
}
