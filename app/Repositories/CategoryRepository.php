<?php

namespace App\Repositories;

use App\DTO\WordPress\CategoryApiData;
use App\Models\Category;
use App\Models\CategoryHistory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CategoryRepository
 *
 * カテゴリ情報に関するDB操作をまとめて担当するRepository。
 *
 * BlogOSでは、WordPress REST APIから取得したカテゴリ情報を
 * categoriesテーブルへ保存し、その変更履歴を
 * category_historiesテーブルへ保存する。
 *
 * このRepositoryでは、Controllerなどから直接Modelを操作するのではなく、
 * カテゴリ情報に関するDB処理をここへ集約する。
 *
 * 主な役割は以下の通り。
 *
 * ・ブログ単位で登録されているカテゴリを全件取得する
 * ・IDを使ってカテゴリを1件取得する
 * ・WordPress APIから取得したカテゴリ情報を新規登録する
 * ・複数のカテゴリ情報をまとめて新規登録する
 * ・WordPress API取得結果とDBに保存されている情報を比較する
 * ・変更された項目だけを抽出する
 * ・変更内容をcategoriesテーブルへ反映する
 * ・変更内容をcategory_historiesテーブルへ保存する
 * ・カテゴリを削除する
 *
 * Controller側では、
 *
 * 「カテゴリを取得する」
 * 「カテゴリを登録する」
 * 「カテゴリに差分があるか確認する」
 * 「カテゴリを更新する」
 * 「カテゴリを削除する」
 *
 * といった処理の指示だけを行い、
 * 実際のDB処理はこのRepositoryへ委譲する。
 *
 * これにより、ControllerにDB処理の詳細が集中することを防ぎ、
 * カテゴリ情報に関するDB処理を一箇所で管理できるようにする。
 */
class CategoryRepository
{
    /**
     * CategoryApiDataのプロパティ名と、
     * categoriesテーブルのカラム名との対応表。
     *
     * WordPress APIから取得した情報はCategoryApiData DTOとして、
     * BlogOS内部では以下のプロパティ名で保持している。
     *
     * 例えば、
     *
     * $data->categoryId
     *
     * は、categoriesテーブルでは
     *
     * category_id
     *
     * というカラムへ保存する。
     *
     * この対応関係をここで一元管理することで、
     * DTOとDBカラムの名前が異なる部分を
     * 各処理に個別に記述する必要をなくしている。
     *
     * なお、WordPress APIから取得できるcountについては、
     * BlogOSではDBへ保存しないため、この対応表にも含めない。
     *
     * キー：
     *     CategoryApiDataのプロパティ名。
     *
     * 値：
     *     categoriesテーブルのカラム名。
     */
    protected const FIELD_MAP = [
        'id'          => 'category_id',
        'name'        => 'name',
        'slug'        => 'slug',
        'parent'      => 'parent',
        'link'        => 'link',
        'description' => 'description',
    ];

    /**
     * categoriesテーブルのカラム名と、
     * 画面に表示する日本語ラベルとの対応表。
     *
     * FIELD_MAPが「DTOとDBの対応」を表すのに対して、
     * FIELD_LABELSは「DB項目と画面表示名の対応」を表す。
     *
     * 主に、既存カテゴリとの比較結果を表示する
     * 確認画面などで利用する。
     */
    public const FIELD_LABELS = [
        'id'          => 'WordPressカテゴリID',
        'name'        => 'カテゴリ名',
        'slug'        => 'スラッグ',
        'parent'      => '親カテゴリID',
        'link'        => 'カテゴリURL',
        'description' => '説明',
    ];

    /**
     * 指定されたブログに紐づくカテゴリを全件取得する。
     *
     * WordPress REST APIへは一切アクセスせず、
     * categoriesテーブルに保存済みの情報のみを返す。
     *
     * カテゴリ一覧画面など、DBの内容をそのまま表示したい場合に使用する。
     *
     * @param int $blogId
     *     取得対象となるブログのID（blogs.id）。
     *
     * @return Collection<int, Category>
     *     指定されたブログに紐づくカテゴリの全レコード。
     *     category_id昇順で取得する。
     */
    public function getAll(int $blogId): Collection
    {
        return Category::where('blog_id', $blogId)
            ->orderBy('category_id')
            ->get();
    }

