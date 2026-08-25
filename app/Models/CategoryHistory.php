<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CategoryHistory Model
 *
 * category_historiesテーブルの1レコードを、
 * Laravel上で扱うためのModel。
 *
 * BlogOSでは、categoriesテーブルに保存されているカテゴリ情報が変更された際に、
 * 変更前の値・変更後の値・変更された項目・変更経路を
 * category_historiesテーブルへ履歴として保存する。
 *
 * 例えば、カテゴリ名が、
 *
 * 「PHP」
 *     ↓
 * 「PHP入門」
 *
 * と変更された場合、
 *
 * field      = name
 * old_value  = PHP
 * new_value  = PHP入門
 * source     = 手動更新
 *
 * のような1件の履歴レコードを作成する。
 *
 * このModelは、その「カテゴリ変更履歴1件分」を
 * Laravel上で扱うために使用する。
 *
 * categoriesテーブルとcategory_historiesテーブルは、
 *
 * categories.id
 *     ↓
 * category_histories.category_id
 *
 * という親子関係になっている。
 *
 * また、category_historiesテーブルにはblog_idも保持しているため、
 * このModelからは、
 *
 * 「この変更履歴がどのブログに対するものなのか」
 * 「この変更履歴がどのカテゴリに対するものなのか」
 *
 * の両方を取得できるようになっている。
 */
class CategoryHistory extends Model
{
    /**
     * Laravelによるcreated_at / updated_atの自動管理を無効にする。
     *
     * 通常、LaravelのEloquent Modelでは、
     *
     * created_at
     * updated_at
     *
     * の2つのカラムを自動的に管理する。
     *
     * しかし、category_historiesテーブルでは、
     * 「履歴が作成された日時」を表すcreated_atだけを使用し、
     * updated_atは使用しない。
     *
     * また、category_historiesテーブルのcreated_atは、
     * Migrationで
     *
     * $table->timestamp('created_at')->useCurrent();
     *
     * と定義しているため、
     * レコード作成時にDB側で現在日時が自動設定される。
     *
     * そのため、このModelではLaravel側による
     * created_at / updated_atの自動設定を無効にしている。
     */
    public $timestamps = false;

    /**
     * 一括代入（Mass Assignment）を許可する項目。
     *
     * Laravelでは、Model::create()などを使って
     * 配列から複数の項目を一度に登録する場合、
     * セキュリティ上、代入を許可する項目を
     * $fillableへ定義する。
     *
     * category_historiesテーブルでは、
     * 以下の6項目をアプリケーション側から登録可能とする。
     *
     * blog_id
     *     変更対象となったブログのID。
     *
     *     blogs.idを参照し、
     *     「どのブログのカテゴリ変更履歴なのか」を識別する。
     *
     * category_id
     *     変更対象となったカテゴリのID。
     *
     *     categories.idを参照し、
     *     「どのカテゴリの変更履歴なのか」を識別する。
     *
     * field
     *     変更された項目名。
     *
     *     例：
     *     name
     *     slug
     *     parent
     *     link
     *     description
     *
     * old_value
     *     変更前の値。
     *
     * new_value
     *     変更後の値。
     *
     * source
     *     変更が発生した経路。
     *
     *     例：
     *     APIからの自動更新
     *     手動更新
     *     システム処理
     *
     * created_atについては、
     * MigrationでDB側に現在日時を自動設定するよう定義しているため、
     * ここでは一括代入の対象としていない。
     */
    protected $fillable = [
        'blog_id',
        'category_id',
        'field',
        'old_value',
        'new_value',
        'source',
    ];

    /**
     * この変更履歴が属しているブログを取得する。
     *
     * category_historiesテーブルとblogsテーブルは、
     *
     * category_histories.blog_id
     *     ↓
     * blogs.id
     *
     * という関係になっている。
     *
     * つまり、1件のCategoryHistoryは必ず
     * 「どのBlogに対する変更なのか」を持っている。
     *
     * BelongsToは、
     *
     * 「複数のCategoryHistoryが、1つのBlogに属する」
     *
     * という親子関係を表す。
     *
     * この関係を定義しておくことで、
     *
     * $history->blog
     *
     * と記述するだけで、
     * この変更履歴に対応するBlog Modelを
     * Eloquent経由で取得できる。
     *
     * 例えば、ある変更履歴のblog_idが「1」の場合、
     *
     * $history->blog
     *
     * によって、blogs.id = 1のBlogを取得できる。
     *
     * @return BelongsTo
     *     この変更履歴が属しているBlogとの関連情報。
     */
    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    /**
     * この変更履歴が属しているカテゴリを取得する。
     *
     * category_historiesテーブルとcategoriesテーブルは、
     *
     * category_histories.category_id
     *     ↓
     * categories.id
     *
     * という関係になっている。
     *
     * つまり、1件のCategoryHistoryは必ず
     * 「どのCategoryに対する変更なのか」を持っている。
     *
     * BelongsToは、
     *
     * 「複数のCategoryHistoryが、1つのCategoryに属する」
     *
     * という親子関係を表す。
     *
     * この関係を定義しておくことで、
     *
     * $history->category
     *
     * と記述するだけで、
     * この変更履歴に対応するCategory Modelを
     * Eloquent経由で取得できる。
     *
     * 例えば、ある変更履歴のcategory_idが「10」の場合、
     *
     * $history->category
     *
     * によって、categories.id = 10のCategoryを取得できる。
     *
     * Category Model側では、
     *
     * $category->histories
     *
     * とすることで、そのカテゴリに紐づく複数の履歴を取得できる。
     *
     * つまり、以下のように双方向の関連が定義されている。
     *
     * Category
     *     ↓ histories()
     * 複数のCategoryHistory
     *
     * CategoryHistory
     *     ↓ category()
     * 1つのCategory
     *
     * @return BelongsTo
     *     この変更履歴が属しているCategoryとの関連情報。
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
