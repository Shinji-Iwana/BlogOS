<?php

namespace App\Services\Push;

use App\Clients\WordPress\WordPressApiClient;
use App\Clients\WordPress\WordPressApiException;
use App\Enums\PushOperationType;
use App\Enums\PushResourceType;
use App\Models\Category;
use App\Models\Media;
use App\Models\Tag;
use App\Models\WordPressPushOperation;
use App\Repositories\BlogSettingRepository;
use App\Repositories\WordPressPushOperationRepository;
use App\Support\Slug;

/**
 * カテゴリ・タグ・メディアの情報の更新と削除（WORDPRESS_API 21-3・22章、D-15-05）。
 *
 * これらは編集案を持たない。承認時の入力内容を request_summary に、画面を開いた時点の値を base_values に保存し、
 * 反映の直前に、変更する項目のWordPressの最新の値と base_values を比べて競合を確認する（更新日時がないため値で比べる）。
 */
class TermPushService
{
    /**
     * 種類ごとの、画面で変更できる項目（WordPress APIの項目名）
     */
    public const FIELDS = [
        'category' => ['name', 'slug', 'description', 'parent'],
        'tag'      => ['name', 'slug', 'description'],
        'media'    => ['title', 'alt_text', 'caption', 'description'],
    ];

    public function __construct(
        protected PushOperationRunner $runner,
        protected WordPressPushOperationRepository $operations,
        protected BlogSettingRepository $settings,
    ) {
    }

    public static function typeOf(Category|Tag|Media $record): PushResourceType
    {
        return match (true) {
            $record instanceof Category => PushResourceType::Category,
            $record instanceof Tag      => PushResourceType::Tag,
            default                     => PushResourceType::Media,
        };
    }

    /**
     * 画面に表示する名前（削除の確認で入力させる）
     */
    public static function label(Category|Tag|Media $record): string
    {
        return $record instanceof Media
            ? ((string) $record->title_raw !== '' ? (string) $record->title_raw : (string) $record->wordpress_id)
            : (string) $record->name;
    }

    /**
     * DBの現在の値（WordPress APIの項目名で）
     *
     * @return array<string, mixed>
     */
    public function currentValues(Category|Tag|Media $record): array
    {
        return match (true) {
            $record instanceof Category => [
                'name' => $record->name, 'slug' => $record->slug, 'description' => (string) $record->description, 'parent' => (int) $record->wordpress_parent_id,
            ],
            $record instanceof Tag => [
                'name' => $record->name, 'slug' => $record->slug, 'description' => (string) $record->description,
            ],
            default => [
                'title' => (string) $record->title_raw, 'alt_text' => (string) $record->alt_text, 'caption' => (string) $record->caption_raw, 'description' => (string) $record->description_raw,
            ],
        };
    }

    /**
     * WordPressの項目を、currentValues と同じ形にする
     */
    public function valuesFromApi(PushResourceType $type, array $item): array
    {
        return match ($type) {
            PushResourceType::Category => [
                'name' => $item['name'] ?? null, 'slug' => $item['slug'] ?? null, 'description' => (string) ($item['description'] ?? ''), 'parent' => (int) ($item['parent'] ?? 0),
            ],
            PushResourceType::Tag => [
                'name' => $item['name'] ?? null, 'slug' => $item['slug'] ?? null, 'description' => (string) ($item['description'] ?? ''),
            ],
            default => [
                'title' => (string) ($item['title']['raw'] ?? ''), 'alt_text' => (string) ($item['alt_text'] ?? ''),
                'caption' => (string) ($item['caption']['raw'] ?? ''), 'description' => (string) ($item['description']['raw'] ?? ''),
            ],
        };
    }