    /**
     * IDを指定して、categoriesテーブルから1件のカテゴリ情報を取得する。
     *
     * WordPress REST APIへは一切アクセスせず、
     * categoriesテーブルに保存済みの情報のみを返す。
     *
     * カテゴリ詳細画面など、DBの内容をそのまま表示したい場合に使用する。
     *
     * @param int $id
     *     取得対象となるカテゴリのID（categories.id）。
     *
     * @return Category|null
     *     該当するカテゴリが存在する場合はCategory Model、
     *     存在しない場合はnull。
     */
    public function findById(int $id): ?Category
    {
        return Category::find($id);
    }

    /**
     * WordPress APIから取得したカテゴリ情報を
     * categoriesテーブルへ新規登録する。
     *
     * 新規登録時には、categoriesテーブルへの登録だけではなく、
     * 登録された各項目についてcategory_historiesにも
     * 初期登録履歴を作成する。
     *
     * これにより、
     *
     * 「このカテゴリがいつ、どの経路でBlogOSへ登録されたのか」
     *
     * を履歴として残すことができる。
     *
     * 初回登録では変更前の値が存在しないため、
     * old_valueはNULLとなる。
     *
     * new_valueには、登録時点の値を保存する。
     *
     * また、categoriesへの登録とcategory_historiesへの履歴作成は、
     * すべて同一トランザクション内で実行する。
     *
     * そのため、途中でエラーが発生した場合には、
     * カテゴリだけ登録されて履歴が残らない、
     * といった不整合を防ぐことができる。
     *
     * @param int $blogId
     *     カテゴリが属するブログのID（blogs.id）。
     *
     * @param CategoryApiData $data
     *     WordPress REST APIから取得し、
     *     CategoryApiDataへ変換されたカテゴリ情報。
     *
     * @param string $source
     *     登録が発生した経路。
     *     例：初回投入、手動登録、API、自動更新など。
     *
     * @return Category
     *     新規登録されたCategory Model。
     */
    public function createFromApiData(
        int $blogId,
        CategoryApiData $data,
        string $source
    ): Category {
        // categoriesテーブルへの登録と、
        // category_historiesテーブルへの履歴登録を
        // 1つのトランザクションとして処理する。
        //
        // どちらか一方だけ成功する状態を防ぐために使用する。
        return DB::transaction(function () use ($blogId, $data, $source) {
            // CategoryApiDataの各値をcategoriesテーブルの
            // 対応するカラムへ変換して登録する。
            //
            // countについては、WordPress APIから取得できる値ではあるが、
            // BlogOSでは記事数をDBへ保持しない方針のため保存しない。
            $category = Category::create([
                'blog_id'     => $blogId,
                'category_id' => $data->id,
                'name'        => $data->name,
                'slug'        => $data->slug,
                'parent'      => $data->parent,
                'link'        => $data->link,
                'description' => $data->description,
            ]);

            // 新規登録されたカテゴリについて、
            // 各項目の初期値を履歴として保存する。
            //
            // FIELD_MAPを使用することで、
            // DTOとDBの対応関係を共通化している。
            foreach (self::FIELD_MAP as $dtoProp => $dbField) {
                CategoryHistory::create([
                    // 今回登録したカテゴリが属するブログのID。
                    'blog_id' => $blogId,

                    // 変更対象となったcategoriesテーブルのID。
                    //
                    // category_histories.category_idは、
                    // WordPress側のcategory_idではなく、
                    // BlogOS側のcategories.idを参照する。
                    'category_id' => $category->id,

                    // 登録されたDB項目名。
                    'field' => $dbField,

                    // 新規登録なので変更前の値は存在しない。
                    'old_value' => null,

                    // 登録直後のcategoriesテーブルに保存された値。
                    //
                    // nullの場合でも、履歴側ではNULLとして保存する。
                    'new_value' => $category->{$dbField} !== null
                        ? (string) $category->{$dbField}
                        : null,

                    // 登録が発生した経路。
                    'source' => $source,
                ]);
            }

            // 新規登録されたCategory Modelを呼び出し元へ返す。
            return $category;
        });
    }

