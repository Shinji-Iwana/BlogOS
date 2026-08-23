<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>ログイン（si-note.com 管理画面）</title>
</head>
<body>
    <h1>ログイン</h1>

    @if ($errors->any())
        <div>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <p>
            <label for="email">メールアドレス</label><br>
            <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus>
        </p>

        <p>
            <label for="password">パスワード</label><br>
            <input type="password" id="password" name="password" required>
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
</body>
</html>
