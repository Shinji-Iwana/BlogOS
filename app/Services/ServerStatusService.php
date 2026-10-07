<?php

namespace App\Services;

use App\Models\AiGeneration;
use App\Models\Blog;
use App\Models\AiPriceCheck;
use App\Models\GoogleFetchRun;
use App\Models\ScheduledTaskRun;
use App\Models\SyncIssue;
use App\Models\SyncRun;
use App\Models\WordPressPushOperation;
use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * サーバー情報（メニューの「情報 → サーバー情報」。D-71）。
 *
 * BlogOS が動いているサーバー（本番は XServer）の今の状態を読み取る。読み取るだけで、DB への書き込み・削除はしない。
 * 共用のサーバーのため、ロードアベレージはほかの利用者の分も含む。契約上のディスクの残りは読み取れない（サーバーパネルで確認する）。
 */
class ServerStatusService
{
    /**
     * 古い記録の削除（定期実行の model:prune）の対象。日付の列と、削除する条件（各モデルの prunable()）
     *
     * @var list<array{model: class-string, label: string, column: string, rule: string}>
     */
    public const PRUNABLE = [
        ['model' => AiGeneration::class, 'label' => 'AI の実行記録', 'column' => 'created_at', 'rule' => '1年より古いもの（WordPress に反映した編集案を作った記録は残す）'],
        ['model' => AiPriceCheck::class, 'label' => 'OpenAI API料金表との照合の記録', 'column' => 'created_at', 'rule' => '1年より古いもの'],
        ['model' => GoogleFetchRun::class, 'label' => 'Googleとの同期の記録', 'column' => 'started_at', 'rule' => '1年より古いもの'],
        ['model' => ScheduledTaskRun::class, 'label' => '定期実行の記録', 'column' => 'started_at', 'rule' => '1年より古いもの'],
        ['model' => SyncIssue::class, 'label' => 'WordPressとの同期の問題', 'column' => 'resolved_at', 'rule' => '解決してから1年より古いもの'],
        ['model' => SyncRun::class, 'label' => 'WordPressとの同期の記録', 'column' => 'started_at', 'rule' => '1年より古いもの'],
        ['model' => WordPressPushOperation::class, 'label' => 'WordPress への反映の記録', 'column' => 'completed_at', 'rule' => '完了してから1年より古いもの'],
    ];

    /**
     * サーバーの基本情報（XServer のサーバーパネルの「サーバー情報」に近いもの）。読めないものは null。
     * サーバー番号は、ホスト名の先頭（例：sv12345.xserver.jp の sv12345）から読む
     *
     * OS・CPU・メモリーは、サーバーパネルの表示と合わないため出さない（D-71-03）
     *
     * @return array{server_number: string|null, hostname: string|null, ip: string|null}
     */
    public function machine(): array
    {
        $hostname = $this->safe(fn () => gethostname() ?: null);

        return [
            'server_number' => $hostname && preg_match('/^(sv\d+)\./i', $hostname, $match) ? $match[1] : null,
            'hostname'      => $hostname,
            'ip'            => $this->ipAddress($hostname),
        ];
    }

    /**
     * サーバーと PHP
     *
     * @return array{php_version: string, os: string, load: array{0: float, 1: float, 2: float}|null, cpus: int|null, memory_limit: string, max_execution_time: string, peak_memory: int}
     */
    public function server(): array
    {
        $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;

        return [
            'php_version'        => PHP_VERSION,
            'os'                 => PHP_OS_FAMILY,
            'load'               => is_array($load) ? $load : null,
            'cpus'               => $this->cpuCount(),
            'memory_limit'       => (string) ini_get('memory_limit'),
            'max_execution_time' => (string) ini_get('max_execution_time'),
            'peak_memory'        => memory_get_peak_usage(true),
        ];
    }

