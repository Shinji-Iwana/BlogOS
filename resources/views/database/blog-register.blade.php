<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">

    <meta
        name="csrf-token"
        content="{{ csrf_token() }}"
    >

    <title>ブログ登録</title>
</head>

<body>

    <h1>ブログ登録</h1>

    <p>
        BlogOSへWordPressブログを登録します。
    </p>

    <p>
        <label for="site-url">
            サイトURL
        </label>
        <br>

        <input
            type="text"
            id="site-url"
            placeholder="https://example.com"
            style="width:400px;"
        >
    </p>

    <p>
        <button
            type="button"
            id="check-button"
            disabled
        >
            接続確認
        </button>

        <button
            type="button"
            id="register-button"
            disabled
        >
            登録
        </button>
    </p>

    <p id="message-area"></p>

    <table
        style="
            table-layout:fixed;
            width:100%;
            border-collapse:collapse;
        "
        border="1"
        cellpadding="5"
        cellspacing="0"
    >

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
                <th>サイト名</th>
                <th>説明</th>
                <th>URL</th>
                <th>ホームURL</th>
                <th>GMTオフセット</th>
                <th>タイムゾーン</th>
            </tr>
        </thead>

        <tbody id="result-body">
            <tr>
                <td colspan="6">
                    接続確認を行うとここに表示されます。
                </td>
            </tr>
        </tbody>

    </table>


    {{-- ==========================================================
         差分確認ポップアップ
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

        <div
            style="
                background:#fff;
                width:600px;
                max-width:90%;
                margin:80px auto;
                padding:20px;
            "
        >

            <h2>
                登録済みサイトと内容が異なります
            </h2>

            <p>
                以下の項目に差分があります。更新しますか？
            </p>

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

            <p style="margin-top:15px;">

                <button
                    type="button"
                    id="diff-confirm-button"
                >
                    更新する
                </button>

                <button
                    type="button"
                    id="diff-cancel-button"
                >
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
     */

    const urlInput =
        document.getElementById('site-url');

    const checkButton =
        document.getElementById('check-button');

    const registerButton =
        document.getElementById('register-button');

    const messageArea =
        document.getElementById('message-area');

    const resultBody =
        document.getElementById('result-body');

    const csrfToken =
        document.querySelector(
            'meta[name="csrf-token"]'
        ).content;


    const diffModal =
        document.getElementById('diff-modal');

    const diffBody =
        document.getElementById('diff-body');

    const diffConfirmButton =
        document.getElementById(
            'diff-confirm-button'
        );

    const diffCancelButton =
        document.getElementById(
            'diff-cancel-button'
        );


    /*
     * ==========================================================
     * 最新のAPI取得結果
     * ==========================================================
     */

    let latestSiteData = null;


    /*
     * ==========================================================
     * 取得結果をクリア
     * ==========================================================
     */

    function clearResults() {

        latestSiteData = null;

        resultBody.innerHTML =
            '<tr>' +
                '<td colspan="6">' +
                    '接続確認を行うとここに表示されます。' +
                '</td>' +
            '</tr>';

        registerButton.disabled = true;

        messageArea.textContent = '';
    }


    /*
     * ==========================================================
     * 接続確認ボタン状態
     * ==========================================================
     */

    function updateCheckButtonState() {

        checkButton.disabled =
            urlInput.value.trim() === '';
    }


    /*
     * ==========================================================
     * URL入力
     * ==========================================================
     */

    urlInput.addEventListener('input', () => {

        updateCheckButtonState();

        clearResults();
    });


    /*
     * ==========================================================
     * URL整形
     * ==========================================================
     */

    urlInput.addEventListener('blur', () => {

        let value =
            urlInput.value.trim();

        if (value.endsWith('/')) {
            value =
                value.replace(/\/+$/, '');
        }

        urlInput.value = value;
    });


    /*
     * ==========================================================
     * 接続確認
     * ==========================================================
     */

    checkButton.addEventListener(
        'click',
        async () => {

            messageArea.textContent =
                '確認中...';

            registerButton.disabled = true;

            try {

                const response = await fetch(
                    '{{ route('database-blog-register.check') }}',
                    {
                        method: 'POST',

                        headers: {
                            'Content-Type':
                                'application/json',

                            'X-CSRF-TOKEN':
                                csrfToken,
                        },

                        body: JSON.stringify({
                            url:
                                urlInput.value.trim()
                        }),
                    }
                );

                const result =
                    await response.json();

                if (result.success) {

                    latestSiteData =
                        result.data;

                    messageArea.textContent =
                        '';

                    resultBody.innerHTML = `
                        <tr>
                            <td>
                                ${result.data.name}
                            </td>

                            <td>
                                ${result.data.description}
                            </td>

                            <td>
                                ${result.data.url}
                            </td>

                            <td>
                                ${result.data.home}
                            </td>

                            <td>
                                ${result.data.gmt_offset}
                            </td>

                            <td>
                                ${result.data.timezone_string}
                            </td>
                        </tr>
                    `;

                    registerButton.disabled =
                        false;

                } else {

                    clearResults();

                    messageArea.textContent =
                        result.message;
                }

            } catch (err) {

                clearResults();

                messageArea.textContent =
                    '接続確認処理中にエラーが発生しました。';
            }
        }
    );


    /*
     * ==========================================================
     * ブログ登録処理
     * ==========================================================
     */

    async function submitRegister(confirmed) {

        if (!latestSiteData) {
            return;
        }

        const payload =
            Object.assign(
                {},
                latestSiteData,
                {
                    confirmed:
                        confirmed
                }
            );

        const response =
            await fetch(
                '{{ route('database-blog-register.store') }}',
                {
                    method: 'POST',

                    headers: {
                        'Content-Type':
                            'application/json',

                        'X-CSRF-TOKEN':
                            csrfToken,
                    },

                    body:
                        JSON.stringify(payload),
                }
            );

        return await response.json();
    }


    /*
     * ==========================================================
     * 登録ボタン
     * ==========================================================
     */

    registerButton.addEventListener(
        'click',
        async () => {

            try {

                const result =
                    await submitRegister(false);

                /*
                 * 新規登録成功。
                 */
                if (result.success) {

                    /*
                     * BlogRepository側で、
                     *
                     * ・既存ブログのis_selected=false
                     * ・今回登録したブログのis_selected=true
                     *
                     * が実行されている。
                     *
                     * そのため、ここでは親ページへ
                     * 登録完了だけを通知する。
                     */
                    window.parent.postMessage(
                        {
                            type:
                                'blog-register-complete'
                        },
                        window.location.origin
                    );

                    return;
                }


                /*
                 * 差分あり。
                 */
                if (result.type === 'diff') {

                    diffBody.innerHTML =
                        result.diff.map(
                            row => `
                                <tr>
                                    <td>
                                        ${row.label}
                                    </td>

                                    <td>
                                        ${row.old_value}
                                    </td>

                                    <td>
                                        ${row.new_value}
                                    </td>
                                </tr>
                            `
                        ).join('');

                    diffModal.style.display =
                        'block';

                    return;
                }


                /*
                 * その他の結果。
                 */
                messageArea.textContent =
                    result.message;

            } catch (err) {

                messageArea.textContent =
                    '登録処理中にエラーが発生しました。';
            }
        }
    );


    /*
     * ==========================================================
     * 差分更新
     * ==========================================================
     */

    diffConfirmButton.addEventListener(
        'click',
        async () => {

            diffModal.style.display =
                'none';

            try {

                const result =
                    await submitRegister(true);

                /*
                 * 差分更新が成功した場合も、
                 * ブログ登録処理自体は完了している。
                 *
                 * そのため親ページへ通知する。
                 */
                if (result.success) {

                    window.parent.postMessage(
                        {
                            type:
                                'blog-register-complete'
                        },
                        window.location.origin
                    );

                    return;
                }

                messageArea.textContent =
                    result.message ?? '';

            } catch (err) {

                messageArea.textContent =
                    '更新処理中にエラーが発生しました。';
            }
        }
    );


    /*
     * ==========================================================
     * 差分更新キャンセル
     * ==========================================================
     */

    diffCancelButton.addEventListener(
        'click',
        () => {

            diffModal.style.display =
                'none';

            messageArea.textContent =
                '更新をキャンセルしました。';
        }
    );


    /*
     * ==========================================================
     * 初期状態
     * ==========================================================
     */

    updateCheckButtonState();

</script>

</body>
</html>
