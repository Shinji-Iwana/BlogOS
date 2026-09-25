<?php

namespace App\Http\Controllers\Database;

use App\Http\Controllers\Controller;
use App\Models\Author;
use App\Models\Category;
use App\Models\Media;
use App\Models\Page;
use App\Models\Post;
use App\Models\Status;
use App\Models\Tag;
use App\Models\Taxonomy;
use App\Models\Type;
use App\Models\WordPressRecord;
use App\Repositories\BlogRepository;
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
            ->paginate(100)
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

        if ($record instanceof Post) {
            $record->load(['categories', 'tags']);
        }

        $historyClass = $record::historyClass();
        $histories = $historyClass::where($record::historyForeignKey(), $record->id)
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        return view('database.wordpress-records.show', [
            'table'      => $table,
            'definition' => $definition,
            'record'     => $record,
            'histories'  => $histories,
        ]);
    }

    protected function definition(string $table): array
    {
        abort_unless(isset(self::TABLES[$table]), 404);

        return self::TABLES[$table];
    }
}