    /**
     * 情報を更新する。
     *
     * @param array<string, mixed> $values 入力された値
     * @param array<string, mixed> $base   画面を開いた時点の値
     * @throws PushException
     */
    public function update(Category|Tag|Media $record, array $values, array $base, ?int $userId): WordPressPushOperation
    {
        $record->loadMissing('blog');
        $type = self::typeOf($record);
        $this->ensurePushable($record);

        $fields = self::FIELDS[$type->value];
        $values = $this->normalize($type, array_intersect_key($values, array_flip($fields)));
        $base = $this->normalize($type, array_intersect_key($base, array_flip($fields)));

        $changed = array_filter($values, fn ($value, $field) => $value !== ($base[$field] ?? null), ARRAY_FILTER_USE_BOTH);
        if ($changed === []) {
            throw new PushException('変更した項目がありません。');
        }

        return $this->runner->withBlogLock($record->blog, function () use ($record, $type, $changed, $base, $userId) {
            $operation = $this->operations->create(
                $record->blog_id,
                $type,
                PushOperationType::Update,
                [$this->runner->column($type) => $record->id],
                $changed,
                array_intersect_key($base, $changed),
                $userId
            );

            $client = WordPressApiClient::forBlog($record->blog);
            $endpoint = $this->runner->endpoint($type) . "/{$record->wordpress_id}";

            // 反映直前の競合確認：変更する項目の最新の値が、画面を開いた時点の値と同じか（WORDPRESS_API 21-3）
            try {
                $latest = $this->normalize($type, $this->valuesFromApi($type, $client->getOrFail($endpoint, ['context' => 'edit'])->json()));
            } catch (WordPressApiException $e) {
                $this->operations->markFailed($operation, "反映前の確認で、WordPressから取得できませんでした：{$e->getMessage()}", $e->status, $e->body);

                return $operation->fresh();
            }

            $conflicts = array_keys(array_filter($changed, fn ($value, $field) => ($latest[$field] ?? null) !== ($base[$field] ?? null), ARRAY_FILTER_USE_BOTH));
            if ($conflicts !== []) {
                $this->runner->stopForConflict(
                    $operation,
                    $record,
                    (string) $record->wordpress_id,
                    null,
                    'WordPress側で「' . implode('・', $conflicts) . '」が変更されているため、反映を中止しました（競合）。画面を開き直して、最新の値を確認してください。'
                );

                return $operation->fresh();
            }

            $response = $this->runner->send($operation, fn () => $client->post($endpoint, $changed));

            if ($response !== null) {
                $this->runner->receive($operation, $response->json(), $userId);
            }

            return $operation->fresh();
        });
    }

    /**
     * 完全に削除する。カテゴリ・タグ・メディアにはゴミ箱がないため、常に完全削除になる（WORDPRESS_API 22章）。
     *
     * @throws PushException
     */
    public function delete(Category|Tag|Media $record, ?int $userId): WordPressPushOperation
    {
        $record->loadMissing('blog');
        $type = self::typeOf($record);
        $this->ensurePushable($record);

        // 既定のカテゴリは、WordPressでは削除できない
        if ($type === PushResourceType::Category
            && (int) $this->settings->valueFor($record->blog_id, 'default_category') === (int) $record->wordpress_id) {
            throw new PushException('既定のカテゴリ（投稿設定の「投稿用カテゴリーの初期設定」）は削除できません。');
        }

        return $this->runner->withBlogLock($record->blog, function () use ($record, $type, $userId) {
            $operation = $this->operations->create(
                $record->blog_id,
                $type,
                PushOperationType::Delete,
                [$this->runner->column($type) => $record->id],
                ['force' => true],
                $this->currentValues($record),
                $userId
            );

            $client = WordPressApiClient::forBlog($record->blog);

            $response = $this->runner->send($operation, fn () => $client->delete(
                $this->runner->endpoint($type) . "/{$record->wordpress_id}",
                ['force' => true]
            ));

            if ($response !== null) {
                $this->runner->receive($operation, $response->json(), $userId);
            }

            return $operation->fresh();
        });
    }

    /**
     * @throws PushException
     */
    protected function ensurePushable(Category|Tag|Media $record): void
    {
        if ($record->blog->isArchived()) {
            throw new PushException('アーカイブしたブログには反映できません。');
        }
        if ($record->wordpress_deleted_at !== null) {
            throw new PushException('WordPress側で既に削除されています。');
        }
    }

    /**
     * 比べられるように、値の型をそろえる
     */
    protected function normalize(PushResourceType $type, array $values): array
    {
        foreach ($values as $field => $value) {
            $values[$field] = match ($field) {
                'parent' => (int) $value,
                // スラッグは読める形にそろえて比べる（WordPressは符号化した形で返す。D-29）
                'slug'   => mb_strtolower((string) Slug::display((string) ($value ?? ''))),
                default  => (string) ($value ?? ''),
            };
        }

        return $values;
    }
}
