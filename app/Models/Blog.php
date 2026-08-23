<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Blog Model
 *
 * blogsテーブルの1レコードを、Laravel上で扱うためのModel。
 *
 * BlogOSでは、1つのBlogOSで複数のWordPressブログを管理することを
 * 想定している。
 *
 * このModelは、blogsテーブルに保存されている
 * 「管理対象ブログ1件分の情報」を表す。
 *
 * 例えば、si-note.comを登録した場合、
 * blogsテーブルの1レコードがBlog Modelの1インスタンスに対応する。
 *
 * 主な役割は以下の通り。
 *
 * ・blogsテーブルのデータを取得する
 * ・blogsテーブルへデータを登録する
 * ・blogsテーブルのデータを更新する
 * ・blogsテーブルとblog_historiesテーブルの関連を定義する
 *
 * 実際のDB操作やブログ情報の登録・更新処理そのものは、
 * RepositoryなどからこのModelを利用して行う。
 */
class Blog extends Model
{
    /**
     * 一括代入（Mass Assignment）を許可する項目。
     *
     * Laravelでは、Model::create()やModel::update()などを使って
     * 配列から複数の項目を一度に登録・更新する場合、
     * セキュリティ上、あらかじめ代入を許可する項目を
     * $fillableへ定義する。
     *
     * このModelでは、blogsテーブルに保存する
     * 以下のブログ基本情報を一括代入可能とする。
     *
     * name
     *     WordPress APIから取得したブログ名。
     *
     * description
     *     WordPress APIから取得したブログの説明。
     *
     * url
     *     WordPress REST APIの情報として返されるURL。
     *
     * home
     *     WordPressサイトのホームURL。
     *     BlogOSでは、登録対象となるブログを識別するURLとして利用する。
     *
     * gmt_offset
     *     WordPress APIから取得したGMTからの時差。
     *
     * timezone
     *     WordPress APIのtimezone_stringに対応する値。
     *
     * id、created_at、updated_atについては、
     * DB側で自動的に生成・管理するため、ここでは指定しない。
     */
    protected $fillable = [
        'name',
        'description',
        'url',
        'home',
        'gmt_offset',
        'timezone',
    ];

    /**
     * このブログに紐づく変更履歴を取得する。
     *
     * blogsテーブルとblog_historiesテーブルは、
     *
     * blogs.id
     *     ↓
     * blog_histories.blog_id
     *
     * という親子関係になっている。
     *
     * つまり、1つのブログに対して、
     * 複数の変更履歴が存在することを表す。
     *
     * 例えば、si-note.comについて、
     *
     * ・ブログ名を変更した
     * ・descriptionを変更した
     * ・homeを変更した
     * ・timezoneを変更した
     *
     * などの変更が発生した場合、
     * それぞれの変更内容がblog_historiesテーブルへ保存される。
     *
     * この関係を定義しておくことで、Blog Modelから
     *
     * $blog->histories
     *
     * と記述するだけで、そのブログに紐づく変更履歴を
     * Eloquent経由で取得できる。
     *
     * HasManyは、
     * 「1つのBlogに対して複数のBlogHistoryが存在する」
     * という1対多（One-to-Many）の関係を表す。
     *
     * @return HasMany
     *     このブログに紐づくBlogHistoryの関連情報。
     */
    public function histories(): HasMany
    {
        return $this->hasMany(BlogHistory::class);
    }
}
