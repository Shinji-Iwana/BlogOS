<?php

namespace App\Http\Controllers\Database;

use App\Http\Controllers\Controller;
use App\Repositories\BlogRepository;
use App\Support\DatabaseTables;
use App\Support\HistoryPage;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * BlogOS の DB の全テーブルの一覧・詳細（DB確認画面。XServer情報の「テーブルごとの件数と容量」から開く。D-80）。
 *
 * 取り込んだ WordPress のデータの11テーブルと同じ形（一覧：条件・レコード。詳細：詳細・関連・変更履歴）で、どのテーブルも出す。
 * 閲覧だけで、変更はしない。blog_id があるテーブルは、選択中のブログと、どのブログにも属さない行を出す（ブログの情報のテーブルは全て出す）。
 */
class TableRecordController extends Controller
{
    public function __construct(
        protected BlogRepository $blogRepository
    ) {
    }

    public function index(Request $request, string $table)
    {
        abort_unless(DatabaseTables::exists($table), 404);

        $key = DatabaseTables::primaryKey($table);
        $keyword = trim((string) $request->query('q', ''));
        $searchColumns = DatabaseTables::searchColumns($table);

        // 関連のパネルからの絞り込み（column=value。外部キーの列だけ）。指定したレコードにつながる行を全て出すため、ブログでは絞らない
        $filterColumn = (string) $request->query('column', '');
        $filterColumn = array_key_exists($filterColumn, DatabaseTables::foreignKeys($table)) ? $filterColumn : null;
        $filterValue = $filterColumn !== null ? (string) $request->query('value', '') : null;

        $blog = $this->blogRepository->findSelected();
        $scoped = $filterColumn === null && DatabaseTables::scopedByBlog($table);

        $records = DB::table($table)
            ->when($scoped, fn (Builder $query) => $query->where(fn (Builder $query) => $query->whereNull('blog_id')->when($blog, fn (Builder $query) => $query->orWhere('blog_id', $blog->id))))
            ->when($filterColumn !== null, fn (Builder $query) => $query->where($filterColumn, $filterValue))
            ->when($keyword !== '' && $searchColumns !== [], function (Builder $query) use ($searchColumns, $keyword) {
                $query->where(function (Builder $query) use ($searchColumns, $keyword) {
                    foreach ($searchColumns as $column) {
                        $query->orWhere($column, 'like', '%' . addcslashes($keyword, '%_\\') . '%');
                    }
                });
            })
            ->when($key !== null, fn (Builder $query) => $query->orderByDesc($key))
            ->paginate(HistoryPage::PER_PAGE)
            ->withQueryString();

        return view('database.tables.index', [
            'table'         => $table,
            'label'         => DatabaseTables::label($table),
            'primaryKey'    => $key,
            'columns'       => DatabaseTables::listColumns($table),
            'searchColumns' => $searchColumns,
            'records'       => $records,
            'keyword'       => $keyword,
            'filterColumn'  => $filterColumn,
            'filterValue'   => $filterValue,
            'scoped'        => $scoped,
            'blog'          => $blog,
        ]);
    }

    public function show(string $table, string $id)
    {
        abort_unless(DatabaseTables::exists($table), 404);
        $key = DatabaseTables::primaryKey($table);
        abort_if($key === null, 404);

        $record = DB::table($table)->where($key, $id)->first();
        abort_if($record === null, 404);

        $histories = null;
        if (($history = DatabaseTables::history($table)) !== null) {
            [$historyTable, $historyKey] = $history;
            $histories = DB::table($historyTable)->where($historyKey, $record->{$key})
                ->orderByDesc('changed_at')->orderByDesc('id')
                ->paginate(HistoryPage::PER_PAGE)->withQueryString();
        }

        return view('database.tables.show', [
            'table'        => $table,
            'label'        => DatabaseTables::label($table),
            'primaryKey'   => $key,
            'record'       => $record,
            'links'        => $this->links($table, (array) $record),
            'references'   => $this->references($table, $record->{$key}, $history[0] ?? null),
            'historyTable' => $history[0] ?? null,
            'histories'    => $histories,
            'historyLinks' => $histories ? $this->historyLinks($table, $history[0], $histories) : [],
        ]);
    }

    /**
     * 値の横に「（リンク）」で出す、つながる先（列 => [{text, url}]。外部キーの列から）
     *
     * @return array<string, list<array{text: string, url: string|null}>>
     */
    protected function links(string $table, array $values): array
    {
        $links = [];
        foreach (DatabaseTables::foreignKeys($table) as $column => $foreignTable) {
            if (($link = DatabaseTables::link($foreignTable, $values[$column] ?? null)) !== null) {
                $links[$column] = [$link];
            }
        }

        return $links;
    }

    /**
     * 変更履歴の値の横に出す、つながる先（履歴の id => 列 => [{text, url}]）。
     * 履歴の列の外部キーに加え、変更前・変更後の値は、変わった項目が元のテーブルの外部キーの列なら、そのつながる先を出す
     *
     * @return array<int, array<string, list<array{text: string, url: string|null}>>>
     */
    protected function historyLinks(string $table, string $historyTable, iterable $histories): array
    {
        $foreignKeys = DatabaseTables::foreignKeys($table);
        $links = [];
        foreach ($histories as $history) {
            $links[$history->id] = $this->links($historyTable, (array) $history);
            if (isset($foreignKeys[$history->field])) {
                foreach (['old_value', 'new_value'] as $column) {
                    if (($link = DatabaseTables::link($foreignKeys[$history->field], $history->{$column})) !== null) {
                        $links[$history->id][$column] = [$link];
                    }
                }
            }
        }

        return $links;
    }

    /**
     * 関連：このレコードを指しているテーブルと件数（1件以上のものだけ。変更履歴のパネルに出す履歴のテーブルは除く）
     *
     * @return list<array{table: string, label: string|null, column: string, count: int, url: string}>
     */
    protected function references(string $table, mixed $id, ?string $historyTable): array
    {
        return collect(DatabaseTables::referencedBy($table))
            ->reject(fn ($reference) => $reference['table'] === $historyTable)
            ->map(fn ($reference) => $reference + [
                'label' => DatabaseTables::label($reference['table']),
                'count' => DB::table($reference['table'])->where($reference['column'], $id)->count(),
                'url'   => DatabaseTables::listUrl($reference['table'], ['column' => $reference['column'], 'value' => $id]),
            ])
            ->filter(fn ($reference) => $reference['count'] > 0)
            ->values()->all();
    }
}
