<?php

namespace App\Services\Schedule;

use App\Models\ScheduledTaskRun;
use App\Models\ScheduledTaskSetting;
use App\Support\ScheduledTasks;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * 定期実行（D-44）。画面の設定（なければ既定）で Scheduler に登録し、実行ごとに開始・終了・件数などを記録する。
 *
 * 実行中の記録の ID は Context に入れる。コマンドは report() で件数を加え、コマンドが Queue に登録した処理（同期・Google の取得）は
 * Context を受け継ぐため、trackJob() で終わったときに件数を加える。記録は、コマンドと Queue の処理がすべて終わったときに閉じる
 * （pending_jobs はコマンド自身を含む数）。コマンドを SSH で直接実行した場合は、記録しない。
 */
class ScheduledTaskService
{
    public const CONTEXT_KEY = 'scheduled_task_run_id';

    /**
     * 定期実行の設定（画面で変えたもの。なければ既定）
     *
     * @return array{frequency: string, weekday: int|null, time: string, enabled: bool}
     */
    public function setting(string $key, ?Collection $settings = null): array
    {
        $task = ScheduledTasks::get($key);
        $row = ($settings ?? $this->settings())->get($key);

        return [
            'frequency' => $row?->frequency ?? $task['frequency'],
            'weekday'   => $row !== null ? $row->weekday : $task['weekday'],
            'time'      => $row?->time ?? $task['time'],
            // 画面で無効にできない定期実行は、常に有効
            'enabled'   => ! $task['can_disable'] || ($row?->enabled ?? true),
        ];
    }

    /**
     * いつ・有効を保存する（メニューのポップアップ。D-44・D-63）。時刻の順番の注意を返す
     *
     * @param  array{frequency: string, weekday?: int|string|null, time: string, enabled?: bool|string|null}  $values
     * @return list<string>
     */
    public function save(string $key, array $values, ?int $userId): array
    {
        $task = ScheduledTasks::get($key);

        ScheduledTaskSetting::updateOrCreate(['task_key' => $key], [
            'frequency'  => $values['frequency'],
            'weekday'    => $values['frequency'] === 'weekly' ? (int) $values['weekday'] : null,
            'time'       => $values['time'],
            // 画面で無効にできない定期実行は、常に有効にしておく
            'enabled'    => ! $task['can_disable'] || (bool) ($values['enabled'] ?? false),
            'updated_by' => $userId,
        ]);

        return $this->orderWarnings()[$key] ?? [];
    }

    /**
     * @return Collection<string, ScheduledTaskSetting>
     */
    public function settings(): Collection
    {
        try {
            return ScheduledTaskSetting::all()->keyBy('task_key');
        } catch (QueryException) {
            // Migration の前（テーブルがない）は、既定で動かす
            return collect();
        }
    }

    /**
     * Scheduler に登録する（bootstrap/app.php の withSchedule。schedule:run のたびに、画面の設定を読む）
     */
    public function register(Schedule $schedule): void
    {
        $settings = $this->settings();
        foreach (array_keys(ScheduledTasks::TASKS) as $key) {
            $setting = $this->setting($key, $settings);
            if (! $setting['enabled']) {
                continue;
            }

            $event = $schedule->call(fn () => $this->run($key, 'scheduled', null, now()->startOfMinute()))
                ->name($key)
                ->timezone(config('blogos.display_timezone'))
                // 前回の実行が終わっていなければ、重ねて動かさない（3時間で解除）
                ->withoutOverlapping(ScheduledTaskRun::STALE_HOURS * 60);

            $setting['frequency'] === 'weekly'
                ? $event->weeklyOn((int) $setting['weekday'], $setting['time'])
                : $event->dailyAt($setting['time']);
        }
    }

    /**
     * 次に実行する日時（無効なら null）
     */
    public function nextRunAt(string $key, ?Collection $settings = null): ?CarbonImmutable
    {
        $setting = $this->setting($key, $settings);
        if (! $setting['enabled']) {
            return null;
        }

        [$hour, $minute] = array_map('intval', explode(':', $setting['time']));
        $cron = $setting['frequency'] === 'weekly' ? "{$minute} {$hour} * * {$setting['weekday']}" : "{$minute} {$hour} * * *";
        $timezone = (string) config('blogos.display_timezone');

        return CarbonImmutable::instance((new CronExpression($cron))->getNextRunDate(now($timezone), 0, false, $timezone));
    }

