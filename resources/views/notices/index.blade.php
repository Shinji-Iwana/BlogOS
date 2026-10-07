{{--
    お知らせ（notices。ヘッダーのお知らせのボタン・メニューの「履歴 → お知らせ」。D-74）

    要対応・注意のお知らせを、新しい順に出す。チェックを入れて「確認」を押すと確認済みにする（一覧からは消さない）。
    内容が変わって新しいお知らせを記録した前のものには「変動」、問題がなくなったものには「解消」を添える。
    行は、お知らせの色（要対応：赤・注意：金）で囲む。
--}}

@extends('layouts.app')

@section('content')

    <h1>お知らせ @include('partials.tip', ['tip' => "要対応・注意のお知らせの記録です（トップページを開いたときと、定期実行が終わったときに更新します）。\nチェックを入れて「確認」を押すと、確認済みにします（一覧からは消えません）。\n内容（件数など）が変わったときは、新しいお知らせとして記録し、前のお知らせに「変動」を添えます。問題がなくなったときは「解消」を添えます。\nヘッダーのお知らせの数は、変動も解消もしていない、未確認のお知らせの数です。"])</h1>

    <p><a href="{{ route('home') }}">トップページに戻る</a></p>

    @include('partials.history-last', ['label' => '最後のお知らせ', 'at' => $latest?->occurred_at, 'result' => null])

    @include('partials.flash')

    <section class="panel">
    <h2>お知らせの記録</h2>
    @include('partials.history-count', ['paginator' => $notices])

    <form method="POST" action="{{ route('notices.confirm') }}" class="notice-form">
        @csrf
        <div style="overflow-x:auto;">
            <table class="data notice-list">
                <thead>
                    <tr>
                        <th><input type="checkbox" class="notice-check-all" aria-label="このページのお知らせを全て選ぶ" title="このページのお知らせを全て選ぶ"></th>
                        <th>お知らせ日</th>
                        <th>メッセージ</th>
                        <th>状態</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($notices as $notice)
                        <tr class="notice-row notice-{{ $notice->level }} {{ $notice->changed_at || $notice->resolved_at ? 'notice-closed' : '' }}">
                            <td>
                                @unless ($notice->isConfirmed())
                                    <input type="checkbox" name="ids[]" value="{{ $notice->id }}" aria-label="確認済みにする">
                                @endunless
                            </td>
                            <td style="white-space:nowrap;">{{ \App\Support\DisplayTime::format($notice->occurred_at) }}</td>
                            <td>
                                {{ $notice->message }}
                                @if ($notice->url)
                                    <a href="{{ $notice->url }}">{{ $notice->link_label ?? '確認する' }}</a>
                                @endif
                            </td>
                            <td style="white-space:nowrap;">
                                @if ($notice->isConfirmed())
                                    確認済み
                                    <br><span class="text-muted">{{ \App\Support\DisplayTime::format($notice->confirmed_at) }}{{ $notice->confirmer ? '（' . $notice->confirmer->name . '）' : '' }}</span>
                                @else
                                    <strong>未確認</strong>
                                @endif
                                @if ($notice->changed_at)
                                    <br><span class="notice-tag">変動</span> <span class="text-muted">{{ \App\Support\DisplayTime::format($notice->changed_at) }}</span>
                                @endif
                                @if ($notice->resolved_at)
                                    <br><span class="notice-tag">解消</span> <span class="text-muted">{{ \App\Support\DisplayTime::format($notice->resolved_at) }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4">お知らせはありません。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p><button type="submit">確認</button></p>
    </form>
    {{ $notices->links() }}
    </section>

    <script>
        // 見出しのチェックボックスで、このページの未確認のお知らせを全て選ぶ・外す
        document.querySelectorAll('.notice-check-all').forEach((all) => all.addEventListener('change', () => {
            all.closest('form').querySelectorAll('input[name="ids[]"]').forEach((box) => { box.checked = all.checked; });
        }));
    </script>

@endsection