    /**
     * DB の全体と、テーブルごとの件数・容量（容量の大きい順）。$schema を省くと BlogOS の DB
     *
     * 容量は、データと索引の合計（XServer のサーバーパネルの値とほぼ同じ）。使用率は、これを上限（設定）で割る
     *
     * @return array{version: string, name: string, total_bytes: int, capacity_bytes: int|null, usage_ratio: float|null, tables: list<array{name: string, rows: int, bytes: int}>}
     */
    public function database(?string $schema = null): array
    {
        $schema ??= (string) DB::getDatabaseName();

        $tables = collect(DB::select(
            'select TABLE_NAME as name, DATA_LENGTH + INDEX_LENGTH as bytes from information_schema.TABLES where TABLE_SCHEMA = ? and TABLE_TYPE = ?',
            [$schema, 'BASE TABLE'],
        ))->map(fn ($table) => [
            'name'  => $table->name,
            // 件数は数える（information_schema の TABLE_ROWS は、InnoDB では目安の値のため）
            'rows'  => DB::table($schema . '.' . $table->name)->count(),
            'bytes' => (int) $table->bytes,
        ])->sortByDesc('bytes')->values()->all();

        $total = array_sum(array_column($tables, 'bytes'));
        $capacityMb = (int) config('blogos.server.db_capacity_mb');
        $capacity = $capacityMb > 0 ? $capacityMb * 1024 * 1024 : null;

        return [
            'version'        => (string) DB::scalar('select version()'),
            'name'           => $schema,
            'total_bytes'    => $total,
            'capacity_bytes' => $capacity,
            'usage_ratio'    => $capacity ? $total / $capacity : null,
            'tables'         => $tables,
        ];
    }

    /**
     * BlogOS の DB のユーザーにアクセス権がある、ほかの DB（WordPress の DB。D-71-04）。
     * XServer のサーバーパネルで、WordPress の DB のアクセス権所有ユーザーに BlogOS の DB のユーザーを足すと見える。
     * 権限の上では書き換えられるが、ここでは読むだけ（select）にする。
     * WordPress の DB なら、設定（options の home）の URL から、登録しているブログを探す
     *
     * @return list<array{database: array, home: string|null, blog: \App\Models\Blog|null}>
     */
    public function otherDatabases(): array
    {
        $own = (string) DB::getDatabaseName();
        $schemas = collect(DB::select('select SCHEMA_NAME as name from information_schema.SCHEMATA'))
            ->pluck('name')
            ->reject(fn (string $name) => $name === $own || in_array(strtolower($name), ['information_schema', 'mysql', 'performance_schema', 'sys'], true))
            ->sort()
            ->values();

        $blogs = Blog::all();

        return $schemas->map(function (string $schema) use ($blogs) {
            $home = $this->wordPressHome($schema);

            return [
                'database' => $this->database($schema),
                'home'     => $home,
                'blog'     => $home ? $blogs->first(fn (Blog $blog) => $this->sameSite($blog->home, $home)) : null,
            ];
        })->all();
    }

    /**
     * WordPress の DB の、サイトの URL（options の home。WordPress でなければ null）
     */
    protected function wordPressHome(string $schema): ?string
    {
        $optionTables = collect(DB::select(
            'select TABLE_NAME as name from information_schema.COLUMNS where TABLE_SCHEMA = ? and TABLE_NAME like ? and COLUMN_NAME = ?',
            [$schema, '%options', 'option_name'],
        ))->pluck('name');

        foreach ($optionTables as $table) {
            $home = $this->safe(fn () => DB::table($schema . '.' . $table)->where('option_name', 'home')->value('option_value'));
            if (is_string($home) && $home !== '') {
                return $home;
            }
        }

        return null;
    }

    /**
     * 同じサイトの URL か（http と https・末尾の / の違いは問わない）
     */
    protected function sameSite(?string $a, string $b): bool
    {
        $normalize = fn (?string $url) => rtrim(strtolower((string) preg_replace('#^https?://#i', '', trim((string) $url))), '/');

        return $a !== null && $normalize($a) === $normalize($b);
    }