    /**
     * 定期実行を1回実行し、記録する（定期実行・画面の「今すぐ実行」から）
     */
    public function run(string $key, string $trigger, ?int $userId = null, $scheduledFor = null): ScheduledTaskRun
    {
        ScheduledTasks::get($key) ?? throw new \InvalidArgumentException("定期実行 {$key} はありません。");

        $run = ScheduledTaskRun::create([
            'task_key'      => $key,
            'trigger'       => $trigger,
            'status'        => 'running',
            'scheduled_for' => $scheduledFor,
            'started_at'    => now(),
            'pending_jobs'  => 1,
            'requested_by'  => $userId,
        ]);

        Context::add(self::CONTEXT_KEY, $run->id);
        $output = new BufferedOutput();
        $error = null;
        try {
            $exitCode = Artisan::call($key, [], $output);
            if ($exitCode !== 0) {
                $error = "コマンドが失敗しました（終了コード {$exitCode}）。";
            }
        } catch (Throwable $e) {
            report($e);
            $error = $e->getMessage();
        } finally {
            Context::forget(self::CONTEXT_KEY);
        }

        $text = trim($output->fetch());
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $text))));
        $run->update([
            'message'        => $lines !== [] ? mb_substr(end($lines), 0, 500) : null,
            'peak_memory_mb' => (int) ceil(memory_get_peak_usage(true) / 1048576),
        ]);

        // 古い記録の削除（Laravel のコマンド）は、出力の件数を数える
        $processed = $key === 'model:prune' && preg_match_all('/(\d+)\s+records?/i', $text, $m) ? array_sum(array_map('intval', $m[1])) : null;
        // コマンドの出力は、先に終わった Queue の処理の結果の行より前に置く
        $this->apply($run->id, ['processed_count' => $processed], -1, $error, $text !== '' ? $text : null, prepend: true);

        return $run->fresh();
    }

    /**
     * コマンドの中から、処理件数などを加える（定期実行の記録の中でだけ記録する）
     */
    public function report(?int $processed = null, ?int $changed = null, int $errors = 0, ?int $blogs = null): void
    {
        $runId = Context::get(self::CONTEXT_KEY);
        if ($runId !== null) {
            $this->apply((int) $runId, ['processed_count' => $processed, 'changed_count' => $changed, 'error_count' => $errors, 'blog_count' => $blogs], 0, null, null);
        }
    }

    /**
     * コマンドが Queue に処理を登録する前に呼ぶ（その処理が終わるまで、記録を閉じない）
     */
    public function addPendingJob(): void
    {
        $runId = Context::get(self::CONTEXT_KEY);
        if ($runId !== null) {
            $this->apply((int) $runId, [], 1, null, null);
        }
    }

    /**
     * Queue の処理を実行し、終わったら件数を加える。定期実行から登録された処理でなければ、そのまま実行する
     *
     * @param callable(): (array{processed?: int, changed?: int, errors?: int, line?: string}|null) $work
     */
    public function trackJob(callable $work): void
    {
        $runId = Context::get(self::CONTEXT_KEY);
        if ($runId === null) {
            $work();

            return;
        }

        try {
            $counts = (array) ($work() ?? []);
            $this->apply((int) $runId, [
                'processed_count' => $counts['processed'] ?? null,
                'changed_count'   => $counts['changed'] ?? null,
                'error_count'     => $counts['errors'] ?? 0,
            ], -1, null, $counts['line'] ?? null);
        } catch (Throwable $e) {
            // 定期実行の記録に失敗として残す（Queue の失敗にはしない。同じ処理の失敗を二重に数えないため）
            report($e);
            $this->apply((int) $runId, [], -1, $e->getMessage(), null);
        }
    }

    /**
     * Queue の処理が時間切れなどで失敗したとき（Job の failed() から）
     */
    public function jobFailed(?Throwable $e): void
    {
        $runId = Context::get(self::CONTEXT_KEY);
        if ($runId !== null) {
            $this->apply((int) $runId, [], -1, 'Queue の処理が失敗しました：' . ($e?->getMessage() ?? '不明'), null);
        }
    }

    /**
     * 記録に件数を加え、待っている処理がなくなったら閉じる
     *
     * @param array<string, int|null> $counts
     */
    protected function apply(int $runId, array $counts, int $pendingDelta, ?string $error, ?string $line, bool $prepend = false): void
    {
        DB::transaction(function () use ($runId, $counts, $pendingDelta, $error, $line, $prepend) {
            $run = ScheduledTaskRun::lockForUpdate()->find($runId);
            if ($run === null) {
                return;
            }

            foreach ($counts as $column => $value) {
                if ($value !== null && ($value !== 0 || $column !== 'error_count')) {
                    $run->{$column} = (int) ($run->{$column} ?? 0) + $value;
                }
            }
            if ($line !== null) {
                $run->output = mb_substr(trim($prepend ? $line . "\n" . ($run->output ?? '') : ($run->output ?? '') . "\n" . $line), -8000);
            }
            if ($error !== null) {
                $run->error = trim(($run->error ?? '') . "\n" . $error);
            }

            $run->pending_jobs = max(0, $run->pending_jobs + $pendingDelta);
            if ($pendingDelta < 0 && $run->pending_jobs === 0 && $run->finished_at === null) {
                $run->finished_at = now();
                $run->duration_seconds = (int) $run->started_at->diffInSeconds($run->finished_at);
                $run->status = $run->error !== null ? 'failed' : 'succeeded';
            }
            $run->save();
        });
    }

    /**
     * 時刻の順番の注意（この定期実行の前に終わっていてほしい定期実行より、前か同じ時刻になっている）
     *
     * @return array<string, list<string>> キー => 注意
     */
    public function orderWarnings(?Collection $settings = null): array
    {
        $settings ??= $this->settings();
        $warnings = [];
        foreach (ScheduledTasks::TASKS as $key => $task) {
            $setting = $this->setting($key, $settings);
            if (! $setting['enabled']) {
                continue;
            }
            foreach ($task['after'] as $before) {
                $beforeSetting = $this->setting($before, $settings);
                if (! $beforeSetting['enabled']) {
                    continue;
                }
                $last = ScheduledTaskRun::where('task_key', $before)->whereNotNull('duration_seconds')->latest('started_at')->value('duration_seconds');
                $end = $this->minutes($beforeSetting['time']) + (int) ceil(($last ?? 0) / 60);
                if ($this->minutes($setting['time']) <= $end) {
                    $label = ScheduledTasks::get($before)['label'];
                    $warnings[$key][] = "「{$label}」（{$beforeSetting['time']}" . ($last !== null ? '。前回は ' . self::duration((int) $last) . 'かかった' : '') . '）が終わった後の時刻にしてください。';
                }
            }
        }

        return $warnings;
    }

    protected function minutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $hour * 60 + $minute;
    }

    public static function duration(?int $seconds): string
    {
        if ($seconds === null) {
            return '-';
        }

        return $seconds < 60 ? "{$seconds}秒" : intdiv($seconds, 60) . '分' . ($seconds % 60 > 0 ? ($seconds % 60) . '秒' : '');
    }
}
