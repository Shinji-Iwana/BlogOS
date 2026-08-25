<?php

namespace App\Services\WordPress;

use App\DTO\WordPress\CategoryApiData;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;

/**
 * WordPress REST APIとの通信を担当するクラス。
 *
 * BlogOSからWordPressサイトへHTTPリクエストを送信し、
 * APIから取得したデータをBlogOS側へ返す役割を持つ。
 *
 * このクラスでは「APIへ接続する処理」を担当し、
 * 取得したデータの内容をどう解釈するか、どうDBへ保存するか
 * といった処理は担当しない。
 *
 * そのため、主な役割は以下のとおり。
 *
 * 1. 接続先となるWordPressサイトURLを受け取る
 * 2. 必要に応じて認証情報を付加する
 * 3. WordPress REST APIへHTTPリクエストを送信する
 * 4. APIのレスポンスをBlogOS側で扱える配列として返す
 *
 * ブログ基本情報、カテゴリなど、WordPress REST APIを利用する
 * 各種処理のHTTP通信をこのクラスへ集約する。
 */
class WordPressApiClient
{
    /**
     * WordPressサイトのベースURL。
     *
     * 例：
     * https://si-note.com
     *
     * このURLを元に、
     *
     * https://si-note.com/wp-json
     * https://si-note.com/wp-json/wp/v2/categories
     *
     * のようなAPIエンドポイントを組み立てる。
     *
     * BlogOSは将来的に複数ブログを管理するため、
     * 特定のブログURLを固定値として持たず、
     * 呼び出し元からURLを受け取る設計としている。
     */
    protected string $rawBase;

    /**
     * WordPressApiClientを生成する。
     *
     * @param string $baseUrl
     *        接続対象となるWordPressサイトのベースURL。
     *
     *        ブログ登録時：
     *        BlogRegisterControllerから画面入力されたURLを受け取る。
     *
     *        登録済みブログの更新時：
     *        UpdateBlogsFromApiからblogs.homeを受け取る。
     *
     *        カテゴリ処理時：
     *        対象となるブログのURLを呼び出し元から受け取る。
     */
    public function __construct(string $baseUrl)
    {
        /*
         * URL末尾に「/」が付いている場合に備えて、
         * 末尾のスラッシュを削除する。
         *
         * 例えば、
         *
         * https://si-note.com/
         *
         * を
         *
         * https://si-note.com
         *
         * に統一する。
         *
         * これにより、後で「/wp-json」を追加した際に
         *
         * https://si-note.com//wp-json
         *
         * のような二重スラッシュになることを防ぐ。
         */
        $this->rawBase = rtrim($baseUrl, '/');
    }

    /**
     * WordPress APIへ認証情報を付加する必要があるか判定する。
     *
     * BlogOSでは、WordPress REST APIへの接続について、
     * 認証情報が設定されている場合のみBasic認証を使用する。
     *
     * config/services.phpの以下の設定を確認する。
     *
     * - services.wp.username
     * - services.wp.app_password
     *
     * 両方が設定されている場合：
     *     → Basic認証を使用する。
     *
     * どちらか一方でも設定されていない場合：
     *     → 認証なしでAPIへアクセスする。
     *
     * 現在使用している /wp-json のサイト基本情報取得や
     * カテゴリ情報取得では、公開されているWordPressサイトであれば
     * 基本的に認証は不要。
     *
     * ただし、カテゴリの登録・削除など、
     * WordPress側のデータを変更するAPIを利用する場合は
     * 認証が必要となる。
     *
     * 認証処理はこのクラスに集約する。
     */
    public function hasAuth(): bool
    {
        return filled(config('services.wp.username'))
            && filled(config('services.wp.app_password'));
    }

    /**
     * WordPress APIへリクエストを送信するためのHTTPクライアントを生成する。
     *
     * 認証情報が設定されている場合はBasic認証を付加する。
     *
     * 認証情報が設定されていない場合は、
     * 認証なしのHTTPリクエストを生成する。
     *
     * 実際のHTTP通信は、このメソッドで生成したPendingRequestに対して
     * get()、post()、delete()などを実行することで行う。
     */
    protected function request(): PendingRequest
    {
        /*
         * WordPress API用のユーザー名とアプリケーションパスワードが
         * 設定されている場合は、Basic認証を付加する。
         *
         * config()を使用することで、
         * .envに直接アクセスせずLaravelの設定経由で値を取得する。
         */
        if ($this->hasAuth()) {
            return Http::withBasicAuth(
                config('services.wp.username'),
                config('services.wp.app_password')
            );
        }

        /*
         * 認証情報が設定されていない場合は、
         * 認証なしのHTTPクライアントを返す。
         *
         * /wp-jsonなどの公開情報取得では、
         * 現時点では通常こちらが使用される。
         */
        return Http::withOptions([]);
    }

