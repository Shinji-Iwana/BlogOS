<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>固定ページ詳細（si-note.com）</title>
</head>
<body>
    <h1>固定ページ詳細（si-note.com）</h1>
    <p><a href="{{ route('page-info') }}">固定ページ一覧に戻る</a></p>

    @if (empty($page))
        <p>該当の固定ページが見つかりませんでした。</p>
    @else
        <table style="width:100%; border-collapse:collapse;" border="1" cellpadding="8" cellspacing="0">
            <colgroup>
                <col style="width:20%">
                <col style="width:80%">
            </colgroup>
            <tbody>
                <tr>
                    <th style="text-align:left; white-space:nowrap;">ID</th>
                    <td><input type="text" readonly style="width:100%; box-sizing:border-box;" value="{{ $page['id'] ?? '' }}"></td>
                </tr>
                <tr>
                    <th style="text-align:left; white-space:nowrap;">タイトル</th>
                    <td><input type="text" readonly style="width:100%; box-sizing:border-box;" value="{{ $page['title']['rendered'] ?? $page['title']['raw'] ?? '' }}"></td>
                </tr>
                <tr>
                    <th style="text-align:left; white-space:nowrap;">ステータス</th>
                    <td><input type="text" readonly style="width:100%; box-sizing:border-box;" value="{{ $page['status'] ?? '' }}"></td>
                </tr>
                <tr>
                    <th style="text-align:left; white-space:nowrap;">スラッグ</th>
                    <td><input type="text" readonly style="width:100%; box-sizing:border-box;" value="{{ $page['slug'] ?? '' }}"></td>
                </tr>
                <tr>
                    <th style="text-align:left; white-space:nowrap;">投稿日</th>
                    <td><input type="text" readonly style="width:100%; box-sizing:border-box;" value="{{ $page['date'] ?? '' }}"></td>
                </tr>
                <tr>
                    <th style="text-align:left; white-space:nowrap;">更新日</th>
                    <td><input type="text" readonly style="width:100%; box-sizing:border-box;" value="{{ $page['modified'] ?? '' }}"></td>
                </tr>
                <tr>
                    <th style="text-align:left; white-space:nowrap;">リンク</th>
                    <td><input type="text" readonly style="width:100%; box-sizing:border-box;" value="{{ $page['link'] ?? '' }}"></td>
                </tr>
                <tr>
                    <th style="text-align:left; white-space:nowrap;">抜粋</th>
                    <td><textarea readonly rows="4" style="width:100%; box-sizing:border-box;">{{ strip_tags($page['excerpt']['rendered'] ?? $page['excerpt']['raw'] ?? '') }}</textarea></td>
                </tr>
                <tr>
                    <th style="text-align:left; white-space:nowrap;">メタディスクリプション</th>
                    <td><textarea readonly rows="3" style="width:100%; box-sizing:border-box;">{{ $page['aioseo_meta_description'] ?? '' }}</textarea></td>
                </tr>
                <tr>
                    <th style="text-align:left; white-space:nowrap;">親ページID</th>
                    <td><input type="text" readonly style="width:100%; box-sizing:border-box;" value="{{ $page['parent'] ?? '' }}"></td>
                </tr>
                <tr>
                    <th style="text-align:left; white-space:nowrap;">並び順</th>
                    <td><input type="text" readonly style="width:100%; box-sizing:border-box;" value="{{ $page['menu_order'] ?? '' }}"></td>
                </tr>
                <tr>
                    <th style="text-align:left; white-space:nowrap;">テンプレート</th>
                    <td><input type="text" readonly style="width:100%; box-sizing:border-box;" value="{{ $page['template'] ?? '' }}"></td>
                </tr>
                <tr>
                    <th style="text-align:left; white-space:nowrap;">アイキャッチ画像</th>
                    <td><input type="text" readonly style="width:100%; box-sizing:border-box;" value="{{ $page['_embedded']['wp:featuredmedia'][0]['source_url'] ?? '' }}"></td>
                </tr>
                <tr>
                    <th style="text-align:left; white-space:nowrap;">本文</th>
                    <td><textarea readonly rows="20" style="width:100%; box-sizing:border-box;">{{ $page['content']['rendered'] ?? $page['content']['raw'] ?? '' }}</textarea></td>
                </tr>
            </tbody>
        </table>
    @endif
</body>
</html>
