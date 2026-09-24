<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>タグ一覧（si-note.com）</title>
</head>
<body>
    <h1>タグ一覧（si-note.com）</h1>
    <p><a href="{{ url('/') }}">トップページに戻る</a></p>
    <p>取得件数：{{ $total }}件</p>

    <table style="table-layout:fixed; width:100%; border-collapse:collapse;" border="1" cellpadding="5" cellspacing="0">
        <colgroup>
            <col style="width:6%">
            <col style="width:15%">
            <col style="width:15%">
            <col style="width:8%">
            <col style="width:9%">
            <col style="width:47%">
        </colgroup>
        <thead>
            <tr>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">ID</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">名前</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">スラッグ</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">記事数</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">リンク</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">説明</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($tags as $tag)
                <tr>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $tag['id'] ?? '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $tag['name'] ?? '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $tag['slug'] ?? '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $tag['count'] ?? '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">
                        @if (!empty($tag['link']))
                            <a href="{{ $tag['link'] }}" target="_blank">リンク</a>
                        @endif
                    </td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $tag['description'] ?? '' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">タグが取得できませんでした。</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
