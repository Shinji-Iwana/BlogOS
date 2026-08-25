<?php

namespace Database\Seeders;

use App\Models\Blog;
use App\Repositories\CategoryRepository;
use App\Services\WordPress\WordPressApiClient;
use Illuminate\Database\Seeder;

/**
 * WordPressからカテゴリ情報を取得し、
 * categoriesテーブルへ初期投入するSeeder。
 *
 * BlogOSでは、WordPressを正としたカテゴリ情報の管理を行う。
 *
 * 初期投入時には、BlogOSに登録されている各ブログについて
 * WordPress REST APIからカテゴリ情報を全件取得し、
 * categoriesテーブルへ保存する。
 *
 * 主な処理の流れは以下の通り。
 *
 * 1. blogsテーブルから登録済みブログを取得する
 * 2. 各ブログについてcategoriesテーブルへの登録状況を確認する
 * 3. 既にカテゴリが登録されているブログは処理をスキップする
 * 4. 未登録のブログについてWordPress REST APIへ接続する
 * 5. /wp-json/wp/v2/categoriesからカテゴリ情報を全件取得する
 * 6. 取得したカテゴリをcategoriesテーブルへ登録する
 * 7. 初回投入されたカテゴリについてcategory_historiesへ履歴を保存する
 *
 * API通信そのものはWordPressApiClient、
 * categoriesテーブルへの登録や履歴保存などのDB処理は
 * CategoryRepositoryへ処理を委譲する。
 *
 * このSeederでは、WordPress側のカテゴリ情報を
 * BlogOS側へ初めて取り込むことを目的とする。
 *
 * そのため、既にcategoriesテーブルにカテゴリが登録されているブログについては、
 * 既存データを上書きせず、そのブログの処理をスキップする。
 *
 * これにより、Seederを誤って複数回実行した場合でも、
 * 既存カテゴリの重複登録や不要な履歴作成が発生することを防ぐ。
 */
class CategorySeeder extends Seeder
{
    /**
     * カテゴリ情報を操作するRepository。
     *
     * categoriesテーブルへのカテゴリ登録や、
     * category_historiesテーブルへの履歴保存などの
     * DB関連処理はCategoryRepositoryへ委譲する。
     *
     * Seederから直接Category Modelなどを操作するのではなく、
     * Repositoryを経由することで、
     * カテゴリ情報のDB操作をSeederから分離する。
     */
    public function __construct(
        protected CategoryRepository $categoryRepository
    ) {
    }

    /**
     * WordPressからカテゴリ情報を取得し、
     * BlogOSへ初期投入する。
     *
     * BlogOSに登録されているすべてのブログを対象として処理する。
     *
     * ただし、既にcategoriesテーブルへカテゴリが登録されているブログについては、
     * 初期投入済みと判断して処理をスキップする。
     *
     * 処理の流れ：
     *
     * 1. blogsテーブルから登録済みブログを取得する
     * 2. ブログを1件ずつ処理する
     * 3. そのブログにカテゴリが既に登録されているか確認する
     * 4. 登録済みの場合は処理をスキップする
     * 5. 未登録の場合はWordPressApiClientを生成する
     * 6. WordPress REST APIからカテゴリを全件取得する
     * 7. 取得したカテゴリをCategoryRepositoryへ渡して登録する
     * 8. 初回投入としてcategory_historiesへ履歴を保存する
     *
     * @return void
     */
    public function run(): void
    {
        // blogsテーブルに登録されているブログをすべて取得する。
        //
        // BlogOSでは複数のWordPressブログを管理するため、
        // すべてのブログを順番に処理する。
        $blogs = Blog::all();

        // BlogOSに登録されているブログを1件ずつ処理する。
        foreach ($blogs as $blog) {
            // ==========================================================
            // 既にカテゴリが登録されているか確認
            // ==========================================================

            // 対象ブログについて、
            // categoriesテーブルに既にカテゴリが登録されているか確認する。
            //
            // 初回投入済みのブログについては、
            // WordPressから再取得して既存データを上書きする必要はない。
            //
            // また、Seederを誤って複数回実行した場合でも、
            // カテゴリの重複登録や不要な履歴作成を防止する。
            if ($this->categoryRepository->getAll($blog->id)->isNotEmpty()) {
                // 既にカテゴリが登録されているため、
                // このブログについては処理を行わず、
                // 次のブログの処理へ進む。
                $this->command?->info(
                    "ブログID {$blog->id} は既にカテゴリ登録済みのため、スキップします。"
                );

                continue;
            }

            // ==========================================================
            // WordPress REST APIへ接続
            // ==========================================================

            // 対象ブログのURLを基準に、
            // WordPress REST APIクライアントを生成する。
            //
            // BlogOSでは複数ブログを管理するため、
            // 特定のWordPressサイトURLを固定値として使用せず、
            // blogsテーブルから取得したhomeを使用する。
            $client = new WordPressApiClient($blog->home);

            // ==========================================================
            // WordPressからカテゴリを全件取得
            // ==========================================================

            // WordPress REST API
            //
            // /wp-json/wp/v2/categories
            //
            // からカテゴリ情報を全件取得する。
            //
            // 今回は初期投入処理のため、
            // 1件だけ取得するのではなく、
            // WordPress側に存在するカテゴリをすべて取得する。
            $categories = $client->getCategories();

            // APIからカテゴリ情報を取得できなかった場合。
            //
            // WordPress側へ接続できなかった場合や、
            // APIから正常なレスポンスを取得できなかった場合は、
            // DBへの登録処理を行わない。
            //
            // 1つのブログで問題が発生しても、
            // 他のブログの初期投入処理は継続する。
            if ($categories === null) {
                $this->command?->warn(
                    "ブログID {$blog->id} のカテゴリ情報をWordPressから取得できなかったため、スキップします。"
                );

                continue;
            }

            // ==========================================================
            // カテゴリ情報をDBへ初期投入
            // ==========================================================

            // WordPressから取得したカテゴリ情報を、
            // CategoryRepositoryへ渡してcategoriesテーブルへ登録する。
            //
            // 第2引数には、今回の登録経路を指定する。
            //
            // 「初回投入」は、
            // SeederによってWordPressから初めてカテゴリ情報を
            // BlogOSへ取り込んだことを表す。
            //
            // CategoryRepository側では、この値をcategory_historiesの
            // sourceへ保存する。
            $this->categoryRepository->createManyFromApiData(
                $blog->id,
                $categories,
                '初回投入'
            );

            // このブログについて初期投入処理が完了したことを表示する。
            $this->command?->info(
                "ブログID {$blog->id} のカテゴリを初回投入しました。"
            );
        }
    }
}
