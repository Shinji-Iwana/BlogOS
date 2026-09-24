@extends('layouts.app')

@section('content')

    <h1>カテゴリ詳細</h1>

    <p>
        <a href="{{ route('api-category-list', ['blogId' => $blogId,]) }}">カテゴリ一覧に戻る</a>
    </p>
    <p>
        <a href="{{ url('/') }}">トップページに戻る</a>
    </p>

    {{-- API確認 --}}
    <section>
        <h2>API取得結果</h2>

        <p>
            WordPress REST APIの
            /wp-json/wp/v2/categories/{id}
            から取得したカテゴリ1件分の情報を表示する。
        </p>

        @if ($category !== null)

            <table
                style="table-layout:fixed; width:100%; border-collapse:collapse;"
                border="1"
                cellpadding="5"
                cellspacing="0"
            >
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
                    @foreach ($category->toArray() as $key => $value)
                        <tr>
                            <td style="vertical-align:top; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">
                                {{ $key }}
                            </td>

                            <td style="text-align:left; word-break:break-word;">
                                @if (is_array($value))
                                    {{ json_encode(
                                        $value,
                                        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
                                    ) }}
                                @elseif (is_object($value))
                                    {{ json_encode(
                                        $value,
                                        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
                                    ) }}
                                @else
                                    {{ $value }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

        @else

            <p>
                カテゴリ情報を取得できませんでした。
            </p>

        @endif
    </section>

    @if ($category !== null)
        <section>
            <h2>API取得結果全体（JSON）</h2>

            <p>
                CategoryApiDtoが保持しているカテゴリ情報全体を、
                JSON形式で表示する。
            </p>

            <pre
                style="
                    width:100%;
                    box-sizing:border-box;
                    overflow:auto;
                    white-space:pre-wrap;
                    word-break:break-word;
                    border:1px solid #ccc;
                    padding:10px;
                "
            >{{ json_encode(
                $category->toArray(),
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
            ) }}</pre>
        </section>
    @endif

@endsection