    /**
     * WordPress REST APIの /wp-json からサイト基本情報を取得する。
     *
     * WordPressの /wp-json は、サイトの基本情報や
     * 利用可能なREST APIの情報などを返すエンドポイント。
     *
     * BlogOSでは、この中から以下のサイト基本情報を取得する。
     *
     * - name
     * - description
     * - url
     * - home
     * - gmt_offset
     * - timezone_string
     *
     * 取得した生のAPIレスポンスを配列として返し、
     * APIデータの必須項目チェックやDTOへの変換、
     * DBへの保存処理などは呼び出し側で行う。
     *
     * APIへの接続に失敗した場合や、
     * HTTPステータスが成功ではない場合はnullを返す。
     *
     * @return array|null
     *         API取得成功時：/wp-jsonのレスポンスを配列で返す。
     *         API取得失敗時：nullを返す。
     */
    public function getSiteInfo(): ?array
    {
        /*
         * WordPress REST APIのサイト基本情報エンドポイントへアクセスする。
         *
         * $this->rawBaseには、
         *
         * https://si-note.com
         *
         * のようなサイトURLが入っているため、
         *
         * {$this->rawBase}/wp-json
         *
         * とすることで、
         *
         * https://si-note.com/wp-json
         *
         * へアクセスする。
         *
         * timeout(10)により、APIから10秒以上応答がない場合は
         * タイムアウトとして処理する。
         *
         * これにより、接続先サイトに問題が発生している場合でも、
         * BlogOS側の処理が長時間停止し続けることを防ぐ。
         */
        $response = $this->request()
            ->timeout(10)
            ->get("{$this->rawBase}/wp-json");

        /*
         * HTTPステータスが成功（2xx）の場合のみ、
         * APIレスポンスのJSONを配列として返す。
         *
         * APIへの接続自体はできても、
         * 404、403、500などのエラーが返された場合は
         * nullを返して呼び出し側でエラーとして扱う。
         */
        return $response->successful()
            ? $response->json()
            : null;
    }

