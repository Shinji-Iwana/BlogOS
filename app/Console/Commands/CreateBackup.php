<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Services\BackupService;
use App\Services\Schedule\ScheduledTaskService;
use Illuminate\Console\Command;

/**
 * 月1回のバックアップ（毎月の cron。D-89）。サーバーの中に作り、XServer情報の画面からダウンロードする（料金はかからない）。
 */
class CreateBackup extends Command
{
    protected $signature = 'backup:create';

    protected $description = 'WordPress と BlogOS の DB・wp-content・秘密の情報（パスワード付き zip）のバックアップを、サーバーの中に作る';

    public function handle(BackupService $service, ScheduledTaskService $recorder): int
    {
        $backup = $service->create();
        if ($backup === null) {
            $this->error('ほかでバックアップを作成中です。');

            return self::FAILURE;
        }

        if ($backup->status !== Backup::SUCCEEDED) {
            $recorder->report(processed: 0, errors: 1);
            $this->error('バックアップを作れませんでした：' . $backup->message);

            return self::FAILURE;
        }

        foreach ($backup->files as $file) {
            $this->line("  {$file['label']}：" . number_format($file['bytes'] / 1048576, 1) . ' MB');
        }
        if ($backup->message !== null) {
            $this->warn($backup->message);
        }
        $recorder->report(processed: count($backup->files));
        $this->info("バックアップを作りました（{$backup->name}・合計 " . number_format($backup->total_bytes / 1048576, 1) . ' MB）。');

        return self::SUCCESS;
    }
}
