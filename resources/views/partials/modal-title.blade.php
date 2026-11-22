{{--
    ポップアップの題名（D-100-01。docs/BLOGOS_UI.md 4-2）。
    題名の左に、モードのアイコン（新規：＋・編集：鉛筆・切替：回る矢印・情報：i）を出す。アイコン・題名・「?」は、縦の中央でそろえる。
    4つのアイコンを全て出しておき、ポップアップの data-modal-mode に合うものだけを CSS で見せる（開くときにモードを変えられるように）。
    アイコンには、マウスを乗せるとツールチップで名前を出す（title。モードの名前は App\Support\ModalMode::ICON_LABELS。D-108-01）。
    種類のアイコン（$kindIcon）を渡すと、モードのアイコンの右に、同じ枠で出す（定期実行：カレンダーと時計。D-103-01）。
    受け取る値：$title（題名）、$tip（説明。なければ「?」を出さない）、$kindIcon（任意。schedule）
--}}
@php
    $modeIconLabels = \App\Support\ModalMode::ICON_LABELS;
@endphp
<h2 class="modal-title">
    <span class="modal-mode-icon" aria-hidden="true">
        <span data-modal-icon="create" title="{{ $modeIconLabels['create'] }}"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg></span>
        <span data-modal-icon="edit" title="{{ $modeIconLabels['edit'] }}"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></span>
        <span data-modal-icon="switch" title="{{ $modeIconLabels['switch'] }}"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg></span>
        <span data-modal-icon="info" title="{{ $modeIconLabels['info'] }}"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5.5"/><path d="M12 7.5h.01"/></svg></span>
    </span>
    @if (($kindIcon ?? null) === 'schedule')
        <span class="modal-kind-icon" title="定期実行" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M11 20H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v3.5"/><path d="M7 3v4"/><path d="M11 3v4"/><path d="M15 3v4"/><g fill="currentColor" stroke="none"><rect x="5.6" y="9.2" width="2" height="2" rx=".4"/><rect x="9.2" y="9.2" width="2" height="2" rx=".4"/><rect x="12.8" y="9.2" width="2" height="2" rx=".4"/><rect x="5.6" y="12.8" width="2" height="2" rx=".4"/><rect x="9.2" y="12.8" width="2" height="2" rx=".4"/><rect x="5.6" y="16.4" width="2" height="2" rx=".4"/></g><circle cx="17" cy="17" r="5"/><path d="M17 14.5V17h2.2"/></svg>
        </span>
    @endif
    <span class="modal-title-text">{{ $title }}</span>
    @if (! empty($tip))
        @include('partials.tip', ['tip' => $tip])
    @endif
</h2>
