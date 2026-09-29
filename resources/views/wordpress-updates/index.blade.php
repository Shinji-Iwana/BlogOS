{{--
    WordPress 本体・プラグイン・テーマの更新（D-38）
--}}

@extends('layouts.app')

@section('content')

    <h1>WordPress の更新（{{ $blog->display_name }}）</h1>

    <p><a href="{{ route('home') }}">トップページに戻る</a>・<a href="{{ $adminUrl }}" target="_blank" rel="noopener noreferrer">WordPress の更新の画面を開く</a></p>

    @include('partials.flash')

    <p style="color:#666;">
        WordPress 本体・プラグイン・テーマのバージョンを、WordPress.org の最新のバージョンと比べます（毎日 04:45 に自動で確認します）。
        更新は BlogOS からは行いません。WordPress の管理画面の「更新」で行ってください（更新の前に、バックアップがあることを確認してください）。
        最後に確認した日時：{{ $checkedAt ? \App\Support\DisplayTime::format($checkedAt) : '未確認' }}
    </p>

    <form method="POST" action="{{ route('wordpress-updates.check') }}" style="margin-bottom:8px;">
        @csrf
        @include('partials.selected-blog-field')
        <button type="submit">今すぐ確認する</button>
    </form>

    @if ($components->isEmpty())
        <p>まだ確認していません。</p>
    @else
        <div style="overflow-x:auto;">
            <table border="1" cellpadding="4" cellspacing="0">
                <thead><tr><th>種類</th><th>名前</th><th>状態</th><th>インストール中</th><th>最新</th><th>必要な PHP</th><th>お知らせ</th></tr></thead>
                <tbody>
                    @foreach ($components as $component)
                        <tr>
                            <td>{{ \App\Models\WordPressComponent::TYPE_LABELS[$component->type] ?? $component->type }}</td>
                            <td>{{ $component->name }}<br><span style="color:#666;">{{ $component->slug }}</span></td>
                            <td>{{ ['active' => '有効', 'inactive' => '無効'][$component->status] ?? '' }}</td>
                            <td>{{ $component->installed_version ?? '不明' }}</td>
                            <td>{{ $component->latest_version ?? '-' }}</td>
                            <td>{{ $component->requires_php ?? '-' }}</td>
                            <td style="max-width:360px;">
                                @if ($component->update_available)
                                    <strong style="color:#b00;">更新があります。</strong>
                                @endif
                                @if ($component->wporg_state === 'closed')
                                    <span style="color:#b00;">WordPress.org で公開停止になっています（{{ $component->wporg_note }}）。今後、更新（安全上の修正を含む）は出ません。代わりの方法を検討してください。</span>
                                @elseif ($component->wporg_state === 'not_found')
                                    <span style="color:#666;">WordPress.org にありません（自作・有料のものなど）。更新は、配布元で確認してください。</span>
                                @endif
                                @if ($component->type === 'plugin' && $component->status === 'inactive')
                                    <span style="color:#b60;">無効のまま残っています。使わない場合は削除してください。</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

@endsection
