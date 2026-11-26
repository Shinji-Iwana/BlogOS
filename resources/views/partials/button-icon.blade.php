{{--
    ボタンの名前の左に付けるアイコン（D-121-01。docs/BLOGOS_UI.md 4-4）。ボタンの開始タグのすぐ後ろに置く。
    WordPress：外の輪と、W をくり抜いた円（WordPress のロゴの形）。BlogOS：アークリアクター（外の輪・コイル・逆三角・中心）。
    色はボタンの文字の色（currentColor）。
    受け取る値：$to（App\Support\ButtonIcon::WORDPRESS / BLOGOS）
--}}
@php($buttonIconLabel = \App\Support\ButtonIcon::label($to))
@if ($to === \App\Support\ButtonIcon::WORDPRESS)
@php($buttonIconId = \App\Support\ButtonIcon::nextId())
<span class="button-icon" data-button-icon="wordpress" title="{{ $buttonIconLabel }}"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><defs><mask id="{{ $buttonIconId }}" maskUnits="userSpaceOnUse" x="0" y="0" width="24" height="24"><rect width="24" height="24" fill="#fff"/><g fill="none" stroke="#000" stroke-width="1.9"><path d="M5.7 8.3l3.7 10.1 2.4-6.4"/><path d="M10.6 8.3l3.9 10.1 3-8.2c.5-1.4.3-2.6-.4-3.6"/><path d="M4.3 8.1h3M9.4 8.1h3" stroke-width="1.2"/></g></mask></defs><circle cx="12" cy="12" r="10.9" fill="none" stroke="currentColor" stroke-width="1.2"/><circle cx="12" cy="12" r="9" fill="currentColor" mask="url(#{{ $buttonIconId }})"/></svg></span>@else
<span class="button-icon" data-button-icon="blogos" title="{{ $buttonIconLabel }}"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10.4" stroke="currentColor" stroke-width="1.6"/><circle cx="12" cy="12" r="7.4" stroke="currentColor" stroke-width="2.2" stroke-dasharray="2.4 1.5"/><path d="M7.5 9.3h9L12 17.1z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/><circle cx="12" cy="12" r="1.7" fill="currentColor"/></svg></span>@endif
