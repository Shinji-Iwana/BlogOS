{{--
    アクティビティログ（D-77。メニューの「履歴 → アクティビティログ」）

    BlogOS に残っている作業の記録の日時を、新しい順に並べる（App\Services\Activity\ActivityLogService）。
    出すのは、選択中のブログの作業と、ブログに関係のない BlogOS の作業（定期実行・ログイン・OpenAI など）。
    始めと終わりがある作業は、開始と終了を別の行にする。取得条件（期間。日本時間の日付。初めは直近7日間）で取得し、表示条件（種類）で絞り込む。
    種類の候補は、同じ期間に記録がある種類だけ（選んで0件にならないように）。
--}}

@extends('layouts.app')

@section('content')

    <h1>アクティビティログ @include('partials.tip', ['tip' => "BlogOS に残っている作業の記録（同期・定期実行・AI の実行・編集案・反映など）の日時を、新しい順に並べます。\n始めと終わりがある作業は、開始と終了を別の行に出します。\n記録を残していない操作（テーマの切替・設定の保存など）、削除した記録、古い記録の削除で消えた記録は出ません。\n更新の日時だけを持つ記録（教材など）は、最後の1回だけを出します。\n選択中のブログの作業と、ブログに関係のない BlogOS の作業（定期実行・ログイン・OpenAI など）を出します。"])</h1>

    <p><a href="{{ route('home') }}">トップページに戻る</a></p>

    {{-- 取得条件と表示条件は、横に並べる（狭い画面では縦。D-77-09） --}}
    <div class="panel-columns">

    {{-- 取得条件：期間を変えると、記録を取得し直す（種類の候補も変わる）。選んでいた種類は引き継ぐ（記録がなくなったときは「全て」に戻す） --}}
    <section class="panel">
    <h2 data-code="QUERY">取得条件</h2>
    <form method="GET" action="{{ route('activities.index') }}">
        <input type="hidden" name="kind" value="{{ $filters['kind'] }}">
        <table class="form-grid">
            <tbody>
                <tr>
                    <th><label for="activity-from">期間</label> @include('partials.tip', ['tip' => '日本時間の日付です。初めは、今日を含めた直近7日間です。'])</th>
                    <td>
                        <input type="date" name="from" id="activity-from" value="{{ $filters['from'] }}">
                        〜
                        <input type="date" name="to" aria-label="期間の終わり" value="{{ $filters['to'] }}">
                    </td>
                </tr>
            </tbody>
        </table>
        <p class="filter-actions"><button class="btn-secondary" type="submit">取得</button></p>
    </form>
    </section>

    {{-- 表示条件：取得した記録を、種類で絞り込む（候補は、取得した期間に記録がある種類だけ） --}}
    <section class="panel">
    <h2 data-code="FILTER">表示条件</h2>
    <form method="GET" action="{{ route('activities.index') }}">
        <input type="hidden" name="from" value="{{ $filters['from'] }}">
        <input type="hidden" name="to" value="{{ $filters['to'] }}">
        <table class="form-grid">
            <tbody>
                <tr>
                    <th><label for="activity-kind">種類</label> @include('partials.tip', ['tip' => '候補は、取得条件の期間に記録がある種類だけです。'])</th>
                    <td>
                        <select name="kind" id="activity-kind">
                            <option value="">全て</option>
                            @foreach ($kinds as $kind => $kindLabel)
                                <option value="{{ $kind }}" @selected($filters['kind'] === $kind)>{{ $kindLabel }}</option>
                            @endforeach
                        </select>
                    </td>
                </tr>
            </tbody>
        </table>
        <p class="filter-actions"><button class="btn-secondary" type="submit">絞り込む</button></p>
    </form>
    </section>

    </div>

    <section class="panel">
    <h2 data-code="ACTIVITY">作業の記録</h2>
    {{-- どの条件の一覧か --}}
    <p>期間：{{ $filters['from'] }} 〜 {{ $filters['to'] }}（日本時間）／種類：{{ \App\Services\Activity\ActivityLogService::KINDS[$filters['kind']] ?? '全て' }}</p>
    @include('partials.history-count', ['paginator' => $activities])

    <div style="overflow-x:auto;">
        <table class="data">
            <thead>
                <tr><th>日時</th><th>種類</th><th>内容</th></tr>
            </thead>
            <tbody>
                @forelse ($activities as $activity)
                    <tr>
                        <td style="white-space:nowrap;">{{ \App\Support\DisplayTime::format($activity->at) }}</td>
                        <td style="white-space:nowrap;">{{ $activity->kind_label }}</td>
                        <td>
                            {{ $activity->text }}
                            @if ($activity->url)
                                <br><a href="{{ $activity->url }}">詳しく見る</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3">ありません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $activities->links() }}
    </section>

@endsection
