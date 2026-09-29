<?php

namespace App\Support;

use App\Models\TopicSuggestion;

/**
 * 記事の企画の案から、新規記事作成の画面を開くときの値（D-40）
 */
class TopicLinks
{
    /**
     * @return array<string, string|int>
     */
    public static function newArticle(TopicSuggestion $suggestion): array
    {
        return array_filter([
            'mode'            => 'new_article',
            'main_keyword'    => $suggestion->main_keyword,
            'sub_keywords'    => implode("\n", (array) $suggestion->sub_keywords),
            'search_intent'   => $suggestion->search_intent,
            'article_type'    => $suggestion->article_type,
            'article_subtype' => $suggestion->article_subtype,
            // 子カテゴリの案の記事は、まだカテゴリがないため、親のカテゴリにしておく（カテゴリを作ってから選び直す）
            'category_id'     => $suggestion->category_id,
            'notes'           => "記事の企画の案：{$suggestion->title}" . ($suggestion->roadmap_step ? "\nロードマップのステップ：{$suggestion->roadmap_step}" : '') . ($suggestion->reason ? "\n企画の理由：{$suggestion->reason}" : ''),
        ], fn ($value) => filled($value));
    }
}