    /**
     * WordPress REST APIからカテゴリ情報を全件取得する。
     *
     * 使用するエンドポイントは、
     *
     * /wp-json/wp/v2/categories
     *
     * である。
     *
     * WordPress REST APIのカテゴリ取得APIは、
     * 1回のリクエストで取得できる件数に上限があるため、
     * APIレスポンスに含まれるページ情報を確認しながら
     * 複数ページに分けて取得する。
     *
     * BlogOSでは、WordPress側に存在するカテゴリ全件と
     * BlogOSのcategoriesテーブルに保存されているカテゴリ全件を
     * 比較する処理を予定している。
     *
     * そのため、このメソッドでは1件だけを取得するのではなく、
     * 対象ブログに存在するカテゴリを全件取得する。
     *
     * APIから取得した生データについては、
     * CategoryApiData::fromApiResponse()を使用して
     * BlogOS内部で扱うCategoryApiDataへ変換する。
     *
     * 必須項目が不足しているなどの理由で
     * CategoryApiDataを生成できないカテゴリについては、
     * 不完全なデータとして登録対象から除外する。
     *
     * APIへの接続に失敗した場合や、
     * HTTPステータスが成功ではない場合はnullを返す。
     *
     * @return array<CategoryApiData>|null
     *         API取得成功時：
     *         CategoryApiDataへ変換されたカテゴリ情報を全件含む配列を返す。
     *
     *         API取得失敗時：
     *         nullを返す。
     */
    public function getCategories(): ?array
    {
        /*
         * CategoryApiDataへ変換したカテゴリ情報を格納する配列。
         *
         * 複数ページに分かれて返されるカテゴリ情報を、
         * この配列へまとめる。
         */
        $categories = [];

        /*
         * WordPress REST APIから取得するページ番号。
         *
         * WordPress REST APIのページ番号は1から始まる。
         */
        $page = 1;

        /*
         * WordPress REST APIからカテゴリ情報を取得する。
         *
         * APIレスポンスの
         *
         * X-WP-Total
         *
         * に総件数が、
         *
         * X-WP-TotalPages
         *
         * に総ページ数が設定されるため、
         * それを利用して全ページを取得する。
         */
        do {
            /*
             * カテゴリ一覧取得APIへアクセスする。
             *
             * per_page=100とすることで、
             * 1回のAPIリクエストで最大100件取得する。
             *
             * pageには現在取得するページ番号を指定する。
             *
             * timeout(10)により、APIから10秒以上応答がない場合は
             * タイムアウトとして処理する。
             */
            $response = $this->request()
                ->timeout(10)
                ->get("{$this->rawBase}/wp-json/wp/v2/categories", [
                    'per_page' => 100,
                    'page' => $page,
                ]);

            /*
             * APIへの接続自体はできても、
             * 404、403、500などのエラーが返された場合は
             * カテゴリ取得処理を失敗として終了する。
             *
             * 途中まで取得したカテゴリだけを返すと、
             * 「全件取得できた」と誤認する可能性があるため、
             * nullを返す。
             */
            if (!$response->successful()) {
                return null;
            }

            /*
             * 現在のページから取得したカテゴリ情報を、
             * APIレスポンスのJSONから配列として取得する。
             */
            $pageCategories = $response->json();

            /*
             * APIレスポンスが配列ではない場合は、
             * 正常なカテゴリ一覧として扱えないため
             * カテゴリ取得処理を失敗として終了する。
             */
            if (!is_array($pageCategories)) {
                return null;
            }

            /*
             * 現在のページから取得したカテゴリを1件ずつ処理する。
             */
            foreach ($pageCategories as $pageCategory) {
                /*
                 * APIレスポンスの1件分のデータが配列でない場合は、
                 * 正常なカテゴリ情報として扱えないため、
                 * そのカテゴリを登録対象から除外する。
                 */
                if (!is_array($pageCategory)) {
                    continue;
                }

                /*
                 * WordPress APIから取得した生データを、
                 * CategoryApiDataへ変換する。
                 *
                 * CategoryApiDataでは、BlogOSが必要とする
                 * 必須項目が存在するか確認したうえで、
                 * DTOを生成する。
                 */
                $category = CategoryApiData::fromApiResponse($pageCategory);

                /*
                 * 必須項目が不足しているなどの理由で
                 * CategoryApiDataを生成できなかった場合は、
                 * 不完全なカテゴリ情報として登録対象から除外する。
                 *
                 * 1件のカテゴリに問題があっても、
                 * 他の正常なカテゴリについては処理を継続する。
                 */
                if ($category === null) {
                    continue;
                }

                /*
                 * CategoryApiDataへ変換できたカテゴリを、
                 * 全カテゴリを格納する配列へ追加する。
                 */
                $categories[] = $category;
            }

            /*
             * WordPress REST APIのレスポンスヘッダーから
             * 総ページ数を取得する。
             *
             * X-WP-TotalPagesが取得できない場合は、
             * 現在のページを最後のページとして扱う。
             */
            $totalPages = (int) $response->header('X-WP-TotalPages', $page);

            /*
             * 次のページを取得するため、
             * ページ番号を1つ増やす。
             */
            $page++;
        } while ($page <= $totalPages);

        /*
         * WordPressから取得し、
         * CategoryApiDataへ変換したカテゴリ情報を全件返す。
         *
         * ここではDBへの保存は行わない。
         */
        return $categories;
    }

    /**
     * WordPress REST APIへカテゴリを1件登録する。
     *
     * 使用するエンドポイントは、
     *
     * /wp-json/wp/v2/categories
     *
     * である。
     *
     * カテゴリ登録にはWordPress側での認証が必要となる。
     *
     * 登録するカテゴリ情報を配列で受け取り、
     * WordPress APIへPOSTリクエストを送信する。
     *
     * BlogOSでは、WordPressへの登録が成功した後、
     * APIから登録結果を取得してBlogOS側のcategoriesテーブルへ
     * 保存することを想定している。
     *
     * そのため、このメソッドではDBへの保存処理は行わない。
     *
     * @param array $data
     *        WordPress REST APIへ登録するカテゴリ情報。
     *
     *        主に以下の項目を指定する。
     *
     *        name
     *            カテゴリ名。
     *
     *        slug
     *            カテゴリのスラッグ。
     *
     *        parent
     *            親カテゴリのWordPress側ID。
     *
     * @return array|null
     *         登録成功時：WordPress APIが返したカテゴリ情報。
     *         登録失敗時：nullを返す。
     */
    public function createCategory(array $data): ?array
    {
        /*
         * WordPress REST APIのカテゴリ登録エンドポイントへ
         * POSTリクエストを送信する。
         *
         * JSON形式でカテゴリ情報を送信する。
         *
         * timeout(10)により、APIから10秒以上応答がない場合は
         * タイムアウトとして処理する。
         */
        $response = $this->request()
            ->timeout(10)
            ->post("{$this->rawBase}/wp-json/wp/v2/categories", $data);

        /*
         * HTTPステータスが成功（2xx）の場合のみ、
         * WordPressが返した登録結果を配列として返す。
         *
         * 400、401、403、500などのエラーが返された場合は
         * nullを返して呼び出し側でエラーとして扱う。
         */
        return $response->successful()
            ? $response->json()
            : null;
    }

