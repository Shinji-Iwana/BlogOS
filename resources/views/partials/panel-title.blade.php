{{--
    パネルの題名の行（D-126-01・D-128-01。docs/BLOGOS_UI.md 2-5）。パネル（section.panel）の最初の h2 として置く。
    題名の右端に、操作のアイコンを並べる：編集（鉛筆）が左、削除（ごみ箱）が右。
    ・編集：押すと、編集のポップアップを開く（$editModal）。押したときに何を開くか（ポップアップか、画面か）は、画面ごとに決める（D-128-01）
    ・削除：押すと、共通の削除のポップアップ（partials/delete-modal）を開く（$deleteModal）
    アイコンは、右のパネルの再読み込みと同じ形（btn-secondary）。編集は青、削除は赤の枠と赤のアイコン（panel-icon-delete。D-126-02）。
    マウスを乗せると「編集」「削除」のツールチップを出す（BLOGOS_UI 1-1）。
    ポップアップを開くだけで何も変えないため、送るボタンのアイコン（WordPress／BlogOS。4-4）は付けない。
    受け取る値：$title（題名。例：詳細）、$editModal（任意。開く編集のポップアップの id）、$deleteModal（任意。開く削除のポップアップの id）。
    渡さないアイコンは出さない
--}}
<h2 class="panel-title-row">
    <span class="panel-title-text">{{ $title }}</span>
    @if (! empty($editModal) || ! empty($deleteModal))
        <span class="panel-title-icons">
            @if (! empty($editModal))
                <button type="button" class="btn-secondary panel-icon-button" data-modal-open="{{ $editModal }}" title="編集" aria-label="編集"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg><span aria-hidden="true">&#8203;</span></button>
            @endif
            @if (! empty($deleteModal))
                <button type="button" class="btn-secondary panel-icon-button panel-icon-delete" data-modal-open="{{ $deleteModal }}" title="削除" aria-label="削除"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>{{-- 幅のない文字で1行の高さを持たせる（再読み込みのアイコンと同じ） --}}<span aria-hidden="true">&#8203;</span></button>
            @endif
        </span>
    @endif
</h2>
