{{--
    認証情報のポップアップ（選択中のブログ。D-63-22。以前は画面「認証情報」（blogs.credentials.edit）。D-03-02、D-03-03）

    メニューの「設定 → ブログ → 認証情報」を押した場合に表示する（data-modal-open="blog-credential-modal"。layouts/header から読み込む）。
    登録済みの Application Password は表示しない（D-03-03）。変更は上書きだけ。
    更新・接続確認の後は、開いていた画面に戻り、このポップアップを開いて結果を出す（session の credential_modal）。
    入力の誤りで戻ったときも、ポップアップを開いたままにして誤りを出す（_form。Application Password は入れ直す）。
--}}

@php
    $credentialFailed = old('_form') === 'blog-credential';
    $credentialOpen = $credentialFailed || session('credential_modal');
    $blogCredential = $selectedBlog ? app(\App\Repositories\BlogCredentialRepository::class)->findForBlog($selectedBlog->id) : null;
@endphp

<div
    id="blog-credential-modal"
    class="blog-switch-modal site-modal blog-credential-modal"
    @if ($credentialOpen) data-modal-autoopen @endif
>

    <div
        class="blog-switch-dialog"
    >

        <h2>
            認証情報
            @include('partials.tip', ['tip' => "選択中のブログの、WordPress の Application Password です。\n登録済みの Application Password は表示しません。変えるときは、上書きします（接続を確認してから保存します）。"])
        </h2>

        @if ($credentialOpen)
            @include('partials.flash')
        @endif

        @if (! $selectedBlog)
            <p>ブログを選んでください（画面上部のブログ切り替え）。</p>

            <div
                class="blog-switch-actions"
            >
                <button type="button" class="btn-secondary" data-modal-close>閉じる</button>
            </div>
        @else
            <p>対象のブログ：{{ $selectedBlog->display_name }}（{{ $selectedBlog->home }}）</p>

            <h3>現在の状態</h3>

            @if ($blogCredential)
                <p>設定済み（ユーザー名：{{ $blogCredential->username }}）</p>
                <p>最終確認日時：{{ $blogCredential->verified_at ? \App\Support\DisplayTime::format($blogCredential->verified_at) : '未確認' }}</p>
                <p>
                    WordPress側の拡張（新規作成の照合に使う投稿メタ）：
                    @if ($blogCredential->connector_extension === true)
                        有効
                    @elseif ($blogCredential->connector_extension === false)
                        無効（新規作成の結果が不明になった場合は、人が照合します）
                    @else
                        未判定（接続確認で判定します。投稿が1件もない場合は判定できません）
                    @endif
                </p>
                @if ($blogCredential->last_failed_at)
                    <p>最後の失敗：{{ $blogCredential->last_failed_at }}（{{ $blogCredential->last_error }}）</p>
                @endif

                <form method="POST" action="{{ route('blogs.credentials.verify') }}">
                    @csrf
                    @include('partials.selected-blog-field')
                    <button class="btn-secondary" type="submit">接続確認</button>
                </form>
            @else
                <p>未設定です。</p>
            @endif

            <h3>更新（上書き）</h3>

            <form method="POST" action="{{ route('blogs.credentials.update') }}">
                @csrf
                @method('PUT')
                @include('partials.selected-blog-field')
                <input type="hidden" name="_form" value="blog-credential">

                <p>
                    <label>
                        WordPressのユーザー名<br>
                        <input type="text" name="username" value="{{ $credentialFailed ? old('username') : $blogCredential?->username }}" autocomplete="off" required style="width:100%; max-width:420px;">
                    </label>
                </p>

                <p>
                    <label>
                        Application Password<br>
                        <input type="password" name="application_password" autocomplete="new-password" required style="width:100%; max-width:420px;">
                    </label>
                </p>

                <div
                    class="blog-switch-actions"
                >
                    <button type="submit">接続を確認して保存する</button>
                    <button type="button" class="btn-secondary" data-modal-close>キャンセル</button>
                </div>
            </form>
        @endif

    </div>

</div>
