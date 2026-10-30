<?php

namespace App\Services;

use App\Models\Backup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * 月1回のバックアップ（D-89。定期実行 backup:create）。
 *
 * サーバーの中（storage/app/backups/{日時}。ブラウザから直接は開けない場所）に、次の4つを作る：
 * ・WordPress の DB と BlogOS の DB（mysqldump。読み取るだけで、DB は書き換えない）
 * ・WordPress のファイル（wp-content。作り直せるキャッシュなどは除く）
 * ・秘密の情報（BlogOS の .env・WordPress の wp-config.php・Basic 認証のパスワードのファイル）。パスワード付きの zip（AES-256）
 * 外へ持ち出すのは人（XServer情報の画面からダウンロードする）。残すのは新しいものから決まった回数（既定 3回）。
 */
class BackupService
{
    // 作成中のまま、この時間を過ぎたものは、途中で止まったとみなす
    protected const STALE_RUNNING_HOURS = 3;

    /**
     * ファイルの種類（この順に作る・画面に出す）
     */
    public const FILES = [
        'wordpress_db' => ['file' => 'wordpress-db.sql.gz', 'label' => 'WordPress の DB', 'encrypted' => false],
        'blogos_db'    => ['file' => 'blogos-db.sql.gz', 'label' => 'BlogOS の DB', 'encrypted' => false],
        'wp_content'   => ['file' => 'wp-content.tar.gz', 'label' => 'WordPress のファイル（wp-content）', 'encrypted' => false],
        'secrets'      => ['file' => 'secrets.zip', 'label' => '秘密の情報（パスワード付き zip）', 'encrypted' => true],
    ];

    /**
     * バックアップを1回作る。ほかで作成中なら null。失敗しても例外にせず、失敗の記録を返す（作りかけのファイルは消す）
     */
    public function create(): ?Backup
    {
        return Cache::lock('backup:create', 3600)->get(function () {
            $this->closeStaleRunning();

            $backup = Backup::create([
                'name'       => now(config('blogos.display_timezone'))->format('Ymd-His'),
                'status'     => Backup::RUNNING,
                'started_at' => now(),
            ]);

            $files = [];
            $notes = [];
            try {
                $settings = $this->settings();
                File::ensureDirectoryExists($backup->directory(), 0700);

                foreach (self::FILES as $key => $definition) {
                    $path = $backup->directory() . DIRECTORY_SEPARATOR . $definition['file'];
                    $note = match ($key) {
                        'wordpress_db' => $this->dumpDatabase($settings['wordpress_database'], $path),
                        'blogos_db'    => $this->dumpDatabase((string) config('database.connections.' . config('database.default') . '.database'), $path),
                        'wp_content'   => $this->archiveWpContent($settings['wordpress_path'], $path),
                        'secrets'      => $this->secretsZip($settings, $path),
                    };
                    @chmod($path, 0600);
                    if ($note !== null) {
                        $notes[] = $note;
                    }
                    $files[] = ['key' => $key, 'file' => $definition['file'], 'label' => $definition['label'], 'bytes' => (int) filesize($path), 'encrypted' => $definition['encrypted'], 'downloaded_at' => null];
                }
            } catch (Throwable $e) {
                report($e);
                File::deleteDirectory($backup->directory());
                $backup->update(['status' => Backup::FAILED, 'message' => $e->getMessage(), 'finished_at' => now()]);

                return $backup;
            }

            $backup->update([
                'status'      => Backup::SUCCEEDED,
                'files'       => $files,
                'total_bytes' => array_sum(array_column($files, 'bytes')),
                'message'     => $notes !== [] ? implode("\n", $notes) : null,
                'finished_at' => now(),
            ]);
            $this->prune();

            return $backup;
        }) ?: null;
    }

