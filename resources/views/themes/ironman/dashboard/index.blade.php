{{--
    トップページ（ironman テーマ。D-49-07）

    共通の画面（resources/views/dashboard/index.blade.php）の代わりに使う。データは共通と同じ（DashboardController）。
    ・上：SYSTEM STATUS の帯（全体の状態・要対応と注意の数。選択中のブログはヘッダーに出ている）
    ・中：アークリアクター（全体の状態の色。同期の実行中は HUD の円が速く回る）と、左右に3つずつ状態のパネル。
      パソコンの幅では、アークリアクターから各パネルへ線を引く（js/dashboard/connectors.js）
    ・下：お知らせ（共通の dashboard/notices。同期はパネルに出すため除く）と、種類ごとの入口のパネル
--}}

@extends('layouts.app')

@section('content')

    @if ($blogs->isEmpty())

        {{-- ブログが1件もない場合は、アークリアクターと、ブログ登録への誘導だけ --}}
        @include('themes.ironman.components.reactor', ['reactorState' => 'normal'])
        @include('dashboard.content')

    @else

        @php
            $stateLabels = ['normal' => 'ALL SYSTEMS NORMAL', 'warning' => 'CAUTION', 'critical' => 'ALERT'];
        @endphp

        {{-- SYSTEM STATUS の帯 --}}
        <div class="hud-statusbar" data-state="{{ $systemState }}">
            <span class="hud-statusbar-title">SYSTEM STATUS</span>
            <span class="hud-statusbar-state">{{ $stateLabels[$systemState] }}</span>
            <span class="hud-statusbar-counts">
                @if ($statusCounts['error'] === 0 && $statusCounts['warn'] === 0)
                    すべて正常
                @else
                    @if ($statusCounts['error'] > 0)<span class="text-error">要対応 {{ $statusCounts['error'] }}</span>@endif
                    @if ($statusCounts['warn'] > 0)<span class="text-warn">注意 {{ $statusCounts['warn'] }}</span>@endif
                @endif
            </span>
        </div>

        {{-- アークリアクターと、左右の状態のパネル --}}
        <div class="hud-core">
            {{-- アークリアクターから各パネルへの線（js/dashboard/connectors.js が描く） --}}
            <svg class="hud-connectors" aria-hidden="true"></svg>

            <div class="hud-panels hud-panels-left">
                @foreach (array_slice($statusPanels, 0, 3) as $panel)
                    @include('themes.ironman.components.status-panel', ['panel' => $panel])
                @endforeach
            </div>

            <div class="hud-reactor">
                @include('themes.ironman.components.reactor', ['reactorState' => $systemState, 'reactorBusy' => $systemBusy])
            </div>

            <div class="hud-panels hud-panels-right">
                @foreach (array_slice($statusPanels, 3) as $panel)
                    @include('themes.ironman.components.status-panel', ['panel' => $panel])
                @endforeach
            </div>
        </div>

        {{-- お知らせ（同期は状態のパネルに出すため除く） --}}
        <div class="hud-notices">
            @include('dashboard.notices', ['withSync' => false])
        </div>

        {{-- 種類ごとの入口 --}}
        <div class="hud-links">
            @foreach ($linkGroups as $group)
                <section class="hud-panel hud-link-panel">
                    <header class="hud-panel-head">
                        <span class="hud-panel-code">{{ $group['code'] }}</span>
                        <span class="hud-panel-label">{{ $group['label'] }}</span>
                    </header>
                    <ul>
                        @foreach ($group['links'] as $link)
                            <li><a href="{{ $link['url'] }}">{{ $link['label'] }}</a></li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>


        @if ($syncStatus)
            @include('partials.sync-poll')
        @endif

        <script src="{{ app(\App\Services\ThemeService::class)->assetUrl('js/dashboard/connectors.js') }}" defer></script>
        {{-- パネルの枠を流れる電気（マウスを乗せたときだけ） --}}
        <script src="{{ app(\App\Services\ThemeService::class)->assetUrl('js/dashboard/panel-flow.js') }}" defer></script>

    @endif

@endsection
