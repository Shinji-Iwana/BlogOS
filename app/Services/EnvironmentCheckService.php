<?php

namespace App\Services;

use App\Models\EnvironmentCheck;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * サーバー環境の点検（D-88。定期実行 environment:check）。AI は使わない（料金はかからない）。
 *
 * 更新は3つに分けて扱う。① セキュリティの修正（入っていなければすぐ対応）、② サポートの終わり（近づいたら計画して上げる）、
 * ③ ただの新しい版（追いかけない。知らせない）。知らせるのは ① と ② だけ。
 *
 * - PHP（Web 側・コマンド側）：PHP の公式の版の一覧と比べ、同じ系列のセキュリティの修正版が、出てから決まった日数を過ぎても入っていなければ ①。
 *   系列のサポートの終わり（endoflife.date）が近ければ ②。共用サーバーでは、PHP の更新は XServer が行う。
 *   Web 側の版は、BlogOS の画面を開いたときに記録した版（WordPress も同じドメインの PHP で動く）。コマンド側は、この点検が動く PHP。
 * - BlogOS の部品：composer audit で脆弱性があれば ①。
 * - Laravel：大きな版のセキュリティの修正の終わりが近ければ ②。
 * - DB：参考として出すだけ（XServer が管理していて、こちらでは上げられないため、知らせない）。
 */
class EnvironmentCheckService
{
    // BlogOS の画面を開いたときに記録する、Web 側の PHP の版（キャッシュのキー）
    public const WEB_PHP_CACHE = 'environment.php_web';

    /**
     * BlogOS の画面（Web）で動いている PHP の版を記録する（点検はコマンドで動くため、Web 側の版はここで覚えておく）
     */
    public static function rememberWebPhp(): void
    {
        $known = Cache::get(self::WEB_PHP_CACHE);
        if (($known['version'] ?? null) !== PHP_VERSION || Carbon::parse($known['at'] ?? '2000-01-01')->lt(now()->subDay())) {
            Cache::forever(self::WEB_PHP_CACHE, ['version' => PHP_VERSION, 'at' => now()->toIso8601String()]);
        }
    }

    /**
     * 全ての対象を点検して保存する
     *
     * @return array{security: int, support: int, unknown: int}
     */
    public function check(): array
    {
        $web = Cache::get(self::WEB_PHP_CACHE);
        $results = [
            'php_web'  => $web !== null
                ? $this->php('PHP（Web 側：BlogOS の画面・WordPress）', (string) $web['version'])
                : $this->row('PHP（Web 側：BlogOS の画面・WordPress）', null, EnvironmentCheck::UNKNOWN, 'まだ版を記録していません。BlogOS のトップページを開くと記録します。'),
            'php_cli'  => $this->php('PHP（コマンド側：定期実行・キュー）', PHP_VERSION),
            'packages' => $this->packages(),
            'laravel'  => $this->laravel(),
            'database' => $this->database(),
        ];

        foreach ($results as $target => $result) {
            EnvironmentCheck::updateOrCreate(['target' => $target], $result + ['checked_at' => now()]);
        }

        $levels = array_count_values(array_column($results, 'level'));

        return ['security' => $levels[EnvironmentCheck::SECURITY] ?? 0, 'support' => $levels[EnvironmentCheck::SUPPORT] ?? 0, 'unknown' => $levels[EnvironmentCheck::UNKNOWN] ?? 0];
    }

    /**
     * @return array<string, mixed>
     */
    protected function php(string $label, string $version): array
    {
        if (! preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $m)) {
            return $this->row($label, $version, EnvironmentCheck::UNKNOWN, '版を読み取れませんでした。');
        }
        $current = "{$m[1]}.{$m[2]}.{$m[3]}";
        $branch = "{$m[1]}.{$m[2]}";

        try {
            $releases = (array) Http::timeout(20)->get((string) config('blogos.environment.php_releases_url'), ['json' => 1, 'version' => $branch, 'max' => 100])->throw()->json();
        } catch (\Throwable $e) {
            return $this->row($label, $current, EnvironmentCheck::UNKNOWN, "PHP の公式の版の一覧を読み取れませんでした（{$e->getMessage()}）。");
        }
        $versions = array_filter(array_keys($releases), fn ($v) => preg_match('/^\d+\.\d+\.\d+$/', (string) $v));
        usort($versions, 'version_compare');
        $latest = $versions === [] ? null : (string) end($versions);

