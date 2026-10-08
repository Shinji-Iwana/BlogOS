<?php

namespace App\Http\Controllers\Database;

use App\Http\Controllers\Controller;
use App\Models\Author;
use App\Models\Category;
use App\Models\CustomContent;
use App\Models\CustomTerm;
use App\Models\Media;
use App\Models\Page;
use App\Models\Post;
use App\Models\Status;
use App\Models\Tag;
use App\Models\Taxonomy;
use App\Models\Type;
use App\Models\WordPressRecord;
use App\Repositories\BlogRepository;
use App\Support\HistoryPage;
use Illuminate\Http\Request;

/**
 * WordPress由来のテーブルのDB確認画面（D-05-10、D-11-02）。
 *
 * 選択中のブログのレコードを、WordPress側で完全削除されたもの（論理削除）も含めて表示する。
 * 詳細では、全ての列と変更履歴を表示する。閲覧だけで、変更はしない。
 */
class WordPressRecordController extends Controller
{
    /**
     * 表示できるテーブルと、一覧に表示する列。search は絞り込みの対象の列
     */
    protected const TABLES = [
        'posts'      => ['label' => '投稿', 'model' => Post::class, 'search' => ['title_raw', 'slug'], 'columns' => ['wordpress_id', 'status', 'title_raw', 'slug', 'wordpress_modified', 'synced_at']],
        'pages'      => ['label' => '固定ページ', 'model' => Page::class, 'search' => ['title_raw', 'slug'], 'columns' => ['wordpress_id', 'status', 'title_raw', 'slug', 'wordpress_parent_id', 'wordpress_modified', 'synced_at']],
        'media'      => ['label' => 'メディア', 'model' => Media::class, 'search' => ['title_raw', 'slug'], 'columns' => ['wordpress_id', 'title_raw', 'mime_type', 'source_url', 'wordpress_post_id', 'wordpress_modified', 'synced_at']],
        'categories' => ['label' => 'カテゴリ', 'model' => Category::class, 'search' => ['name', 'slug'], 'columns' => ['wordpress_id', 'name', 'slug', 'wordpress_parent_id', 'synced_at']],
        'tags'       => ['label' => 'タグ', 'model' => Tag::class, 'search' => ['name', 'slug'], 'columns' => ['wordpress_id', 'name', 'slug', 'synced_at']],
        'authors'    => ['label' => '投稿者', 'model' => Author::class, 'search' => ['name', 'slug'], 'columns' => ['wordpress_id', 'name', 'slug', 'synced_at']],
        'custom_contents' => ['label' => 'カスタム投稿タイプの内容', 'model' => CustomContent::class, 'search' => ['title_raw', 'slug', 'type'], 'columns' => ['wordpress_id', 'type', 'status', 'title_raw', 'slug', 'wordpress_modified', 'synced_at']],
        'custom_terms'    => ['label' => 'カスタムタクソノミーの項目', 'model' => CustomTerm::class, 'search' => ['name', 'slug', 'taxonomy'], 'columns' => ['wordpress_id', 'taxonomy', 'name', 'slug', 'wordpress_parent_id', 'synced_at']],
        'statuses'   => ['label' => '投稿ステータスの定義', 'model' => Status::class, 'search' => ['slug', 'name'], 'columns' => ['slug', 'name', 'public', 'synced_at']],
        'types'      => ['label' => '投稿タイプの定義', 'model' => Type::class, 'search' => ['slug', 'name'], 'columns' => ['slug', 'name', 'rest_base', 'synced_at']],
        'taxonomies' => ['label' => 'タクソノミーの定義', 'model' => Taxonomy::class, 'search' => ['slug', 'name'], 'columns' => ['slug', 'name', 'rest_base', 'synced_at']],
    ];

    public function __construct(
        protected BlogRepository $blogRepository
    ) {
    }

    /**
     * テーブルの一覧（DB確認画面の入口）
     */
    public function tables()
    {
        $blog = $this->blogRepository->findSelected();
        abort_if($blog === null, 404, 'ブログが選択されていません。');

        $counts = [];
        foreach (self::TABLES as $table => $definition) {
            $counts[$table] = [
                'label'   => $definition['label'],
                'total'   => $definition['model']::where('blog_id', $blog->id)->count(),
                'deleted' => $definition['model']::where('blog_id', $blog->id)->whereNotNull('wordpress_deleted_at')->count(),
            ];
        }

        return view('database.wordpress-records.tables', ['blog' => $blog, 'counts' => $counts]);
    }

    public function index(Request $request, string $table)
    {
        $definition = $this->definition($table);
        $blog = $this->blogRepository->findSelected();
        abort_if($blog === null, 404, 'ブログが選択されていません。');

        $keyword = trim((string) $request->query('q', ''));

        $records = $definition['model']::where('blog_id', $blog->id)
            ->when($keyword !== '', function ($query) use ($definition, $keyword) {
                $query->where(function ($query) use ($definition, $keyword) {
                    foreach ($definition['search'] as $column) {
                        $query->orWhere($column, 'like', '%' . addcslashes($keyword, '%_\\') . '%');
                    }
                });
            })
            ->orderBy($definition['columns'][0])
            ->paginate(HistoryPage::PER_PAGE)
            ->withQueryString();

        return view('database.wordpress-records.index', [
            'table'      => $table,
            'definition' => $definition,
            'records'    => $records,
            'keyword'    => $keyword,
        ]);
    }

