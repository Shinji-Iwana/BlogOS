<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>投稿ステータス一覧（si-note.com）</title>
</head>
<body>
    <h1>投稿ステータス一覧（si-note.com）</h1>
    <p><a href="{{ url('/') }}">トップページに戻る</a></p>
    <p>取得件数：{{ $total }}件</p>

    <table style="table-layout:fixed; width:100%; border-collapse:collapse;" border="1" cellpadding="5" cellspacing="0">
        <colgroup>
            <col style="width:20%">
            <col style="width:20%">
            <col style="width:20%">
            <col style="width:20%">
            <col style="width:20%">
        </colgroup>
        <thead>
            <tr>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">スラッグ</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">名前</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">公開ステータスか</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">検索対象か</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">一覧表示対象か</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($statuses as $status)
                <tr>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $status['slug'] }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $status['name'] }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $status['public'] }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $status['queryable'] }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $status['show_in_list'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">ステータスが取得できませんでした。</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
