<?php

namespace App\Services\WordPress;

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
     * 現在使用している /wp-json のサイト基本情報取得では、
     * 公開されているWordPressサイトであれば基本的に認証は不要。
     *
     * ただし、将来的に認証が必要なWordPress REST APIを利用する
     * 可能性を考慮し、認証処理をこのクラスに集約している。
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
     * get()などを実行することで行う。
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
         * /wp-jsonは公開情報の取得を目的としているため、
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
}
