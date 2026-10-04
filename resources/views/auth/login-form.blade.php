{{--
    ログインの入力欄（D-51）。auth/login と、テーマのログイン画面（themes/{テーマ名}/auth/login）から読み込む。
--}}
@if ($errors->any())
    <div class="text-error">
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route('login') }}" class="panel login-form" data-code="LOGIN">
    @csrf

    <p>
        <label for="email">メールアドレス</label><br>
        <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
    </p>

    <p>
        <label for="password">パスワード</label><br>
        <input type="password" id="password" name="password" required autocomplete="current-password">
    </p>

    <p>
        <label>
            <input type="checkbox" name="remember"> ログイン状態を保持する
        </label>
    </p>

    <p>
        <button type="submit">ログイン</button>
    </p>
</form>