    /**
     * WordPress APIから取得した複数のカテゴリ情報を
     * categoriesテーブルへ新規登録する。
     *
     * 初回投入など、WordPress側から取得したカテゴリを
     * 複数件まとめてBlogOSへ登録する場合に使用する。
     *
     * 実際の1件ごとの登録処理はcreateFromApiData()へ委譲する。
     *
     * これにより、1件登録時と複数件登録時で、
     * categoriesへの登録処理やcategory_historiesへの履歴作成処理が
     * 別々の実装になってしまうことを防ぐ。
     *
     * なお、このメソッド自体では
     * 「既に登録されているカテゴリをどう扱うか」
     * という判定は行わない。
     *
     * 初回投入や再実行時の対象判定は、
     * Seederなど呼び出し側で行う。
     *
     * @param int $blogId
     *     カテゴリが属するブログのID（blogs.id）。
     *
     * @param array<CategoryApiData> $dataList
     *     WordPress REST APIから取得し、
     *     CategoryApiDataへ変換されたカテゴリ情報の一覧。
     *
     * @param string $source
     *     登録が発生した経路。
     *     例：初回投入、手動登録、API、自動更新など。
     *
     * @return array<Category>
     *     新規登録されたCategory Modelの一覧。
     */
    public function createManyFromApiData(
        int $blogId,
        array $dataList,
        string $source
    ): array {
        // 登録されたカテゴリを格納する配列。
        $categories = [];

        // 取得したカテゴリを1件ずつ処理する。
        foreach ($dataList as $data) {
            // 1件分の登録処理はcreateFromApiData()へ委譲する。
            //
            // createFromApiData()側でcategoriesへの登録と
            // category_historiesへの履歴登録を行う。
            $categories[] = $this->createFromApiData(
                $blogId,
                $data,
                $source
            );
        }

        // 新規登録されたカテゴリ一覧を呼び出し元へ返す。
        return $categories;
    }

