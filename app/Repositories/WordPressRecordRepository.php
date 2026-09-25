<?php

namespace App\Repositories;

use App\Enums\ChangeSource;
use App\Models\WordPressRecord;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WordPress由来のテーブルへの保存と履歴の記録（BLOGOS_DATABASE.md 3-6・8章）。
 *
 * 同期（App\Services\Sync）から使う。履歴は対象の更新と同じトランザクションで記録する（DEVELOPMENT_RULES 8-3）。
 */
class WordPressRecordRepository
{
    /** 保存の結果 */
    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const UNCHANGED = 'unchanged';

    /**
     * 変更の比較と履歴の対象から外す列（同期の管理用の列）
     */
    protected const IGNORED_COLUMNS = ['synced_at', 'created_at', 'updated_at', 'wordpress_deleted_at'];

    /**
     * ブログの既存のレコード（完全削除を検知したものを含む）を、識別の列の値をキーにして返す。
     *
     * @param class-string<WordPressRecord> $modelClass
     * @return Collection<string, WordPressRecord>
     */
    public function existing(string $modelClass, int $blogId, string $keyColumn): Collection
    {
        return $modelClass::where('blog_id', $blogId)
            ->get()
            ->keyBy(fn (WordPressRecord $record) => (string) $record->{$keyColumn});
    }

    /**
     * WordPressから取得した値を保存し、履歴を記録する。
     *
     * - 新規：レコードを作成し、__created の1行だけを記録する（D-13-04）
     * - 変更：変更した列ごとに1行を記録する（D-05-09）
     * - 完全削除を検知していたものが再び取得された：wordpress_deleted_at を解除し、__restored を記録する
     * - 差分なし：履歴を作らない
     *
     * @param array<string, mixed> $attributes WordPress由来の列の値
     * @param callable|null $afterSave 保存後に関連（カテゴリ等）を更新する処理。変更した項目 [field => [old, new]] を返す
     * @return string CREATED / UPDATED / UNCHANGED
     */
    public function save(
        WordPressRecord $record,
        array $attributes,
        ChangeSource $source,
        ?int $syncRunId,
        ?callable $afterSave = null,
        array $historyAttributes = []
    ): string {
        return DB::transaction(function () use ($record, $attributes, $source, $syncRunId, $afterSave, $historyAttributes) {
            $changeSetId = (string) Str::uuid();
            $now = now();
            $isNew = ! $record->exists;
            $wasDeleted = $record->exists && $record->wordpress_deleted_at !== null;

            $record->fill($attributes);
            $record->synced_at = $now;

            if ($wasDeleted) {
                $record->wordpress_deleted_at = null;
            }

            $changes = [];
            if (! $isNew) {
                foreach ($record->getDirty() as $column => $newValue) {
                    if (in_array($column, self::IGNORED_COLUMNS, true)) {
                        continue;
                    }

                    $changes[$column] = [
                        $this->stringify($record->getOriginal($column)),
                        $this->stringify($record->getAttribute($column)),
                    ];
                }
            }

            $record->save();

            $relationChanges = $afterSave !== null ? $afterSave($record, $isNew) : [];

            if ($isNew) {
                $this->writeHistory($record, $changeSetId, '__created', null, null, $source, $syncRunId, $now, $historyAttributes);

                return self::CREATED;
            }

            // 復活も変更として数える
            $changed = $wasDeleted;

            if ($wasDeleted) {
                $this->writeHistory($record, $changeSetId, '__restored', null, null, $source, $syncRunId, $now, $historyAttributes);
            }

            foreach (array_merge($changes, $relationChanges) as $field => [$old, $new]) {
                // 型変換の違いだけで、値が同じ場合は記録しない
                if ($old === $new) {
                    continue;
                }

                $this->writeHistory($record, $changeSetId, $field, $old, $new, $source, $syncRunId, $now, $historyAttributes);
                $changed = true;
            }

            return $changed ? self::UPDATED : self::UNCHANGED;
        });
    }

    /**
     * 変更がなかったレコードの照合日時だけを更新する（2段階の取得で詳細を取得しなかったもの）。
     *
     * @param class-string<WordPressRecord> $modelClass
     * @param array<int, int> $ids
     */
    public function touchSynced(string $modelClass, array $ids): void
    {
        foreach (array_chunk($ids, 500) as $chunk) {
            $modelClass::whereIn('id', $chunk)->update(['synced_at' => now()]);
        }
    }

    /**
     * WordPress側での完全削除を記録する（論理削除。D-09-02）。
     */
    public function markDeleted(WordPressRecord $record, ChangeSource $source, ?int $syncRunId, array $historyAttributes = []): void
    {
        DB::transaction(function () use ($record, $source, $syncRunId, $historyAttributes) {
            $now = now();
            $record->wordpress_deleted_at = $now;
            $record->save();

            $this->writeHistory($record, (string) Str::uuid(), '__deleted', null, null, $source, $syncRunId, $now, $historyAttributes);
        });
    }

