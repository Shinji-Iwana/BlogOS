<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>サイト内検索（si-note.com）</title>
</head>
<body>
    <h1>サイト内検索（si-note.com）</h1>
    <p><a href="{{ url('/') }}">トップページに戻る</a></p>

    <form method="GET" action="{{ route('api-site-search') }}">
        <input type="text" name="q" value="{{ $keyword }}" placeholder="検索したいキーワードを入力">
        <button type="submit">検索</button>
    </form>

    @if ($keyword !== '')
        <p>「{{ $keyword }}」の検索結果：{{ $total }}件</p>

        <table style="table-layout:fixed; width:100%; border-collapse:collapse;" border="1" cellpadding="5" cellspacing="0">
            <colgroup>
                <col style="width:8%">
                <col style="width:30%">
                <col style="width:12%">
                <col style="width:12%">
                <col style="width:38%">
            </colgroup>
            <thead>
                <tr>
                    <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">ID</th>
                    <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">タイトル</th>
                    <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">種別</th>
                    <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">サブタイプ</th>
                    <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">リンク</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($results as $result)
                    <tr>
                        <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $result['id'] ?? '' }}</td>
                        <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $result['title'] ?? '' }}</td>
                        <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $result['type'] ?? '' }}</td>
                        <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $result['subtype'] ?? '' }}</td>
                        <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">
                            @if (!empty($result['url']))
                                <a href="{{ $result['url'] }}" target="_blank">{{ $result['url'] }}</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">該当する結果が見つかりませんでした。</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    @endif
</body>
</html>
