<!DOCTYPE html>

<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>カテゴリ一覧</title>
</head>
<body>
    <h1>カテゴリ一覧</h1>

<p>
    <a href="{{ route('settings', ['blogId' => $blogId,]) }}">設定ページに戻る</a>
</p>

<p>
    <a href="{{ url('/') }}">トップページに戻る</a>
</p>


{{-- ==========================================================
     API取得結果
     ========================================================== --}}

<h2>API取得結果</h2>

<p>
    WordPress REST APIの
    /wp-json/wp/v2/categories
    から取得したカテゴリ情報を全件表示する。
</p>

<p>
    取得件数：{{ $total }}件
</p>


@if (!empty($categories))

    @php
        /*
         * 取得したすべてのCategoryApiDtoから、
         * APIレスポンスに存在する項目名を収集する。
         *
         * CategoryApiDtoはAPIレスポンス全体を保持する設計のため、
         * id、name、slugなどの固定項目だけではなく、
         * APIレスポンスに追加された項目も表示対象とする。
         */
        $fields = [];

        foreach ($categories as $category) {
            foreach ($category->toArray() as $key => $value) {
                if (!in_array($key, $fields, true)) {
                    $fields[] = $key;
                }
            }
        }
    @endphp


    {{-- ==========================================================
         カテゴリ一覧
         ========================================================== --}}

    <table
        style="table-layout:fixed; width:100%; border-collapse:collapse;"
        border="1"
        cellpadding="5"
        cellspacing="0"
    >
        <colgroup>
            @foreach ($fields as $field)
                <col style="width:{{ 100 / max(count($fields), 1) }}%">
            @endforeach
        </colgroup>

        <thead>
            <tr>
                @foreach ($fields as $field)
                    <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                        {{ $field }}
                    </th>
                @endforeach
            </tr>
        </thead>

        <tbody>
            @foreach ($categories as $category)

                @php
                    /*
                     * CategoryApiDtoが保持している
                     * APIレスポンス全体を取得する。
                     */
                    $categoryData = $category->toArray();
                @endphp

                <tr>
                    @foreach ($fields as $field)

                        <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">
                            @if (array_key_exists($field, $categoryData))

                                @php
                                    $value = $categoryData[$field];
                                @endphp

                                {{-- ==================================================
                                     ID項目
                                     ================================================== --}}

                                @if ($field === 'id')
                                    <a href="{{ route('api-category-detail', ['blogId' => $blogId, 'categoryId' => $value,]) }}">{{ $value }}</a>


                                {{-- ==================================================
                                     配列・オブジェクト
                                     ================================================== --}}

                                @elseif (is_array($value) || is_object($value))

                                    {{ json_encode(
                                        $value,
                                        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
                                    ) }}

                                {{-- ==================================================
                                     その他の項目
                                     ================================================== --}}

                                @else

                                    {{ $value }}

                                @endif

                            @else

                                {{-- そのカテゴリのAPIレスポンスに
                                     該当項目が存在しない場合 --}}
                                -

                            @endif
                        </td>

                    @endforeach
                </tr>

            @endforeach
        </tbody>
    </table>


    {{-- ==========================================================
         API取得結果全体
         ========================================================== --}}

    <h2>API取得結果全体（JSON）</h2>

    <p>
        WordPress REST APIから取得し、
        CategoryApiDtoが保持しているカテゴリ情報全件を、
        JSON形式で表示する。
    </p>

    @php
        /*
         * CategoryApiDtoそのものではなく、
         * 各DTOが保持しているAPIレスポンスを配列として取り出す。
         *
         * これにより、CategoryApiDtoの内部データを
         * JSONとしてそのまま確認できる。
         */
        $allCategoryData = [];

        foreach ($categories as $category) {
            $allCategoryData[] = $category->toArray();
        }
    @endphp

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
        $allCategoryData,
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    ) }}</pre>

@else

    <p>
        カテゴリ情報を取得できませんでした。
    </p>

@endif

</body>
</html>