    /**
     * 作るのに要る設定。足りなければ、何を .env に書けばよいかを例外で返す
     *
     * @return array{passphrase: string, wordpress_database: string, wordpress_path: string}
     */
    public function settings(): array
    {
        $passphrase = (string) config('blogos.backup.passphrase');
        $database = (string) config('blogos.backup.wordpress_database');
        $path = rtrim((string) config('blogos.backup.wordpress_path'), '/\\');

        $problems = [];
        if (mb_strlen($passphrase) < 12) {
            $problems[] = '秘密の情報の zip のパスワード（BLOGOS_BACKUP_PASSPHRASE。12文字以上）';
        }
        if ($database === '') {
            $problems[] = 'WordPress の DB の名前（BLOGOS_BACKUP_WORDPRESS_DB）';
        }
        if ($path === '' || ! is_dir($path . '/wp-content') || ! is_file($path . '/wp-config.php')) {
            $problems[] = 'WordPress のフォルダ（BLOGOS_BACKUP_WORDPRESS_PATH。wp-content と wp-config.php がある場所）';
        }
        if ($problems !== []) {
            throw new RuntimeException('.env の設定が足りないか、正しくありません：' . implode('、', $problems) . '。');
        }

        return ['passphrase' => $passphrase, 'wordpress_database' => $database, 'wordpress_path' => $path];
    }

    /**
     * DB を書き出して gzip にする。接続の情報（パスワード）は、コマンドの引数に出さず、一時的な設定ファイルで渡す
     */
    protected function dumpDatabase(string $database, string $target): ?string
    {
        $connection = config('database.connections.' . config('database.default'));
        $sql = substr($target, 0, -3);
        $options = $this->optionFile($connection);

        try {
            $command = [
                (string) config('blogos.backup.mysqldump'),
                '--defaults-extra-file=' . $options,
                '--single-transaction', '--quick', '--no-tablespaces',
                '--default-character-set=utf8mb4',
                '--result-file=' . $sql,
                $database,
            ];
            $result = Process::timeout((int) config('blogos.backup.timeout'))->run($command);
            // MySQL 8 の mysqldump は、ほかの種類の DB の情報（COLUMN_STATISTICS）を読もうとして失敗することがある。その場合は読まずに書き出し直す
            if (! $result->successful() && stripos($result->errorOutput(), 'column_statistics') !== false) {
                array_splice($command, 2, 0, ['--column-statistics=0']);
                $result = Process::timeout((int) config('blogos.backup.timeout'))->run($command);
            }
            if (! $result->successful()) {
                throw new RuntimeException("DB（{$database}）を書き出せませんでした：" . $this->firstLine($result->errorOutput() ?: $result->output()));
            }
            // 最後まで書き出せたか（mysqldump は、終わりに「Dump completed」を書く）
            if (! is_file($sql) || ! str_contains($this->tail($sql), 'Dump completed')) {
                throw new RuntimeException("DB（{$database}）の書き出しが、途中で終わっています。");
            }

            $this->gzip($sql, $target);
        } finally {
            @unlink($options);
            @unlink($sql);
        }

        return null;
    }

    /**
     * mysqldump に渡す接続の情報（[client]）。BlogOS だけが読めるよう、権限を絞る
     *
     * @param  array<string, mixed>  $connection
     */
    protected function optionFile(array $connection): string
    {
        $quote = fn ($value) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value) . '"';
        $lines = ['[client]', 'user=' . $quote($connection['username'] ?? ''), 'password=' . $quote($connection['password'] ?? '')];
        if (! empty($connection['unix_socket'])) {
            $lines[] = 'socket=' . $quote($connection['unix_socket']);
        } else {
            $lines[] = 'host=' . $quote($connection['host'] ?? '127.0.0.1');
            $lines[] = 'port=' . (int) ($connection['port'] ?? 3306);
        }

        File::ensureDirectoryExists((string) config('blogos.backup.path'), 0700);
        $path = rtrim((string) config('blogos.backup.path'), '/\\') . DIRECTORY_SEPARATOR . '.client-' . Str::random(16) . '.cnf';
        file_put_contents($path, implode("\n", $lines) . "\n");
        @chmod($path, 0600);

