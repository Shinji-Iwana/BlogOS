{{--
    AIの費用と残高（全ブログ共通。D-31-04）：OpenAI の残高の見込み、残高・課金の登録、OpenAI の画面と比べるための日ごとの記録
--}}

@extends('layouts.app')

@section('content')

    <h1>AIの費用と残高（OpenAI）</h1>

    <p>
        <a href="{{ route('home') }}">トップページに戻る</a>
        ・<a href="{{ route('ai.settings.edit') }}#prices">API実行の料金表</a>
        ・<a href="{{ route('ai.generations.index') }}">AI実行記録</a>
    </p>

    @include('partials.flash')
    @include('partials.ai-credit-notice')

    <p class="text-muted">
        OpenAI の API は前払い（チャージした残高から引かれる）です。BlogOS は OpenAI の残高を直接取得できないため、
        OpenAI の画面で見た残高と、課金した額をここに登録し、その後のAPI実行の費用の目安を引いて、残高を見込みます。
        見込みが ${{ number_format($status['warning'], 2) }} 以下になったら画面で知らせ、足りなくなる見込みならAPI実行を止めます（${{ number_format($status['reserve'], 2) }} は、見込みのずれに備えて残します）。
    </p>

    <h2>残高の見込み</h2>
    @if ($status['base'] === null)
        <p>まだ残高が登録されていません。下の「OpenAI の画面で見た残高を登録する」から登録してください。</p>
    @else
        <table class="data">
            <tr><th style="text-align:left;">最後に登録した残高</th><td>${{ number_format($status['base']->amount, 2) }}（{{ \App\Support\DisplayTime::format($status['base']->occurred_at) }}）</td></tr>
            <tr><th style="text-align:left;">その後の課金</th><td>＋ ${{ number_format($status['purchases'], 2) }}</td></tr>
            <tr><th style="text-align:left;">その後のAPI実行の費用の目安</th><td>− ${{ number_format($status['spent'], 4) }}</td></tr>
            <tr><th style="text-align:left;">残高の見込み</th><td><strong>${{ number_format($status['balance'], 2) }}</strong></td></tr>
        </table>
        @if ($status['days_since_check'] >= $reconcileDays)
            <p class="text-warn">最後に残高を登録してから{{ $status['days_since_check'] }}日たちました。OpenAI の画面の残高と比べて、ずれていないか確認してください（ずれていたら、実際の残高を登録すると見込みが直ります）。</p>
        @endif
    @endif
    <p class="text-muted">
        今月（日本時間）のAPI実行の費用の目安：${{ number_format($spent, 2) }}
        @if ($budget !== null)（月の支出の上限 ${{ number_format($budget, 2) }}。.env の BLOGOS_AI_MONTHLY_BUDGET_USD）@else（月の支出の上限は設けていません）@endif
    </p>

    <h2>登録する</h2>
    <div style="display:flex; flex-wrap:wrap; gap:16px;">
        <fieldset style="max-width:420px;">
            <legend>OpenAI の画面で見た残高を登録する</legend>
            <p class="text-muted">OpenAI の Billing の画面の Credit balance。登録すると、残高の見込みはこの額から計算し直します（実際との差を記録します）。</p>
            <form method="POST" action="{{ route('ai.credits.balance') }}">
                @csrf
                @include('partials.selected-blog-field')
                <p><label>残高（米ドル） $<input type="number" name="amount" step="0.01" min="0" value="{{ old('amount') }}" style="width:100px;" required></label></p>
                <p><label>見た日時（日本時間。空なら今） <input type="datetime-local" name="occurred_at" value="{{ old('occurred_at') }}"></label></p>
                <p><label>メモ <input type="text" name="note" value="{{ old('note') }}" style="width:260px;"></label></p>
                <button type="submit">残高を登録する</button>
            </form>
        </fieldset>
        <fieldset style="max-width:420px;">
            <legend>課金した額を登録する</legend>
            <p class="text-muted">残高に加わった額（税を除く）。課金の直後に残高も見た場合は、左の「残高を登録する」だけでもかまいません。</p>
            <form method="POST" action="{{ route('ai.credits.purchase') }}">
                @csrf
                @include('partials.selected-blog-field')
                <p><label>課金した額（米ドル） $<input type="number" name="amount" step="0.01" min="0" value="" style="width:100px;" required></label></p>
                <p><label>課金した日時（日本時間。空なら今） <input type="datetime-local" name="occurred_at" value=""></label></p>
                <p><label>メモ <input type="text" name="note" value="" style="width:260px;"></label></p>
                <button type="submit">課金を登録する</button>
            </form>
        </fieldset>
    </div>

    <h2>OpenAI の画面と比べる</h2>
    <p class="text-muted">
        見込みと実際の残高が大きく違うときは、OpenAI の Usage の画面（日付・モデルごとの Requests・Input tokens・Web Searches）と、下の表を比べてください。
        OpenAI の画面の日付は UTC のため、下の表も UTC の日付で集計しています。接続の確認（<code>php artisan ai:check</code>）は、BlogOS の記録に含まれません（1回1セント未満）。
        トークン数が同じで金額が違う場合は料金表の違い、トークン数から違う場合は記録の漏れが考えられます。
    </p>
    <p>
        期間：
        @foreach ([7, 30, 90] as $option)
            @if ($option === $days)<strong>直近{{ $option }}日</strong>@else<a href="{{ route('ai.credits.index', ['days' => $option]) }}">直近{{ $option }}日</a>@endif
        @endforeach
    </p>
    <div style="overflow-x:auto;">
        <table class="data">
            <thead><tr><th>日付（UTC）</th><th>モデル</th><th>Requests</th><th>Input tokens</th><th>うちキャッシュ済み</th><th>Output tokens</th><th>Web Searches</th><th>費用の目安</th></tr></thead>
            <tbody>
                @forelse ($usage as $row)
                    <tr>
                        <td>{{ $row->day }}</td>
                        <td>{{ $row->model }}</td>
                        <td style="text-align:right;">{{ number_format($row->requests) }}</td>
                        <td style="text-align:right;">{{ number_format($row->input_tokens) }}</td>
                        <td style="text-align:right;">{{ number_format($row->cached_input_tokens) }}</td>
                        <td style="text-align:right;">{{ number_format($row->output_tokens) }}</td>
                        <td style="text-align:right;">{{ number_format($row->web_search_calls) }}</td>
                        <td style="text-align:right;">${{ number_format($row->cost, 4) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8">この期間のAPI実行はありません。</td></tr>
                @endforelse
                @if ($usage->isNotEmpty())
                    <tr>
                        <th colspan="2" style="text-align:left;">合計</th>
                        <td style="text-align:right;">{{ number_format($usage->sum('requests')) }}</td>
                        <td style="text-align:right;">{{ number_format($usage->sum('input_tokens')) }}</td>
                        <td style="text-align:right;">{{ number_format($usage->sum('cached_input_tokens')) }}</td>
                        <td style="text-align:right;">{{ number_format($usage->sum('output_tokens')) }}</td>
                        <td style="text-align:right;">{{ number_format($usage->sum('web_search_calls')) }}</td>
                        <td style="text-align:right;">${{ number_format($usage->sum('cost'), 4) }}</td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>

    <h2>登録の記録（新しい順）</h2>
    @if ($history->isEmpty())
        <p>まだ登録はありません。</p>
    @else
        <div style="overflow-x:auto;">
            <table class="data">
                <thead><tr><th>日時</th><th>種類</th><th>金額</th><th>その時点の BlogOS の見込み・差</th><th>メモ</th><th>登録した人</th><th></th></tr></thead>
                <tbody>
                    @foreach ($history as $entry)
                        <tr>
                            <td>{{ \App\Support\DisplayTime::format($entry->occurred_at) }}</td>
                            <td>{{ $entry->type->label() }}</td>
                            <td>{{ $entry->type === \App\Enums\AiCreditEntryType::Purchase ? '＋' : '' }}${{ number_format($entry->amount, 2) }}</td>
                            <td>
                                @if ($entry->estimated_balance !== null)
                                    ${{ number_format($entry->estimated_balance, 2) }}（差 {{ sprintf('%+.2f', $entry->amount - $entry->estimated_balance) }}）
                                @else - @endif
                            </td>
                            <td>{{ $entry->note }}</td>
                            <td>{{ $entry->creator?->name ?? '-' }}</td>
                            <td>
                                <form method="POST" action="{{ route('ai.credits.destroy', ['id' => $entry->id]) }}" onsubmit="return confirm('この記録を削除しますか？（登録の誤りを直すとき）');">
                                    @csrf
                                    @method('DELETE')
                                    @include('partials.selected-blog-field')
                                    <button type="submit">削除</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

@endsection
