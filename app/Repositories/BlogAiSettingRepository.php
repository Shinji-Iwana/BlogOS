<?php

namespace App\Repositories;

use App\Models\Blog;
use App\Models\BlogAiSetting;
use Illuminate\Support\Collection;

/**
 * ブログごとのAIの設定（blog_ai_settings）。D-25。
 */
class BlogAiSettingRepository
{
    /**
     * 設定がまだなければ、初期値（自動の再評価は無効）で返す（保存はしない）
     */
    public function forBlog(Blog $blog): BlogAiSetting
    {
        return BlogAiSetting::firstOrNew(['blog_id' => $blog->id], [
            'auto_reevaluation_enabled' => false,
            'auto_model'                => config('blogos.ai.auto_reevaluation.model'),
            'auto_reasoning_effort'     => config('blogos.ai.auto_reevaluation.effort'),
            'auto_revision_enabled'     => true,
            'auto_revision_scope'       => 'auto',
        ]);
    }

    public function save(Blog $blog, array $attributes, ?int $userId): BlogAiSetting
    {
        return BlogAiSetting::updateOrCreate(['blog_id' => $blog->id], $attributes + ['updated_by' => $userId]);
    }

    /**
     * 自動の再評価を有効にしているブログの設定
     *
     * @return Collection<int, BlogAiSetting>
     */
    public function autoEnabled(): Collection
    {
        return BlogAiSetting::with('blog')->where('auto_reevaluation_enabled', true)->get();
    }
}