        return $path;
    }

    protected function gzip(string $source, string $target): void
    {
        $in = fopen($source, 'rb');
        $out = gzopen($target, 'wb6');
        if ($in === false || $out === false) {
            throw new RuntimeException('gzip のファイルを作れませんでした。');
        }
        while (! feof($in)) {
            gzwrite($out, (string) fread($in, 1048576));
        }
        fclose($in);
        gzclose($out);
    }

    /**
     * wp-content を tar.gz にする（作り直せるキャッシュなどは除く）
     */
    protected function archiveWpContent(string $wordpressPath, string $target): ?string
    {
        $command = [(string) config('blogos.backup.tar'), '-czf', $target];
        foreach ((array) config('blogos.backup.exclude') as $exclude) {
            $command[] = '--exclude=' . $exclude;
        }
        array_push($command, '-C', $wordpressPath, 'wp-content');

        $result = Process::timeout((int) config('blogos.backup.timeout'))->run($command);
        // GNU tar の終了コード 1 は「読んでいる間に変わったファイルがある」（作れてはいる）。注意として残す
        if ($result->exitCode() === 1 && is_file($target) && filesize($target) > 0) {
            return 'WordPress のファイル：作っている間に変わったファイルがありました（' . $this->firstLine($result->errorOutput()) . '）。';
        }
        if (! $result->successful() || ! is_file($target)) {
            throw new RuntimeException('WordPress のファイル（wp-content）をまとめられませんでした：' . $this->firstLine($result->errorOutput() ?: $result->output()));
        }

        return null;
    }

    /**
     * 秘密の情報を、パスワード付きの zip（AES-256）にする。暗号化できたかを開き直して確かめ、できていなければ消して失敗にする
     *
     * @param  array{passphrase: string, wordpress_path: string}  $settings
     */
    protected function secretsZip(array $settings, string $target): ?string
    {
        if (! class_exists(ZipArchive::class) || ! method_exists(ZipArchive::class, 'setEncryptionName') || ! defined(ZipArchive::class . '::EM_AES_256')) {
            throw new RuntimeException('この PHP では、パスワード付きの zip を作れません（ZipArchive の暗号化がない）。');
        }

        $entries = ['blogos.env' => base_path('.env'), 'wp-config.php' => $settings['wordpress_path'] . '/wp-config.php'];
        foreach ((array) config('blogos.backup.secret_files') as $path) {
            if (is_file($path)) {
                $entries[basename($path)] = $path;
            }
        }

        $zip = new ZipArchive();
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('秘密の情報の zip を作れませんでした。');
        }
        foreach ($entries as $name => $path) {
            if (! is_file($path) || ! $zip->addFile($path, $name) || ! $zip->setEncryptionName($name, ZipArchive::EM_AES_256, $settings['passphrase'])) {
                $zip->close();
                @unlink($target);
                throw new RuntimeException("秘密の情報の zip に {$name} を入れられませんでした（暗号化を含む）。");
            }
        }
        $zip->close();

        // 開き直して、全てが AES-256 で暗号化され、パスワードで読めることを確かめる（平文の秘密の情報を残さない）
        $check = new ZipArchive();
        $encrypted = $check->open($target) === true && $check->numFiles === count($entries);
        for ($i = 0; $encrypted && $i < $check->numFiles; $i++) {
            $encrypted = ($check->statIndex($i)['encryption_method'] ?? null) === ZipArchive::EM_AES_256;
        }
        if ($encrypted) {
            $check->setPassword($settings['passphrase']);
            $encrypted = $check->getFromName('blogos.env') === file_get_contents($entries['blogos.env']);
        }
        $check->close();
        if (! $encrypted) {
            @unlink($target);
            throw new RuntimeException('秘密の情報の zip を、パスワード付きで作れませんでした（この PHP の zip が AES-256 の暗号化に対応していない可能性があります）。');
        }

        return null;
    }

    /**
     * 新しいものから決まった回数だけ残し、古いもの（ファイルと記録）を消す。失敗の記録は、最新の成功より前のものを消す
     */
    public function prune(): void
    {
        $keep = max(1, (int) config('blogos.backup.keep'));
        $succeeded = Backup::succeeded()->latest('started_at')->latest('id')->get();
        foreach ($succeeded->slice($keep) as $old) {
            File::deleteDirectory($old->directory());
            $old->delete();
        }

        $newest = $succeeded->first();
        if ($newest !== null) {
            Backup::where('status', Backup::FAILED)->where('started_at', '<', $newest->started_at)->delete();
        }
    }

    /**
     * 作成中のまま止まったもの（サーバーの再起動など）を、失敗にする
     */
    protected function closeStaleRunning(): void
    {
        Backup::where('status', Backup::RUNNING)->where('started_at', '<', now()->subHours(self::STALE_RUNNING_HOURS))->get()
            ->each(function (Backup $backup) {
                File::deleteDirectory($backup->directory());
                $backup->update(['status' => Backup::FAILED, 'message' => '作成の途中で止まりました。', 'finished_at' => now()]);
            });
    }

    /**
     * ファイルの場所（成功したもので、ファイルが残っているときだけ）
     */
    public function path(Backup $backup, string $key): ?string
    {
        $file = $backup->status === Backup::SUCCEEDED ? $backup->file($key) : null;
        $path = $file !== null ? $backup->directory() . DIRECTORY_SEPARATOR . $file['file'] : null;

        return $path !== null && is_file($path) ? $path : null;
    }

    /**
     * ダウンロードした印を付ける。全てのファイルをダウンロードしたら、その回をダウンロード済みにする
     */
    public function markDownloaded(Backup $backup, string $key): void
    {
        $files = collect($backup->files ?? [])->map(fn (array $file) => $file['key'] === $key && $file['downloaded_at'] === null
            ? ['downloaded_at' => now()->toIso8601String()] + $file
            : $file)->all();
        $backup->files = $files;
        if ($backup->downloaded_at === null && collect($files)->every(fn (array $file) => $file['downloaded_at'] !== null)) {
            $backup->downloaded_at = now();
        }
        $backup->save();
    }

    /**
     * トップページの「作成しました」（緑）を1回だけ出す：まだ出していない、最新の成功したバックアップ。出した印を付ける
     */
    public function announce(): ?Backup
    {
        $latest = Backup::succeeded()->latest('started_at')->latest('id')->first();
        if ($latest === null || $latest->announced_at !== null) {
            return null;
        }
        $latest->update(['announced_at' => now()]);

        return $latest;
    }

    /**
     * お知らせの元（D-74）：最後の作成が失敗した（エラー）、最後に作れてから日がたった・まだ作っていない（注意）、
     * 作ってから日がたつのにダウンロードしていない（注意）
     *
     * @return array{failed: string|null, stale: int|null, never: bool, not_downloaded: list<string>}
     */
    public function noticeState(): array
    {
        $latest = Backup::where('status', '!=', Backup::RUNNING)->latest('started_at')->latest('id')->first();
        $success = Backup::succeeded()->latest('started_at')->latest('id')->first();
        $staleDays = (int) config('blogos.backup.stale_days');
        $downloadDays = (int) config('blogos.backup.download_days');

        return [
            'failed'         => $latest?->status === Backup::FAILED ? (string) $latest->message : null,
            'stale'          => $success !== null && $success->finished_at->lt(now()->subDays($staleDays)) ? (int) $success->finished_at->diffInDays(now()) : null,
            'never'          => $success === null,
            'not_downloaded' => $success !== null && $success->downloaded_at === null && $success->finished_at->lte(now()->subDays($downloadDays))
                ? $success->notDownloaded() : [],
        ];
    }

    protected function firstLine(string $text): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: [])));

        return mb_substr($lines[0] ?? '理由は不明', 0, 300);
    }

    protected function tail(string $path): string
    {
        $handle = fopen($path, 'rb');
        fseek($handle, -min(512, (int) filesize($path)), SEEK_END);
        $tail = (string) fread($handle, 512);
        fclose($handle);

        return $tail;
    }
}
