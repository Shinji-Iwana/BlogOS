<?php

namespace App\Repositories;

use App\DTO\WordPress\BlogApiData;
use App\Models\Blog;
use App\Models\BlogHistory;
use Illuminate\Support\Facades\DB;

/**
 * BlogRepository
 *
 * ブログ情報に関するDB操作をまとめて担当するRepository。
 *
 * BlogOSでは、WordPress REST APIから取得したブログ情報を
 * blogsテーブルへ保存し、その変更履歴を
 * blog_historiesテーブルへ保存する。
 *
 * このRepositoryでは、Controllerなどから直接Modelを操作するのではなく、
 * ブログ情報に関するDB処理をここへ集約する。
 *
 * 主な役割は以下の通り。
 *
 * ・homeを使って既存ブログを検索する
 * ・WordPress APIから取得したブログ情報を新規登録する
 * ・API取得結果とDBに保存されている情報を比較する
 * ・変更された項目だけを抽出する
 * ・変更内容をblogsテーブルへ反映する
 * ・変更内容をblog_historiesテーブルへ保存する
 * ・GMTオフセットなど、DB保存前に必要な値の整形を行う
 *
 * Controller側では、
 *
 * 「ブログを登録する」
 * 「ブログに差分があるか確認する」
 * 「ブログを更新する」
 *
 * といった処理の指示だけを行い、
 * 実際のDB処理はこのRepositoryへ委譲する。
 *
 * これにより、ControllerにDB処理の詳細が集中することを防ぎ、
 * ブログ情報に関するDB処理を一箇所で管理できるようにする。
 */
class BlogRepository
{
    /**
     * BlogApiDataのプロパティ名と、
     * blogsテーブルのカラム名との対応表。
     *
     * WordPress APIから取得した情報はBlogApiData DTOとして、
     * BlogOS内部では以下のプロパティ名で保持している。
     *
     * 例えば、
     *
     * $data->gmtOffset
     *
     * は、blogsテーブルでは
     *
     * gmt_offset
     *
     * というカラムへ保存する。
     *
     * また、
     *
     * $data->timezoneString
     *
     * は、
     *
     * timezone
     *
     * というカラムへ保存する。
     *
     * この対応関係をここで一元管理することで、
     * DTOとDBカラムの名前が異なる部分を
     * 各処理に個別に記述する必要をなくしている。
     *
     * キー：
     *     BlogApiDataのプロパティ名。
     *
     * 値：
     *     blogsテーブルのカラム名。
     */
    protected const FIELD_MAP = [
        'name'            => 'name',
        'description'     => 'description',
        'url'             => 'url',
        'gmtOffset'       => 'gmt_offset',
        'timezoneString'  => 'timezone',
    ];

    /**
     * blogsテーブルのカラム名と、
     * 画面に表示する日本語ラベルとの対応表。
     *
     * FIELD_MAPが「DTOとDBの対応」を表すのに対して、
     * FIELD_LABELSは「DB項目と画面表示名の対応」を表す。
     *
     * 例えば、
     *
     * gmt_offset
     *
     * というDB上の項目名を、
     *
     * GMTオフセット
     *
     * として画面に表示するために使用する。
     *
     * 主に、既存ブログとの比較結果を表示する
     * 確認ポップアップなどで利用する。
     */
    public const FIELD_LABELS = [
        'name'        => 'サイト名',
        'description' => '説明',
        'url'         => 'URL',
        'gmt_offset'  => 'GMTオフセット',
        'timezone'    => 'タイムゾーン',
    ];

    /**
     * blogsテーブルへ登録されている、全ブログ情報を取得する。
     *
     * WordPress REST APIへは一切アクセスせず、
     * blogsテーブルに保存済みの情報のみを返す。
     *
     * ブログ一覧画面など、DBの内容をそのまま表示したい場合に使用する。
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Blog>
     *     blogsテーブルの全レコード（id昇順）。
     */
    public function getAll(): \Illuminate\Database\Eloquent\Collection
    {
        return Blog::orderBy('id')->get();
    }

    /**
     * homeを使って既存のブログを検索する。
     *
     * BlogOSでは、WordPressサイトのhomeを
     * ブログを識別するための値として使用する。
     *
     * 例えば、
     *
     * https://si-note.com
     *
     * が既にblogsテーブルへ登録されている場合、
     * 同じhomeを指定して検索すると、そのBlog Modelを取得する。
     *
     * blogs.homeにはunique制約を設定しているため、
     * 同じhomeを持つブログが複数登録されることはない。
     *
     * @param string $home
     *     検索対象となるWordPressサイトのホームURL。
     *
     * @return Blog|null
     *     該当するブログが存在する場合はBlog Model、
     *     存在しない場合はnull。
     */
    public function findByHome(string $home): ?Blog
    {
        return Blog::where('home', $home)->first();
    }

