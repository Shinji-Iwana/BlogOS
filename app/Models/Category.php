<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Category Model
 *
 * categoriesテーブルの1レコードを、
 * Laravel上で扱うためのModel。
 *
 * BlogOSでは、WordPress REST APIから取得したカテゴリ情報を
 * categoriesテーブルへ保存し、ブログごとのカテゴリ情報を管理する。
 *
 * このModelは、その「カテゴリ1件分の情報」を
 * Laravel上で扱うために使用する。
 *
 * categoriesテーブルでは、
 *
 * id
 *     BlogOS内部で使用するカテゴリの一意なID。
 *
 * blog_id
 *     このカテゴリが属しているブログのID。
 *
 * category_id
 *     WordPress側で管理されているカテゴリID。
 *
 * name
 *     WordPress側で管理されているカテゴリ名。
 *
 * slug
 *     WordPress側で管理されているカテゴリのスラッグ。
 *
 * parent
 *     WordPress側で管理されている親カテゴリのID。
 *
 * link
 *     WordPress側で管理されているカテゴリページのURL。
 *
 * description
 *     WordPress側で管理されているカテゴリの説明。
 *
 * という情報を保持する。
 *
 * また、categoriesテーブルとcategory_historiesテーブルの関連を定義し、
 * このカテゴリに対して発生した変更履歴を取得できるようにする。
 */
class Category extends Model
{
    /**
     * 一括代入（Mass Assignment）を許可する項目。
     *
     * Laravelでは、Model::create()やModel::update()などを使って
     * 配列から複数の項目を一度に登録・更新する場合、
     * セキュリティ上、あらかじめ代入を許可する項目を
     * $fillableへ定義する。
     *
     * このModelでは、categoriesテーブルに保存する
     * 以下のカテゴリ情報を一括代入可能とする。
     *
     * blog_id
     *     このカテゴリが属するブログのID。
     *
     *     blogs.idを参照する。
     *
     * category_id
     *     WordPress側で管理されているカテゴリID。
     *
     *     WordPress REST API
     *     「/wp-json/wp/v2/categories」の
     *     idから取得した値を保存する。
     *
     * name
     *     WordPress APIから取得したカテゴリ名。
     *
     * slug
     *     WordPress APIから取得したカテゴリのスラッグ。
     *
     * parent
     *     WordPress APIから取得した親カテゴリのID。
     *
     *     親カテゴリが存在しない場合はNULL。
     *
     * link
     *     WordPress APIから取得したカテゴリページのURL。
     *
     * description
     *     WordPress APIから取得したカテゴリの説明。
     *
     * id、created_at、updated_atについては、
     * DB側で自動的に生成・管理するため、ここでは指定しない。
     *
     * また、WordPress APIから取得できるcountについては、
     * categoriesテーブルには保存しないため、
     * このModelでも保持・一括代入の対象としない。
     *
     * カテゴリに属する記事数が必要な場合は、
     * BlogOS側の投稿情報から必要に応じて集計する。
     */
    protected $fillable = [
        'blog_id',
        'category_id',
        'name',
        'slug',
        'parent',
        'link',
        'description',
    ];

    /**
     * このカテゴリが属しているブログを取得する。
     *
     * categoriesテーブルとblogsテーブルは、
     *
     * categories.blog_id
     *     ↓
     * blogs.id
     *
     * という親子関係になっている。
     *
     * つまり、複数のCategoryが1つのBlogに属することを表す。
     *
     * この関係を定義しておくことで、Category Modelから
     *
     * $category->blog
     *
     * と記述するだけで、このカテゴリが属するBlogを
     * Eloquent経由で取得できる。
     *
     * 例えば、あるカテゴリのblog_idが「1」の場合、
     *
     * $category->blog
     *
     * によって、blogs.id = 1のBlogを取得できる。
     *
     * Blog Model側では、今後categories()を定義することで、
     *
     * $blog->categories
     *
     * とするだけで、そのブログに紐づく複数のカテゴリを
     * 取得できるようになる。
     *
     * つまり、以下のような双方向の関連を定義できる。
     *
     * Blog
     *     ↓ categories()
     * 複数のCategory
     *
     * Category
     *     ↓ blog()
     * 1つのBlog
     *
     * @return BelongsTo
     *     このカテゴリが属しているBlogとの関連情報。
     */
    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    /**
     * このカテゴリに紐づく変更履歴を取得する。
     *
     * categoriesテーブルとcategory_historiesテーブルは、
     *
     * categories.id
     *     ↓
     * category_histories.category_id
     *
     * という親子関係になっている。
     *
     * つまり、1つのカテゴリに対して、
     * 複数の変更履歴が存在することを表す。
     *
     * 例えば、あるカテゴリについて、
     *
     * ・カテゴリ名を変更した
     * ・slugを変更した
     * ・parentを変更した
     * ・descriptionを変更した
     *
     * などの変更が発生した場合、
     * それぞれの変更内容がcategory_historiesテーブルへ保存される。
     *
     * この関係を定義しておくことで、Category Modelから
     *
     * $category->histories
     *
     * と記述するだけで、そのカテゴリに紐づく変更履歴を
     * Eloquent経由で取得できる。
     *
     * HasManyは、
     * 「1つのCategoryに対して複数のCategoryHistoryが存在する」
     * という1対多（One-to-Many）の関係を表す。
     *
     * @return HasMany
     *     このカテゴリに紐づくCategoryHistoryの関連情報。
     */
    public function histories(): HasMany
    {
        return $this->hasMany(CategoryHistory::class);
    }
}
