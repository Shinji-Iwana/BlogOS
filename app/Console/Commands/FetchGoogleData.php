<?php

namespace App\Console\Commands;

use App\Enums\GoogleService;
use App\Enums\SyncTrigger;
use App\Jobs\GoogleFetchJob;
use App\Repositories\BlogRepository;
use App\Services\Google\GoogleFetchService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Googleのデータを取得する（毎日のcron。D-21-07）。
 *
 * 通常は、アーカイブしていない全ブログの取得をQueueに登録する。
 * --now を付けるとその場で実行し、--from・--to・--service で期間とサービスを指定できる（取り直し・手元での確認用）。
 */
class FetchGoogleData extends Command
{
    protected $signature = 'google:fetch
        {--blog=* : 対象のブログID（省略時は全ブログ）}
        {--service=* : ga4 / search_console / adsense（省略時は設定済みの全サービス）}
        {--from= : 取得の始まりの日（YYYY-MM-DD）}
        {--to= : 取得の終わりの日（YYYY-MM-DD）}
        {--now : Queueを使わずにその場で実行する}';

    protected $description = 'Googleのデータ（GA4・Search Console・AdSense）を取得する';

    public function handle(BlogRepository $blogRepository, GoogleFetchService $service): int
    {
        $ids = array_map('intval', (array) $this->option('blog'));
        $services = $this->option('service') === [] ? null : array_map(fn ($value) => GoogleService::from($value), (array) $this->option('service'));
        $from = $this->option('from') ? Carbon::parse($this->option('from')) : null;
        $to = $this->option('to') ? Carbon::parse($this->option('to')) : null;
        $immediate = $this->option('now') || $services !== null || $from !== null || $to !== null;

        foreach ($blogRepository->getActive() as $blog) {
            if ($ids !== [] && ! in_array($blog->id, $ids, true)) {
                continue;
            }

            if (! $immediate) {
                GoogleFetchJob::dispatch($blog->id, SyncTrigger::Scheduled);
                $this->info("[取得を登録しました] {$blog->home}");

                continue;
            }

            foreach ($service->run($blog, SyncTrigger::Manual, null, $services, $from, $to) as $name => $result) {
                $this->info("[{$name}] {$result['status']->label()}：{$result['rows']}行" . ($result['message'] ? "（{$result['message']}）" : ''));
            }
        }

        return self::SUCCESS;
    }
}