        // 今より新しいセキュリティの修正版
        $grace = now()->subDays((int) config('blogos.environment.security_grace_days'));
        $missing = [];
        foreach ($versions as $v) {
            if (version_compare((string) $v, $current, '>') && in_array('security', (array) ($releases[$v]['tags'] ?? []), true)) {
                $date = isset($releases[$v]['date']) ? Carbon::parse($releases[$v]['date']) : null;
                $missing[] = ['version' => (string) $v, 'date' => $date?->toDateString(), 'overdue' => $date === null || $date->lt($grace)];
            }
        }
        $overdue = array_values(array_filter($missing, fn ($s) => $s['overdue']));

        [$supportUntil, $supportNote] = $this->supportUntil('php', $branch);
        $messages = [];
        $level = EnvironmentCheck::OK;

        if ($overdue !== []) {
            $level = EnvironmentCheck::SECURITY;
            $last = end($overdue);
            $messages[] = "PHP {$branch} のセキュリティの修正版が " . count($overdue) . "件 入っていません（最新は {$last['version']}、{$last['date']} 公開）。"
                . '共用サーバーでは XServer が入れます。XServer のお知らせを確認し、入らないままならサポートに問い合わせてください。';
        } elseif ($missing !== []) {
            $messages[] = "セキュリティの修正版 {$missing[0]['version']} が出たばかりです（XServer の対応を待っています）。";
        }

        if ($this->nearEnd($supportUntil)) {
            $level = $level === EnvironmentCheck::SECURITY ? $level : EnvironmentCheck::SUPPORT;
            $messages[] = "PHP {$branch} のサポート（セキュリティの修正）は " . $supportUntil->toDateString() . ' に終わります。'
                . 'それまでに、サーバーパネルの「PHP Ver.切替」で新しい系列に切り替えてください（BlogOS のコマンドの php の指定も合わせて変えます）。';
        }
        if ($supportNote !== null) {
            $messages[] = $supportNote;
        }

