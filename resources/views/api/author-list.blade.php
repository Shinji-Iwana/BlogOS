<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>投稿者情報一覧（si-note.com）</title>
</head>
<body>
    <h1>投稿者情報一覧（si-note.com）</h1>
    <p><a href="{{ url('/') }}">トップページに戻る</a></p>
    <p>取得件数：{{ $total }}件</p>

    <table style="table-layout:fixed; width:100%; border-collapse:collapse;" border="1" cellpadding="5" cellspacing="0">
        <colgroup>
            <col style="width:5%">
            <col style="width:10%">
            <col style="width:10%">
            <col style="width:10%">
            <col style="width:10%">
            <col style="width:20%">
            <col style="width:10%">
            <col style="width:10%">
            <col style="width:15%">
        </colgroup>
        <thead>
            <tr>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">ID</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">名前</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">表示名</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">ニックネーム</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">スラッグ</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">自己紹介</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">投稿者URL</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">権限</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">リンク</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($users as $user)
                <tr>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $user['id'] ?? '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $user['name'] ?? '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $user['name'] ?? '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $user['slug'] ?? '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $user['slug'] ?? '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $user['description'] ?? '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">
                        @if (!empty($user['url']))
                            <a href="{{ $user['url'] }}" target="_blank">{{ $user['url'] }}</a>
                        @endif
                    </td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ isset($user['roles']) ? implode(', ', $user['roles']) : '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">
                        @if (!empty($user['link']))
                            <a href="{{ $user['link'] }}" target="_blank">プロフィール</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9">投稿者が取得できませんでした。</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
