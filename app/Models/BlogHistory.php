<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BlogHistory Model
 *
 * blog_historiesテーブルの1レコードを、
 * Laravel上で扱うためのModel。
 *
 * BlogOSでは、blogsテーブルに保存されているブログ情報が変更された際に、
 * 変更前の値・変更後の値・変更された項目・変更経路を
 * blog_historiesテーブルへ履歴として保存する。
 *
 * 例えば、ブログ名が、
 *
 * 「SI Note」
 *     ↓
 * 「SI Note Technical Blog」
 *
 * と変更された場合、
 *
 * field      = name
 * old_value  = SI Note
 * new_value  = SI Note Technical Blog
 * source     = 手動更新
 *
 * のような1件の履歴レコードを作成する。
 *
 * このModelは、その「変更履歴1件分」をLaravel上で扱うために使用する。
 *
 * blogsテーブルとblog_historiesテーブルは、
 *
 * blogs.id
 *     ↓
 * blog_histories.blog_id
 *
 * という親子関係になっている。
 *
 * そのため、このModelからは
 * 「この変更履歴がどのブログに対するものなのか」
 * も取得できるようになっている。
 */
class BlogHistory extends Model
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
     * しかし、blog_historiesテーブルでは、
     * 「履歴が作成された日時」を表すcreated_atだけを使用し、
     * updated_atは使用しない。
     *
     * また、blog_historiesテーブルのcreated_atは、
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
     * blog_historiesテーブルでは、
     * 以下の5項目をアプリケーション側から登録可能とする。
     *
     * blog_id
     *     変更対象となったブログのID。
     *
     *     blogs.idを参照し、
     *     「どのブログの変更履歴なのか」を識別する。
     *
     * field
     *     変更された項目名。
     *
     *     例：
     *     name
     *     description
     *     home
     *     timezone
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
        'field',
        'old_value',
        'new_value',
        'source',
    ];

    /**
     * この変更履歴が属しているブログを取得する。
     *
     * blog_historiesテーブルとblogsテーブルは、
     *
     * blog_histories.blog_id
     *     ↓
     * blogs.id
     *
     * という関係になっている。
     *
     * つまり、1件のBlogHistoryは必ず
     * 「どのBlogに対する変更なのか」を持っている。
     *
     * BelongsToは、
     *
     * 「複数のBlogHistoryが、1つのBlogに属する」
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
     * Blog Model側では、
     *
     * $blog->histories
     *
     * とすることで、そのブログに紐づく複数の履歴を取得できる。
     *
     * つまり、以下のように双方向の関連が定義されている。
     *
     * Blog
     *     ↓ histories()
     * 複数のBlogHistory
     *
     * BlogHistory
     *     ↓ blog()
     * 1つのBlog
     *
     * @return BelongsTo
     *     この変更履歴が属しているBlogとの関連情報。
     */
    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }
}
