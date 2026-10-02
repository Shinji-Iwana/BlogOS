<?php

namespace App\Services\Quality;

/**
 * 読み込んだ品質基準（共通基準＋ブログ別の定義）。resources/quality/。
 *
 * 採点の対象は、共通の採点項目（対象外を除く）と、記事の型の項目（D-47）。記事の型は、記事種類に項目があればその記事種類、
 * なければ集客記事の細分類で決める（article-types.md 6章）。
 */
class QualityStandard
{
    /**
     * @param array<string, array{label: string, ai: bool}> $required 必須条件（キー => 内容・AIが判定できるか）
     * @param array<string, array<string, mixed>> $items 共通の採点項目（category・label・points・ai・criteria・axes・required）
     * @param array<string, string> $categories 分類の見出し（例：① 検索意図）
     * @param array<string, array<int, string>> $typeExclusions 記事種類ごとの対象外の採点項目
     * @param array<int, string> $blogExclusions ブログ単位の対象外の採点項目
     * @param array<string, string> $articleTypes 記事種類（値 => 名前）
     * @param array<string, array{label: string, description: string}> $axes 観点（キー => 名前・説明）
     * @param array<string, array<string, array<string, mixed>>> $typeItems 記事の型 => 記事の型の採点項目
     * @param array<string, array<string, string>> $importance 記事種類 => 観点 => 重要度
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
        public readonly array $axes = [],
        public readonly array $typeItems = [],
        public readonly array $importance = [],
    ) {
    }

    /**
     * 記事の型（記事の型の項目がある記事種類、なければ細分類）。決まらなければ null
     */
    public function formFor(?string $articleType, ?string $articleSubtype = null): ?string
    {
        return match (true) {
            $articleType !== null && isset($this->typeItems[$articleType])       => $articleType,
            $articleSubtype !== null && isset($this->typeItems[$articleSubtype]) => $articleSubtype,
            default                                                              => null,
        };
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
     * 採点の対象の項目（共通の項目から対象外を除き、記事の型の項目を加える）
     *
     * @return array<string, array<string, mixed>>
     */
    public function applicableItems(?string $articleType, ?string $articleSubtype = null): array
    {
        $form = $this->formFor($articleType, $articleSubtype);

        return array_diff_key($this->items, array_flip($this->excludedItems($articleType))) + ($form !== null ? $this->typeItems[$form] : []);
    }

    /**
     * すべての採点項目（共通の項目と、すべての記事の型の項目。名前の表示・キーの確認に使う）
     *
     * @return array<string, array<string, mixed>>
     */
    public function allItems(): array
    {
        $all = $this->items;
        foreach ($this->typeItems as $items) {
            $all += $items;
        }

        return $all;
    }

    /**
     * 観点の重要度（記事種類ごと。定義がなければ「重要」）
     */
    public function importance(?string $articleType, string $axis): string
    {
        return $this->importance[$articleType][$axis] ?? '重要';
    }
}