    /**
     * WordPress REST APIへカテゴリを複数件登録する。
     *
     * WordPress REST APIのカテゴリ登録APIには、
     * 複数カテゴリを1回のリクエストで登録する専用エンドポイントは
     * 現在のBlogOSでは使用しない。
     *
     * そのため、1件登録用のcreateCategory()を
     * 1件ずつ呼び出して登録する。
     *
     * 初期投入など、複数カテゴリをまとめて処理する場合に使用する。
     *
     * @param array $categories
     *        登録するカテゴリ情報の配列。
     *
     *        各要素にはcreateCategory()へ渡す
     *        カテゴリ情報の配列を指定する。
     *
     * @return array
     *         WordPressへの登録に成功したカテゴリ情報の一覧。
     *
     *         1件でも登録に失敗した場合は、
     *         それまでに成功したカテゴリ情報を返すのではなく、
     *         空配列を返す。
     */
    public function createCategories(array $categories): array
    {
        /*
         * WordPressへの登録に成功したカテゴリ情報を格納する配列。
         */
        $createdCategories = [];

        /*
         * 登録対象となるカテゴリを1件ずつ処理する。
         */
        foreach ($categories as $category) {
            /*
             * 1件登録用メソッドを利用して、
             * WordPressへカテゴリを登録する。
             */
            $createdCategory = $this->createCategory($category);

            /*
             * 1件でも登録に失敗した場合は、
             * 複数件登録処理全体を失敗として扱う。
             *
             * 途中まで登録されたカテゴリについては、
             * WordPress側にすでに登録されているため、
             * 自動的に削除する処理は行わない。
             *
             * BlogOS側のDB保存についても、
             * 呼び出し側で成功した登録結果を確認してから行う。
             */
            if ($createdCategory === null) {
                return [];
            }

            /*
             * WordPressから返された登録結果を、
             * 成功したカテゴリ一覧へ追加する。
             */
            $createdCategories[] = $createdCategory;
        }

        /*
         * WordPressへの登録に成功したカテゴリ情報を
         * 一覧として返す。
         */
        return $createdCategories;
    }

    /**
     * WordPress REST APIからカテゴリを1件削除する。
     *
     * 使用するエンドポイントは、
     *
     * /wp-json/wp/v2/categories/{id}
     *
     * である。
     *
     * カテゴリ削除にはWordPress側での認証が必要となる。
     *
     * BlogOSではカテゴリ削除が記事にも影響する可能性があるため、
     * 現時点では複数カテゴリをまとめて削除する処理は用意せず、
     * 1件ずつ削除する処理のみを提供する。
     *
     * @param int $categoryId
     *        WordPress側で管理されているカテゴリID。
     *
     * @return array|null
     *         削除成功時：WordPress APIが返した削除済みカテゴリ情報。
     *         削除失敗時：nullを返す。
     */
    public function deleteCategory(int $categoryId): ?array
    {
        /*
         * WordPress REST APIのカテゴリ削除エンドポイントへ
         * DELETEリクエストを送信する。
         *
         * force=trueを指定することで、
         * WordPress側でカテゴリを完全に削除する。
         *
         * timeout(10)により、APIから10秒以上応答がない場合は
         * タイムアウトとして処理する。
         */
        $response = $this->request()
            ->timeout(10)
            ->delete(
                "{$this->rawBase}/wp-json/wp/v2/categories/{$categoryId}",
                [
                    'force' => true,
                ]
            );

        /*
         * HTTPステータスが成功（2xx）の場合のみ、
         * WordPress APIが返した削除結果を配列として返す。
         *
         * 400、401、403、404、500などのエラーが返された場合は
         * nullを返して呼び出し側でエラーとして扱う。
         */
        return $response->successful()
            ? $response->json()
            : null;
    }
}