    /**
     * IDを指定して、blogsテーブルから1件のブログ情報を取得する。
     *
     * WordPress REST APIへは一切アクセスせず、
     * blogsテーブルに保存済みの情報のみを返す。
     *
     * ブログ詳細画面など、DBの内容をそのまま表示したい場合に使用する。
     *
     * @param int $id
     *     取得対象となるブログのID（blogs.id）。
     *
     * @return Blog|null
     *     該当するブログが存在する場合はBlog Model、
     *     存在しない場合はnull。
     */
    public function findById(int $id): ?Blog
    {
        return Blog::find($id);
    }

    /**
     * WordPress APIから取得したブログ情報を
     * blogsテーブルへ新規登録する。
     *
     * 新規登録時には、blogsテーブルへの登録だけではなく、
     * 登録された各項目についてblog_historiesにも
     * 初期登録履歴を作成する。
     *
     * これにより、
     *
     * 「このブログがいつ、どの経路でBlogOSへ登録されたのか」
     *
     * を履歴として残すことができる。
     *
     * 初回登録では変更前の値が存在しないため、
     * old_valueはNULLとなる。
     *
     * new_valueには、登録時点の値を保存する。
     *
     * また、blogsへの登録とblog_historiesへの履歴作成は、
     * すべて同一トランザクション内で実行する。
     *
     * そのため、途中でエラーが発生した場合には、
     * ブログだけ登録されて履歴が残らない、
     * といった不整合を防ぐことができる。
     *
     * @param BlogApiData $data
     *     WordPress REST APIから取得し、
     *     BlogApiDataへ変換されたブログ情報。
     *
     * @param string $source
     *     登録が発生した経路。
     *     例：手動更新、API、自動更新など。
     *
     * @return Blog
     *     新規登録されたBlog Model。
     */
    public function createFromApiData(BlogApiData $data, string $source): Blog
    {
        // blogsテーブルへの登録と、
        // blog_historiesテーブルへの履歴登録を
        // 1つのトランザクションとして処理する。
        //
        // どちらか一方だけ成功する状態を防ぐために使用する。
        return DB::transaction(function () use ($data, $source) {
            // BlogApiDataの各値をblogsテーブルの
            // 対応するカラムへ変換して登録する。
            //
            // gmt_offsetについては、
            // APIから取得した値をそのまま保存するのではなく、
            // DB保存用にnormalizeGmtOffset()で整形する。
            $blog = Blog::create([
                'name'        => $data->name,
                'description' => $data->description,
                'url'         => $data->url,
                'home'        => $data->home,
                'gmt_offset'  => $this->normalizeGmtOffset($data->gmtOffset),
                'timezone'    => $data->timezoneString,
            ]);

            // 新規登録されたブログについて、
            // 各項目の初期値を履歴として保存する。
            //
            // FIELD_MAPを使用することで、
            // DTOとDBカラムの対応関係を共通化している。
            foreach (self::FIELD_MAP as $dtoProp => $dbField) {
                BlogHistory::create([
                    // 今回登録したブログのID。
                    'blog_id'   => $blog->id,

                    // 登録されたDB項目名。
                    'field'     => $dbField,

                    // 新規登録なので変更前の値は存在しない。
                    'old_value' => null,

                    // 登録直後のblogsテーブルに保存された値。
                    'new_value' => (string) $blog->{$dbField},

                    // 登録が発生した経路。
                    'source'    => $source,
                ]);
            }

            // 新規登録されたBlog Modelを呼び出し元へ返す。
            return $blog;
        });
    }

    /**
     * 既存のBlogとWordPress APIから取得した情報を比較し、
     * 差分が存在する項目だけを返す。
     *
     * blogsテーブルに保存されている現在の値と、
     * WordPress REST APIから新しく取得した値を
     * FIELD_MAPに定義された項目ごとに比較する。
     *
     * 差分がない項目は結果へ含めない。
     *
     * 例えば、nameだけ変更されていた場合、
     *
     * [
     *     'name' => [
     *         'old' => '旧サイト名',
     *         'new' => '新サイト名',
     *     ],
     * ]
     *
     * のような結果を返す。
     *
     * この差分情報は、
     *
     * ・更新が必要かどうかの判定
     * ・画面上の差分確認
     * ・blogsテーブルの更新
     * ・blog_historiesへの履歴保存
     *
     * に利用される。
     *
     * @param Blog $blog
     *     DBに現在保存されているBlog Model。
     *
     * @param BlogApiData $data
     *     WordPress REST APIから新しく取得したブログ情報。
     *
     * @return array<string, array{old: string, new: string}>
     *     差分が存在する項目のみを格納した配列。
     */
    public function diff(Blog $blog, BlogApiData $data): array
    {
        // 差分を格納する配列。
        $diff = [];

        // DTOとDBの対応関係に基づいて、
        // 対象となる項目を1つずつ比較する。
        foreach (self::FIELD_MAP as $dtoProp => $dbField) {
            // 現在DBに保存されている値を取得する。
            //
            // 比較時に型の違いが発生しないよう、
            // 文字列へ統一している。
            $oldValue = (string) $blog->{$dbField};

            // APIから取得した新しい値を取得する。
            //
            // gmt_offsetだけは、
            // API側の表現とDB側の表現を統一するため、
            // normalizeGmtOffset()で小数点以下2桁に整形する。
            //
            // それ以外の項目は文字列へ変換する。
            $newValue = $dbField === 'gmt_offset'
                ? $this->normalizeGmtOffset($data->{$dtoProp})
                : (string) $data->{$dtoProp};

            // DBに既に保存されているgmt_offsetについても
            // 比較前に同じ形式へ正規化する。
            //
            // 例えば、
            //
            // DB      : 9
            // API     : 9.00
            //
            // のような場合でも、
            // 同じ「9.00」として比較できるようにする。
            $oldValueForCompare = $dbField === 'gmt_offset'
                ? $this->normalizeGmtOffset($oldValue)
                : $oldValue;

            // 現在のDB値とAPIから取得した新しい値を比較する。
            //
            // 値が異なる場合だけ差分として保存する。
            if ($oldValueForCompare !== $newValue) {
                $diff[$dbField] = [
                    // 変更前の値。
                    'old' => $oldValue,

                    // 変更後の値。
                    'new' => $newValue,
                ];
            }
        }

        // 差分が存在する項目だけを返す。
        return $diff;
    }

