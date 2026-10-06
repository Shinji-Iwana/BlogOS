{{--
    ブログの登録ポップアップ（D-63-21。以前は画面「ブログ登録」（blogs.create）。BLOGOS_WORDPRESS_API.md 29章）

    メニューの「設定 → ブログを登録」を押した場合に表示する（data-modal-open="blog-register-modal"。layouts/header から読み込む）。
    入力：ブログのURL、WordPressのユーザー名、Application Password、品質基準（ブログ別の定義）。
    サイト名・ホームURLなどは、サーバー側でWordPressから取得して保存する。
    登録できたら、そのブログの詳細の画面を開く。入力の誤りや、WordPress を確認できなかったときは、開いていた画面に戻り、
    ポップアップを開いたままにして誤りを出す（_form。Application Password は入れ直す）。
--}}

@php
    $blogRegisterFailed = old('_form') === 'blog-register' && $errors->any();
@endphp

<div
    id="blog-register-modal"
    class="blog-switch-modal site-modal blog-register-modal"
    @if ($blogRegisterFailed) data-modal-autoopen @endif
>

    <div
        class="blog-switch-dialog"
    >

        <h2>
            ブログを登録
            @include('partials.tip', ['tip' => "WordPress のブログを BlogOS に登録します。サイト名などは WordPress から読み取ります。\n登録すると、そのブログを選び、投稿などの初回取得を始めます（完了まで数分かかることがあります）。"])
        </h2>

        @if ($blogRegisterFailed)
            <ul class="text-error">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        @endif

        <form
            method="POST"
            action="{{ route('blogs.store') }}"
        >

            @csrf
            <input type="hidden" name="_form" value="blog-register">

            <p>
                <label>
                    ブログのURL<br>
                    <input type="url" name="url" value="{{ $blogRegisterFailed ? old('url') : '' }}" placeholder="https://example.com" required style="width:100%; max-width:420px;">
                </label>
            </p>

            <p>
                <label>
                    WordPressのユーザー名<br>
                    <input type="text" name="username" value="{{ $blogRegisterFailed ? old('username') : '' }}" autocomplete="off" required style="width:100%; max-width:420px;">
                </label>
            </p>

            <p>
                <label>
                    Application Password
                    @include('partials.tip', ['tip' => "WordPressの「ユーザー → プロフィール → アプリケーションパスワード」で、「BlogOS」という名前で発行したものを入力してください。\n登録後は画面に表示しません。"])
                    <br>
                    <input type="password" name="application_password" autocomplete="new-password" required style="width:100%; max-width:420px;">
                </label>
            </p>

            <p>
                <label>
                    品質基準（ブログ別の定義）<br>
                    <select name="quality_profile">
                        <option value="">（未設定）</option>
                        @foreach (\App\Support\QualityProfiles::available() as $profile)
                            <option value="{{ $profile }}" @selected($blogRegisterFailed && old('quality_profile') === $profile)>{{ $profile }}</option>
                        @endforeach
                    </select>
                </label>
            </p>

            <div
                class="blog-switch-actions"
            >
                <button type="submit">確認して登録する</button>
                <button type="button" class="btn-secondary" data-modal-close>キャンセル</button>
            </div>

        </form>

    </div>

</div>