    /**
     * 多対多の関連（投稿のカテゴリ・タグ）を、WordPress IDの一覧に合わせて更新する。
     *
     * @param string $pivotTable      中間テーブル（post_categories 等）
     * @param string $relatedTable    関連先のテーブル（categories 等）
     * @param string $relatedColumn   中間テーブルでの関連先の列（category_id 等）
     * @param array<int, int> $wordpressIds 受け取ったWordPress IDの一覧
     * @return array{0: string|null, 1: string|null}|null 変更があった場合は [変更前, 変更後]（WordPress IDの一覧のJSON）
     */
    public function syncPivot(
        WordPressRecord $record,
        string $ownerColumn,
        string $pivotTable,
        string $relatedTable,
        string $relatedColumn,
        array $wordpressIds
    ): ?array {
        $current = DB::table($pivotTable)
            ->join($relatedTable, "{$relatedTable}.id", '=', "{$pivotTable}.{$relatedColumn}")
            ->where("{$pivotTable}.{$ownerColumn}", $record->id)
            ->pluck("{$relatedTable}.wordpress_id")
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();

        $wanted = collect($wordpressIds)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        $related = DB::table($relatedTable)
            ->where('blog_id', $record->blog_id)
            ->whereIn('wordpress_id', $wanted)
            ->pluck('wordpress_id', 'id');

        DB::table($pivotTable)->where($ownerColumn, $record->id)->delete();
        DB::table($pivotTable)->insert(array_map(
            fn ($relatedId) => [$ownerColumn => $record->id, $relatedColumn => $relatedId],
            $related->keys()->all()
        ));

        // 実際に関連付けたもので比べる。DBにないカテゴリ等が含まれていても、取得のたびに履歴が増えないようにする
        $linked = $related->map(fn ($id) => (int) $id)->sort()->values()->all();

        if ($current === $linked) {
            return null;
        }

        return [json_encode($current), json_encode($linked)];
    }

    /**
     * 参照先のWordPress IDから、内部の外部キーを設定する（D-02-03）。
     * 参照先が見つからない場合はNULLにする。
     *
     * @return array<int, array{id: int, wordpress_id: int, reference: int}> 参照先が見つからなかったもの
     */
    public function resolveReference(
        string $table,
        int $blogId,
        string $wordpressColumn,
        string $internalColumn,
        string $targetTable
    ): array {
        // 内部IDを設定する（見つからない場合はNULL）
        DB::table("{$table} as t")
            ->leftJoin("{$targetTable} as r", function ($join) use ($wordpressColumn) {
                $join->on('r.blog_id', '=', 't.blog_id')->on('r.wordpress_id', '=', "t.{$wordpressColumn}");
            })
            ->where('t.blog_id', $blogId)
            ->update(["t.{$internalColumn}" => DB::raw('r.id')]);

        return DB::table($table)
            ->where('blog_id', $blogId)
            ->whereNull('wordpress_deleted_at')
            ->where($wordpressColumn, '>', 0)
            ->whereNull($internalColumn)
            ->get(['id', 'wordpress_id', $wordpressColumn])
            ->map(fn ($row) => [
                'id'           => (int) $row->id,
                'wordpress_id' => (int) $row->wordpress_id,
                'reference'    => (int) $row->{$wordpressColumn},
            ])
            ->all();
    }

    /**
     * 同期するカスタム投稿タイプ・カスタムタクソノミーの定義（D-10-04、WORDPRESS_API 18章）。
     *
     * 標準のもの（投稿・固定ページ・メディア、カテゴリ・タグ）と、WordPressの内部用のもの
     * （wp_ で始まるもの、ナビゲーションメニュー、投稿フォーマット）を除く。
     *
     * @param string $table types / taxonomies
     * @return Collection<int, object{slug: string, rest_base: string, rest_namespace: string|null}>
     */
    public function customDefinitions(int $blogId, string $table): Collection
    {
        $builtIn = $table === 'types'
            ? ['post', 'page', 'attachment', 'nav_menu_item']
            : ['category', 'post_tag', 'nav_menu', 'post_format'];

        return DB::table($table)
            ->where('blog_id', $blogId)
            ->whereNull('wordpress_deleted_at')
            ->whereNotNull('rest_base')
            ->where('rest_base', '!=', '')
            ->whereNotIn('slug', $builtIn)
            ->where('slug', 'not like', 'wp\_%')
            ->orderBy('slug')
            ->get(['slug', 'rest_base', 'rest_namespace']);
    }

    /**
     * 指定したカテゴリ・タグ・メディアに関連していた投稿のWordPress IDを返す（D-09-04）。
     *
     * @param array<int, int> $relatedIds 内部ID
     * @return array<int, int>
     */
    public function postWordpressIdsLinkedTo(string $kind, array $relatedIds): array
    {
        if ($relatedIds === []) {
            return [];
        }

        $query = match ($kind) {
            'categories' => DB::table('post_categories')->join('posts', 'posts.id', '=', 'post_categories.post_id')
                ->whereIn('post_categories.category_id', $relatedIds),
            'tags'       => DB::table('post_tags')->join('posts', 'posts.id', '=', 'post_tags.post_id')
                ->whereIn('post_tags.tag_id', $relatedIds),
            'media'      => DB::table('posts')->whereIn('featured_media_id', $relatedIds),
        };

        return $query->pluck('posts.wordpress_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    protected function writeHistory(
        WordPressRecord $record,
        string $changeSetId,
        string $field,
        ?string $oldValue,
        ?string $newValue,
        ChangeSource $source,
        ?int $syncRunId,
        $changedAt,
        array $historyAttributes = []
    ): void {
        $historyClass = $record::historyClass();

        $historyClass::create($historyAttributes + [
            'blog_id'                => $record->blog_id,
            $record::historyForeignKey() => $record->id,
            'change_set_id'          => $changeSetId,
            'field'                  => $field,
            'old_value'              => $oldValue,
            'new_value'              => $newValue,
            'source'                 => $source,
            'sync_run_id'            => $syncRunId,
            'changed_at'             => $changedAt,
        ]);
    }

    /**
     * 履歴に保存する文字列にする
     */
    protected function stringify(mixed $value): ?string
    {
        return match (true) {
            $value === null                     => null,
            is_bool($value)                     => $value ? 'true' : 'false',
            is_array($value)                    => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            $value instanceof BackedEnum        => (string) $value->value,
            default                             => (string) $value,
        };
    }
}
