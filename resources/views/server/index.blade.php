{{--
    XServer情報（メニューの「情報 → XServer情報」。D-71）

    BlogOS が動いているサーバーの今の状態（サーバーの基本情報・DB・古い記録の削除・キュー・ファイルの容量）を、開いたときに読み取って出す。
    読み取るだけで、DB への書き込み・削除はしない。全ブログ共通のため、ブログを選んでいなくても開ける。
--}}

@extends('layouts.app')

@section('content')

    @php
        $size = function (?int $bytes): string {
            if ($bytes === null) {
                return '読み取れない';
            }
            foreach (['GB' => 1024 ** 3, 'MB' => 1024 ** 2, 'KB' => 1024] as $unit => $base) {
                if ($bytes >= $base) {
                    return number_format($bytes / $base, 1) . ' ' . $unit;
                }
            }

            return number_format($bytes) . ' B';
        };
    @endphp

    <h1>XServer情報 @include('partials.tip', ['tip' => "BlogOS が動いているサーバーの、今の状態です（この画面を開いたときに読み取ります。料金はかかりません）。\n読み取るだけで、DB への書き込み・削除はしません。契約上のディスクの残りと、CPU・メモリーの使用量は読み取れないため、XServer のサーバーパネルで確認してください。"])</h1>

    <p>
        <a href="{{ route('home') }}">トップページに戻る</a>
        @if (config('blogos.server.panel_url'))
            ／ <a href="{{ config('blogos.server.panel_url') }}" target="_blank" rel="noopener">XServer のサーバーパネルを開く</a>
        @endif
    </p>

    @include('partials.history-last', ['label' => '最後の読み取り', 'at' => now(), 'result' => '成功'])

    <section class="panel">
    <h2>サーバーの基本情報</h2>
    <table class="data">
        <tbody>
            <tr><th>サーバー番号 @include('partials.tip', ['tip' => 'ホスト名の先頭（例：sv12345.xserver.jp の sv12345）から読みます。'])</th><td>{{ $machine['server_number'] ?? '読み取れない' }}</td></tr>
            <tr><th>ホスト名</th><td>{{ $machine['hostname'] ?? '読み取れない' }}</td></tr>
            <tr><th>IPアドレス</th><td>{{ $machine['ip'] ?? '読み取れない' }}</td></tr>
            <tr><th>PHP</th><td>{{ $machine['php_version'] }}</td></tr>
        </tbody>
    </table>
    </section>

    {{-- サーバー環境の点検（D-88）。知らせるのは「すぐ対応」と「計画して対応」だけ（ただの新しい版は知らせない） --}}
    <section class="panel" id="environment">
    <h2>サーバー環境の点検 @include('partials.tip', ['tip' => "毎日、定期実行で確かめます（すぐ確かめるときは、メニューの「設定 → 即時実行 → サーバー環境の点検」）。料金はかかりません。\nすぐ対応：セキュリティの修正が入っていない、または BlogOS の部品に脆弱性がある。\n計画して対応：サポート（セキュリティの修正）の終わりまで6か月以内。\n新しい版が出ただけでは知らせません（同じ系列のセキュリティの修正は入れ、大きな版はサポートの終わりの前に計画して上げる）。\nPHP の Web 側の版は、BlogOS のトップページかこの画面を開いたときに記録した版です（WordPress も同じ PHP で動きます）。"])</h2>
    @include('partials.history-last', ['label' => '最後の点検', 'at' => $environmentRun?->started_at ?? $environment->max('checked_at'), 'result' => $environmentRun?->statusLabel() ?? ($environment->isNotEmpty() ? '成功' : null)])
    @if ($environment->isEmpty())
        <p>まだ点検していません。</p>
    @else
        <div style="overflow-x:auto;">
            <table class="data">
                <thead><tr><th>対象</th><th>今の版</th><th>同じ系列の最新</th><th>サポートの終わり</th><th>状態</th><th>すること・説明</th></tr></thead>
                <tbody>
                    @foreach ($environment as $check)
                        <tr>
                            <td>{{ $check->label }}</td>
                            <td>{{ $check->current_version ?? '-' }}</td>
                            <td>{{ $check->latest_version ?? '-' }}</td>
                            <td style="white-space:nowrap;">{{ $check->support_until?->format('Y-m-d') ?? '-' }}</td>
                            <td style="white-space:nowrap;">
                                <span class="{{ ['security' => 'text-error', 'support' => 'text-warn', 'unknown' => 'text-warn', 'info' => 'text-muted'][$check->level] ?? '' }}">{{ $check->levelLabel() }}</span>
                            </td>
                            <td style="max-width:420px;">
                                {!! nl2br(e($check->message ?? '')) !!}
                                @if (! empty($check->details['advisories']))
                                    <ul>
                                        @foreach ($check->details['advisories'] as $advisory)
                                            <li>{{ $advisory['package'] }}（{{ $advisory['severity'] ?? '不明' }}）：{{ $advisory['title'] }}@if ($advisory['cve']) {{ $advisory['cve'] }}@endif</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    </section>

    {{-- 月1回のバックアップ（D-89）。サーバーの中に作り、ここからパソコンへダウンロードする --}}
    <section class="panel" id="backup">
    <h2>バックアップ @include('partials.tip', ['tip' => "毎月、定期実行でサーバーの中（BlogOS の storage。ブラウザから直接は開けない場所）に作り、新しいものから" . config('blogos.backup.keep') . "回分を残します（すぐ作るときは、メニューの「設定 → 即時実行 → バックアップの作成」）。料金はかかりません。\nXServer に何かあったときのために、作ったらここからパソコンにダウンロードしてください（作ってから" . config('blogos.backup.download_days') . "日たってもダウンロードしていないと、お知らせが出ます）。\n秘密の情報（BlogOS の .env・WordPress の wp-config.php・Basic 認証のパスワードのファイル）は、パスワード付きの zip（AES-256）です。7-Zip などで、.env の BLOGOS_BACKUP_PASSPHRASE に決めたパスワード（パスワード管理アプリの控え）を入れて開きます。\n戻す手順は docs/BLOGOS_DEPLOYMENT.md の「バックアップから戻す」にあります。"])</h2>
    @include('partials.history-last', ['label' => '最後の作成', 'at' => $backupRun?->started_at ?? $backups->max('started_at'), 'result' => $backupRun?->statusLabel() ?? $backups->first()?->statusLabel()])
    <p class="text-muted">次の作成：{{ $backupNextRunAt ? \App\Support\DisplayTime::format($backupNextRunAt, 'Y-m-d H:i') : '定期実行が無効です' }}</p>
    @if ($backups->isEmpty())
        <p>まだ作っていません。</p>
    @else
        <div style="overflow-x:auto;">
            <table class="data">
                <thead><tr><th>作った日時</th><th>状態</th><th>ファイル（押すとダウンロード）</th><th>合計</th></tr></thead>
                <tbody>
                    @foreach ($backups as $backup)
                        <tr>
                            <td style="white-space:nowrap;">{{ \App\Support\DisplayTime::format($backup->finished_at ?? $backup->started_at, 'Y-m-d H:i') }}</td>
                            <td style="white-space:nowrap;">
                                <span class="{{ [\App\Models\Backup::FAILED => 'text-error', \App\Models\Backup::RUNNING => 'text-warn'][$backup->status] ?? '' }}">{{ $backup->statusLabel() }}</span>
                                @if ($backup->status === \App\Models\Backup::SUCCEEDED)
                                    <br><span class="{{ $backup->downloaded_at ? 'text-muted' : 'text-warn' }}">{{ $backup->downloaded_at ? 'ダウンロード済み' : '未ダウンロードあり' }}</span>
                                @endif
                            </td>
                            <td>
                                @if ($backup->status === \App\Models\Backup::SUCCEEDED)
                                    <ul>
                                        @foreach ($backup->files as $file)
                                            <li>
                                                <a href="{{ route('server.backups.download', ['backup' => $backup->id, 'key' => $file['key']]) }}">{{ $file['label'] }}</a>
                                                （{{ $size($file['bytes']) }}）
                                                @if ($file['downloaded_at'])<span class="text-muted">ダウンロード済み</span>@endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                                @if ($backup->message)
                                    <span class="{{ $backup->status === \App\Models\Backup::FAILED ? 'text-error' : 'text-muted' }}">{!! nl2br(e($backup->message)) !!}</span>
                                @endif
                            </td>
                            <td style="text-align:right; white-space:nowrap;">{{ $backup->total_bytes !== null ? $size($backup->total_bytes) : '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    </section>

    <section class="panel">
    <h2>DB（BlogOS）</h2>
    @include('server.database', ['database' => $database, 'wordpressTables' => $wordpressTables, 'breakdown' => $breakdown, 'blogosDb' => true, 'size' => $size])
    </section>

    {{-- WordPress の DB（BlogOS の DB のユーザーにアクセス権がある DB。読むだけ。D-71-04） --}}
    @forelse ($others as $other)
        <section class="panel">
        <h2>
            DB（{{ $other['blog'] ? 'WordPress：' . $other['blog']->display_name : ($other['home'] ? 'WordPress' : 'ほかの DB') }}）
            @if ($other['blog'] && $other['blog']->id === ($selectedBlog?->id))
                <span class="text-muted">選択中のブログ</span>
            @endif
        </h2>
        @if ($other['home'])
            <p class="text-muted">サイトの URL：{{ $other['home'] }}{{ $other['blog'] ? '' : '（BlogOS に登録していないサイト）' }}</p>
        @endif
        {{-- @include は画面の変数を引き継ぐため、BlogOS の DB のテーブルの情報（リンク・削除済みの件数）は空にして渡す。テーブルは WordPress の DB の画面へつなぐ（D-81） --}}
        @include('server.database', ['database' => $other['database'], 'wordpressTables' => [], 'wordpressDb' => true, 'breakdown' => null, 'size' => $size])
        </section>
    @empty
        <section class="panel">
        <h2>DB（WordPress）</h2>
        <p class="text-muted">
            BlogOS から見える WordPress の DB はありません。出すには、XServer のサーバーパネル（データベース → MySQL設定）で、WordPress の DB の「ユーザー設定」から、アクセス権所有ユーザーに BlogOS の DB のユーザーを足してください。
            BlogOS は読むだけで、WordPress の DB を書き換えません。
        </p>
        </section>
    @endforelse

    <section class="panel">
    <h2>古い記録の削除 @include('partials.tip', ['tip' => "定期実行の「古い記録の削除」が消す記録です。「今削除の対象」が 0 なら、削除は働いています（次の定期実行で消える分だけが残ります）。\n実行の記録は、メニューの「履歴 → 定期実行履歴」にあります。"])</h2>
    <div style="overflow-x:auto;">
    <table class="data">
        <thead><tr><th>記録</th><th>削除する条件</th><th>件数</th><th>一番古い記録</th><th>今削除の対象</th></tr></thead>
        <tbody>
            @foreach ($prunable as $item)
                <tr>
                    <td>{{ $item['label'] }}<br><span class="text-muted">{{ $item['table'] }}</span></td>
                    <td>{{ $item['rule'] }}</td>
                    <td style="text-align:right;">{{ number_format($item['rows']) }}</td>
                    <td>{{ $item['oldest'] ? \App\Support\DisplayTime::format($item['oldest'], 'Y-m-d') : '-' }}</td>
                    <td style="text-align:right;">{{ number_format($item['prunable']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    </div>
    </section>

    <section class="panel">
    <h2>キュー @include('partials.tip', ['tip' => '待っている処理が減らない・一番古いものが古いままの場合は、queue:work が止まっている可能性があります。'])</h2>
    <table class="data">
        <tbody>
            <tr>
                <th>待っている処理</th>
                <td>
                    {{ number_format($queue['pending']) }}件
                    @if ($queue['by_queue'] !== [])
                        （@foreach ($queue['by_queue'] as $name => $total){{ $loop->first ? '' : '・' }}{{ $name }} {{ number_format($total) }}件@endforeach）
                    @endif
                    @if ($queue['oldest_pending'])
                        <br><span class="text-muted">一番古いもの：{{ \App\Support\DisplayTime::format(\Illuminate\Support\Carbon::createFromTimestamp($queue['oldest_pending'])) }}</span>
                    @endif
                </td>
            </tr>
            <tr><th>処理中</th><td>{{ number_format($queue['reserved']) }}件</td></tr>
            <tr>
                <th>失敗した処理</th>
                <td>
                    {{ number_format($queue['failed']) }}件
                    @if ($queue['last_failed'])
                        <span class="text-muted">（最後：{{ \App\Support\DisplayTime::format($queue['last_failed']) }}）</span>
                    @endif
                </td>
            </tr>
        </tbody>
    </table>
    </section>

    <section class="panel">
    <h2>ファイル @include('partials.tip', ['tip' => 'BlogOS のフォルダの中だけです。'])</h2>
    <table class="data">
        <thead><tr><th>フォルダ</th><th>容量</th><th>ファイルの数</th></tr></thead>
        <tbody>
            @foreach ($files as $item)
                <tr>
                    <td>{{ $item['label'] }}<br><span class="text-muted">{{ $item['path'] }}</span></td>
                    <td style="text-align:right;">{{ $size($item['bytes']) }}</td>
                    <td style="text-align:right;">{{ $item['files'] === null ? '-' : number_format($item['files']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    </section>

@endsection
