<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>投稿タイプ一覧（si-note.com）</title>
</head>
<body>
    <h1>投稿タイプ一覧（si-note.com）</h1>
    <p><a href="{{ url('/') }}">トップページに戻る</a></p>
    <p>取得件数：{{ $total }}件</p>

    <table style="table-layout:fixed; width:100%; border-collapse:collapse;" border="1" cellpadding="5" cellspacing="0">
        <colgroup>
            <col style="width:14%">
            <col style="width:14%">
            <col style="width:30%">
            <col style="width:12%">
            <col style="width:12%">
            <col style="width:18%">
        </colgroup>
        <thead>
            <tr>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">スラッグ</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">名前</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">説明</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">階層構造か</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">RESTベース</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">紐づくタクソノミー</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($types as $type)
                <tr>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $type['slug'] }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $type['name'] }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $type['description'] }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ ($type['hierarchical'] ?? false) ? 'true' : 'false' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $type['rest_base'] }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ implode(', ', (array) ($type['taxonomies'] ?? [])) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">投稿タイプが取得できませんでした。</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
