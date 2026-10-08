{{--
    お知らせ（notices。ヘッダーのお知らせのボタン・メニューの「履歴 → お知らせ」。D-74）

    要対応・注意のお知らせを、新しい順に出す。チェックを入れて「確認」を押すと確認済みにする（一覧からは消さない）。
    内容が変わって新しいお知らせを記録した前のものには「変動」、問題がなくなったものには「解消」を添える。
    要対応（赤）・注意（金）は、チェックボックスの右の、アークリアクターの絵の色で表す（行の色は、ほかの一覧と同じ。D-74-06）。
--}}

@extends('layouts.app')

@section('content')

    <h1>お知らせ履歴 @include('partials.tip', ['tip' => "要対応・注意のお知らせの記録です（トップページを開いたときと、定期実行が終わったときに更新します）。\nチェックを入れて「確認」を押すと、確認済みにします（一覧からは消えません）。\n内容（件数など）が変わったときは、新しいお知らせとして記録し、前のお知らせに「変動」を添えます。問題がなくなったときは「解消」を添えます。\nヘッダーのお知らせの数は、変動も解消もしていない、未確認のお知らせの数です。"])</h1>

    <p><a href="{{ route('home') }}">トップページに戻る</a></p>

    {{-- 最後に、今の状態とお知らせの記録を照合した日時（トップページを開いたとき・定期実行が終わったとき） --}}
    @include('partials.history-last', ['label' => '最後の照合', 'at' => $checkedAt, 'result' => '成功'])

    @include('partials.flash')

    <section class="panel">
    <h2>お知らせの記録</h2>
    @include('partials.history-count', ['paginator' => $notices])

    {{-- 要対応・注意を表すアークリアクターの絵の形（各行で使う） --}}
    @include('notices.reactor-icon')

    <form method="POST" action="{{ route('notices.confirm') }}" class="notice-form">
        @csrf
        <div style="overflow-x:auto;">
            <table class="data notice-list">
                <thead>
                    <tr>
                        <th><input type="checkbox" class="notice-check-all" aria-label="このページのお知らせを全て選ぶ" title="このページのお知らせを全て選ぶ"></th>
                        <th aria-label="区分"></th>
                        <th>お知らせ日</th>
                        <th>メッセージ</th>
                        <th>状態</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($notices as $notice)
                        <tr>
                            <td>
                                @unless ($notice->isConfirmed())
                                    <input type="checkbox" name="ids[]" value="{{ $notice->id }}" aria-label="確認済みにする">
                                @endunless
                            </td>
                            {{-- 要対応（赤）・注意（金）を、アークリアクターの絵の色で表す --}}
                            <td class="notice-icon-cell">
                                <svg class="notice-icon notice-icon-{{ $notice->level }}" viewBox="0 0 40 40" role="img" aria-label="{{ $notice->level === 'error' ? '要対応' : '注意' }}">
                                    <title>{{ $notice->level === 'error' ? '要対応' : '注意' }}</title>
                                    <use href="#notice-reactor"/>
                                </svg>
                            </td>
                            <td style="white-space:nowrap;">{{ \App\Support\DisplayTime::format($notice->occurred_at) }}</td>
                            <td>
                                {{-- メッセージで1行、確認する画面へのリンクで1行 --}}
                                {{ $notice->message }}
                                @if ($notice->url)
                                    <br><a href="{{ $notice->url }}">{{ $notice->link_label ?? '確認する' }}</a>
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
                        <tr><td colspan="5">お知らせはありません。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="notice-actions"><button type="submit">確認</button></p>
    </form>
    {{ $notices->links() }}
    </section>

    <script>
        // ヘッダーのお知らせの数を、数え直した数にする（確認済みにした後に開き直したとき。
        // トップページの横の画面のパネルの中で開いているときは、トップページのヘッダーの数も直す）
        (function () {
            const count = {{ (int) $openCount }};
            const update = (doc) => {
                const wrap = doc.querySelector('.notice-wrap');
                if (!wrap) {
                    return;
                }
                let badge = wrap.querySelector('.notice-badge');
                if (count > 0) {
                    if (!badge) {
                        badge = doc.createElement('span');
                        badge.className = 'notice-badge';
                        badge.setAttribute('aria-hidden', 'true');
                        wrap.appendChild(badge);
                    }
                    badge.textContent = count > 99 ? '99+' : String(count);
                } else if (badge) {
                    badge.remove();
                }
                const link = wrap.querySelector('.notice-button');
                const label = 'お知らせ' + (count > 0 ? '（未確認 ' + count + '件）' : '');
                if (link) {
                    link.title = label;
                    link.setAttribute('aria-label', label);
                }
            };
            update(document);
            try {
                if (window.parent !== window) {
                    update(window.parent.document);
                }
            } catch (e) {
                // 別のサイトの中に開かれているときは、何もしない
            }
        })();

        // 見出しのチェックボックスで、このページの未確認のお知らせを全て選ぶ・外す
        document.querySelectorAll('.notice-check-all').forEach((all) => all.addEventListener('change', () => {
            all.closest('form').querySelectorAll('input[name="ids[]"]').forEach((box) => { box.checked = all.checked; });
        }));
    </script>

@endsection
