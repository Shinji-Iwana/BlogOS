<?php

namespace App\Services\Images;

use App\Enums\ChangeSource;
use App\Enums\DraftState;
use App\Enums\ImageKind;
use App\Enums\ImageSource;
use App\Enums\ImageStatus;
use App\Enums\PushState;
use App\Enums\RevisionScope;
use App\Enums\SyncIssueType;
use App\Models\ArticleDraft;
use App\Models\Blog;
use App\Models\Image;
use App\Models\Page;
use App\Models\Post;
use App\Models\WordPressPushOperation;
use App\Repositories\ArticleContentRepository;
use App\Repositories\ArticleDraftRepository;
use App\Repositories\SyncIssueRepository;
use App\Services\Articles\DraftService;
use App\Services\Push\ArticlePushService;
use App\Services\Push\PushException;
use App\Services\ServerStatusService;
use App\Support\WordPressDatabaseTables;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 古い画像（/wp-content/img/…）の移し替え（D-90）。
 *
 * 古い記事は、メディアライブラリにない /wp-content/img/ の画像を使っている。記事ごとに、
 * 1. 本文の古い画像を拾い、サイトから読み込んで BlogOS の画像にする（同じファイルは1回だけ）
 * 2. 人が名前・alt・ファイル名を入れて確認済みにし、人の承認で WordPress のメディアに登録する
 * 3. 公開中の記事は URL だけを置き換えて反映し（人の承認。作業中の編集案とは別）、全ての作業中の編集案の URL も置き換える
 * 4. どこにも使われなくなった古いファイルを、人が SSH で消すためのコマンドを出す（BlogOS は WordPress のファイルを消さない）
 */
class LegacyImageService
{
    public const PREFIX = '/wp-content/img/';

    public const REASON = 'legacy_images';

    /**
     * 1回のまとめての取り込み・登録の上限（サイトや WordPress への通信を続けすぎないため）
     */
    public const IMPORT_LIMIT = 30;

    public const UPLOAD_LIMIT = 20;

    /**
     * テーマの中で、古い画像の URL を探すファイル
     */
    protected const THEME_EXTENSIONS = ['php', 'css', 'js', 'html', 'htm', 'json', 'txt', 'svg'];

