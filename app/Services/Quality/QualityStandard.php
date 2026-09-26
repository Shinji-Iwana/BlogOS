<?php

namespace App\Services\Quality;

/**
 * 読み込んだ品質基準（共通基準＋ブログ別の定義）。resources/quality/。
 */
class QualityStandard
{
    /**
     * @param array<string, array{label: string, ai: bool}> $required 必須条件（キー => 内容・AIが判定できるか）
     * @param array<string, array{category: string, label: string, points: int, ai: bool}> $items 採点項目
     * @param array<string, string> $categories 分類の見出し（例：① 検索意図）
     * @param array<string, array<int, string>> $typeExclusions 記事種類ごとの対象外の採点項目
     * @param array<int, string> $blogExclusions ブログ単位の対象外の採点項目
     * @param array<string, string> $articleTypes 記事種類（値 => 名前）
     */
    public function __construct(
        public readonly string $commonVersion,
        public readonly ?string $profile,
        public readonly ?string $profileVersion,
        public readonly array $required,
        public readonly array $items,
        public readonly array $categories,
        public readonly array $typeExclusions,
        public readonly array $blogExclusions,
        public readonly array $articleTypes,
    ) {
    }

    /**
     * 記事種類に対して対象外の採点項目
     *
     * @return array<int, string>
     */
    public function excludedItems(?string $articleType): array
    {
        return array_values(array_unique(array_merge($this->blogExclusions, $this->typeExclusions[$articleType] ?? [])));
    }

    /**
     * 採点の対象の項目（対象外を除く）
     *
     * @return array<string, array{category: string, label: string, points: int, ai: bool}>
     */
    public function applicableItems(?string $articleType): array
    {
        return array_diff_key($this->items, array_flip($this->excludedItems($articleType)));
    }
}
