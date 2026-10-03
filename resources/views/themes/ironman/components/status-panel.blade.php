{{--
    状態のパネル（ironman のトップページ。D-49-07）

    受け取る値：$panel（App\Services\Dashboard\DashboardStatusService::panels の1つ）
    state：ok（青）／warn（金）／error（赤）／none（判定しない）
    同期のパネルには、「今すぐ同期」のボタンを置き、値に id="sync-state" を付ける（partials/sync-poll が実行中の表示を更新する）。
--}}
@php($stateText = ['ok' => 'NORMAL', 'warn' => 'CAUTION', 'error' => 'ALERT', 'none' => 'STANDBY'][$panel['state']])
<section class="hud-panel hud-status-panel" data-state="{{ $panel['state'] }}">
    <header class="hud-panel-head">
        <span class="hud-panel-code">{{ $panel['code'] }}</span>
        <span class="hud-panel-label">{{ $panel['label'] }}</span>
        <span class="hud-panel-state"><span class="hud-dot"></span>{{ $stateText }}</span>
    </header>

    <p class="hud-panel-value" @if ($panel['key'] === 'sync') id="sync-state" @endif>{{ $panel['value'] }}</p>

    @foreach ($panel['lines'] as $line)
        <p class="hud-panel-line">{{ $line }}</p>
    @endforeach

    <footer class="hud-panel-foot">
        @if ($panel['url'])
            <a href="{{ $panel['url'] }}">{{ $panel['link'] }}</a>
        @endif
        @if ($panel['key'] === 'sync' && $syncStatus)
            @include('partials.sync-run-form')
        @endif
    </footer>
</section>
