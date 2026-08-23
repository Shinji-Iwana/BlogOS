<?php

namespace App\Http\Controllers;

use App\DTO\WordPress\BlogApiData;
use App\Repositories\BlogRepository;
use App\Services\WordPress\WordPressApiClient;
use Illuminate\Http\Request;

/**
 * ブログ登録・更新画面からのリクエストを処理するController。
 *
 * BlogOSでは、管理対象となるWordPressブログを複数登録できるようにする。
 *
 * このControllerでは、画面から入力されたブログURLを基に
 * WordPress REST API（/wp-json）へアクセスし、
 * ブログの基本情報を取得・確認・登録・更新する処理を行う。
 *
 * 主な処理の流れは以下の通り。
 *
 * 1. ブログ登録画面を表示する
 * 2. 画面から入力されたURLを使ってWordPress REST APIへ接続する
 * 3. /wp-jsonからブログ基本情報を取得する
 * 4. BlogOSが必要とする必須項目が取得できているか確認する
 * 5. 取得した情報を画面へ返して確認できるようにする
 * 6. 新規ブログの場合はblogsテーブルへ登録する
 * 7. 既存ブログの場合は現在のDB情報との差分を確認する
 * 8. 差分がある場合はユーザーへ更新確認を求める
 * 9. ユーザーが更新を確認した場合はDBを更新し、変更履歴を保存する
 *
 * API通信そのものはWordPressApiClient、
 * ブログ情報のDB検索・登録・差分判定・更新・履歴保存は
 * BlogRepositoryへ処理を委譲する。
 *
 * そのため、このControllerは各処理を直接実装するのではなく、
 * 画面からの入力を受け取り、適切な処理へつなぐ役割を担う。
 */
class BlogRegisterController extends Controller
{
    /**
     * ブログ情報を操作するRepository。
     *
     * ブログの検索、登録、差分判定、更新、履歴保存などの
     * DB関連処理はBlogRepositoryへ委譲する。
     */
    public function __construct(
        protected BlogRepository $blogRepository
    ) {
    }

    /**
     * ブログ登録画面を表示する。
     *
     * 初期表示ではブログURLを入力する画面を表示する。
     *
     * @return \Illuminate\View\View
     *     ブログ登録画面。
     */
    public function index()
    {
        return view('blog-register');
    }

    /**
     * 入力されたブログURLを使ってWordPress REST APIへ接続し、
     * ブログ基本情報を取得する。
     *
     * この処理は「登録前の確認処理」であり、
     * この段階ではDBへの登録・更新は行わない。
     *
     * 処理の流れ：
     *
     * 1. 入力されたURLをバリデーションする
     * 2. URL末尾の「/」を削除して基準URLを整える
     * 3. WordPressApiClientを生成する
     * 4. /wp-jsonからサイト情報を取得する
     * 5. APIから必要な項目が取得できているか確認する
     * 6. 問題がなければBlogApiDataへ変換する
     * 7. JSON形式で画面へ返す
     *
     * @param Request $request
     *     ブログ登録画面から送信されたリクエスト。
     *
     * @return \Illuminate\Http\JsonResponse
     *     API接続結果と取得したブログ情報。
     */
    public function check(Request $request)
    {
        // ブログURLが入力されていることを確認する。
        //
        // URLの形式そのものについては、
        // WordPressApiClient側で実際にAPIへ接続することで確認する。
        $request->validate([
            'url' => ['required', 'string'],
        ]);

        // 入力されたURLの末尾に「/」がある場合は削除する。
        //
        // 例えば、
        //
        // https://si-note.com/
        //
        // を、
        //
        // https://si-note.com
        //
        // に統一する。
        //
        // これにより、APIのURLを組み立てる際に
        // 「//wp-json」のような二重スラッシュが発生することを防ぐ。
        $baseUrl = rtrim($request->input('url'), '/');

        // 入力されたブログURLを基準URLとして
        // WordPress REST APIクライアントを生成する。
        //
        // ここで指定するURLはWordPressサイトのホームURLを想定している。
        //
        // 例えば、
        //
        // https://si-note.com
        //
        // を指定した場合、WordPressApiClientは
        //
        // https://si-note.com/wp-json
        //
        // へアクセスする。
        $client = new WordPressApiClient($baseUrl);

        // WordPress REST API（/wp-json）から
        // ブログの基本情報を取得する。
        //
        // この時点では、取得した値はWordPress APIの
        // 生レスポンスとして配列で保持されている。
        $rawData = $client->getSiteInfo();

        // APIへ接続できなかった場合、
        // または有効な配列データを取得できなかった場合。
        //
        // この場合はブログ情報を取得できないため、
        // DB登録処理へ進まずエラーを画面へ返す。
        if ($rawData === null || !is_array($rawData)) {
            return response()->json([
                'success' => false,
                'message' => "「{$baseUrl}/wp-json」への接続に失敗しました。URLが正しいか、サイトが公開されているかご確認ください。",
            ]);
        }

        // WordPress REST APIから取得したデータについて、
        // BlogOSが必要とする必須項目がすべて存在するか確認する。
        //
        // API自体へ接続できても、必要な項目が取得できない場合は
        // BlogOSがブログ情報として正常に扱えないため、
        // DBへの登録・更新は行わない。
        //
        // APIの仕様変更などによって項目が取得できなくなった場合も、
        // ここで検出する。
        $missingFields = BlogApiData::findMissingFields($rawData);

        // 必須項目が不足している場合。
        //
        // どの項目が取得できなかったのかを画面へ返し、
        // 管理者が問題を確認できるようにする。
        if (!empty($missingFields)) {
            return response()->json([
                'success' => false,
                'message' => "「{$baseUrl}/wp-json」への接続はできましたが、以下の項目が取得できませんでした：" . implode(', ', $missingFields),
            ]);
        }

        // APIから取得した生データをBlogApiDataへ変換する。
        //
        // BlogApiDataは、WordPress APIの生レスポンスを
        // BlogOS内部で扱いやすいデータ構造として保持するDTO。
        $blogApiData = BlogApiData::fromApiResponse($rawData);

        // 取得したブログ情報をJSON形式で画面へ返す。
        //
        // このcheck処理ではまだDBへの登録・更新は行わない。
        // 画面側で取得内容を確認した後、
        // 必要に応じてstore()が呼び出される。
        return response()->json([
            'success' => true,
            'data'    => $blogApiData->toArray(),
        ]);
    }