    /**
     * 差分情報をblogsテーブルへ反映し、
     * 同時にblog_historiesへ変更履歴を保存する。
     *
     * この処理では、
     *
     * 1. blog_historiesへ変更履歴を保存
     * 2. blogsの該当項目を変更
     * 3. blogsを保存
     *
     * を1つのトランザクションとして実行する。
     *
     * 例えばnameが、
     *
     * 旧：SI Note
     * 新：SI Note Technical Blog
     *
     * に変更された場合、
     *
     * blog_histories
     *     old_value = SI Note
     *     new_value = SI Note Technical Blog
     *
     * と記録したうえで、
     *
     * blogs.name
     *     ↓
     * SI Note Technical Blog
     *
     * と更新する。
     *
     * ブログ本体の更新と履歴保存を同じトランザクション内で行うことで、
     * 片方だけ成功する不整合を防ぐ。
     *
     * @param Blog $blog
     *     更新対象となる既存のBlog Model。
     *
     * @param array<string, array{old: string, new: string}> $diff
     *     diff()で生成された変更内容。
     *
     * @param string $source
     *     変更が発生した経路。
     *     例：手動更新、API、自動更新など。
     */
    public function updateWithHistory(Blog $blog, array $diff, string $source): void
    {
        // 差分が存在しない場合は、
        // DB更新も履歴作成も必要ないため何もしない。
        if (empty($diff)) {
            return;
        }

        // blogsの更新とblog_historiesへの履歴保存を
        // 1つのトランザクションとして処理する。
        //
        // 例えば、履歴保存に成功したものの、
        // blogsの更新に失敗する、といった状態を防ぐ。
        DB::transaction(function () use ($blog, $diff, $source) {
            // 差分が存在する項目を1つずつ処理する。
            foreach ($diff as $field => $values) {
                // まず、変更内容を履歴として保存する。
                //
                // これにより、
                // 「何が」「何から」「何へ」
                // 変更されたのかを後から確認できる。
                BlogHistory::create([
                    // 変更対象となったブログのID。
                    'blog_id'   => $blog->id,

                    // 変更されたblogsテーブルの項目名。
                    'field'     => $field,

                    // 変更前の値。
                    'old_value' => $values['old'],

                    // 変更後の値。
                    'new_value' => $values['new'],

                    // 変更が発生した経路。
                    'source'    => $source,
                ]);

                // Blog Modelの該当項目へ新しい値を設定する。
                //
                // この時点ではまだDBへ保存されておらず、
                // 最後の$blog->save()でまとめて保存される。
                $blog->{$field} = $values['new'];
            }

            // 差分を反映したBlog Modelを
            // blogsテーブルへ保存する。
            $blog->save();
        });
    }

    /**
     * GMTオフセットの値をDB保存・比較用に正規化する。
     *
     * WordPress REST APIから取得したgmt_offsetは、
     * 状況によって「9」「9.0」「9.00」など、
     * 表現が異なる可能性がある。
     *
     * そのまま比較すると、
     *
     * 9
     * と
     * 9.00
     *
     * が文字列としては異なる値として判定される。
     *
     * そのため、BlogOS内部では小数点以下2桁の形式へ統一する。
     *
     * 例えば、
     *
     * 9     → 9.00
     * 9.0   → 9.00
     * 9.00  → 9.00
     * -5    → -5.00
     *
     * のように変換される。
     *
     * blogs.gmt_offsetのDECIMAL(4,2)というDB定義とも
     * 整合する形式にするために使用する。
     *
     * @param string $value
     *     正規化対象となるGMTオフセット。
     *
     * @return string
     *     小数点以下2桁に統一されたGMTオフセット。
     */
    protected function normalizeGmtOffset(string $value): string
    {
        return number_format((float) $value, 2);
    }
}