        return $this->row($label, $current, $level, $messages === [] ? null : implode("\n", $messages), $latest, $supportUntil, $missing === [] ? null : ['missing_security_releases' => $missing]);
    }

    /**
     * BlogOS の部品の脆弱性（composer audit）
     *
     * @return array<string, mixed>
     */
    protected function packages(): array
    {
        $label = 'BlogOS の部品（Laravel など）';
        // composer の設定の置き場所（COMPOSER_HOME）は、元からあればそのまま使う（手元の証明書の設定などを読むため）。
        // cron など、HOME もない環境では composer が動かないため、BlogOS の storage を使う
        $env = ['COMPOSER_NO_INTERACTION' => '1'];
        if (! getenv('COMPOSER_HOME') && ! getenv('HOME') && ! getenv('APPDATA')) {
            $env['COMPOSER_HOME'] = storage_path('app/composer');
        }
        $result = Process::path(base_path())
            ->timeout((int) config('blogos.environment.composer_timeout'))
            ->env($env)
            ->run([...$this->composerCommand(), 'audit', '--locked', '--format=json', '--no-ansi']);

        // 脆弱性が見つかると、終了コードは 0 以外になる。出力の JSON で判断する
        $json = json_decode((string) preg_replace('/^[^{]*/s', '', $result->output()), true);
        if (! is_array($json) || ! array_key_exists('advisories', $json)) {
            return $this->row($label, null, EnvironmentCheck::UNKNOWN, '脆弱性を確認できませんでした（composer audit：' . mb_substr(trim($result->errorOutput() ?: $result->output()), 0, 300) . '）。');
        }

        $advisories = [];
        foreach ((array) $json['advisories'] as $package => $items) {
            foreach ((array) $items as $item) {
                $advisories[] = [
                    'package'  => (string) ($item['packageName'] ?? $package),
                    'severity' => $item['severity'] ?? null,
                    'title'    => (string) ($item['title'] ?? ''),
                    'cve'      => $item['cve'] ?? null,
                    'affected' => $item['affectedVersions'] ?? null,
                    'link'     => $item['link'] ?? null,
                ];
            }
        }

        if ($advisories === []) {
            return $this->row($label, null, EnvironmentCheck::OK, '既知の脆弱性はありません。');
        }

        $severities = array_count_values(array_map(fn ($a) => $a['severity'] ?: '不明', $advisories));

        return $this->row($label, null, EnvironmentCheck::SECURITY,
            '脆弱性が ' . count($advisories) . '件 あります（危険度：' . implode('・', array_map(fn ($s, $n) => "{$s} {$n}件", array_keys($severities), $severities)) . '）。'
            . '手元で composer update をして、テストを通してから反映してください（反映の後に、本番で composer install --no-dev）。',
            null, null, ['advisories' => $advisories]);
    }

    /**
     * Laravel の大きな版のサポートの終わり
     *
     * @return array<string, mixed>
     */
    protected function laravel(): array
    {
        $label = 'Laravel（BlogOS の土台）';
        $version = app()->version();
        $major = (string) (int) $version;
        [$supportUntil, $supportNote, $latest] = $this->supportUntil('laravel', $major);

        if ($this->nearEnd($supportUntil)) {
            return $this->row($label, $version, EnvironmentCheck::SUPPORT,
                "Laravel {$major} のセキュリティの修正は " . $supportUntil->toDateString() . ' に終わります。それまでに、次の大きな版へ上げる作業を計画してください（大きな作業のため、別の作業として行います）。',
                $latest, $supportUntil);
        }

        return $this->row($label, $version, $supportNote === null ? EnvironmentCheck::OK : EnvironmentCheck::UNKNOWN, $supportNote, $latest, $supportUntil);
    }

    /**
     * DB の版（参考。XServer が管理していて、こちらでは上げられないため、知らせない）
     *
     * @return array<string, mixed>
     */
    protected function database(): array
    {
        $label = 'DB（MySQL・MariaDB）';
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return $this->row($label, null, EnvironmentCheck::INFO, 'MySQL・MariaDB ではないため、点検しません。');
        }

        $version = (string) (DB::selectOne('select version() as v')->v ?? '');
        $product = stripos($version, 'mariadb') !== false ? 'mariadb' : 'mysql';
        if (! preg_match('/^(\d+)\.(\d+)/', $version, $m)) {
            return $this->row($label, $version, EnvironmentCheck::INFO, '版を読み取れませんでした。');
        }
        [$supportUntil, $supportNote] = $this->supportUntil($product, "{$m[1]}.{$m[2]}");

        $message = $supportUntil !== null && $supportUntil->isPast()
            ? '開発元のサポートは ' . $supportUntil->toDateString() . ' に終わっています。XServer が管理しているため、こちらでは上げられません（XServer が新しい版を用意したら、移行を検討します）。'
            : ($supportNote ?? 'XServer が管理しています。');

        return $this->row($label, $version, EnvironmentCheck::INFO, $message, null, $supportUntil);
    }

    /**
     * サポート（セキュリティの修正）の終わり（endoflife.date）
     *
     * @return array{0: Carbon|null, 1: string|null, 2: string|null} 終わりの日・読み取れないときの説明・系列の最新の版
     */
    protected function supportUntil(string $product, string $cycle): array
    {
        $url = str_replace(['{product}', '{cycle}'], [$product, $cycle], (string) config('blogos.environment.eol_url'));
        try {
            $data = Http::timeout(20)->get($url)->throw()->json('result');
        } catch (ConnectionException|\Illuminate\Http\Client\RequestException $e) {
            $note = "サポートの終わりを読み取れませんでした（endoflife.date：{$e->getMessage()}）。";

            return [null, $note, null];
        }

        $until = filled($data['eolFrom'] ?? null) ? Carbon::parse($data['eolFrom']) : null;
        $latest = $data['latest']['name'] ?? null;

        return [$until, null, $latest];
    }

    protected function nearEnd(?Carbon $until): bool
    {
        return $until !== null && $until->lte(now()->addMonths((int) config('blogos.environment.support_warn_months')));
    }

    /**
     * @return list<string>
     */
    protected function composerCommand(): array
    {
        $configured = config('blogos.environment.composer');
        if (filled($configured)) {
            return preg_split('/\s+/', trim((string) $configured));
        }

        // 本番：BlogOS のフォルダに置いた composer（phar）を、動いている PHP で実行する
        if (is_file(base_path('composer'))) {
            return [PHP_BINARY, base_path('composer')];
        }

        // 手元（Windows の Herd）では composer は composer.bat のため、cmd から呼ぶ
        return PHP_OS_FAMILY === 'Windows' ? ['cmd', '/c', 'composer'] : ['composer'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(string $label, ?string $current, string $level, ?string $message, ?string $latest = null, ?Carbon $supportUntil = null, ?array $details = null): array
    {
        return [
            'label'           => $label,
            'current_version' => $current,
            'latest_version'  => $latest,
            'support_until'   => $supportUntil?->toDateString(),
            'level'           => $level,
            'message'         => $message,
            'details'         => $details,
        ];
    }
}