    /**
     * 確認済みのブログ情報をDBへ登録または更新する。
     *
     * check()で取得したブログ情報を受け取り、
     * そのブログがblogsテーブルに既に登録されているか確認する。
     *
     * 処理の流れ：
     *
     * 1. 受け取ったブログ情報をバリデーションする
     * 2. BlogApiDataへ変換する
     * 3. homeを使って既存ブログを検索する
     * 4. 未登録なら新規登録する
     * 5. 登録済みなら現在のDB情報との差分を確認する
     * 6. 差分がなければ重複登録として扱う
     * 7. 差分があり、まだ更新確認されていなければ差分を返す
     * 8. 更新確認済みならDB更新＋履歴保存を行う
     *
     * @param Request $request
     *     ブログ登録画面から送信されたブログ情報。
     *
     * @return \Illuminate\Http\JsonResponse
     *     登録・更新結果、または確認が必要な差分情報。
     */
    public function store(Request $request)
    {
        // 画面から送信されたブログ情報をバリデーションする。
        //
        // check()でAPIから取得した値を画面へ返した後、
        // 画面側で確認した値がここへ送信される。
        $validated = $request->validate([
            'name'             => ['required', 'string'],
            'description'      => ['nullable', 'string'],
            'url'              => ['required', 'string'],
            'home'             => ['required', 'string'],
            'gmt_offset'       => ['required', 'string'],
            'timezone_string'  => ['required', 'string'],
            'confirmed'        => ['nullable', 'boolean'],
        ]);

        // バリデーション済みの値からBlogApiDataを生成する。
        //
        // APIから取得したデータと同じ形式でBlogOS内部に保持するため、
        // check()とstore()の間でもBlogApiDataを利用する。
        $blogApiData = new BlogApiData(
            name: $validated['name'],
            description: $validated['description'] ?? '',
            url: $validated['url'],
            home: $validated['home'],
            gmtOffset: $validated['gmt_offset'],
            timezoneString: $validated['timezone_string'],
        );

        // homeを基準に、blogsテーブルへ既に登録されている
        // ブログを検索する。
        //
        // BlogOSではhomeをブログを一意に識別するURLとして扱う。
        //
        // 例えば、
        //
        // https://si-note.com
        //
        // が既に登録されていれば、そのブログの既存レコードが返される。
        $existing = $this->blogRepository->findByHome($blogApiData->home);

        // ==========================================================
        // 新規登録
        // ==========================================================
        //
        // homeが一致する既存ブログが存在しない場合は、
        // BlogOSにまだ登録されていない新しいブログと判断する。
        if ($existing === null) {
            // APIから取得したブログ情報をblogsテーブルへ新規登録する。
            //
            // 「手動更新」は、この登録処理が管理者による
            // ブログ登録画面から実行されたことを表す。
            $this->blogRepository->createFromApiData($blogApiData, '手動更新');

            return response()->json([
                'success' => true,
                'type'    => 'created',
                'message' => '新しいブログとして登録しました。',
            ]);
        }

        // ==========================================================
        // 既存ブログとの差分確認
        // ==========================================================
        //
        // 既に登録されているブログについて、
        // DBに保存されている現在の値と
        // 今回取得したブログ情報を比較する。
        //
        // 差分がある項目だけが$diffに格納される。
        $diff = $this->blogRepository->diff($existing, $blogApiData);

        // ==========================================================
        // 完全一致
        // ==========================================================
        //
        // 既に登録されており、かつすべての項目が現在のDB情報と一致する場合。
        //
        // DBを更新する必要はないため、
        // 「既に登録済み」としてエラー扱いにする。
        if (empty($diff)) {
            return response()->json([
                'success' => false,
                'type'    => 'duplicate',
                'message' => 'このサイトは既に登録済みです（内容も完全に一致しています）。',
            ]);
        }

        // ==========================================================
        // 差分あり・まだ更新確認されていない
        // ==========================================================
        //
        // 既に登録されているブログだが、
        // APIから取得した情報に現在のDB情報との差分が存在する場合。
        //
        // いきなりDBを更新するのではなく、
        // まず変更内容を画面へ返して管理者に確認してもらう。
        //
        // confirmedがtrueでない場合はここで処理を終了し、
        // DBはまだ変更しない。
        if (!$request->boolean('confirmed')) {
            return response()->json([
                'success' => false,
                'type'    => 'diff',
                'message' => '既に登録されているサイトですが、内容に差分があります。更新しますか？',
                'diff'    => $this->formatDiffForResponse($diff),
            ]);
        }

        // ==========================================================
        // 更新確認済み
        // ==========================================================
        //
        // 管理者が更新を確認した場合。
        //
        // blogsテーブルを最新の情報へ更新すると同時に、
        // 変更前の値・変更後の値・変更項目・変更経路を
        // blog_historiesテーブルへ保存する。
        //
        // 「手動更新」は、管理者がブログ登録画面から
        // 更新を承認したことを表す。
        $this->blogRepository->updateWithHistory($existing, $diff, '手動更新');

        return response()->json([
            'success' => true,
            'type'    => 'updated',
            'message' => 'ブログ情報を更新しました。',
        ]);
    }

