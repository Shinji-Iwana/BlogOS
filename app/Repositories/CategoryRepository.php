<?php

namespace App\Repositories;

use App\DTO\WordPress\CategoryApiDto;
use App\Models\Category;
use App\Models\CategoryHistory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CategoryRepository
{
    protected const FIELD_MAP = [
        'id'          => 'category_id',
        'name'        => 'name',
        'slug'        => 'slug',
        'parent'      => 'parent',
        'link'        => 'link',
        'description' => 'description',
    ];

    /**
     * id：カテゴリID：12
     * count：公開記事数：25
     * description：カテゴリ説明：AWSに関する記事
     * link：カテゴリURL：https://si-note.com/category/aws/
     * name：カテゴリ名：AWS
     * slug：カテゴリスラッグ：aws
     * taxonomy：分類タイプ：category
     * parent：親カテゴリID：0
     * meta：メタ情報：{}
     */
    public const FIELD_LABELS = [
        'blog_id'        => '',
        'category_id'    => 'WordPressカテゴリID',
        'name'           => 'カテゴリ名',
        'slug'           => 'スラッグ',
        'parent_id'      => '親カテゴリID',
        'link'           => 'カテゴリURL',
        'description'    => '説明',
        'taxonomy'       => '分類タイプ',
        'last_synced_at' => '',
    ];

    public function getAll(int $blogId): Collection
    {
        return Category::where('blog_id', $blogId)
            ->orderBy('category_id')
            ->get();
    }

    public function findById(int $id): ?Category
    {
        return Category::find($id);
    }

    public function createFromApiData(int $blogId, CategoryApiDto $data, string $source): Category
    {
        return DB::transaction(function () use ($blogId, $data, $source) {
            $apiData = $data->data;

            $category = Category::create([
                'blog_id'     => $blogId,
                'category_id' => $apiData['id'],
                'name'        => $apiData['name'],
                'slug'        => $apiData['slug'],
                'parent'      => $apiData['parent'],
                'link'        => $apiData['link'],
                'description' => $apiData['description'],
            ]);

            foreach (self::FIELD_MAP as $dbField) {
                CategoryHistory::create([
                    'blog_id'     => $blogId,
                    'category_id' => $category->id,
                    'field'       => $dbField,
                    'old_value'   => null,
                    'new_value'   => $category->{$dbField} !== null
                        ? (string) $category->{$dbField}
                        : null,
                    'source'      => $source,
                ]);
            }

            return $category;
        });
    }

    public function createManyFromApiData(int $blogId, array $dataList, string $source): array
    {
        $categories = [];

        foreach ($dataList as $data) {
            $categories[] = $this->createFromApiData(
                $blogId,
                $data,
                $source
            );
        }

        return $categories;
    }

    public function diff(Category $category, CategoryApiDto $data): array
    {
        $diff = [];

        foreach (self::FIELD_MAP as $dtoProp => $dbField) {
            $oldValue = $category->{$dbField} !== null
                ? (string) $category->{$dbField}
                : null;

            $newValue = $data->{$dtoProp} !== null
                ? (string) $data->{$dtoProp}
                : null;

            if ($oldValue !== $newValue) {
                $diff[$dbField] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }

        return $diff;
    }

    public function updateWithHistory(Category $category, array $diff, string $source): void
    {
        if (empty($diff)) {
            return;
        }

        DB::transaction(function () use ($category, $diff, $source) {
            foreach ($diff as $field => $values) {
                CategoryHistory::create([
                    'blog_id' => $category->blog_id,
                    'category_id' => $category->id,
                    'field' => $field,
                    'old_value' => $values['old'],
                    'new_value' => $values['new'],
                    'source' => $source,
                ]);

                $category->{$field} = $values['new'];
            }

            $category->save();
        });
    }

    public function delete(Category $category): void
    {
        $category->delete();
    }
}
