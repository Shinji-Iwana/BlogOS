<?php

namespace App\Services\Dashboard;

use App\Support\DisplayTime;

/**
 * トップページの状態のパネル（D-49-07）。
 *
 * トップページのお知らせと同じデータ（DashboardController が集めたもの）から、領域ごとの状態
 * （問題なし・注意・要対応）を決め、BlogOS 全体の状態（アークリアクターの色）を出す。
 * テーマによらず同じデータを渡し、どう見せるかはテーマが決める（blank は使わない）。
 */
class DashboardStatusService
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const ERROR = 'error';

    /** 対象のブログがない・設定がないなど、判定しないもの */
    public const NONE = 'none';

    /**
     * 状態のパネル
     *
     * @param  array<string, mixed>  $d  DashboardController が画面に渡すデータ
     * @param  array<string, mixed>  $credit  AiCreditService::status()
     * @return list<array{key: string, code: string, label: string, state: string, value: string, lines: list<string>, url: string|null, link: string}>
     */
    public function panels(array $d, array $credit, bool $creditConfigured): array
    {
        $hasBlog = $d['selectedBlog'] !== null;

        return [
            $this->sync($d['syncStatus'] ?? null),
            $this->schedule($d['scheduleNotice']),
            $hasBlog ? $this->wordpress($d['wordpressNotice']) : $this->none('wordpress', 'WORDPRESS', 'WordPress'),
            $this->credit($credit, $creditConfigured, $d['priceNotice']),
            $hasBlog ? $this->links((int) $d['brokenLinks'], (int) $d['linkSwitchDrafts']) : $this->none('links', 'LINKS', '内部リンク'),
            $hasBlog ? $this->affiliate((int) $d['affiliateSuspects']) : $this->none('affiliate', 'AFFILIATE', '教材・提携'),
        ];
    }

    /**
     * BlogOS 全体の状態（アークリアクターの色）：要対応が1つでもあれば critical、注意があれば warning
     */
    public function overall(array $panels): string
    {
        $states = array_column($panels, 'state');

        return match (true) {
            in_array(self::ERROR, $states, true) => 'critical',
            in_array(self::WARN, $states, true)  => 'warning',
            default                              => 'normal',
        };
    }

    /**
     * 同期の実行中・開始待ちか（アークリアクターの HUD の円を速く回す）
     */
    public function busy(array $d): bool
    {
        return in_array($d['syncStatus']['state'] ?? 'idle', ['queued', 'running'], true);
    }

    /**
     * @return array{error: int, warn: int}
     */
    public function counts(array $panels): array
    {
        $states = array_count_values(array_column($panels, 'state'));

        return ['error' => $states[self::ERROR] ?? 0, 'warn' => $states[self::WARN] ?? 0];
    }

    protected function sync(?array $status): array
    {
        $panel = ['key' => 'sync', 'code' => 'SYNC', 'label' => '同期', 'url' => route('database.sync-runs.index'), 'link' => '同期の記録'];
        if ($status === null) {
            return $this->none('sync', 'SYNC', '同期');
        }

        $run = $status['latest_run'];
        $issues = (int) $status['unresolved_issue_count'];
        $lines = [];
        if ($issues > 0) {
            $lines[] = "未解決の問題 {$issues}件";
        }

        if ($status['state'] !== 'idle') {
            return $panel + ['state' => self::OK, 'value' => $status['state'] === 'running' ? '実行中' : '開始待ち', 'lines' => $lines];
        }
        if ($run === null) {
            return $panel + ['state' => self::WARN, 'value' => '未同期', 'lines' => $lines];
        }

        $lines = array_merge(['最後：' . DisplayTime::format($run['finished_at'] ?? $run['started_at'], 'm/d H:i')], $lines);
        $state = match (true) {
            $run['status'] !== 'succeeded' => self::ERROR,
            $issues > 0                    => self::WARN,
            default                        => self::OK,
        };

        return $panel + ['state' => $state, 'value' => $run['status_label'], 'lines' => $lines];
    }

    protected function schedule(array $notice): array
    {
        $panel = ['key' => 'schedule', 'code' => 'SCHEDULE', 'label' => '定期実行', 'url' => route('scheduled-tasks.index'), 'link' => '定期実行を確認する'];

        if ($notice['stopped']) {
            return $panel + ['state' => self::ERROR, 'value' => '停止の疑い', 'lines' => ['26時間以上動いていません（cron を確認）']];
        }
        if ($notice['failed'] !== []) {
            return $panel + ['state' => self::ERROR, 'value' => '失敗 ' . count($notice['failed']) . '件', 'lines' => $notice['failed']];
        }

        return $panel + ['state' => self::OK, 'value' => '正常', 'lines' => []];
    }

    protected function wordpress(array $notice): array
    {
        $panel = ['key' => 'wordpress', 'code' => 'WORDPRESS', 'label' => 'WordPress', 'url' => route('wordpress-updates.index'), 'link' => 'WordPress の更新を確認する'];
        $lines = array_values(array_filter([
            $notice['closed'] > 0 ? "公開停止のプラグイン {$notice['closed']}件" : null,
            $notice['updates'] > 0 ? "更新 {$notice['updates']}件" : null,
        ]));

        return $panel + match (true) {
            $notice['closed'] > 0  => ['state' => self::ERROR, 'value' => '要対応', 'lines' => $lines],
            $notice['updates'] > 0 => ['state' => self::WARN, 'value' => "更新 {$notice['updates']}件", 'lines' => $lines],
            default                => ['state' => self::OK, 'value' => '最新', 'lines' => []],
        };
    }

    protected function credit(array $credit, bool $configured, array $prices): array
    {
        $panel = ['key' => 'credit', 'code' => 'AI CREDIT', 'label' => 'AI の残高', 'url' => route('ai.credits.index'), 'link' => 'AI の費用と残高'];
        if (! $configured) {
            return $panel + ['state' => self::NONE, 'value' => 'API 未設定', 'lines' => []];
        }

        $lines = array_values(array_filter([
            $prices['failed'] ? '料金表：読み取れなかった料金あり' : null,
            $prices['pending'] ? "料金表：値下がりの確認待ち {$prices['pending']}件" : null,
        ]));
        $value = $credit['balance'] !== null ? '$' . number_format($credit['balance'], 2) : '未登録';
        $state = match (true) {
            $credit['level'] === 'critical' || $prices['failed'] => self::ERROR,
            $credit['level'] !== 'ok' || $prices['pending'] > 0 => self::WARN,
            default                                              => self::OK,
        };

        return $panel + ['state' => $state, 'value' => $value, 'lines' => $lines];
    }

    protected function links(int $broken, int $switchDrafts): array
    {
        $panel = ['key' => 'links', 'code' => 'LINKS', 'label' => '内部リンク', 'url' => route('links.check'), 'link' => '内部リンクを確認する'];
        $lines = $switchDrafts > 0 ? ["切り替え・修正の編集案 {$switchDrafts}件"] : [];

        return $panel + match (true) {
            $broken > 0       => ['state' => self::ERROR, 'value' => "リンク切れ {$broken}件", 'lines' => $lines],
            $switchDrafts > 0 => ['state' => self::WARN, 'value' => "編集案 {$switchDrafts}件", 'lines' => []],
            default           => ['state' => self::OK, 'value' => 'リンク切れなし', 'lines' => []],
        };
    }

    protected function affiliate(int $suspects): array
    {
        $panel = ['key' => 'affiliate', 'code' => 'AFFILIATE', 'label' => '教材・提携', 'url' => route('materials.programs.index'), 'link' => 'アフィリエイトのプログラム'];

        return $panel + ($suspects > 0
            ? ['state' => self::ERROR, 'value' => "提携終了の疑い {$suspects}件", 'lines' => []]
            : ['state' => self::OK, 'value' => '問題なし', 'lines' => []]);
    }

    protected function none(string $key, string $code, string $label): array
    {
        return ['key' => $key, 'code' => $code, 'label' => $label, 'state' => self::NONE, 'value' => 'ブログ未選択', 'lines' => [], 'url' => null, 'link' => ''];
    }
}