    public function show(string $table, int $id)
    {
        $definition = $this->definition($table);
        $blog = $this->blogRepository->findSelected();
        abort_if($blog === null, 404, 'ブログが選択されていません。');

        /** @var WordPressRecord|null $record */
        $record = $definition['model']::where('blog_id', $blog->id)->find($id);
        abort_if($record === null, 404);

        $historyClass = $record::historyClass();
        $histories = $historyClass::where($record::historyForeignKey(), $record->id)
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->paginate(HistoryPage::PER_PAGE)
            ->withQueryString();

        return view('database.wordpress-records.show', [
            'table'      => $table,
            'definition' => $definition,
            'record'     => $record,
            'histories'  => $histories,
            'relations'  => $this->relations($table, $record, $blog->id),
        ]);
    }

    /**
     * 詳細の画面の「関連」に出す、ほかのデータとのつながり（列の値から。D-72-09）。
     * [列, 表示名, つながる先のテーブル, つながる先の列（id・slug）, 値が一覧（JSON）か]
     */
    protected const RELATIONS = [
        'posts' => [
            ['author_id', '投稿者', 'authors', 'id', false],
            ['featured_media_id', 'アイキャッチ画像', 'media', 'id', false],
            ['type', '投稿タイプ', 'types', 'slug', false],
            ['status', '投稿ステータス', 'statuses', 'slug', false],
        ],
        'pages' => [
            ['author_id', '投稿者', 'authors', 'id', false],
            ['featured_media_id', 'アイキャッチ画像', 'media', 'id', false],
            ['parent_id', '親ページ', 'pages', 'id', false],
            ['type', '投稿タイプ', 'types', 'slug', false],
            ['status', '投稿ステータス', 'statuses', 'slug', false],
        ],
        'media' => [
            ['author_id', '投稿者', 'authors', 'id', false],
            ['post_id', '添付先の投稿', 'posts', 'id', false],
            ['page_id', '添付先の固定ページ', 'pages', 'id', false],
            ['status', '投稿ステータス', 'statuses', 'slug', false],
        ],
        'categories' => [
            ['parent_id', '親カテゴリ', 'categories', 'id', false],
        ],
        'custom_contents' => [
            ['author_id', '投稿者', 'authors', 'id', false],
            ['featured_media_id', 'アイキャッチ画像', 'media', 'id', false],
            ['parent_id', '親', 'custom_contents', 'id', false],
            ['type', '投稿タイプ', 'types', 'slug', false],
            ['status', '投稿ステータス', 'statuses', 'slug', false],
        ],
        'custom_terms' => [
            ['parent_id', '親', 'custom_terms', 'id', false],
            ['taxonomy', 'タクソノミー', 'taxonomies', 'slug', false],
        ],
        'types' => [
            ['taxonomies', 'タクソノミー', 'taxonomies', 'slug', true],
        ],
        'taxonomies' => [
            ['types', '投稿タイプ', 'types', 'slug', true],
        ],
    ];

    /**
     * @return list<array{column: string|null, label: string, items: list<array{text: string, url: string|null}>}>
     */
    protected function relations(string $table, WordPressRecord $record, int $blogId): array
    {
        $record->loadMissing('blog');
        $relations = [[
            'column' => 'blog_id',
            'label'  => 'ブログ',
            'items'  => [['text' => $record->blog?->display_name ?? '#' . $record->blog_id, 'url' => route('database-blog-detail', ['id' => $record->blog_id])]],
        ]];

        foreach (self::RELATIONS[$table] ?? [] as [$column, $label, $target, $key, $many]) {
            $raw = $record->getRawOriginal($column);
            $values = $many ? (array) (json_decode((string) $raw, true) ?? []) : [$raw];
            $values = array_values(array_filter($values, fn ($value) => $value !== null && $value !== '' && $value !== 0 && $value !== '0'));

            $model = self::TABLES[$target]['model'];
            $found = $values === [] ? collect() : $model::where('blog_id', $blogId)->whereIn($key, $values)->get()->keyBy($key);
            $relations[] = [
                'column' => $column,
                'label'  => $label,
                'items'  => array_map(fn ($value) => ($related = $found->get($value))
                    ? ['text' => $this->recordName($related), 'url' => route('database.wordpress-records.show', ['table' => $target, 'id' => $related->id])]
                    : ['text' => $value . '（取り込んだデータにない）', 'url' => null], $values),
            ];
        }

        // 投稿のカテゴリ・タグ（列ではなく、つなぐ表で持つ）
        if ($record instanceof Post) {
            $record->load(['categories', 'tags']);
            foreach (['categories' => 'カテゴリ', 'tags' => 'タグ'] as $target => $label) {
                $relations[] = [
                    'column' => null,
                    'label'  => $label,
                    'items'  => $record->{$target}->map(fn ($related) => ['text' => $this->recordName($related), 'url' => route('database.wordpress-records.show', ['table' => $target, 'id' => $related->id])])->all(),
                ];
            }
        }

        return $relations;
    }

    /**
     * つながる先のデータの名前（タイトル・名前・スラッグのどれか）と番号
     */
    protected function recordName(WordPressRecord $record): string
    {
        $name = $record->getRawOriginal('title_raw') ?: $record->getRawOriginal('name') ?: $record->getRawOriginal('slug') ?: '';

        return trim($name . ' #' . $record->id);
    }

    protected function definition(string $table): array
    {
        abort_unless(isset(self::TABLES[$table]), 404);

        return self::TABLES[$table];
    }
}
