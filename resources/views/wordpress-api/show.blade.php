{{--
    API確認画面：一覧・詳細（BLOGOS_ARCHITECTURE.md 21-2）

    リクエストの内容・HTTPステータス・レスポンスヘッダー・レスポンス本文（JSON）を表示する。
    認証情報（Authorizationヘッダー）は表示しない（D-03-03）。
--}}

@extends('layouts.app')

@section('content')

    {{-- 詳細の画面は、題名に名前（投稿・固定ページ・メディアはタイトル、そのほかは name。取り出せなければ ID）を付ける --}}
    <h1>WordPress API情報：{{ $definition['label'] }}@if ($id)：{{ $detailName }}@endif</h1>

    <p>
        <a href="{{ route('wp-api.home') }}">WordPress API情報に戻る</a>
        @if ($id)
            ／ <a href="{{ route('wp-api.resources.index', $resource) }}">{{ $definition['label'] }}の一覧に戻る</a>
        @endif
    </p>

    {{-- 問い合わせ条件（WordPress APIのパラメータ名のまま）。パネルの題名の英字の札（data-code）は、ironman だけで出す --}}
    <section class="panel">
    <h2 data-code="FILTER">条件</h2>
    {{-- 条件は、名前（「?」付き・折り返さない）と入力欄を交互に並べた表（色・枠なし）。1行目：context・page・per_page・status、2行目：search。
         詳細の画面は context だけ。「取得」は表の下の右端 --}}
    <form method="GET" action="{{ $id ? route('wp-api.resources.show', [$resource, $id]) : route('wp-api.resources.index', $resource) }}">
        <table class="filter-table">
            <tbody>
                <tr>
                    <th><label for="api-context">context</label> @include('partials.tip', ['tip' => "どの使い方向けの項目を返してもらうか（WordPress API の context）。\nview：サイトで表示する用（見る人に見せてよい項目だけ）。\nembed：ほかのデータに埋め込む用（最小限の項目だけ）。\nedit：編集する用（入力したそのままの値（raw）や、編集に要る項目も含む。編集の権限が要る）。\n指定なしは view と同じ。"])</th>
                    <td>
                        <select name="context" id="api-context">
                            <option value="">（指定なし）</option>
                            @foreach (['view', 'embed', 'edit'] as $context)
                                <option value="{{ $context }}" @selected(($query['context'] ?? '') === $context)>{{ $context }}</option>
                            @endforeach
                        </select>
                    </td>
                    @unless ($id)
                        <th><label for="api-page">page</label> @include('partials.tip', ['tip' => '何ページ目を取得するか（1から。指定なしは1ページ目）。'])</th>
                        <td><input type="number" name="page" id="api-page" min="1" value="{{ $query['page'] ?? '' }}" style="width:5em;"></td>
                        <th><label for="api-per-page">per_page</label> @include('partials.tip', ['tip' => '1ページに取得する件数（1〜100。この画面の初めの値は20）。'])</th>
                        <td><input type="number" name="per_page" id="api-per-page" min="1" max="100" value="{{ $query['per_page'] ?? '' }}" style="width:5em;"></td>
                        <th><label for="api-status">status</label> @include('partials.tip', ['tip' => "公開の状態でしぼり込む（publish：公開、draft：下書き、pending：レビュー待ち、future：予約、private：非公開。「,」で区切って複数）。\n指定なしは公開（publish）だけ。下書きなどは、編集の権限で取得するため context を edit にする。"])</th>
                        <td><input type="text" name="status" id="api-status" value="{{ $query['status'] ?? '' }}" placeholder="publish,draft" style="width:10em;"></td>
                    @endunless
                </tr>
                @unless ($id)
                    <tr>
                        <th><label for="api-search">search</label> @include('partials.tip', ['tip' => '言葉でしぼり込む（タイトル・本文・名前などに、その言葉を含むもの）。'])</th>
                        <td colspan="7"><input type="text" name="search" id="api-search" value="{{ $query['search'] ?? '' }}" style="width:10em;"></td>
                    </tr>
                @endunless
            </tbody>
        </table>
        <p class="filter-actions"><button class="btn-secondary" type="submit">取得</button></p>
    </form>
    </section>

    <section class="panel">
        <h2 data-code="REQUEST">リクエスト</h2>
        <table class="data">
            <tr><th>HTTPメソッド</th><td>{{ $result['method'] }}</td></tr>
            <tr><th>URL</th><td style="word-break:break-all;">{{ $result['url'] }}</td></tr>
            <tr><th>クエリ</th><td style="word-break:break-all;">{{ $result['query'] ? http_build_query($result['query']) : '（なし）' }}</td></tr>
            <tr><th>Authorization</th><td>{{ $hasAuth ? '****（ブログの認証情報を使用）' : '（なし）' }}</td></tr>
        </table>
    </section>

    <section class="panel">
        <h2 data-code="RESPONSE">レスポンス</h2>

        @if ($result['error'])
            <p class="text-error">{{ $result['error'] }}</p>
        @else
            <table class="data">
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
                    <table class="data">
                        <thead><tr><th>ID</th><th>slug</th><th>名前・タイトル</th><th>status</th></tr></thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr>
                                    <td><a href="{{ route('wp-api.resources.show', [$resource, $row['id']]) }}">{{ $row['id'] }}</a></td>
                                    <td title="{{ $row['slug'] }}">{{ \App\Support\Slug::display($row['slug']) }}</td>
                                    <td>{{ $row['title'] }}</td>
                                    <td>{{ $row['status'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            {{-- 1件のデータの応答（詳細・API Root・Settings）は、全ての項目を表で出す（取り込んだデータの詳細の画面と同じ形。D-72-10）。
                 日時は日本時間に直す（DisplayTime::apiBody）。入れ子の項目は、まとまりの名前の行を押すと開く（wordpress-api/fields） --}}
            @if (($id || $definition['list'] === 'single') && is_array($result['body']) && ! array_is_list($result['body']))
                <h3>詳細</h3>
                <div style="overflow-x:auto;">
                    @include('wordpress-api.fields', ['fields' => \App\Support\DisplayTime::apiBody($result['body']), 'resource' => $resource, 'path' => ''])
                </div>
            @endif

            <h3>レスポンスヘッダー</h3>
            <details>
                <summary>表示する</summary>
                <pre style="white-space:pre-wrap; word-break:break-all;">@foreach (\App\Support\DisplayTime::apiHeaders($result['headers']) as $name => $values){{ $name }}: {{ implode(', ', $values) }}
@endforeach</pre>
            </details>

            {{-- レスポンスヘッダーと同じく、初めは閉じておき、「表示する」で開く --}}
            <h3>レスポンス本文（JSON） @include('partials.tip', ['tip' => '日時は、日本時間に直して出します（直した値に「（日本時間）」を付けます）。'])</h3>
            <details>
                <summary>表示する</summary>
                <pre class="code-block" style="white-space:pre-wrap; word-break:break-all; max-height:60vh; overflow:auto; padding:8px;">{{ $result['body'] !== null ? json_encode(\App\Support\DisplayTime::apiBody($result['body']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $result['raw'] }}</pre>
            </details>
        @endif
    </section>

@endsection
