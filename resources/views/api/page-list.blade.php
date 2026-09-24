<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>固定ページ一覧（si-note.com）</title>
</head>
<body>
    <h1>固定ページ一覧（si-note.com）</h1>
    <p><a href="{{ url('/') }}">トップページに戻る</a></p>
    <p>取得件数：{{ $total }}件</p>

    <table style="table-layout:fixed; width:100%; border-collapse:collapse;" border="1" cellpadding="5" cellspacing="0">
        <colgroup>
            <col style="width:4%">
            <col style="width:10%">
            <col style="width:6%">
            <col style="width:8%">
            <col style="width:7%">
            <col style="width:7%">
            <col style="width:5%">
            <col style="width:9%">
            <col style="width:10%">
            <col style="width:5%">
            <col style="width:5%">
            <col style="width:7%">
            <col style="width:7%">
            <col style="width:7%">
        </colgroup>
        <thead>
            <tr>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">ID</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">タイトル</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">ステータス</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">スラッグ</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">投稿日</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">更新日</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">リンク</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">抜粋</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">メタディスクリプション</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">親ページID</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">並び順</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">テンプレート</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">アイキャッチ画像</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">本文</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($pages as $page)
                <tr>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">
                        <a href="{{ route('page-info.show', $page['id']) }}">{{ $page['id'] ?? '' }}</a>
                    </td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $page['title']['rendered'] ?? $page['title']['raw'] ?? '(タイトルなし)' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $page['status'] ?? '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $page['slug'] ?? '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $page['date'] ?? '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $page['modified'] ?? '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">
                        @if (!empty($page['link']))
                            <a href="{{ $page['link'] }}" target="_blank">リンク</a>
                        @endif
                    </td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{!! strip_tags($page['excerpt']['rendered'] ?? $page['excerpt']['raw'] ?? '') !!}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $page['aioseo_meta_description'] ?? '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $page['parent'] ?? '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $page['menu_order'] ?? '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $page['template'] ?? '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">
                        @if (!empty($page['_embedded']['wp:featuredmedia'][0]['source_url']))
                            <a href="{{ $page['_embedded']['wp:featuredmedia'][0]['source_url'] }}" target="_blank">画像</a>
                        @else
                            なし
                        @endif
                    </td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">
                        <details>
                            <summary>表示</summary>
                            {!! $page['content']['rendered'] ?? $page['content']['raw'] ?? '' !!}
                        </details>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="14">固定ページが取得できませんでした。</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
