<!DOCTYPE html>
<html lang="ja">

<head>
    {{-- ==========================================================
         基本的なHTML設定
         ----------------------------------------------------------
         UTF-8で日本語を正しく表示する。
         ========================================================== --}}
    <meta charset="UTF-8">

    {{-- ==========================================================
         LaravelのCSRFトークン
         ----------------------------------------------------------
         JavaScriptからLaravelへPOSTリクエストを送信する際に使用する。
         Laravel側で不正なリクエストを防ぐために必要。
         ========================================================== --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- ==========================================================
         ページタイトル
         ========================================================== --}}
    <title>ブログ登録（si-note.com）</title>
</head>

<body>

    {{-- ==========================================================
         ブログ登録画面
         ----------------------------------------------------------
         BlogOSへWordPressブログを登録するための画面。

         ユーザーがブログのURLを入力し、

         1. WordPress REST APIへ接続確認
         2. /wp-jsonからブログ基本情報を取得
         3. 取得結果を画面に表示
         4. 登録処理を実行

         という流れでブログを登録する。
         ========================================================== --}}

    <h1>ブログ登録</h1>

    {{-- ==========================================================
         BlogOSトップページへの戻るリンク
         ========================================================== --}}
    <p>
        <a href="{{ url('/') }}">トップページに戻る</a>
    </p>

    {{-- ==========================================================
         ブログURL入力欄
         ----------------------------------------------------------
         ユーザーが登録したいWordPressブログのURLを入力する。

         入力されたURLを元にWordPressApiClientが

             /wp-json

         へアクセスし、ブログ基本情報を取得する。
         ========================================================== --}}
    <p>
        <label for="site-url">サイトURL</label><br>
        <input
            type="text"
            id="site-url"
            placeholder="https://example.com"
            style="width:400px;"
        >
    </p>

    {{-- ==========================================================
         操作用ボタン
         ----------------------------------------------------------
         「接続確認」
             入力されたURLのWordPress REST APIへ接続し、
             ブログ情報を取得する。

         「登録」
             接続確認によって取得したブログ情報を
             BlogOSのDBへ登録する。

         初期状態では両方とも無効化している。
         JavaScript側で入力・接続確認の状態に応じて
         有効／無効を切り替える。
         ========================================================== --}}
    <p>
        <button type="button" id="check-button" disabled>
            接続確認
        </button>

        <button type="button" id="register-button" disabled>
            登録
        </button>
    </p>

    {{-- ==========================================================
         メッセージ表示領域
         ----------------------------------------------------------
         接続確認結果や登録結果、
         エラー・警告メッセージなどを表示する。
         JavaScriptから内容を書き換える。
         ========================================================== --}}
    <p id="message-area"></p>

    {{-- ==========================================================
         WordPress API取得結果表示テーブル
         ----------------------------------------------------------
         /wp-jsonから取得したブログ基本情報を表示する。

         表示する項目は以下の6項目。

         ・name
         ・description
         ・url
         ・home
         ・gmt_offset
         ・timezone_string

         接続確認を実行するまでは、
         「接続確認を行うとここに表示されます。」
         と表示する。
         ========================================================== --}}
    <table
        style="table-layout:fixed; width:100%; border-collapse:collapse;"
        border="1"
        cellpadding="5"
        cellspacing="0"
    >
        {{-- ======================================================
             各列の幅を定義
             ====================================================== --}}
        <colgroup>
            <col style="width:15%">
            <col style="width:25%">
            <col style="width:20%">
            <col style="width:20%">
            <col style="width:10%">
            <col style="width:10%">
        </colgroup>

        <thead>
            <tr>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                    サイト名
                </th>

                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                    説明
                </th>

                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                    URL
                </th>

                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                    ホームURL
                </th>

                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                    GMTオフセット
                </th>

                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                    タイムゾーン
                </th>
            </tr>
        </thead>

        {{-- ======================================================
             API取得結果を表示する領域
             ------------------------------------------------------
             JavaScriptから内容を書き換える。
             ====================================================== --}}
        <tbody id="result-body">
            <tr>
                <td colspan="6">
                    接続確認を行うとここに表示されます。
                </td>
            </tr>
        </tbody>
    </table>


    {{-- ==========================================================
         差分確認用ポップアップ
         ----------------------------------------------------------
         既に同じhomeのブログがblogsテーブルへ登録されているが、
         APIから取得した情報に差分が存在する場合に表示する。

         例えば、

             現在の値：
                 サイト名 = SI Note

             取得した値：
                 サイト名 = SI Note Blog

         のような場合、
         ユーザーへ更新してよいか確認する。

         「更新する」を選択すると、
         確認済みフラグを付けて登録処理を再実行する。

         「キャンセル」を選択すると、
         DBは更新せずポップアップを閉じる。

         初期状態ではdisplay:noneとして非表示にしている。
         ========================================================== --}}
    <div
        id="diff-modal"
        style="
            display:none;
            position:fixed;
            top:0;
            left:0;
            width:100%;
            height:100%;
            background:rgba(0,0,0,0.5);
        "
    >

        {{-- ======================================================
             ポップアップ本体
             ====================================================== --}}
        <div
            style="
                background:#fff;
                width:600px;
                max-width:90%;
                margin:80px auto;
                padding:20px;
            "
        >

            <h2>登録済みサイトと内容が異なります</h2>

            <p>
                以下の項目に差分があります。更新しますか？
            </p>

            {{-- ==================================================
                 差分表示テーブル
                 --------------------------------------------------
                 以下の3項目を表示する。

                 ・変更された項目
                 ・現在DBに保存されている値
                 ・WordPress APIから取得した新しい値

                 JavaScriptから内容を生成する。
                 ================================================== --}}
            <table
                style="width:100%; border-collapse:collapse;"
                border="1"
                cellpadding="5"
                cellspacing="0"
            >
                <thead>
                    <tr>
                        <th>項目</th>
                        <th>現在の値</th>
                        <th>取得した値</th>
                    </tr>
                </thead>

                <tbody id="diff-body"></tbody>
            </table>

            {{-- ==================================================
                 差分更新操作ボタン
                 ================================================== --}}
            <p style="margin-top:15px;">

                {{-- 差分内容をDBへ反映する --}}
                <button type="button" id="diff-confirm-button">
                    更新する
                </button>

                {{-- 差分更新をキャンセルする --}}
                <button type="button" id="diff-cancel-button">
                    キャンセル
                </button>

            </p>
        </div>
    </div>


    <script>
        /*
         * ==========================================================
         * ブログ登録画面 JavaScript
         * ==========================================================
         *
         * このJavaScriptでは、ブログ登録画面の操作を制御する。
         *
         * 基本的な処理の流れは以下の通り。
         *
         * 1. ブログURLを入力
         * 2. 「接続確認」を押す
         * 3. LaravelへURLを送信
         * 4. LaravelがWordPress REST APIへアクセス
         * 5. /wp-jsonからブログ基本情報を取得
         * 6. 取得結果を画面へ表示
         * 7. 「登録」を押す
         * 8. Laravel側で既存ブログとの重複・差分を確認
         * 9. 新規なら登録
         * 10. 差分があれば確認ポップアップを表示
         * 11. 「更新する」を押した場合のみDBを更新
         *
         * このファイルでは画面操作とHTTP通信を担当し、
         * 実際のDB操作やWordPress APIとの通信処理は
         * Laravel側のController・Repository・Serviceなどが担当する。
         * ==========================================================
         */


        /*
         * ==========================================================
         * HTML要素を取得
         * ==========================================================
         *
         * 画面上の各要素をJavaScriptから操作できるように取得する。
         */

        // ブログURL入力欄
        const urlInput = document.getElementById('site-url');

        // WordPress APIへの接続確認ボタン
        const checkButton = document.getElementById('check-button');

        // ブログ登録ボタン
        const registerButton = document.getElementById('register-button');

        // メッセージ表示領域
        const messageArea = document.getElementById('message-area');

        // API取得結果を表示するテーブルのtbody
        const resultBody = document.getElementById('result-body');


        /*
         * ==========================================================
         * Laravel CSRFトークンを取得
         * ==========================================================
         *
         * JavaScriptからLaravelへPOSTリクエストを送信する際に使用する。
         *
         * Blade側のmetaタグにLaravelが生成したCSRFトークンを
         * 埋め込んでおき、ここで取得している。
         */

        const csrfToken =
            document.querySelector('meta[name="csrf-token"]').content;


        /*
         * ==========================================================
         * 差分確認ポップアップ関連のHTML要素を取得
         * ==========================================================
         */

        // 差分確認ポップアップ全体
        const diffModal = document.getElementById('diff-modal');

        // 差分一覧を表示するtbody
        const diffBody = document.getElementById('diff-body');

        // 「更新する」ボタン
        const diffConfirmButton =
            document.getElementById('diff-confirm-button');

        // 「キャンセル」ボタン
        const diffCancelButton =
            document.getElementById('diff-cancel-button');


        /*
         * ==========================================================
         * 最新のAPI取得結果
         * ==========================================================
         *
         * 接続確認が成功した際に、
         * APIから取得したブログ情報をここへ保持する。
         *
         * その後「登録」ボタンが押された際に、
         * このデータをLaravelへ送信する。
         *
         * 初期状態ではまだAPI取得を行っていないためnull。
         */

        let latestSiteData = null;


        /*
         * ==========================================================
         * 画面上の取得結果を初期状態へ戻す
         * ==========================================================
         *
         * URLが変更された場合など、
         * 以前のAPI取得結果をそのまま登録してしまわないようにする。
         *
         * 同時に、
         *
         * ・保持しているAPI取得結果を破棄
         * ・結果テーブルを初期表示へ戻す
         * ・登録ボタンを無効化
         * ・メッセージを消去
         *
         * を行う。
         */

        function clearResults() {

            // 以前取得したブログ情報を破棄する。
            latestSiteData = null;

            // API取得結果表示を初期状態へ戻す。
            resultBody.innerHTML =
                '<tr>' +
                    '<td colspan="6">' +
                        '接続確認を行うとここに表示されます。' +
                    '</td>' +
                '</tr>';

            // 新しい接続確認を行うまでは登録できないようにする。
            registerButton.disabled = true;

            // 以前のメッセージを消去する。
            messageArea.textContent = '';
        }


        /*
         * ==========================================================
         * 「接続確認」ボタンの有効／無効を切り替える
         * ==========================================================
         *
         * URL入力欄が空の場合は接続確認できないため、
         * ボタンを無効化する。
         *
         * URLが1文字以上入力されている場合は
         * 接続確認ボタンを有効化する。
         */

        function updateCheckButtonState() {

            checkButton.disabled =
                urlInput.value.trim() === '';
        }


        /*
         * ==========================================================
         * URL入力欄の入力イベント
         * ==========================================================
         *
         * ユーザーがURLを入力するたびに実行する。
         *
         * URLが変更された場合、
         * 以前取得したAPI結果は現在入力されているURLとは
         * 別サイトの情報である可能性がある。
         *
         * そのため、入力内容が変更されたら
         * 以前の取得結果をクリアする。
         */

        urlInput.addEventListener('input', () => {

            // URL入力内容に応じて接続確認ボタンを制御する。
            updateCheckButtonState();

            // 以前のAPI取得結果をクリアする。
            clearResults();
        });


        /*
         * ==========================================================
         * URL入力欄からフォーカスが外れたときの処理
         * ==========================================================
         *
         * 入力されたURLの末尾に「/」が付いている場合、
         * 末尾の「/」を削除する。
         *
         * 例えば、
         *
         * https://example.com/
         *
         * を
         *
         * https://example.com
         *
         * に整形する。
         *
         * これにより、Laravel側で
         *
         * https://example.com/wp-json
         *
         * のようにAPI URLを組み立てる際に、
         * 「//」となることを防ぐ。
         */

        urlInput.addEventListener('blur', () => {

            // 入力値の前後にある不要な空白を削除する。
            let value = urlInput.value.trim();

            // URL末尾が「/」の場合は削除する。
            if (value.endsWith('/')) {
                value = value.replace(/\/+$/, '');
            }

            // 整形後のURLを入力欄へ戻す。
            urlInput.value = value;
        });


        /*
         * ==========================================================
         * 「接続確認」ボタン
         * ==========================================================
         *
         * ユーザーが入力したブログURLをLaravelへ送信する。
         *
         * Laravel側では、
         *
         * BlogRegisterController::check()
         *         ↓
         * WordPressApiClient
         *         ↓
         * /wp-json
         *
         * の順でWordPressサイトの情報を取得する。
         */

        checkButton.addEventListener('click', async () => {

            // 接続確認中であることを画面へ表示する。
            messageArea.textContent = '確認中...';

            // 新しい接続確認が終わるまでは登録できないようにする。
            registerButton.disabled = true;


            try {

                /*
                 * ==================================================
                 * Laravelの接続確認処理へPOST
                 * ==================================================
                 *
                 * route()でLaravel側の
                 *
                 * blog-register.check
                 *
                 * ルートURLを取得する。
                 *
                 * URLはJSON形式で送信する。
                 */

                const response = await fetch(
                    '{{ route('blog-register.check') }}',
                    {
                        method: 'POST',

                        headers: {
                            // JSON形式で送信することをLaravelへ伝える。
                            'Content-Type': 'application/json',

                            // CSRF対策用トークン。
                            'X-CSRF-TOKEN': csrfToken,
                        },

                        // 入力されたURLをLaravelへ送信する。
                        body: JSON.stringify({
                            url: urlInput.value.trim()
                        }),
                    }
                );


                /*
                 * ==================================================
                 * Laravelから返されたJSONを取得
                 * ==================================================
                 */

                const result = await response.json();


                /*
                 * ==================================================
                 * 接続確認成功
                 * ==================================================
                 *
                 * Laravel側でWordPress APIからブログ情報を
                 * 正常に取得できた場合。
                 */

                if (result.success) {

                    /*
                     * APIから取得したブログ情報を保持する。
                     *
                     * 後で「登録」ボタンが押されたとき、
                     * このデータをLaravelへ送信する。
                     */
                    latestSiteData = result.data;


                    // 接続確認成功なのでメッセージを消去する。
                    messageArea.textContent = '';


                    /*
                     * ==================================================
                     * API取得結果を画面へ表示
                     * ==================================================
                     *
                     * /wp-jsonから取得した以下の情報を表示する。
                     *
                     * ・name
                     * ・description
                     * ・url
                     * ・home
                     * ・gmt_offset
                     * ・timezone_string
                     */

                    resultBody.innerHTML = `
                        <tr>
                            <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">
                                ${result.data.name}
                            </td>

                            <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">
                                ${result.data.description}
                            </td>

                            <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">
                                ${result.data.url}
                            </td>

                            <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">
                                ${result.data.home}
                            </td>

                            <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">
                                ${result.data.gmt_offset}
                            </td>

                            <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">
                                ${result.data.timezone_string}
                            </td>
                        </tr>
                    `;


                    /*
                     * API取得が成功したため、
                     * 「登録」ボタンを有効化する。
                     */
                    registerButton.disabled = false;


                } else {

                    /*
                     * ==================================================
                     * 接続確認失敗
                     * ==================================================
                     *
                     * Laravel側からsuccess=falseが返された場合。
                     *
                     * 例えば、
                     *
                     * ・WordPress APIへ接続できない
                     * ・/wp-jsonへアクセスできない
                     * ・必須項目が取得できない
                     *
                     * などが考えられる。
                     */

                    // 以前の取得結果をクリアする。
                    clearResults();

                    // Laravelから返されたエラーメッセージを表示する。
                    messageArea.textContent = result.message;
                }


            } catch (err) {

                /*
                 * ==================================================
                 * 通信処理そのものに失敗した場合
                 * ==================================================
                 *
                 * fetchやJSON解析など、
                 * Laravelから正常なレスポンスを取得できなかった場合。
                 */

                // 以前の取得結果をクリアする。
                clearResults();

                // 画面へエラーメッセージを表示する。
                messageArea.textContent =
                    '接続確認処理中にエラーが発生しました。';
            }
        });


        /*
         * ==========================================================
         * ブログ登録処理
         * ==========================================================
         *
         * 「登録」ボタン、または差分確認ポップアップの
         * 「更新する」ボタンから呼び出される共通処理。
         *
         * confirmedの値によって、
         *
         * false
         *     → 通常の登録確認
         *
         * true
         *     → ユーザーが差分更新を確認済み
         *
         * をLaravel側へ伝える。
         */

        async function submitRegister(confirmed) {

            /*
             * API取得結果が存在しない場合は、
             * 登録処理を実行しない。
             */
            if (!latestSiteData) {
                return;
            }


            /*
             * API取得結果にconfirmedを追加して
             * Laravelへ送信するデータを作成する。
             *
             * latestSiteData自体は変更せず、
             * 新しいオブジェクトを作成する。
             */
            const payload = Object.assign(
                {},
                latestSiteData,
                {
                    confirmed: confirmed
                }
            );


            /*
             * Laravelのブログ登録処理へPOSTする。
             */
            const response = await fetch(
                '{{ route('blog-register.store') }}',
                {
                    method: 'POST',

                    headers: {
                        // JSON形式で送信する。
                        'Content-Type': 'application/json',

                        // CSRF対策用トークン。
                        'X-CSRF-TOKEN': csrfToken,
                    },

                    // ブログ情報とconfirmedを送信する。
                    body: JSON.stringify(payload),
                }
            );


            /*
             * Laravelから返されたJSON結果を呼び出し元へ返す。
             */
            return await response.json();
        }


        /*
         * ==========================================================
         * 「登録」ボタン押下時の処理
         * ==========================================================
         *
         * ここではまずconfirmed=falseで登録処理を実行する。
         *
         * Laravel側では、
         *
         * ① 新規ブログ
         *     → そのまま登録
         *
         * ② 既に登録済みかつ内容が完全一致
         *     → 登録済みとして終了
         *
         * ③ 既に登録済みだが内容に差分あり
         *     → 差分情報を返す
         *
         * のように判定する。
         */

        registerButton.addEventListener('click', async () => {

            try {

                /*
                 * まずは「未確認」の状態で登録処理を実行する。
                 *
                 * 差分が存在した場合、
                 * Laravel側は実際のDB更新を行わず、
                 * 差分情報を返す。
                 */
                const result = await submitRegister(false);


                /*
                 * ==================================================
                 * 新規登録成功
                 * ==================================================
                 */
                if (result.success) {

                    // Laravelから返された登録成功メッセージを表示する。
                    messageArea.textContent = result.message;


                /*
                 * ==================================================
                 * 差分あり
                 * ==================================================
                 *
                 * 既に登録されているブログだが、
                 * APIから取得した情報とDBの内容に差分がある場合。
                 *
                 * この時点ではまだDBを更新しない。
                 *
                 * まずユーザーへ差分を表示し、
                 * 「更新する」を押した場合のみ更新処理を行う。
                 */
                } else if (result.type === 'diff') {

                    /*
                     * Laravelから返された差分情報を
                     * テーブルの行へ変換する。
                     *
                     * result.diffには、
                     *
                     * field
                     * label
                     * old_value
                     * new_value
                     *
                     * が格納されている。
                     */
                    diffBody.innerHTML = result.diff.map(row => `
                        <tr>
                            <td>${row.label}</td>
                            <td>${row.old_value}</td>
                            <td>${row.new_value}</td>
                        </tr>
                    `).join('');


                    /*
                     * 差分確認ポップアップを表示する。
                     */
                    diffModal.style.display = 'block';


                } else {

                    /*
                     * ==================================================
                     * 登録済みなどの場合
                     * ==================================================
                     *
                     * 例えば、
                     *
                     * ・既に登録済み
                     * ・内容が完全一致
                     *
                     * などの場合。
                     */

                    messageArea.textContent = result.message;
                }


            } catch (err) {

                /*
                 * 登録処理中に通信エラーなどが発生した場合。
                 */
                messageArea.textContent =
                    '登録処理中にエラーが発生しました。';
            }
        });


        /*
         * ==========================================================
         * 差分確認ポップアップ「更新する」ボタン
         * ==========================================================
         *
         * ユーザーが差分内容を確認し、
         * 「更新する」を押した場合に実行する。
         *
         * confirmed=trueとしてLaravelへ送信することで、
         * Laravel側ではユーザーが更新を明示的に承認したと判断する。
         */

        diffConfirmButton.addEventListener('click', async () => {

            /*
             * まず差分確認ポップアップを閉じる。
             */
            diffModal.style.display = 'none';


            try {

                /*
                 * confirmed=trueで登録処理を再実行する。
                 *
                 * Laravel側ではこれを確認済みとして扱い、
                 * blogsテーブルの更新と
                 * blog_historiesへの履歴保存を行う。
                 */
                const result = await submitRegister(true);


                /*
                 * 更新結果のメッセージを表示する。
                 *
                 * ?? '' を使用して、
                 * messageが存在しない場合でも
                 * JavaScriptエラーにならないようにする。
                 */
                messageArea.textContent = result.message ?? '';


            } catch (err) {

                /*
                 * 更新処理中に通信エラーなどが発生した場合。
                 */
                messageArea.textContent =
                    '更新処理中にエラーが発生しました。';
            }
        });


        /*
         * ==========================================================
         * 差分確認ポップアップ「キャンセル」ボタン
         * ==========================================================
         *
         * ユーザーが更新を希望しない場合。
         *
         * DBの更新処理は一切行わず、
         * ポップアップだけを閉じる。
         */

        diffCancelButton.addEventListener('click', () => {

            // 差分確認ポップアップを閉じる。
            diffModal.style.display = 'none';

            // キャンセルしたことを画面へ表示する。
            messageArea.textContent =
                '更新をキャンセルしました。';
        });


        /*
         * ==========================================================
         * 初期表示時のボタン状態を設定
         * ==========================================================
         *
         * ページを開いた直後はURL入力欄が空なので、
         * 「接続確認」ボタンを無効化する。
         *
         * URLが入力されれば、
         * inputイベントによって自動的に有効化される。
         */

        updateCheckButtonState();

    </script>

</body>
</html>
