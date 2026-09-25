{{--
    API確認画面：一覧・詳細（BLOGOS_ARCHITECTURE.md 21-2）

    リクエストの内容・HTTPステータス・レスポンスヘッダー・レスポンス本文（JSON）を表示する。
    認証情報（Authorizationヘッダー）は表示しない（D-03-03）。
--}}

@extends('layouts.app')

@section('content')

    <h1>WordPress API確認：{{ $definition['label'] }}@if ($id) （{{ $id }}）@endif</h1>

    <p>
        <a href="{{ route('wp-api.home') }}">エンドポイント一覧に戻る</a>
        @if ($id)
            ／ <a href="{{ route('wp-api.resources.index', $resource) }}">{{ $definition['label'] }}の一覧に戻る</a>
        @endif
    </p>

    {{-- 問い合わせ条件（WordPress APIのパラメータ名のまま） --}}
    <form method="GET" action="{{ $id ? route('wp-api.resources.show', [$resource, $id]) : route('wp-api.resources.index', $resource) }}">
        <label>context
            <select name="context">
                <option value="">（指定なし）</option>
                @foreach (['view', 'embed', 'edit'] as $context)
                    <option value="{{ $context }}" @selected(($query['context'] ?? '') === $context)>{{ $context }}</option>
                @endforeach
            </select>
        </label>
        @unless ($id)
            <label>page <input type="number" name="page" min="1" value="{{ $query['page'] ?? '' }}" style="width:5em;"></label>
            <label>per_page <input type="number" name="per_page" min="1" max="100" value="{{ $query['per_page'] ?? '' }}" style="width:5em;"></label>
            <label>status <input type="text" name="status" value="{{ $query['status'] ?? '' }}" placeholder="publish,draft" style="width:10em;"></label>
            <label>search <input type="text" name="search" value="{{ $query['search'] ?? '' }}" style="width:10em;"></label>
        @endunless
        <button type="submit">取得</button>
    </form>

    <section>
        <h2>リクエスト</h2>
        <table border="1" cellpadding="4" cellspacing="0">
            <tr><th>HTTPメソッド</th><td>{{ $result['method'] }}</td></tr>
            <tr><th>URL</th><td style="word-break:break-all;">{{ $result['url'] }}</td></tr>
            <tr><th>クエリ</th><td style="word-break:break-all;">{{ $result['query'] ? http_build_query($result['query']) : '（なし）' }}</td></tr>
            <tr><th>Authorization</th><td>{{ $hasAuth ? '****（ブログの認証情報を使用）' : '（なし）' }}</td></tr>
        </table>
    </section>

    <section>
        <h2>レスポンス</h2>

        @if ($result['error'])
            <p style="color:#b00;">{{ $result['error'] }}</p>
        @else
            <table border="1" cellpadding="4" cellspacing="0">
                <tr><th>HTTPステータス</th><td>{{ $result['status'] }}</td></tr>
                @foreach (['X-WP-Total', 'X-WP-TotalPages'] as $header)
                    @if (isset($result['headers'][$header]))
                        <tr><th>{{ $header }}</th><td>{{ implode(', ', $result['headers'][$header]) }}</td></tr>
                    @endif
                @endforeach
            </table>

            @if ($rows)
                <h3>一覧（{{ count($rows) }}件）</h3>
                <div style="overflow-x:auto;">
                    <table border="1" cellpadding="4" cellspacing="0">
                        <thead><tr><th>ID</th><th>slug</th><th>名前・タイトル</th><th>status</th></tr></thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr>
                                    <td><a href="{{ route('wp-api.resources.show', [$resource, $row['id']]) }}">{{ $row['id'] }}</a></td>
                                    <td>{{ $row['slug'] }}</td>
                                    <td>{{ $row['title'] }}</td>
                                    <td>{{ $row['status'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <h3>レスポンスヘッダー</h3>
            <details>
                <summary>表示する</summary>
                <pre style="white-space:pre-wrap; word-break:break-all;">@foreach ($result['headers'] as $name => $values){{ $name }}: {{ implode(', ', $values) }}
@endforeach</pre>
            </details>

            <h3>レスポンス本文（JSON）</h3>
            <pre style="white-space:pre-wrap; word-break:break-all; max-height:60vh; overflow:auto; background:#f6f6f6; padding:8px;">{{ $result['body'] !== null ? json_encode($result['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $result['raw'] }}</pre>
        @endif
    </section>

@endsection
