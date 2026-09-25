<?php

namespace App\Services\Sync\Resources;

use App\Enums\SyncIssueType;
use App\Models\WordPressRecord;
use App\Repositories\SyncIssueRepository;
use App\Repositories\WordPressRecordRepository;
use App\Services\Sync\FetchResult;
use App\Services\Sync\SyncContext;

/**
 * 1種類のリソース（投稿・カテゴリなど）の同期（BLOGOS_WORDPRESS_API.md 第III部）。
 *
 * 流れ：取得 → 保存（変更の検出と履歴）→ 変更のなかったものの照合日時の更新 → 削除の検知 → 参照先の解決
 */
abstract class AbstractResourceSyncer
{
    public function __construct(
        protected WordPressRecordRepository $records,
        protected SyncIssueRepository $issues,
    ) {
    }

    /**
     * sync_run_resources.resource_type に記録する値
     */
    abstract public function key(): string;

    /**
     * @return class-string<WordPressRecord>
     */
    abstract protected function modelClass(): string;

    /**
     * WordPressから取得する。
     *
     * @param \Illuminate\Support\Collection<string, WordPressRecord> $existing
     */
    abstract protected function fetch(SyncContext $context, $existing): FetchResult;

    /**
     * APIの項目を、テーブルの列の値に変換する。
     *
     * @return array<string, mixed>
     */
    abstract protected function map(array $item, SyncContext $context): array;

    /**
     * レコードを識別する列（数値IDを持たない定義情報は slug）
     */
    protected function keyColumn(): string
    {
        return 'wordpress_id';
    }

    /**
     * 取得した値でDBを更新せずに保留するか（記事の競合。ArticleSyncer）
     */
    protected function hold(?WordPressRecord $existing, array $item, SyncContext $context): bool
    {
        return false;
    }

    /**
     * 保存後に関連を更新する（投稿のカテゴリ・タグ）。変更した項目 [field => [old, new]] を返す。
     */
    protected function afterSave(WordPressRecord $record, array $item, SyncContext $context): array
    {
        return [];
    }

    /**
     * 削除を検知した後の処理（関連していた投稿の取得し直し等。D-09-04）
     *
     * @param array<int, WordPressRecord> $deleted
     */
    protected function afterDeleted(array $deleted, SyncContext $context): void
    {
    }

    /**
     * 参照先（投稿者・親など）の内部IDを設定する。同期の最後にまとめて実行する。
     */
    public function resolveReferences(SyncContext $context): void
    {
    }

    /**
     * @return array<string, int> fetched / created / updated / unchanged / deleted
     */
    public function sync(SyncContext $context): array
    {
        $modelClass = $this->modelClass();
        $keyColumn = $this->keyColumn();
        $existing = $this->records->existing($modelClass, $context->blog->id, $keyColumn);

        $result = $this->fetch($context, $existing);

        $counts = [
            'fetched'   => count($result->allKeys),
            'created'   => 0,
            'updated'   => 0,
            'unchanged' => 0,
            'deleted'   => 0,
        ];

        // 詳細を取得したものを保存する
        foreach ($result->items as $key => $item) {
            // DBを更新せずに保留する（作業中の編集案がある記事の競合）。DBは変わらないため「変更なし」に数える
            if ($this->hold($existing->get((string) $key), $item, $context)) {
                $counts['unchanged']++;
                continue;
            }

            $record = $existing->get((string) $key) ?? new $modelClass(['blog_id' => $context->blog->id]);

            $counts[$this->saveItem($record, $item, $context)]++;
        }

        // 2段階の取得で詳細を取得しなかったもの（変更なし）は、照合日時だけを更新する
        $notFetched = array_diff(array_map('strval', $result->allKeys), array_map('strval', array_keys($result->items)));
        $unchangedIds = collect($notFetched)
            ->map(fn ($key) => $existing->get($key))
            ->filter(fn ($record) => $record !== null && $record->wordpress_deleted_at === null)
            ->map(fn ($record) => $record->id)
            ->values()
            ->all();
        $this->records->touchSynced($modelClass, $unchangedIds);
        $counts['unchanged'] += count($unchangedIds);

        $counts['deleted'] = $this->detectDeletions($context, $existing, $result->allKeys);

        return $counts;
    }

