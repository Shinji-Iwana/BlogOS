{{--
    ポップアップの題名（D-100-01。docs/BLOGOS_UI.md 4-2）。
    題名の左に、モードのアイコン（新規：＋・編集：鉛筆・切替：回る矢印・情報：i）を出す。アイコン・題名・「?」は、縦の中央でそろえる。
    4つのアイコンを全て出しておき、ポップアップの data-modal-mode に合うものだけを CSS で見せる（開くときにモードを変えられるように）。
    受け取る値：$title（題名）、$tip（説明。なければ「?」を出さない）
--}}
<h2 class="modal-title">
    <span class="modal-mode-icon" aria-hidden="true">
        <svg data-modal-icon="create" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
        <svg data-modal-icon="edit" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
        <svg data-modal-icon="switch" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>
        <svg data-modal-icon="info" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5.5"/><path d="M12 7.5h.01"/></svg>
    </span>
    <span class="modal-title-text">{{ $title }}</span>
    @if (! empty($tip))
        @include('partials.tip', ['tip' => $tip])
    @endif
</h2>
