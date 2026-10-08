{{--
    WordPress情報：WordPress 本体・プラグイン・テーマの更新（D-38。メニューの「情報 → WordPress情報」。D-63-11）
--}}

@extends('layouts.app')

@section('content')

    <h1>WordPress情報 @include('partials.tip', ['tip' => "選択中のブログの WordPress 本体・プラグイン・テーマのバージョンを、WordPress.org の最新のバージョンと比べます（毎日、定期実行で自動で確認します。すぐ確認するときは、メニューの「設定 → 即時実行 → WordPressの更新確認」）。\n更新は BlogOS からは行いません。WordPress の管理画面の「更新」で行ってください（更新の前に、バックアップがあることを確認してください）。"])</h1>

    <p><a href="{{ route('home') }}">トップページに戻る</a> ／ <a href="{{ $adminUrl }}" target="_blank" rel="noopener noreferrer">WordPress の更新の画面を開く</a></p>

    @include('partials.flash')

    {{-- 最後の確認：定期実行・即時実行の記録（なければ、確認した日時） --}}
    @include('partials.history-last', ['label' => '最後の確認', 'at' => $latestRun?->started_at ?? $checkedAt, 'result' => $latestRun?->statusLabel() ?? ($checkedAt ? '成功' : null)])

    {{-- 本体・プラグイン・テーマを、それぞれのパネルに分ける --}}
    @foreach (\App\Models\WordPressComponent::TYPE_LABELS as $type => $typeLabel)
    @php
        $typeComponents = $components->where('type', $type);
    @endphp
    <section class="panel">
    {{-- パネルの題名の英字の札（data-code）は、ironman だけで出す --}}
    <h2 data-code="{{ ['core' => 'WORDPRESS', 'plugin' => 'PLUGIN', 'theme' => 'THEME'][$type] ?? strtoupper($type) }}">{{ $typeLabel }}</h2>
    @if ($typeComponents->isEmpty())
        <p>{{ $components->isEmpty() ? 'まだ確認していません。' : 'ありません。' }}</p>
    @else
        <p>表示件数：{{ $typeComponents->count() }}件</p>
        <div style="overflow-x:auto;">
            <table class="data">
                <thead><tr><th>名前</th><th>状態</th><th>インストール中</th><th>最新</th><th>必要な PHP</th><th>お知らせ</th></tr></thead>
                <tbody>
                    @foreach ($typeComponents as $component)
                        <tr>
                            <td>{{ $component->name }}<br><span class="text-muted">{{ $component->slug }}</span></td>
                            <td>{{ ['active' => '有効', 'inactive' => '無効'][$component->status] ?? '' }}</td>
                            <td>{{ $component->installed_version ?? '不明' }}</td>
                            <td>{{ $component->latest_version ?? '-' }}</td>
                            <td>{{ $component->requires_php ?? '-' }}</td>
                            <td style="max-width:360px;">
                                @if ($component->update_available)
                                    <strong class="text-error">更新があります。</strong>
                                @endif
                                @if ($component->wporg_state === 'closed')
                                    <span class="text-error">WordPress.org で公開停止になっています（{{ $component->wporg_note }}）。今後、更新（安全上の修正を含む）は出ません。代わりの方法を検討してください。</span>
                                @elseif ($component->wporg_state === 'not_found')
                                    <span class="text-muted">WordPress.org にありません（自作・有料のものなど）。更新は、配布元で確認してください。</span>
                                @endif
                                @if ($component->type === 'plugin' && $component->status === 'inactive')
                                    <span class="text-warn">無効のまま残っています。使わない場合は削除してください。</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    </section>
    @endforeach

@endsection
