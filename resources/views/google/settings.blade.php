{{--
    Google連携の設定（D-21-01、D-21-07）

    Googleアカウントの接続と、選択中のブログの対応先（GA4のプロパティ・Search Consoleのサイト・AdSenseのアカウント）。
    トークンは画面に表示しない。
--}}

@extends('layouts.app')

@section('content')

    <h1>Google連携（{{ $blog->display_name }}）</h1>

    <p>
        <a href="{{ route('home') }}">トップページに戻る</a>
        ・<a href="{{ route('analytics.index') }}">分析</a>
    </p>

    @include('partials.flash')

    {{-- Googleアカウント --}}
    <h2>Googleアカウント</h2>

    @if (! $configured)
        <p style="color:#b00;">GoogleのOAuthクライアントが設定されていません（.env の GOOGLE_OAUTH_CLIENT_ID・GOOGLE_OAUTH_CLIENT_SECRET・GOOGLE_OAUTH_REDIRECT_URI）。</p>
    @endif

    <table border="1" cellpadding="4" cellspacing="0">
        <thead><tr><th>アカウント</th><th>状態</th><th>最後の更新</th><th></th></tr></thead>
        <tbody>
            @forelse ($accounts as $account)
                <tr>
                    <td>{{ $account->email }}</td>
                    <td>
                        @if ($account->last_error)
                            <strong style="color:#b00;">{{ $account->last_error }}</strong>
                        @else
                            接続済み
                        @endif
                    </td>
                    <td>{{ \App\Support\DisplayTime::format($account->last_refreshed_at) }}</td>
                    <td>
                        <a href="{{ route('google.settings', ['candidates' => $account->id]) }}">対応先の候補を読み込む</a>
                        <form method="POST" action="{{ route('google.accounts.destroy', ['id' => $account->id]) }}" style="display:inline;" onsubmit="return confirm('接続を解除しますか？このアカウントを使う対応先の設定も解除されます（取得済みのデータは残ります）。');">
                            @csrf
                            @method('DELETE')
                            @include('partials.selected-blog-field')
                            <button type="submit">接続を解除</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4">接続されていません。</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($configured)
        <p><a href="{{ route('google.oauth.redirect') }}">Googleアカウントを接続する（または接続し直す）</a></p>
    @endif

    {{-- 対応先 --}}
    <h2>このブログの対応先</h2>

    @if ($candidateAccount)
        <p>{{ $candidateAccount->email }} で見られる対応先を、候補として表示しています。</p>
    @elseif ($accounts->isNotEmpty())
        <p>候補から選ぶには、上の「対応先の候補を読み込む」を押してください（Google APIを呼びます）。候補を読み込まずに、値を直接入力することもできます。</p>
    @endif

    @foreach ($services as $service)
        @php
            $property = $properties[$service->value] ?? null;
            $items = $candidates[$service->value]['items'] ?? [];
            $candidateError = $candidates[$service->value]['error'] ?? null;
        @endphp

        <section style="border:1px solid #ccc; padding:10px; margin-bottom:10px;">
            <h3 style="margin-top:0;">{{ $service->label() }}</h3>

            <p>
                現在：
                @if ($property)
                    <strong>{{ $property->display_name ?: $property->resource_name }}</strong>（{{ $property->resource_name }}{{ $property->adsense_domain ? '、ドメイン ' . $property->adsense_domain : '' }}、{{ $property->account?->email }}）
                @else
                    未設定
                @endif
            </p>

            @if ($candidateError)
                <p style="color:#b00;">候補を取得できませんでした：{{ $candidateError }}（GCPのプロジェクトで、このAPIが有効になっているか確認してください）</p>
            @endif

            @if ($accounts->isNotEmpty())
                <form method="POST" action="{{ route('google.properties.update', ['service' => $service->value]) }}">
                    @csrf
                    @method('PUT')
                    @include('partials.selected-blog-field')

                    <label>アカウント
                        <select name="google_account_id">
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}" @selected(($candidateAccount?->id ?? $property?->google_account_id) === $account->id)>{{ $account->email }}</option>
                            @endforeach
                        </select>
                    </label>

                    @if ($items !== [])
                        <label>候補
                            <select onchange="const o = this.selectedOptions[0]; this.form.resource_name.value = o.value; this.form.display_name.value = o.dataset.name || ''; if (this.form.adsense_domain && o.dataset.domain) { this.form.adsense_domain.value = o.dataset.domain; }">
                                <option value="">（選ぶ）</option>
                                @foreach ($items as $item)
                                    @php
                                        $domains = $item['domains'] ?? [];
                                        $domain = collect($domains)->first(fn ($d) => $d === $blogHost) ?? ($domains[0] ?? '');
                                    @endphp
                                    <option value="{{ $item['resource_name'] }}" data-name="{{ $item['display_name'] }}" data-domain="{{ $domain }}">{{ $item['display_name'] }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endif

                    <br>
                    <label>対応先
                        <input type="text" name="resource_name" value="{{ old('resource_name', $property?->resource_name) }}" style="width:260px;"
                               placeholder="{{ ['ga4' => 'properties/123456789', 'search_console' => 'sc-domain:example.com', 'adsense' => 'accounts/pub-0000000000000000'][$service->value] }}">
                    </label>
                    <input type="hidden" name="display_name" value="{{ $property?->display_name }}">

                    @if ($service === \App\Enums\GoogleService::Adsense)
                        <label>集計するドメイン
                            <input type="text" name="adsense_domain" value="{{ old('adsense_domain', $property?->adsense_domain ?? $blogHost) }}" style="width:200px;">
                        </label>
                    @endif

                    <button type="submit">保存</button>
                    @if ($property)
                        <button type="submit" name="remove" value="1" onclick="return confirm('対応先を解除しますか？（取得済みのデータは残ります）');">解除</button>
                    @endif
                </form>
            @endif

            @php $run = $latestRuns[$service->value] ?? null; @endphp
            @if ($run)
                <p style="color:#666;">
                    最後の取得：{{ \App\Support\DisplayTime::format($run->started_at) }}・{{ $run->status->label() }}・{{ $run->date_from?->toDateString() }}〜{{ $run->date_to?->toDateString() }}・{{ $run->row_count }}行
                    @if ($run->message)・{{ $run->message }}@endif
                </p>
            @endif
        </section>
    @endforeach

    {{-- 取得 --}}
    <h2>取得</h2>
    <p>毎日、日本時間5:00に取得します（直近の数日は毎回取得し直します）。初めての取得では、約16か月前から取得します。</p>
    <form method="POST" action="{{ route('google.fetch') }}">
        @csrf
        @include('partials.selected-blog-field')
        <button type="submit" @disabled($queued || $properties->isEmpty())>今すぐ取得する</button>
        @if ($queued)（開始待ちです）@endif
    </form>

    <h2>取得の記録（新しい順、最大30件）</h2>
    <div style="overflow-x:auto;">
        <table border="1" cellpadding="4" cellspacing="0">
            <thead><tr><th>日時</th><th>サービス</th><th>契機</th><th>結果</th><th>期間</th><th>行数</th><th>メッセージ</th></tr></thead>
            <tbody>
                @forelse ($recentRuns as $run)
                    <tr>
                        <td>{{ \App\Support\DisplayTime::format($run->started_at) }}</td>
                        <td>{{ $run->service->label() }}</td>
                        <td>{{ $run->trigger->label() }}</td>
                        <td>{{ $run->status->label() }}</td>
                        <td>{{ $run->date_from?->toDateString() }}〜{{ $run->date_to?->toDateString() }}</td>
                        <td>{{ $run->row_count }}</td>
                        <td>
                            {{ $run->message }}
                            @if ($run->error_body)
                                <details><summary>応答本文（HTTP {{ $run->error_status }}）</summary><pre style="white-space:pre-wrap; word-break:break-all; max-height:200px; overflow:auto;">{{ $run->error_body }}</pre></details>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">ありません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <h2>保存している行数</h2>
    <ul>
        @foreach ($counts as $table => $count)
            <li>{{ $table }}：{{ number_format($count) }}</li>
        @endforeach
    </ul>

@endsection