    /**
     * 古い記録の削除の効き目：記録ごとの件数・一番古い日付・今削除の対象になっている件数
     *
     * @return list<array{label: string, table: string, rule: string, rows: int, oldest: mixed, prunable: int}>
     */
    public function prunable(): array
    {
        return array_map(function (array $item) {
            $model = new $item['model']();

            return [
                'label'    => $item['label'],
                'table'    => $model->getTable(),
                'rule'     => $item['rule'],
                'rows'     => $model->newQuery()->count(),
                'oldest'   => $model->newQuery()->min($item['column']),
                'prunable' => $model->prunable()->count(),
            ];
        }, self::PRUNABLE);
    }

    /**
     * キュー（queue:work が処理する）：待っている処理と、失敗した処理
     *
     * @return array{pending: int, reserved: int, oldest_pending: int|null, by_queue: array<string, int>, failed: int, last_failed: string|null}
     */
    public function queue(): array
    {
        $jobs = DB::table(config('queue.connections.database.table', 'jobs'));
        $failed = DB::table(config('queue.failed.table', 'failed_jobs'));

        return [
            'pending'        => (clone $jobs)->whereNull('reserved_at')->count(),
            'reserved'       => (clone $jobs)->whereNotNull('reserved_at')->count(),
            'oldest_pending' => (clone $jobs)->whereNull('reserved_at')->min('created_at'),
            'by_queue'       => (clone $jobs)->selectRaw('queue, count(*) as total')->groupBy('queue')->pluck('total', 'queue')->map(fn ($total) => (int) $total)->all(),
            'failed'         => (clone $failed)->count(),
            'last_failed'    => (clone $failed)->max('failed_at'),
        ];
    }

    /**
     * BlogOS のフォルダの中の、ログ・保存ファイルの容量
     *
     * @return list<array{label: string, path: string, bytes: int|null, files: int|null}>
     */
    public function files(): array
    {
        return array_map(function (array $item) {
            [$bytes, $files] = $this->directorySize($item['path']);

            return $item + ['bytes' => $bytes, 'files' => $files];
        }, [
            ['label' => 'ログ', 'path' => 'storage/logs'],
            ['label' => '保存ファイル（画像など）', 'path' => 'storage/app'],
            ['label' => 'Laravel の一時ファイル（画面のキャッシュなど）', 'path' => 'storage/framework'],
        ]);
    }

    /**
     * フォルダの中のファイルの合計の容量と数（読めないときは null）
     *
     * @return array{0: int|null, 1: int|null}
     */
    protected function directorySize(string $relative): array
    {
        $path = base_path($relative);
        if (! is_dir($path)) {
            return [null, null];
        }

        try {
            $bytes = 0;
            $files = 0;
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile()) {
                    $bytes += $file->getSize();
                    $files++;
                }
            }

            return [$bytes, $files];
        } catch (Throwable) {
            return [null, null];
        }
    }

    /**
     * CPU の数（ロードアベレージの目安に使う。読めないときは null）
     */
    protected function cpuCount(): ?int
    {
        $cpuinfo = $this->readProc('/proc/cpuinfo');
        $count = $cpuinfo ? preg_match_all('/^processor\s*:/m', $cpuinfo) : 0;

        return $count > 0 ? $count : null;
    }

    /**
     * サーバーの IP アドレス（画面を開いたときの受け口。なければホスト名から引く）
     */
    protected function ipAddress(?string $hostname): ?string
    {
        $ip = request()->server('SERVER_ADDR');
        if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
        if ($hostname === null) {
            return null;
        }
        $resolved = $this->safe(fn () => gethostbyname($hostname));

        return $resolved && filter_var($resolved, FILTER_VALIDATE_IP) ? $resolved : null;
    }

    /**
     * サーバーの情報のファイルを読む（読めないとき・制限で開けないときは null）
     */
    protected function readProc(string $path): ?string
    {
        return $this->safe(fn () => @is_readable($path) ? (@file_get_contents($path) ?: null) : null);
    }

    /**
     * 例外・警告で止めずに値を読む
     */
    protected function safe(callable $read): mixed
    {
        try {
            return $read();
        } catch (Throwable) {
            return null;
        }
    }
}
