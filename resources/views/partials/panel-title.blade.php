{{--
    パネルの題名の行（D-126-01。docs/BLOGOS_UI.md 2-5）。パネル（section.panel）の最初の h2 として置く。
    題名の右端に、操作のアイコンを並べる。今は削除（ごみ箱）だけ：押すと、共通の削除のポップアップ（partials/delete-modal）を開く。
    アイコンは、右のパネルの再読み込みと同じ枠（btn-secondary）。マウスを乗せると「削除」のツールチップを出す（BLOGOS_UI 1-1）。
    ポップアップを開くだけで何も変えないため、送るボタンのアイコン（WordPress／BlogOS。4-4）は付けない。
    受け取る値：$title（題名。例：詳細）、$deleteModal（任意。開く削除のポップアップの id。なければ、ごみ箱のアイコンを出さない）
--}}
<h2 class="panel-title-row">
    <span class="panel-title-text">{{ $title }}</span>
    @if (! empty($deleteModal))
        <span class="panel-title-icons">
            <button type="button" class="btn-secondary panel-icon-button" data-modal-open="{{ $deleteModal }}" title="削除" aria-label="削除"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>{{-- 幅のない文字で1行の高さを持たせる（再読み込みのアイコンと同じ） --}}<span aria-hidden="true">&#8203;</span></button>
        </span>
    @endif
</h2>