    /**
     * ブログ情報の差分を画面へ返すための形式に変換する。
     *
     * BlogRepository::diff()が返す差分情報は、
     * DB更新処理などで利用する内部的な形式になっている。
     *
     * そのまま画面へ返すのではなく、
     * 画面表示用に以下の情報へ整理する。
     *
     * field
     *     変更されたDB項目名。
     *
     * label
     *     画面表示用の項目名。
     *
     * old_value
     *     変更前の値。
     *
     * new_value
     *     変更後の値。
     *
     * 例えば、
     *
     * [
     *     'field'     => 'name',
     *     'label'     => 'ブログ名',
     *     'old_value' => '旧ブログ名',
     *     'new_value' => '新ブログ名',
     * ]
     *
     * のような形式へ変換する。
     *
     * @param array $diff
     *     BlogRepository::diff()によって生成された差分情報。
     *
     * @return array
     *     画面表示用に整形した差分情報。
     */
    protected function formatDiffForResponse(array $diff): array
    {
        // 画面へ返す差分情報を格納する配列。
        $formatted = [];

        // 変更された項目を1つずつ処理する。
        foreach ($diff as $field => $values) {
            $formatted[] = [
                // DB上の項目名。
                // 例：name、description、home、timezoneなど。
                'field'     => $field,

                // 画面表示用の項目名。
                //
                // BlogRepository::FIELD_LABELSに定義されている
                // 日本語の項目名を使用する。
                //
                // ラベルが定義されていない場合は、
                // DB上の項目名をそのまま表示する。
                'label'     => BlogRepository::FIELD_LABELS[$field] ?? $field,

                // 変更前の値。
                'old_value' => $values['old'],

                // 変更後の値。
                'new_value' => $values['new'],
            ];
        }

        // 画面表示用に整形した差分情報を返す。
        return $formatted;
    }
}
