<?php

namespace App\Support;

/**
 * 定期実行の一覧と既定の時刻（D-44）。時刻は日本時間。
 *
 * 画面で変えた時刻・有効かは scheduled_task_settings にあり、なければここの既定で動く。
 * - after：この定期実行の前に終わっていてほしい定期実行（時刻の順番の注意に使う）
 * - can_disable：画面で無効にできるか（古い記録の削除は止めると DB が増え続けるため不可。料金がかかる2つは AI の設定で有効・無効を決める）
 * - manual：画面の「今すぐ実行」を出すか（料金がかかるものは出さない）
 * - counts：処理件数・変更件数が何を数えたものか
 */
class ScheduledTasks
{
    public const TASKS = [
        'blogs:sync' => [
            'label'       => 'WordPress との同期',
            'description' => 'WordPress の記事・カテゴリ・メディアなどを取り込む（Queue で1ブログずつ処理する）',
            'frequency'   => 'daily', 'weekday' => null, 'time' => '03:00',
            'after'       => [], 'can_disable' => true, 'manual' => true,
            'counts'      => '処理件数：取得した件数／変更件数：作成・更新・削除した件数',
        ],
        'model:prune' => [
            'label'       => '古い記録の削除',
            'description' => '保存期間を過ぎた同期の記録・AI の実行記録などを削除する',
            'frequency'   => 'daily', 'weekday' => null, 'time' => '04:00',
            'after'       => [], 'can_disable' => false, 'manual' => true,
            'counts'      => '処理件数：削除した件数',
        ],
        'ai:check-prices' => [
            'label'       => 'AI の料金表の照合',
            'description' => 'API 実行の料金表を、OpenAI の公式のページと照合する（値上がりは自動で反映、値下がりは人が確認）',
            'frequency'   => 'daily', 'weekday' => null, 'time' => '04:30',
            'after'       => [], 'can_disable' => true, 'manual' => true,
            'counts'      => '変更件数：値上がり（反映）と値下がり（確認待ち）の件数',
        ],
        'wordpress:check-updates' => [
            'label'       => 'WordPress の更新の確認',
            'description' => 'WordPress 本体・プラグイン・テーマの更新と、公開停止のプラグインを確認する',
            'frequency'   => 'daily', 'weekday' => null, 'time' => '04:45',
            'after'       => [], 'can_disable' => true, 'manual' => true,
            'counts'      => '処理件数：確認した件数／変更件数：更新がある件数',
        ],
        'google:fetch' => [
            'label'       => 'Google のデータの取得',
            'description' => 'GA4・Search Console・AdSense のデータを取得して、記事に対応付ける（Queue で1ブログずつ処理する）',
            'frequency'   => 'daily', 'weekday' => null, 'time' => '05:00',
            'after'       => ['blogs:sync'], 'can_disable' => true, 'manual' => true,
            'counts'      => '処理件数：取得した行数',
        ],
        'google:inspect-index' => [
            'label'       => 'インデックスの登録状態の確認',
            'description' => 'Search Console の URL 検査で、記事が Google のインデックスに登録されているかを調べる（1日の上限まで）',
            'frequency'   => 'daily', 'weekday' => null, 'time' => '05:30',
            'after'       => ['blogs:sync'], 'can_disable' => true, 'manual' => true,
            'counts'      => '処理件数：調べた記事の数',
        ],
        'ai:auto-reevaluate' => [
            'label'       => '自動の再評価',
            'description' => '再評価の条件に当てはまる記事を、まとめて品質診断する（AI の設定で有効にしたブログだけ。料金がかかる）',
            'frequency'   => 'daily', 'weekday' => null, 'time' => '06:00',
            'after'       => ['blogs:sync', 'google:fetch'], 'can_disable' => false, 'manual' => false,
            'counts'      => '処理件数：まとめて実行に登録した記事の数（処理は「まとめて実行」で行う）',
        ],
        'materials:check' => [
            'label'       => '教材の定期チェック',
            'description' => '前回の調査から期間が過ぎた教材を、AI で調べ直す（AI の設定で有効にしたブログだけ。料金がかかる）',
            'frequency'   => 'daily', 'weekday' => null, 'time' => '06:30',
            'after'       => ['blogs:sync'], 'can_disable' => false, 'manual' => false,
            'counts'      => '処理件数：調査を始めた教材の数（処理は AI の実行で行う）',
        ],
        'affiliate:check-links' => [
            'label'       => 'アフィリエイトのリンクの確認',
            'description' => '提携先（プログラム）のリンクを開き、提携が終わったプログラムを見つける',
            'frequency'   => 'weekly', 'weekday' => 1, 'time' => '06:45',
            'after'       => [], 'can_disable' => true, 'manual' => true,
            'counts'      => '処理件数：確認したプログラムの数／変更件数：提携終了の疑いの数',
        ],
    ];

    public const WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土'];

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $key): ?array
    {
        return self::TASKS[$key] ?? null;
    }
}
