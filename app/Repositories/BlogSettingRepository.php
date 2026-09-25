<?php

namespace App\Repositories;

use App\Enums\ChangeSource;
use App\Models\Blog;
use App\Models\BlogSetting;
use App\Models\BlogSettingHistory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BlogSettingRepository
{
    public function getForBlog(int $blogId): Collection
    {
        return BlogSetting::where('blog_id', $blogId)->orderBy('key')->get();
    }

    public function valueFor(int $blogId, string $key): ?string
    {
        return BlogSetting::where('blog_id', $blogId)->where('key', $key)->value('value');
    }

    /**
     * WordPressから取得したサイト設定をDBに反映し、変更を履歴に残す（BLOGOS_DATABASE.md 5-4、8-2）。
     *
     * - 保存するのは BlogSetting::KEYS のキーだけ
     * - 新しいキーは作成し、__created の履歴を1行記録する
     * - 値が変わったキーは更新し、変更前後の値を記録する
     * - 同時に反映した変更には同じ change_set_id を付ける
     * - 差分がない場合は履歴を作らない
     *
     * @param array<string, mixed> $values キー => 値
     * @return array<int, string> 作成・変更したキー
     */
    public function sync(
        Blog $blog,
        array $values,
        ChangeSource $source,
        ?int $userId = null,
        ?int $syncRunId = null
    ): array {
        return DB::transaction(function () use ($blog, $values, $source, $userId, $syncRunId) {
            $now = now();
            $changeSetId = (string) Str::uuid();
            $changed = [];

            $existing = BlogSetting::where('blog_id', $blog->id)->get()->keyBy('key');

            foreach (BlogSetting::KEYS as $key) {
                if (! array_key_exists($key, $values)) {
                    continue;
                }

                $newValue = $this->toStoredValue($values[$key]);
                $setting = $existing->get($key);

                if ($setting === null) {
                    $setting = BlogSetting::create([
                        'blog_id'   => $blog->id,
                        'key'       => $key,
                        'value'     => $newValue,
                        'synced_at' => $now,
                    ]);

                    $this->recordHistory($setting, $changeSetId, '__created', null, null, $source, $userId, $syncRunId, $now);
                    $changed[] = $key;

                    continue;
                }

                if ($setting->value !== $newValue) {
                    $this->recordHistory($setting, $changeSetId, $key, $setting->value, $newValue, $source, $userId, $syncRunId, $now);
                    $setting->value = $newValue;
                    $changed[] = $key;
                }

                $setting->synced_at = $now;
                $setting->save();
            }

            return $changed;
        });
    }

    /**
     * 配列などはJSON文字列で保存する。真偽値は WordPress の表記に合わせる。
     */
    protected function toStoredValue(mixed $value): ?string
    {
        return match (true) {
            $value === null  => null,
            is_bool($value)  => $value ? 'true' : 'false',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default          => (string) $value,
        };
    }

    protected function recordHistory(
        BlogSetting $setting,
        string $changeSetId,
        string $field,
        ?string $oldValue,
        ?string $newValue,
        ChangeSource $source,
        ?int $userId,
        ?int $syncRunId,
        $changedAt
    ): void {
        BlogSettingHistory::create([
            'blog_id'         => $setting->blog_id,
            'blog_setting_id' => $setting->id,
            'change_set_id'   => $changeSetId,
            'field'           => $field,
            'old_value'       => $oldValue,
            'new_value'       => $newValue,
            'source'          => $source,
            'sync_run_id'     => $syncRunId,
            'user_id'         => $userId,
            'changed_at'      => $changedAt,
        ]);
    }
}
