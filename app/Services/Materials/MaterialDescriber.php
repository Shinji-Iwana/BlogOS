<?php

namespace App\Services\Materials;

use App\Models\Material;

/**
 * 教材の情報を、AIへの指示文に入れる文章にする（D-30）。
 */
class MaterialDescriber
{
    public static function describe(Material $material, bool $withLinks = false): string
    {
        $list = fn ($values) => implode('、', array_filter((array) $values, fn ($value) => is_string($value) && $value !== ''));
        $labels = fn (array $values, array $names) => implode('、', array_map(fn ($value) => $names[$value] ?? $value, $values));

        $lines = ["- 教材ID {$material->id}：{$material->kind->label()}「{$material->name}」" . ($material->isActive() ? '' : '（使わない）')];
        $add = function (string $label, ?string $value) use (&$lines) {
            if (filled($value)) {
                $lines[] = "  - {$label}：" . str_replace("\n", ' ', trim($value));
            }
        };

        $add('著者・講師・運営', $material->creator);
        $add('出版社・提供元', $material->publisher);
        $add('版', $material->edition);
        $add($material->kind->value === 'udemy' ? '最終更新日' : '出版日', $material->published_on?->format('Y-m-d'));
        $add('ISBN', $material->isbn);
        $add('カテゴリ', $material->relationLoaded('categories') ? $material->categories->pluck('name')->implode('、') : null);
        $add('分野の語句', $list($material->topics));
        $add('対象のバージョン', $list($material->target_versions));
        $add('対象のレベル', $labels((array) $material->levels, Material::LEVELS));
        $add('向いている場面', $labels((array) $material->scenes, Material::SCENES));
        $add('学べる内容', $material->summary);
        $add('向いている人', $material->target_readers);
        $add('向いていない人', $material->not_for);
        $add('メリット', $list($material->merits));
        $add('注意点', $list($material->cautions));
        $add('費用の目安', $material->cost_note ? $material->cost_note . ($material->cost_checked_on ? "（{$material->cost_checked_on->format('Y-m-d')} に確認）" : '') : null);
        $add('学習期間', $material->duration_note);
        if ($material->relationLoaded('previous') && $material->previous) {
            $add('前の版', "教材ID {$material->previous->id}「{$material->previous->name}」");
        }
        if ($material->relationLoaded('successors') && $material->successors->isNotEmpty()) {
            $add('新しい版', $material->successors->map(fn ($next) => "教材ID {$next->id}「{$next->name}」")->implode('、'));
        }
        $add('Amazonの商品ページ', $material->amazon_product_url);
        $add('楽天の商品ページ', $material->rakuten_product_url);
        $add($material->kind->value === 'book' ? '出版社などのページ' : '商品ページ', $material->product_url);
        if ($withLinks) {
            foreach ($material->affiliateLinks() as $label => $url) {
                $add("紹介リンク（{$label}）", $url);
            }
        }

        return implode("\n", $lines);
    }
}
