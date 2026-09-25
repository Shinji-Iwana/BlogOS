<?php

namespace App\Repositories;

use App\Enums\ChangeSource;
use App\Models\Blog;
use App\Models\BlogHistory;
use App\Support\HomeUrl;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class BlogRepository
{
    /**
     * 画面で表示する項目名
     */
    public const FIELD_LABELS = [
        'home'            => 'ホームURL',
        'display_name'    => '表示名',
        'quality_profile' => '品質基準（ブログ別の定義）',
        'is_selected'     => '選択中',
        'archived_at'     => 'アーカイブ日時',
    ];

    public function getAll(): Collection
    {
        return Blog::orderBy('id')->get();
    }

    /**
     * アーカイブしていないブログ（同期・反映・AIの対象。D-09-06）
     */
    public function getActive(): Collection
    {
        return Blog::whereNull('archived_at')->orderBy('id')->get();
    }

    public function findById(int $id): ?Blog
    {
        return Blog::find($id);
    }

    /**
     * 同じブログが登録済みかを調べる。スキームの違いや末尾のスラッシュを吸収して比べる（D-13-01）。
     */
    public function findByHome(string $home): ?Blog
    {
        $key = HomeUrl::comparisonKey($home);

        return Blog::query()
            ->get(['id', 'home'])
            ->first(fn (Blog $blog) => HomeUrl::comparisonKey($blog->home) === $key)
            ?->fresh();
    }

    /**
     * 選択中のブログを取得する。
     *
     * 選択中のブログがない場合は null を返す。
     * 画面の表示だけでDBを書き換えないよう、最初のブログを自動で選択することはしない。
     */
    public function findSelected(): ?Blog
    {
        return Blog::where('is_selected', true)
            ->orderBy('id')
            ->first();
    }

    /**
     * ブログを作成し、選択中にする。作成の履歴（__created）を記録する（D-13-04）。
     */
    public function create(string $home, string $displayName, ?string $qualityProfile, ?int $userId): Blog
    {
        return DB::transaction(function () use ($home, $displayName, $qualityProfile, $userId) {
            if ($this->findByHome($home) !== null) {
                throw new RuntimeException("指定されたブログ（{$home}）は既に登録されています。");
            }

            Blog::query()->update(['is_selected' => false]);

            $blog = Blog::create([
                'home'            => HomeUrl::normalize($home),
                'display_name'    => $displayName,
                'quality_profile' => $qualityProfile,
                'is_selected'     => true,
            ]);

            BlogHistory::create([
                'blog_id'       => $blog->id,
                'change_set_id' => (string) Str::uuid(),
                'field'         => '__created',
                'source'        => ChangeSource::BlogosManual,
                'user_id'       => $userId,
                'changed_at'    => now(),
            ]);

            return $blog;
        });
    }

    /**
     * 選択中のブログを切り替える。1つのトランザクションで、全ブログの選択を解除してから対象を選択する（D-02-05）。
     */
    public function updateSelected(int $blogId): Blog
    {
        return DB::transaction(function () use ($blogId) {
            $blog = Blog::find($blogId);

            if ($blog === null) {
                throw new RuntimeException('指定されたブログが存在しません。');
            }

            Blog::query()->update(['is_selected' => false]);

            $blog->is_selected = true;
            $blog->save();

            return $blog->fresh();
        });
    }
}
