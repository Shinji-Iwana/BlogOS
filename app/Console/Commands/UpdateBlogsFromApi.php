<?php

namespace App\Console\Commands;

use App\DTO\WordPress\BlogApiData;
use App\Models\Blog;
use App\Repositories\BlogRepository;
use App\Services\WordPress\WordPressApiClient;
use Illuminate\Console\Command;

/**
 * 登録済みブログの情報をWordPress REST APIから取得し、
 * DBに保存されている情報との差分を確認・更新するArtisanコマンド。
 *
 * このコマンドは、BlogOSが管理している全ブログを対象として、
 * 各ブログのWordPress REST API（/wp-json）からサイト基本情報を取得する。
 *
 * APIから取得した情報と、現在blogsテーブルに保存されている情報を比較し、
 * 変更が存在する場合はblogsテーブルを更新するとともに、
 * blog_historiesテーブルへ変更履歴を保存する。
 *
 * 処理の全体的な流れは以下の通り。
 *
 * 1. blogsテーブルから登録済みブログをすべて取得する
 * 2. ブログごとにWordPress REST APIへアクセスする
 * 3. /wp-jsonからサイト基本情報を取得する
 * 4. BlogOSが必要とする必須項目が揃っているか確認する
 * 5. 現在DBに保存されている情報とAPIから取得した情報を比較する
 * 6. 差分がなければ何も変更せず処理を終了する
 * 7. 差分があればblogsテーブルを更新する
 * 8. 変更内容をblog_historiesテーブルへ履歴として保存する
 *
 * なお、このコマンド自体はAPI通信やDB更新の詳細処理を担当しない。
 * API通信はWordPressApiClient、
 * 差分判定・DB更新・履歴保存はBlogRepositoryへ処理を委譲する。
 */
class UpdateBlogsFromApi extends Command
{
    /**
     * Artisanコマンドの実行名。
     *
     * ターミナルから以下のコマンドで実行する。
     *
     * php artisan blogs:update-from-api
     */
    protected $signature = 'blogs:update-from-api';

    /**
     * Artisanコマンドの説明。
     *
     * 登録済みの全ブログについてWordPress REST APIから
     * サイト情報を取得し、DBとの差分がある場合のみ更新する。
     *
     * 更新した項目については、変更履歴も保存する。
     */
    protected $description = '登録済み全ブログのサイト情報をAPIから取得し、差分があればDBを更新して履歴を残す';

    /**
     * コマンドを実行する。
     *
     * 登録済みの全ブログを順番に処理し、
     * WordPress REST APIから取得した最新情報と
     * DBに保存されている現在の情報を比較する。
     *
     * APIから情報を取得できなかったブログや、
     * 必須項目が不足しているブログはスキップする。
     *
     * 1つのブログでエラーが発生しても、他のブログの処理まで
     * 中断しないよう、ブログ単位で例外を捕捉する。
     *
     * @param BlogRepository $blogRepository
     *     ブログ情報の差分判定、DB更新、履歴保存を担当するRepository。
     *
     * @return int
     *     Artisanコマンドの終了ステータス。
     */
    public function handle(BlogRepository $blogRepository): int
    {
        // blogsテーブルに登録されているブログをすべて取得する。
        //
        // BlogOSは将来的に複数のブログを管理するため、
        // ここでは特定のブログを指定せず、登録済みの全ブログを対象とする。
        $blogs = Blog::all();

        // 登録されているブログを1件ずつ処理する。
        foreach ($blogs as $blog) {
            try {
                // WordPress REST APIへアクセスするためのクライアントを生成する。
                //
                // APIアクセスの基準URLにはblogs.homeを使用する。
                //
                // 例えば、
                //
                // home
                // ↓
                // https://si-note.com
                //
                // WordPressApiClient内部で、
                // https://si-note.com/wp-json
                // へアクセスする。
                //
                // urlではなくhomeを使用するのは、
                // BlogOSではhomeをWordPress REST APIへアクセスする際の
                // 基準となるホームURLとして扱うため。
                $client = new WordPressApiClient($blog->home);

                // WordPress REST API（/wp-json）からブログのサイト基本情報を取得する。
                //
                // 取得したデータは、まだWordPress APIの生レスポンスであり、
                // BlogApiDataへの変換前の配列として保持する。
                $rawData = $client->getSiteInfo();

                // APIから情報を取得できなかった場合、
                // またはBlogOSが必要とする必須項目が不足している場合は、
                // このブログの更新処理を行わずスキップする。
                //
                // 必須項目の確認はBlogApiData::findMissingFields()で行う。
                //
                // API仕様変更などによって必要な項目が取得できなくなった場合も、
                // 不完全なデータでDBを更新しないようにする。
                if ($rawData === null || !empty(BlogApiData::findMissingFields($rawData))) {
                    $this->warn("[スキップ] {$blog->home}：サイト情報を取得できませんでした。");
                    continue;
                }

                // APIから取得した生データをBlogApiDataへ変換する。
                //
                // BlogApiDataは、WordPress APIの生レスポンスを
                // BlogOS内部で扱いやすいデータ構造として保持するDTO。
                $apiData = BlogApiData::fromApiResponse($rawData);

                // DBに現在保存されているブログ情報と、
                // APIから取得した最新のブログ情報を比較する。
                //
                // 変更された項目のみがdiffに格納される。
                //
                // 例えばnameだけが変更されていた場合、
                // nameの変更情報だけがdiffに入る。
                $diff = $blogRepository->diff($blog, $apiData);

                // APIから取得した情報とDBの情報に差分がない場合。
                //
                // DBを更新する必要がないため、
                // 「変更なし」と表示してこのブログの処理を終了する。
                if (empty($diff)) {
                    $this->info("[変更なし] {$blog->home}");
                    continue;
                }

                // 差分が存在する場合は、
                // blogsテーブルを最新のAPI情報へ更新する。
                //
                // 同時に、変更前の値・変更後の値・変更項目・
                // 変更経路などをblog_historiesテーブルへ保存する。
                //
                // 第3引数の「定期自動更新」は、
                // 今回の変更がBlogOSによる自動更新処理によって
                // 発生したことを履歴として記録するための値。
                $blogRepository->updateWithHistory($blog, $diff, '定期自動更新');

                // 更新された項目名をコンソールへ表示する。
                //
                // 例えばnameとtimezoneが変更された場合、
                //
                // [更新] https://si-note.com：name, timezone
                //
                // のように表示される。
                $this->info("[更新] {$blog->home}：" . implode(', ', array_keys($diff)));
            } catch (\Throwable $e) {
                // このブログの処理中に予期しないエラーが発生した場合。
                //
                // ブログ単位で例外を捕捉しているため、
                // 1つのブログでエラーが発生しても、
                // foreachの次のブログの処理は継続する。
                //
                // エラー内容はコンソールへ表示し、
                // 後からどのブログで問題が発生したのか
                // 確認できるようにする。
                $this->error("[エラー] {$blog->home}：{$e->getMessage()}");
            }
        }

        // すべてのブログの処理が完了したことを示して、
        // Artisanコマンドを正常終了する。
        return self::SUCCESS;
    }
}
