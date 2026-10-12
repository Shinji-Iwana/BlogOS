<?php

namespace App\Http\Controllers\Database;

use App\Http\Controllers\Controller;
use App\Support\HistoryPage;
use App\Support\WordPressDatabaseTables;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * WordPress の DB のテーブルの一覧・詳細（XServer情報の DB（WordPress）の「テーブルごとの件数と容量」から開く。D-81）。
 *
 * BlogOS の DB の全テーブルの画面（D-80）と同じ形（一覧：条件・レコード。詳細：詳細・関連）。
 * 読むだけ（select だけ）で、WordPress の DB を書き換えない。開けるのは、XServer情報に出している DB のテーブルだけ。
 * WordPress は変更の履歴を持たないため、変更履歴のパネルはない（投稿のリビジョンは、関連に出る）。
 */
class WordPressDbController extends Controller
{
    public function __construct(
        protected WordPressDatabaseTables $tables
    ) {
    }

    public function index(Request $request, string $schema, string $table)
    {
        abort_unless($this->tables->exists($schema, $table), 404);

        $key = $this->tables->primaryKey($schema, $table);
        $keyword = trim((string) $request->query('q', ''));
        $searchColumns = $this->tables->searchColumns($schema, $table);

        // 関連のパネルからの絞り込み（column=value。つながりを決めた列だけ）
        $filterColumn = (string) $request->query('column', '');
        $filterColumn = array_key_exists($filterColumn, $this->tables->foreignKeys($schema, $table)) ? $filterColumn : null;
        $filterValue = $filterColumn !== null ? (string) $request->query('value', '') : null;

        $records = DB::table("{$schema}.{$table}")
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

        return view('database.wordpress-db.index', [
            'schema'        => $schema,
            'table'         => $table,
            'label'         => $this->tables->label($schema, $table),
            'primaryKey'    => $key,
            'columns'       => $this->tables->listColumns($schema, $table),
            'searchColumns' => $searchColumns,
            'records'       => $records,
            'keyword'       => $keyword,
            'filterColumn'  => $filterColumn,
            'filterValue'   => $filterValue,
            'wp'            => $this->tables,
        ]);
    }

    public function show(string $schema, string $table, string $id)
    {
        abort_unless($this->tables->exists($schema, $table), 404);
        $key = $this->tables->primaryKey($schema, $table);
        abort_if($key === null, 404);

        $record = DB::table("{$schema}.{$table}")->where($key, $id)->first();
        abort_if($record === null, 404);
        $values = (array) $record;

        $links = [];
        foreach ($this->tables->foreignKeys($schema, $table) as $column => $target) {
            if (($link = $this->tables->link($schema, $target, $values[$column] ?? null)) !== null) {
                $links[$column] = [$link];
            }
        }

        return view('database.wordpress-db.show', [
            'schema'     => $schema,
            'table'      => $table,
            'label'      => $this->tables->label($schema, $table),
            'primaryKey' => $key,
            'record'     => $values,
            'links'      => $links,
            'references' => $this->references($schema, $table, $values[$key]),
            'blogosLink' => $this->tables->blogosLink($schema, $table, $values),
            'wp'         => $this->tables,
        ]);
    }

    /**
     * 関連：このレコードを指しているテーブルと件数（1件以上のものだけ）
     *
     * @return list<array{table: string, label: string|null, column: string, count: int, url: string}>
     */
    protected function references(string $schema, string $table, mixed $id): array
    {
        return collect($this->tables->referencedBy($schema, $table))
            ->map(fn ($reference) => $reference + [
                'label' => $this->tables->label($schema, $reference['table']),
                'count' => DB::table("{$schema}.{$reference['table']}")->where($reference['column'], $id)->count(),
                'url'   => route('database.wordpress-db.index', ['schema' => $schema, 'table' => $reference['table'], 'column' => $reference['column'], 'value' => $id]),
            ])
            ->filter(fn ($reference) => $reference['count'] > 0)
            ->values()->all();
    }
}