    /**
     * 既存のCategoryとWordPress APIから取得した情報を比較し、
     * 差分が存在する項目だけを返す。
     *
     * categoriesテーブルに保存されている現在の値と、
     * WordPress REST APIから新しく取得した値を
     * FIELD_MAPに定義された項目ごとに比較する。
     *
     * 差分がない項目は結果へ含めない。
     *
     * 例えばnameだけ変更されていた場合、
     *
     * [
     *     'name' => [
     *         'old' => '旧カテゴリ名',
     *         'new' => '新カテゴリ名',
     *     ],
     * ]
     *
     * のような結果を返す。
     *
     * この差分情報は、
     *
     * ・更新が必要かどうかの判定
     * ・画面上の差分確認
     * ・categoriesテーブルの更新
     * ・category_historiesへの履歴保存
     *
     * に利用される。
     *
     * なお、WordPress APIのcountについては、
     * BlogOSではDBへ保存していないため比較対象としない。
     *
     * @param Category $category
     *     DBに現在保存されているCategory Model。
     *
     * @param CategoryApiData $data
     *     WordPress REST APIから新しく取得したカテゴリ情報。
     *
     * @return array<string, array{old: string|null, new: string|null}>
     *     差分が存在する項目のみを格納した配列。
     */
    public function diff(
        Category $category,
        CategoryApiData $data
    ): array {
        // 差分を格納する配列。
        $diff = [];

        // DTOとDBの対応関係に基づいて、
        // 対象となる項目を1つずつ比較する。
        foreach (self::FIELD_MAP as $dtoProp => $dbField) {
            // 現在DBに保存されている値を取得する。
            //
            // 比較時に型の違いが発生しないよう、
            // null以外は文字列へ統一する。
            $oldValue = $category->{$dbField} !== null
                ? (string) $category->{$dbField}
                : null;

            // APIから取得した新しい値を取得する。
            //
            // nullが許容されている項目については、
            // nullをそのまま保持する。
            $newValue = $data->{$dtoProp} !== null
                ? (string) $data->{$dtoProp}
                : null;

            // 現在のDB値とAPIから取得した新しい値を比較する。
            //
            // 値が異なる場合だけ差分として保存する。
            if ($oldValue !== $newValue) {
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
     * 差分情報をcategoriesテーブルへ反映し、
     * 同時にcategory_historiesへ変更履歴を保存する。
     *
     * この処理では、
     *
     * 1. category_historiesへ変更履歴を保存
     * 2. categoriesの該当項目を変更
     * 3. categoriesを保存
     *
     * を1つのトランザクションとして実行する。
     *
     * 例えばnameが、
     *
     * 旧：PHP
     * 新：PHP Laravel
     *
     * に変更された場合、
     *
     * category_histories
     *     old_value = PHP
     *     new_value = PHP Laravel
     *
     * と記録したうえで、
     *
     * categories.name
     *     ↓
     * PHP Laravel
     *
     * と更新する。
     *
     * カテゴリ本体の更新と履歴保存を同じトランザクション内で行うことで、
     * 片方だけ成功する不整合を防ぐ。
     *
     * @param Category $category
     *     更新対象となる既存のCategory Model。
     *
     * @param array<string, array{old: string|null, new: string|null}> $diff
     *     diff()で生成された変更内容。
     *
     * @param string $source
     *     変更が発生した経路。
     *     例：手動更新、API、自動更新など。
     */
    public function updateWithHistory(
        Category $category,
        array $diff,
        string $source
    ): void {
        // 差分が存在しない場合は、
        // DB更新も履歴作成も必要ないため何もしない。
        if (empty($diff)) {
            return;
        }

        // categoriesの更新とcategory_historiesへの履歴保存を
        // 1つのトランザクションとして処理する。
        //
        // 例えば、履歴保存に成功したものの、
        // categoriesの更新に失敗する、といった状態を防ぐ。
        DB::transaction(function () use ($category, $diff, $source) {
            // 差分が存在する項目を1つずつ処理する。
            foreach ($diff as $field => $values) {
                // まず、変更内容を履歴として保存する。
                //
                // これにより、
                // 「何が」「何から」「何へ」
                // 変更されたのかを後から確認できる。
                CategoryHistory::create([
                    // 変更対象となったカテゴリが属するブログのID。
                    'blog_id' => $category->blog_id,

                    // 変更対象となったcategoriesテーブルのID。
                    //
                    // WordPress側のcategory_idではなく、
                    // BlogOS側のcategories.idを使用する。
                    'category_id' => $category->id,

                    // 変更されたcategoriesテーブルの項目名。
                    'field' => $field,

                    // 変更前の値。
                    'old_value' => $values['old'],

                    // 変更後の値。
                    'new_value' => $values['new'],

                    // 変更が発生した経路。
                    'source' => $source,
                ]);

                // Category Modelの該当項目へ新しい値を設定する。
                //
                // この時点ではまだDBへ保存されておらず、
                // 最後の$category->save()で保存される。
                $category->{$field} = $values['new'];
            }

            // 差分を反映したCategory Modelを
            // categoriesテーブルへ保存する。
            $category->save();
        });
    }

    /**
     * 指定されたカテゴリをcategoriesテーブルから削除する。
     *
     * カテゴリ削除はWordPress側の記事との関係など、
     * 影響範囲が大きくなる可能性があるため、
     * Repositoryでは1件単位の削除だけを提供する。
     *
     * 複数カテゴリをまとめて削除する処理は、
     * 現時点では提供しない。
     *
     * また、categoriesテーブルの削除と、
     * 関連するcategory_historiesの扱いについては、
     * Migrationで定義された外部キー制約に従う。
     *
     * @param Category $category
     *     削除対象となるCategory Model。
     *
     * @return void
     */
    public function delete(Category $category): void
    {
        // 指定されたカテゴリをcategoriesテーブルから削除する。
        //
        // WordPress側のカテゴリ削除処理はこのRepositoryでは行わない。
        // WordPress REST APIへの通信はWordPressApiClientが担当する。
        $category->delete();
    }
}