    public function __construct(
        protected ImageService $images,
        protected ImageUploadService $uploader,
        protected DraftService $draftService,
        protected ArticleDraftRepository $drafts,
        protected ArticlePushService $pushService,
        protected SyncIssueRepository $issues,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | 拾う
    |--------------------------------------------------------------------------
    */

    /**
     * 本文の古い画像のパス（/wp-content/img/… 。URL の符号化を戻した形。出てくる順）
     *
     * @return list<string>
     */
    public function paths(Blog $blog, string $content): array
    {
        if (! str_contains($content, 'wp-content/img/')) {
            return [];
        }
        preg_match_all($this->pattern($blog), $content, $matches);

        return array_values(array_unique(array_map(fn ($path) => rawurldecode($path), $matches[1])));
    }

    /**
     * 記事の古い画像の一覧（記事の画面のパネル）
     *
     * @return list<array{path: string, url: string, image: Image|null, others: list<Post|Page>}>
     */
    public function entries(Blog $blog, Post|Page $article): array
    {
        $paths = $this->paths($blog, (string) $article->content_raw);
        if ($paths === []) {
            return [];
        }

        $images = $this->imagesFor($blog, $paths);
        $articles = $this->articlesWithLegacy($blog);

        return array_map(fn (string $path) => [
            'path'   => $path,
            'url'    => $this->url($blog, $path),
            'image'  => $images->get($path),
            // 同じ古い画像を使っている、ほかの記事
            'others' => array_values(array_filter($articles, fn ($other) => ! ($other::class === $article::class && $other->id === $article->id)
                && in_array($path, $this->paths($blog, (string) $other->content_raw), true))),
        ], $paths);
    }

    /**
     * 古い画像が残っている記事（古い画像の削除の画面で、次に移し替える記事を選ぶため）
     *
     * @return list<array{article: Post|Page, paths: int, imported: int, registered: int}>
     */
    public function remaining(Blog $blog): array
    {
        $rows = [];
        foreach ($this->articlesWithLegacy($blog) as $article) {
            $paths = $this->paths($blog, (string) $article->content_raw);
            if ($paths === []) {
                continue;
            }
            $images = $this->imagesFor($blog, $paths);
            $rows[] = [
                'article'    => $article,
                'paths'      => count($paths),
                'imported'   => $images->count(),
                'registered' => $images->filter(fn (Image $image) => $image->media_id !== null)->count(),
            ];
        }

        return $rows;
    }

    /*
    |--------------------------------------------------------------------------
    | 取り込む・登録する
    |--------------------------------------------------------------------------
    */

    /**
     * 古い画像をサイトから読み込み、BlogOS の画像（スクリーンショット）にする。取り込み済みなら、その画像を返す
     *
     * @throws ImageException
     */
    public function import(Blog $blog, string $path, ?int $userId): Image
    {
        if (! str_starts_with($path, self::PREFIX)) {
            throw new ImageException("古い画像のパスではありません：{$path}");
        }
        if ($existing = Image::where('blog_id', $blog->id)->where('legacy_path', $path)->first()) {
            return $existing;
        }

        try {
            $response = Http::timeout(30)->get($this->url($blog, $path));
        } catch (ConnectionException $e) {
            throw new ImageException("{$path} を読み込めませんでした：{$e->getMessage()}");
        }
        if ($response->failed()) {
            throw new ImageException("{$path} を読み込めませんでした（HTTP {$response->status()}）。");
        }

        $image = $this->images->create($blog, ImageKind::Screenshot, pathinfo($path, PATHINFO_FILENAME), null, $userId, ['legacy_path' => $path]);
        try {
            $this->images->storeBytes($image, $response->body(), ImageSource::Legacy);
        } catch (ImageException $e) {
            $this->images->delete($image);

            throw new ImageException("{$path}：{$e->getMessage()}");
        }

        return $image->fresh();
    }

    /**
     * 記事の、まだ取り込んでいない古い画像をまとめて取り込む
     *
     * @return array{imported: int, errors: list<string>}
     */
    public function importAll(Blog $blog, Post|Page $article, ?int $userId): array
    {
        $paths = $this->paths($blog, (string) $article->content_raw);
        $done = $this->imagesFor($blog, $paths)->keys()->all();

        $imported = 0;
        $errors = [];
        foreach (array_slice(array_values(array_diff($paths, $done)), 0, self::IMPORT_LIMIT) as $path) {
            try {
                $this->import($blog, $path, $userId);
                $imported++;
            } catch (ImageException $e) {
                $errors[] = $e->getMessage();
            }
        }

        return ['imported' => $imported, 'errors' => $errors];
    }

    /**
     * 記事の古い画像のうち、確認済みでまだ登録していない画像を、WordPress のメディアにまとめて登録する（人の承認）
     *
     * @return array{uploaded: int, errors: list<string>}
     */
    public function uploadReady(Blog $blog, Post|Page $article, ?int $userId): array
    {
        $images = $this->imagesFor($blog, $this->paths($blog, (string) $article->content_raw))
            ->filter(fn (Image $image) => $image->status === ImageStatus::Ready && $image->media_id === null)
            ->take(self::UPLOAD_LIMIT);

        $uploaded = 0;
        $errors = [];
        foreach ($images as $image) {
            try {
                $operation = $this->uploader->upload($image, $userId);
                if ($operation->state === PushState::Completed) {
                    $uploaded++;
                } else {
                    $errors[] = "{$image->legacy_path}：{$operation->state->label()}（{$operation->message}）";
                }
            } catch (PushException $e) {
                $errors[] = "{$image->legacy_path}：{$e->getMessage()}";
            }
        }

        return ['uploaded' => $uploaded, 'errors' => $errors];
    }

    /*
    |--------------------------------------------------------------------------
    | 置き換える
    |--------------------------------------------------------------------------
    */

    /**
     * WordPress に登録した画像について、本文の古い画像を置き換える。
     * img は src・alt・width・height・class（wp-image-ID）を直し、ほかの属性と、囲むリンク（href）などの古い URL もメディアの URL にする
     *
     * @return array{content: string, replaced: list<array{path: string, url: string}>}
     */
    public function replacements(Blog $blog, string $content): array
    {
        $images = $this->imagesFor($blog, $this->paths($blog, $content))
            ->filter(fn (Image $image) => filled($image->media?->source_url));
        if ($images->isEmpty()) {
            return ['content' => $content, 'replaced' => []];
        }

        $content = preg_replace_callback('/<img\b[^>]*>/i', function (array $match) use ($blog, $images) {
            $tag = $match[0];
            $path = $this->pathOf($blog, (string) self::attribute($tag, 'src'));
            $image = $path !== null ? $images->get($path) : null;
            if ($image === null) {
                return $tag;
            }

            $media = $image->media;
            $tag = self::setAttribute($tag, 'src', $media->source_url);
            $tag = self::setAttribute($tag, 'alt', (string) ($image->alt ?: $media->alt_text));
            if ($media->width && $media->height) {
                $tag = self::setAttribute($tag, 'width', (string) $media->width);
                $tag = self::setAttribute($tag, 'height', (string) $media->height);
            }
            $class = trim((string) preg_replace('/\s*\bwp-image-\d+\b/', '', (string) self::attribute($tag, 'class')) . " wp-image-{$media->wordpress_id}");
            $tag = self::setAttribute($tag, 'class', $class);

            // 古いファイルの縮小版を指す指定は、残すと古いファイルを読み込むため外す
            return self::removeAttribute(self::removeAttribute($tag, 'srcset'), 'sizes');
        }, $content) ?? $content;

        // 画像を囲むリンクなど、残りの古い URL
        $content = preg_replace_callback($this->pattern($blog), function (array $match) use ($images) {
            $image = $images->get(rawurldecode($match[1]));

            return $image !== null ? $image->media->source_url : $match[0];
        }, $content) ?? $content;

        return [
            'content'  => $content,
            'replaced' => $images->map(fn (Image $image, string $path) => ['path' => $path, 'url' => $image->media->source_url])->values()->all(),
        ];
    }

    /**
     * 作業中の編集案のうち、古い画像の URL を置き換えられるもの（確認画面に出す）
     *
     * @return Collection<int, ArticleDraft>
     */
    public function draftsToUpdate(Blog $blog): Collection
    {
        return $this->activeDraftsWithLegacy($blog)->filter(fn (ArticleDraft $draft) => $this->replacements($blog, (string) $draft->content_raw)['replaced'] !== [])->values();
    }

    /**
     * 公開中の記事の古い画像の URL だけを置き換えて反映し（人の承認）、全ての作業中の編集案の URL も置き換える。
     *
     * 反映には、URL だけを直した BlogOS の内部の編集案を使い、作ってすぐに反映する（作業中の編集案とは別。作業中の編集案が2つ並ぶ時間を作らない）。
     * 反映した記事の作業中の編集案が、反映の直前の版を元にしていたら、元の版を反映後の版に進める（同じ置き換えを編集案にも入れるため、競合にしない）
     *
     * @return array{operation: WordPressPushOperation|null, replaced: int, drafts: int, error: string|null}
     *
     * @throws ImageException 置き換えられる画像がない場合
     */
    public function replace(Blog $blog, Post|Page $article, ?int $userId): array
    {
        $result = $this->replacements($blog, (string) $article->content_raw);
        if ($result['replaced'] === []) {
            throw new ImageException('置き換えられる画像がありません。古い画像を WordPress のメディアに登録してから押してください。');
        }

        $previous = $article->wordpress_modified_gmt?->format('Y-m-d H:i:s');
        $column = ArticleContentRepository::articleColumn($article);
        $activeIds = ArticleDraft::active()->where($column, $article->id)->pluck('id')->all();

        $error = null;
        $operation = null;
        try {
            $draft = $this->draftService->createFromArticle($article, RevisionScope::Minor, $userId, null, true);
            $this->drafts->update($draft, ['content_raw' => $result['content']], ChangeSource::System, $userId);
            $draft->forceFill([
                'auto_reason'  => self::REASON,
                'finish_notes' => ['古い画像（/wp-content/img/）の URL だけを、メディアの画像に置き換えました：' . count($result['replaced']) . '件。'],
            ])->save();

            try {
                $operation = $this->pushService->push($draft, $userId)->fresh();
            } catch (PushException $e) {
                $error = $e->getMessage();
            }

            if ($operation?->state === PushState::Completed) {
                $article->refresh();
                $this->advanceBase($activeIds, $previous, $article->wordpress_modified_gmt, $userId);
            } elseif ($operation === null || $operation->state === PushState::Failed) {
                // 反映できなかった内部の編集案は破棄する（作業中の編集案が2つ並ばないように）
                $error ??= (string) $operation?->message;
                $this->drafts->changeState($draft->fresh(), DraftState::Discarded, ChangeSource::System, $userId);
                $this->issues->resolveRelated($blog->id, [SyncIssueType::Conflict], $operation?->id, $draft->id, $userId,
                    '古い画像の URL の置き換えを中止しました。同期してから、もう一度置き換えてください。');
            }
        } catch (PushException $e) {
            $error = $e->getMessage();
        }

        // 作業中の編集案は、反映できたかによらず置き換える（WordPress は変えない）
        $drafts = $this->replaceInDrafts($blog, $userId);

        return ['operation' => $operation, 'replaced' => count($result['replaced']), 'drafts' => $drafts, 'error' => $error];
    }

    /**
     * 全ての作業中の編集案の、WordPress に登録した古い画像の URL を置き換える（WordPress は変えない。反映の結果待ちの編集案は変えない）
     *
     * @return int 置き換えた編集案の数
     */
    public function replaceInDrafts(Blog $blog, ?int $userId): int
    {
        $count = 0;
        foreach ($this->activeDraftsWithLegacy($blog) as $draft) {
            if ($draft->isLocked()) {
                continue;
            }
            $result = $this->replacements($blog, (string) $draft->content_raw);
            if ($result['replaced'] === []) {
                continue;
            }
            $this->drafts->update($draft, ['content_raw' => $result['content']], ChangeSource::System, $userId);
            $note = '古い画像（/wp-content/img/）を、メディアの画像に置き換えました：' . count($result['replaced']) . '件。';
            $draft->forceFill(['finish_notes' => array_values(array_unique(array_merge((array) $draft->finish_notes, [$note])))])->save();
            $count++;
        }

        return $count;
    }

    /*
    |--------------------------------------------------------------------------
    | 削除の一覧
    |--------------------------------------------------------------------------
    */

    /**
     * WordPress のメディアに登録した古いファイルのうち、どこにも使われていないもの（削除のコマンドを出す）と、まだ使われているもの。
     * BlogOS の記事・編集案に加えて、WordPress の DB（全てのテーブルの文字の列。投稿の版（revision）は除く）とテーマのファイルも調べる。
     * WordPress の DB かテーマを読めないときは、一覧を出さない（記事以外での使用を見落とさないため）。
     * 古いファイルの横にある縮小版（名前-幅x高さ.拡張子。以前 WordPress が作ったもの）も、1つずつ同じように調べる（元のファイルを消した後も出す）
     *
     * @return array{available: bool, reason: string|null, deletable: list<array{image: Image, path: string, file: string}>, used: list<array{image: Image, path: string, places: list<string>}>, command: string}
     */
    public function deletion(Blog $blog): array
    {
        $empty = ['available' => false, 'deletable' => [], 'used' => [], 'command' => ''];
        $root = rtrim((string) config('blogos.backup.wordpress_path'), '/');
        if ($root === '' || ! is_dir("{$root}/wp-content")) {
            return $empty + ['reason' => 'WordPress のフォルダを読めません（.env の BLOGOS_BACKUP_WORDPRESS_PATH）。'];
        }
        $schema = $this->schemaFor($blog);
        if ($schema === null) {
            return $empty + ['reason' => 'このブログの WordPress の DB を読めません。'];
        }

        // 登録済みの画像の、まだ残っている古いファイルと縮小版（パスをキーにする）
        $candidates = collect();
        foreach (Image::with('media')->where('blog_id', $blog->id)->whereNotNull('legacy_path')->whereNotNull('media_id')->orderBy('legacy_path')->get() as $image) {
            foreach ($this->legacyFiles($root, $image->legacy_path) as $path) {
                $candidates->put($path, $image);
            }
        }
        if ($candidates->isEmpty()) {
            return ['available' => true, 'reason' => null, 'deletable' => [], 'used' => [], 'command' => ''];
        }

        $places = [];
        $note = function (string $text, string $place) use ($candidates, &$places) {
            foreach ($candidates->keys() as $path) {
                if (str_contains($text, ltrim($path, '/')) || str_contains($text, ltrim(self::encode($path), '/'))) {
                    $places[$path][] = $place;
                }
            }
        };

        foreach ($this->articlesWithLegacy($blog) as $article) {
            $note((string) $article->content_raw, "BlogOS の記事「{$article->title_raw}」");
        }
        foreach ($this->activeDraftsWithLegacy($blog) as $draft) {
            $note((string) $draft->content_raw, "作業中の編集案 #{$draft->id}");
        }
        foreach ($this->wordPressTexts($schema) as [$place, $text]) {
            $note($text, $place);
        }
        foreach (File::allFiles("{$root}/wp-content/themes") as $file) {
            if (in_array(strtolower($file->getExtension()), self::THEME_EXTENSIONS, true) && $file->getSize() < 5 * 1024 * 1024) {
                $note((string) file_get_contents($file->getPathname()), 'テーマ：' . $file->getRelativePathname());
            }
        }

        $deletable = [];
        $used = [];
        foreach ($candidates as $path => $image) {
            if (isset($places[$path])) {
                $used[] = ['image' => $image, 'path' => $path, 'places' => array_values(array_unique($places[$path]))];
            } else {
                $deletable[] = ['image' => $image, 'path' => $path, 'file' => $root . $path];
            }
        }

        return [
            'available' => true,
            'reason'    => null,
            'deletable' => $deletable,
            'used'      => $used,
            'command'   => implode("\n", array_map(fn ($row) => "rm -f -- '" . str_replace("'", "'\\''", $row['file']) . "'", $deletable)),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | 共通の処理
    |--------------------------------------------------------------------------
    */

    /**
     * 古い画像のサイトの URL（パスの各部分を符号化する）
     */
    public function url(Blog $blog, string $path): string
    {
        return rtrim($blog->home, '/') . self::encode($path);
    }

    /**
     * 古い画像を指す URL（相対・このブログの絶対 URL）。ほかのサイトの URL の一部には一致させない
     */
    protected function pattern(Blog $blog): string
    {
        $host = preg_quote((string) preg_replace('/^www\./i', '', (string) parse_url($blog->home, PHP_URL_HOST)), '#');

        return '#(?:(?:https?:)?//(?:www\.)?' . $host . '|(?<![\w.:/-]))(/wp-content/img/[^"\'\s<>()?\#]+)#iu';
    }

    /**
     * 属性の値（src など）が古い画像なら、そのパス
     */
    protected function pathOf(Blog $blog, string $url): ?string
    {
        return preg_match($this->pattern($blog), trim($url), $match) === 1 && str_ends_with(trim($url), $match[1]) ? rawurldecode($match[1]) : null;
    }

    /**
     * 古いファイルと、その縮小版（名前-幅x高さ.拡張子）のうち、残っているもののパス
     *
     * @return list<string>
     */
    protected function legacyFiles(string $root, string $path): array
    {
        $directory = dirname($path);
        $name = pathinfo($path, PATHINFO_FILENAME);
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        $paths = is_file($root . $path) ? [$path] : [];
        foreach (is_dir($root . $directory) ? (scandir($root . $directory) ?: []) : [] as $file) {
            if (preg_match('/^' . preg_quote($name, '/') . '-\d+x\d+\.' . preg_quote($extension, '/') . '$/', $file) === 1 && is_file("{$root}{$directory}/{$file}")) {
                $paths[] = "{$directory}/{$file}";
            }
        }

        return $paths;
    }

    /**
     * 取り込んだ画像（パスをキーにする）
     *
     * @param list<string> $paths
     * @return Collection<string, Image>
     */
    protected function imagesFor(Blog $blog, array $paths): Collection
    {
        if ($paths === []) {
            return collect();
        }

        return Image::with('media')->where('blog_id', $blog->id)->whereIn('legacy_path', $paths)->get()->keyBy('legacy_path');
    }

    /**
     * 本文に古い画像がある記事（WordPress で削除されていないもの。ゴミ箱・非公開も含む）
     *
     * @return list<Post|Page>
     */
    protected function articlesWithLegacy(Blog $blog): array
    {
        $articles = [];
        foreach ([Post::class, Page::class] as $modelClass) {
            foreach ($modelClass::where('blog_id', $blog->id)->existing()->where('content_raw', 'like', '%wp-content/img/%')->orderBy('id')->get() as $article) {
                $articles[] = $article;
            }
        }

        return $articles;
    }

    /**
     * @return Collection<int, ArticleDraft>
     */
    protected function activeDraftsWithLegacy(Blog $blog): Collection
    {
        return ArticleDraft::with(['post:id,title_raw', 'page:id,title_raw'])->where('blog_id', $blog->id)->active()
            ->where('content_raw', 'like', '%wp-content/img/%')->orderBy('id')->get();
    }

    /**
     * 反映した記事の作業中の編集案のうち、反映の直前の版を元にしていたものの元の版を、反映後の版に進める
     *
     * @param list<int> $draftIds
     */
    protected function advanceBase(array $draftIds, ?string $previous, mixed $current, ?int $userId): void
    {
        foreach (ArticleDraft::whereIn('id', $draftIds)->active()->get() as $draft) {
            if ($previous !== null && $draft->base_wordpress_modified_gmt?->format('Y-m-d H:i:s') === $previous) {
                $this->drafts->update($draft, ['base_wordpress_modified_gmt' => $current], ChangeSource::System, $userId);
            }
        }
    }

    /**
     * このブログの WordPress の DB の名前（options の home で探す。読めなければ null）
     */
    protected function schemaFor(Blog $blog): ?string
    {
        try {
            $status = app(ServerStatusService::class);
            foreach ($status->otherSchemas() as $schema) {
                if ($status->blogForSchema($schema)?->id === $blog->id) {
                    return $schema;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('古い画像：WordPress の DB を探せませんでした。', ['message' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * WordPress の DB の、古い画像の URL を含む文字の値と、その場所（全てのテーブルの文字の列。投稿の版は除く）
     *
     * @return list<array{0: string, 1: string}>
     */
    protected function wordPressTexts(string $schema): array
    {
        $tables = app(WordPressDatabaseTables::class);
        $posts = $tables->tableName($schema, 'posts');
        $columns = DB::select(
            'select TABLE_NAME as tbl, COLUMN_NAME as col from information_schema.COLUMNS where TABLE_SCHEMA = ? and DATA_TYPE in (?, ?, ?, ?, ?, ?, ?) order by TABLE_NAME, ORDINAL_POSITION',
            [$schema, 'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'json'],
        );

        $texts = [];
        foreach ($columns as $column) {
            $query = DB::table("{$schema}.{$column->tbl}")->where($column->col, 'like', '%wp-content/img/%');
            if ($column->tbl === $posts) {
                $query->where('post_type', '!=', 'revision');
            }
            foreach ($query->limit(1000)->pluck($column->col) as $value) {
                $texts[] = ["WordPress の DB：{$column->tbl}.{$column->col}", (string) $value];
            }
        }

        return $texts;
    }

    /**
     * パスの各部分を URL の形に符号化する（/ はそのまま）
     */
    protected static function encode(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /**
     * タグの属性の値（なければ null）
     */
    protected static function attribute(string $tag, string $name): ?string
    {
        if (preg_match('/\s' . preg_quote($name, '/') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $match) !== 1) {
            return null;
        }

        return html_entity_decode($match[1] !== '' ? $match[1] : ($match[2] ?? '') . ($match[3] ?? ''), ENT_QUOTES | ENT_HTML5);
    }

    /**
     * タグの属性を置き換える（なければ、タグの最後に加える）
     */
    protected static function setAttribute(string $tag, string $name, string $value): string
    {
        $attribute = ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES) . '"';
        $pattern = '/\s' . preg_quote($name, '/') . '\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i';
        if (preg_match($pattern, $tag) === 1) {
            return (string) preg_replace_callback($pattern, fn () => $attribute, $tag, 1);
        }

        return (string) preg_replace_callback('#\s*(/?)>$#', fn ($match) => $attribute . ($match[1] === '/' ? ' />' : '>'), $tag, 1);
    }

    protected static function removeAttribute(string $tag, string $name): string
    {
        return (string) preg_replace('/\s' . preg_quote($name, '/') . '\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $tag);
    }
}