    /**
     * APIの1項目を保存する（履歴を含む）。
     *
     * @return string created / updated / unchanged
     */
    protected function saveItem(WordPressRecord $record, array $item, SyncContext $context): string
    {
        return $this->records->save(
            $record,
            $this->map($item, $context),
            $context->source,
            $context->runId(),
            fn (WordPressRecord $saved) => $this->afterSave($saved, $item, $context),
            $context->historyAttributes
        );
    }

    /**
     * 反映・回復処理で、WordPressが返した1項目をDBに保存する（WORDPRESS_API 23章）。
     * 保留（競合）の判定は行わない。反映の結果は、WordPressの返却値を正とするため。
     */
    public function storeFromApi(array $item, SyncContext $context): WordPressRecord
    {
        $modelClass = $this->modelClass();

        $record = $modelClass::where('blog_id', $context->blog->id)
            ->where($this->keyColumn(), $item[$this->keyColumn() === 'wordpress_id' ? 'id' : $this->keyColumn()])
            ->first() ?? new $modelClass(['blog_id' => $context->blog->id]);

        $this->saveItem($record, $item, $context);
        $this->resolveReferences($context);

        return $record;
    }

    /**
     * 反映で、WordPress側で完全に削除したことを記録する
     */
    public function markDeletedFromApi(WordPressRecord $record, SyncContext $context): void
    {
        $this->records->markDeleted($record, $context->source, $context->runId(), $context->historyAttributes);
    }

    /**
     * 一覧に含まれなくなったものを、完全削除として記録する（D-09-02・D-09-03）。
     *
     * 一覧は最後まで取得できている（途中で失敗した場合は、fetch が例外を投げてここに来ない）。
     * 一度に一定の割合を超えて消えた場合は、削除として扱わず人の確認を待つ。
     */
    protected function detectDeletions(SyncContext $context, $existing, array $allKeys): int
    {
        $present = array_flip(array_map('strval', $allKeys));

        $alive = $existing->filter(fn (WordPressRecord $record) => $record->wordpress_deleted_at === null);
        $missing = $alive->filter(fn (WordPressRecord $record, $key) => ! isset($present[(string) $key]))->values()->all();

        if ($missing === []) {
            return 0;
        }

        $ratio = (float) config('blogos.sync.mass_deletion_ratio', 0.1);
        $minimum = (int) config('blogos.sync.mass_deletion_minimum', 2);

        if (count($missing) >= $minimum && count($missing) / max($alive->count(), 1) > $ratio) {
            $this->issues->record($context->blog->id, SyncIssueType::MassDeletionSuspected, $this->key(), null, [
                'sync_run_id' => $context->runId(),
                'message'     => sprintf(
                    '%d件中%d件が一覧から消えたため、削除として扱いませんでした。WordPress側を確認してください。',
                    $alive->count(),
                    count($missing)
                ),
            ]);

            return 0;
        }

        foreach ($missing as $record) {
            $this->records->markDeleted($record, $context->source, $context->runId(), $context->historyAttributes);

            $this->issues->record($context->blog->id, SyncIssueType::DeletedDetected, $this->key(), (string) $record->{$this->keyColumn()}, [
                'sync_run_id' => $context->runId(),
                'post_id'     => $this->key() === 'posts' ? $record->id : null,
                'page_id'     => $this->key() === 'pages' ? $record->id : null,
                'message'     => 'WordPress側で完全に削除されたことを検知しました。',
            ]);
        }

        $this->afterDeleted($missing, $context);

        return count($missing);
    }

    /**
     * 参照先が見つからなかったものを記録する
     */
    protected function recordUnresolved(SyncContext $context, array $unresolved, string $what): void
    {
        foreach ($unresolved as $row) {
            $this->issues->record(
                $context->blog->id,
                SyncIssueType::UnresolvedReference,
                $this->key(),
                "{$row['wordpress_id']}:{$what}",
                [
                    'sync_run_id' => $context->runId(),
                    'message'     => "{$what}（WordPress ID：{$row['reference']}）が見つかりません。",
                ]
            );
        }
    }

    /**
     * raw・rendered を持つ項目から値を取り出す
     */
    protected function part(array $item, string $field, string $kind): ?string
    {
        $value = $item[$field] ?? null;

        if (is_array($value)) {
            return $value[$kind] ?? null;
        }

        return $kind === 'rendered' ? $value : null;
    }

    /**
     * WordPressの日時（例：2026-09-01T12:00:00）を保存用に変換する
     */
    protected function datetime(?string $value): ?string
    {
        return $value ? str_replace('T', ' ', $value) : null;
    }
}
