<?php

namespace App\Services\Push;

use App\Clients\WordPress\WordPressApiClient;
use App\Clients\WordPress\WordPressApiException;
use App\Enums\ChangeSource;
use App\Enums\PushOperationType;
use App\Enums\PushResourceType;
use App\Enums\PushState;
use App\Enums\SyncIssueType;
use App\Models\Blog;
use App\Models\WordPressPushOperation;
use App\Repositories\BlogCredentialRepository;
use App\Repositories\SyncIssueRepository;
use App\Repositories\WordPressPushOperationRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 回復処理と、結果が不明な反映の確認（BLOGOS_WORDPRESS_API.md 24-2・24-3、ARCHITECTURE 13-6）。
 */
class PushRecoveryService
{
    /**
     * 「送信中」のまま、この時間を過ぎた反映記録は、処理が途中で止まったものとして「結果が不明」にする
     */
    protected const STALE_SENT_SECONDS = 600;

    /**
     * 新規作成の結果が不明な場合に、候補とする記事の範囲（送信した時刻の少し前から）
     */
    protected const CANDIDATE_MARGIN_SECONDS = 300;

    public function __construct(
        protected PushOperationRunner $runner,
        protected WordPressPushOperationRepository $operations,
        protected SyncIssueRepository $issues,
        protected BlogCredentialRepository $credentials,
    ) {
    }

    /**
     * 同期の最初に行う回復処理。同期のロックの中で呼ぶ（ロックは取らない）。
     *
     * @return array{recovered: int, matched: int} 完了させた件数、拡張で照合できた新規作成の件数
     */
    public function recover(Blog $blog): array
    {
        $this->operations->markStaleSentAsUnknown($blog->id, self::STALE_SENT_SECONDS);

        $client = WordPressApiClient::forBlog($blog);
        $recovered = 0;

        foreach ($this->operations->inState($blog->id, PushState::WpSucceeded) as $operation) {
            try {
                $item = $operation->operation === PushOperationType::Delete
                    ? ['id' => $operation->wordpress_id]
                    : $client->getOrFail($this->runner->endpoint($operation->resource_type) . "/{$operation->wordpress_id}", ['context' => 'edit'])->json();

                $this->runner->complete($operation, $item, ChangeSource::BlogosRecovery, $operation->approved_by);
                $recovered++;
            } catch (Throwable $e) {
                Log::error('回復処理：反映記録を完了できませんでした。', ['wordpress_push_operation_id' => $operation->id, 'message' => $e->getMessage()]);
            }
        }

        // 拡張が有効なブログでは、結果が不明な新規作成を、投稿メタの編集案IDで照合する
        $matched = 0;
        if ($this->credentials->findForBlog($blog->id)?->connector_extension) {
            foreach ($this->operations->inState($blog->id, PushState::Unknown) as $operation) {
                if ($operation->operation !== PushOperationType::Create || $operation->draft === null) {
                    continue;
                }

                try {
                    $candidate = collect($this->candidates($operation))
                        ->first(fn (array $item) => ($item['meta']['_blogos_draft_id'] ?? null) === $operation->draft->uuid);

                    if ($candidate !== null) {
                        $this->resolveAsApplied($operation, (int) $candidate['id'], $operation->approved_by);
                        $matched++;
                    }
                } catch (Throwable $e) {
                    Log::error('回復処理：新規作成の照合に失敗しました。', ['wordpress_push_operation_id' => $operation->id, 'message' => $e->getMessage()]);
                }
            }
        }

        return ['recovered' => $recovered, 'matched' => $matched];
    }

    /**
     * 結果が不明な新規作成について、送信した時刻の少し前以降に更新された記事を返す（人が照合するための候補）
     *
     * @return array<int, array> WordPressの項目（id、title、status、modified_gmt、link、meta）
     *
     * @throws WordPressApiException
     */
    public function candidates(WordPressPushOperation $operation): array
    {
        $operation->loadMissing('blog');

        if (! in_array($operation->resource_type, [PushResourceType::Post, PushResourceType::Page], true)) {
            return [];
        }

        $since = ($operation->sent_at ?? $operation->created_at)->copy()->subSeconds(self::CANDIDATE_MARGIN_SECONDS);

        $items = WordPressApiClient::forBlog($operation->blog)->getOrFail($this->runner->endpoint($operation->resource_type), [
            'context'  => 'edit',
            'status'   => 'publish,future,draft,pending,private,trash',
            'orderby'  => 'modified',
            'order'    => 'desc',
            'per_page' => 20,
            '_fields'  => 'id,title,status,modified_gmt,link,meta',
        ])->json();

        return array_values(array_filter(
            is_array($items) ? $items : [],
            fn ($item) => isset($item['modified_gmt']) && Carbon::parse($item['modified_gmt'], 'UTC')->greaterThanOrEqualTo($since)
        ));
    }

    /**
     * 結果が不明な反映を「反映された」として完了させる。WordPressの現在の内容を取得してDBを更新する。
     *
     * @param int|null $wordpressId 新規作成の場合は、人が照合した記事のWordPress ID
     * @throws PushException
     * @throws WordPressApiException
     */
    public function resolveAsApplied(WordPressPushOperation $operation, ?int $wordpressId, ?int $userId): void
    {
        $operation->loadMissing(['blog', 'post', 'page', 'category', 'tag', 'media']);

        if ($operation->state !== PushState::Unknown) {
            throw new PushException('結果が不明な反映記録だけを確認できます。');
        }

        $wordpressId ??= ($operation->post ?? $operation->page ?? $operation->category ?? $operation->tag ?? $operation->media)?->wordpress_id;
        if ($wordpressId === null) {
            throw new PushException('WordPressの記事を選んでください。');
        }

        if ($operation->operation === PushOperationType::Delete) {
            $this->operations->markWpSucceeded($operation, $wordpressId, null);
            $this->runner->complete($operation->fresh(), ['id' => $wordpressId], ChangeSource::BlogosRecovery, $userId);

            return;
        }

        $item = WordPressApiClient::forBlog($operation->blog)
            ->getOrFail($this->runner->endpoint($operation->resource_type) . "/{$wordpressId}", ['context' => 'edit'])
            ->json();

        $this->operations->markWpSucceeded($operation, (int) $item['id'], $item['modified_gmt'] ?? null);
        $this->runner->complete($operation->fresh(), $item, ChangeSource::BlogosRecovery, $userId);
    }

    /**
     * 結果が不明な反映を「反映されなかった」として失敗にする。編集案のロックが解除され、やり直せる。
     *
     * @throws PushException
     */
    public function resolveAsNotApplied(WordPressPushOperation $operation, ?int $userId): void
    {
        if ($operation->state !== PushState::Unknown) {
            throw new PushException('結果が不明な反映記録だけを確認できます。');
        }

        $this->operations->markFailed($operation, 'WordPressに反映されていないことを、人が確認しました。', $operation->error_status, $operation->error_body);
        $this->issues->resolveRelated($operation->blog_id, [SyncIssueType::PushUnknown], $operation->id, null, $userId, '反映されていないことを確認しました。');
    }
}
