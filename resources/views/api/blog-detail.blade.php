@extends('layouts.app')

@section('content')

    <h1>ブログ詳細</h1>

    <p>
        <a href="{{ url('/') }}">トップページに戻る</a>
    </p>

    {{-- ==========================================================
    基本情報
    ========================================================== --}}
    <section>
        <h2>基本情報</h2>

        <p>
            /wp-jsonのレスポンスから、
            名前空間（namespaces）とルート情報（routes）を除いた
            基本情報を表示する。
        </p>

        <table style="table-layout:fixed; width:100%; border-collapse:collapse;" border="1" cellpadding="5" cellspacing="0">
            <colgroup>
                <col style="width:25%">
                <col style="width:75%">
            </colgroup>

        <thead>
            <tr>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                    項目
                </th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                    値
                </th>
            </tr>
        </thead>

        <tbody>
            @forelse ($basicInfo as $key => $value)
                <tr>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">
                        {{ $key }}
                    </td>

                    <td style="text-align:left; word-break:break-word;">
                        {{ $value }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="2">
                        情報が取得できませんでした。
                    </td>
                </tr>
            @endforelse
        </tbody>

        </table>
    </section>

    {{-- ==========================================================
    対応名前空間
    ========================================================== --}}
    <section>
        <h2>対応名前空間（namespaces）</h2>

        <p>
            WordPress REST APIが対応している名前空間を表示する。
        </p>

        <table style="table-layout:fixed; width:100%; border-collapse:collapse;" border="1" cellpadding="5" cellspacing="0">
            <colgroup>
                <col style="width:100%">
            </colgroup>

        <thead>
            <tr>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                    名前空間
                </th>
            </tr>
        </thead>

        <tbody>
            @forelse ($namespaces as $namespace)
                <tr>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">
                        {{ $namespace }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td>
                        情報が取得できませんでした。
                    </td>
                </tr>
            @endforelse
        </tbody>

        </table>
    </section>

    {{-- ==========================================================
    APIルート一覧
    ========================================================== --}}
    <section>
        <h2>エンドポイント一覧（routes）</h2>

        <p>
            取得件数：{{ $total }}件
        </p>

        <p>
            WordPress REST APIに登録されている各ルートについて、
            APIから取得したルート情報を項目ごとの表形式で表示する。
        </p>

        <table style="table-layout:fixed; width:100%; border-collapse:collapse;" border="1" cellpadding="5" cellspacing="0">
            <colgroup>
                <col style="width:30%">
                <col style="width:70%">
            </colgroup>

        <thead>
            <tr>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                    パス
                </th>

                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                    ルート情報
                </th>
            </tr>
        </thead>

        <tbody>
            @forelse ($routes as $route)
                <tr>
                    {{-- ----------------------------------------------
                        APIルートのパス
                        ---------------------------------------------- --}}

                    <td style="vertical-align:top; word-break:break-word;">
                        {{ $route['path'] }}
                    </td>

                    {{-- ----------------------------------------------
                        APIルートの詳細情報

                        $route['info'] に含まれる各項目を、
                        「項目」と「値」の表形式で表示する。

                        配列・オブジェクトの場合は、
                        JSON形式に整形して内容を確認できるようにする。
                        ---------------------------------------------- --}}

                    <td style="vertical-align:top; padding:0;">

                        @if (!empty($route['info']))
                            <table style="width:100%; border-collapse:collapse;" border="1" cellpadding="5" cellspacing="0">
                                <colgroup>
                                    <col style="width:30%">
                                    <col style="width:70%">
                                </colgroup>

                                <thead>
                                    <tr>
                                        <th>
                                            項目
                                        </th>

                                        <th>
                                            値
                                        </th>
                                    </tr>
                                </thead>

                                <tbody>
                                    @foreach ($route['info'] as $key => $value)
                                        <tr>
                                            <td style="vertical-align:top; word-break:break-word;">
                                                {{ $key }}
                                            </td>

                                            <td style="vertical-align:top; word-break:break-word;">

                                                @if (is_array($value) || is_object($value))
                                                    <pre style="margin:0; white-space:pre-wrap; word-break:break-word;">{{ json_encode(
                                                        $value,
                                                        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
                                                    ) }}</pre>
                                                @else
                                                    {{ $value }}
                                                @endif

                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @else
                            ルート情報がありません。
                        @endif

                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="2">
                        エンドポイントが取得できませんでした。
                    </td>
                </tr>
            @endforelse
        </tbody>

        </table>
    </section>

    {{-- ==========================================================
    API取得結果全体
    ========================================================== --}}
    <section>
        <h2>API取得結果全体（/wp-json）</h2>

        <p>
            WordPress REST APIから取得したレスポンス全体を、
            加工せずJSON形式で表示する。
        </p>

        @if (!empty($apiData))
            <pre style="width:100%; box-sizing:border-box; overflow:auto; white-space:pre-wrap; word-break:break-word; border:1px solid #ccc; padding:10px;">
                {{ json_encode(
                    $apiData,
                    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
                ) }}
            </pre>
        @else
            <p>API情報が取得できませんでした。 </p>
        @endif
    </section>
@endsection
