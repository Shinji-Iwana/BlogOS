{{--
    定期実行の確認・時刻の変更・今すぐ実行（D-44）
--}}

@extends('layouts.app')

@section('content')

    @php
        use App\Support\ScheduledTasks;
    @endphp

    <h1>定期実行</h1>

    @include('partials.flash')

    <p class="text-muted">
        サーバーの cron から毎分起動し、決めた時刻（日本時間）に実行します。
        いつ・有効の設定は、各行の「設定」か、メニューの「設定 → 定期実行」で変えます。変えると、次の定期実行から反映されます（サーバーでの作業は要りません）。
        実行するたびに、開始・終了・かかった時間・処理件数などを記録します。
        同期と Google の取得は Queue で処理するため、Queue の処理が終わった時点を「終了」にします。
    </p>

    <table class="data" style="max-width:1200px;">
        {{-- 次の実行・前回は、設定のポップアップに出す（D-63-06・D-63-07） --}}
        <thead><tr><th>内容</th><th>設定</th><th></th></tr></thead>
        <tbody>
            @foreach ($tasks as $key => $task)
                <tr>
                    <td style="max-width:420px;">
                        {{-- 名前は、メニューとポップアップの題名と同じ（D-63-05） --}}
                        <strong>{{ ScheduledTasks::menuLabel($key) }}</strong><br>
                        <span class="text-muted" style="font-size:90%;">{{ $task['description'] }}</span>
                        @foreach ($warnings[$key] ?? [] as $warning)
                            <br><span class="text-error" style="font-size:90%;">順番の注意：{{ $warning }}</span>
                        @endforeach
                    </td>
                    <td>
                        {{-- いつ・有効は、メニューの「設定 → 定期実行」と同じポップアップで変える（D-63-04） --}}
                        @if (in_array($key, ScheduledTasks::MENU, true))
                            <button type="button" class="btn-secondary" data-modal-open="{{ ScheduledTasks::modalId($key) }}">設定</button>
                        @endif
                    </td>
                    <td>
                        @if ($task['manual'])
                            <form method="POST" action="{{ route('scheduled-tasks.run', ['key' => $key]) }}" onsubmit="return confirm('「{{ ScheduledTasks::menuLabel($key) }}」を今すぐ実行しますか？');">
                                @csrf
                                <button class="btn-secondary" type="submit">今すぐ実行</button>
                            </form>
                        @else
                            <span class="text-muted" style="font-size:90%;">（料金がかかるため、ボタンはありません）</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- 実行の記録は、画面「定期実行の履歴」（メニューの「履歴 → 定期実行」）に移した（D-63-08） --}}
    <p class="text-muted">実行の記録は、<a href="{{ route('scheduled-tasks.runs') }}">定期実行の履歴</a>（メニューの「履歴 → 定期実行」）で見られます。</p>

@endsection
