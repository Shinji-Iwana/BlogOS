{{--
    残高・課金の登録ポップアップ（D-62。以前は画面「AIの費用と残高」の「登録する」の欄。D-31-04）

    メニューの「設定 → OpenAI → OpenAIの画面で見た残高を登録」「課金額を登録」を押した場合に表示する
    （data-modal-open="credit-balance-modal"・"credit-purchase-modal"。layouts/header から読み込む）。
    テーマ切替・音声操作のポップアップと同じ形。説明は、各項目の「?」のツールチップに出す（partials/tip）。
    日時の欄は、ポップアップを開いた時刻（日本時間）を初めから入れる（data-default-now。layouts/header の JavaScript）。
    登録した後は、開いていた画面に戻る。入力の誤りで戻ったときは、ポップアップを開いたままにして誤りを出す。
--}}

@php
    $creditForms = [
        'balance' => [
            'title'  => 'OpenAIの画面で見た残高を登録',
            'route'  => 'ai.credits.balance',
            'tip'    => "OpenAI の API は前払い（チャージした残高から引かれる）です。BlogOS は OpenAI の残高を直接取得できないため、OpenAI の画面で見た残高を登録し、その後のAPI実行の費用の目安を引いて、残高を見込みます。\n登録すると、残高の見込みはこの額から計算し直します（その時点の見込みとの差を記録します）。",
            'amount' => ['label' => '残高（米ドル）', 'tip' => 'OpenAI の Billing の画面の Credit balance。'],
            'at'     => '見た日時（日本時間）',
            'submit' => '残高を登録する',
        ],
        'purchase' => [
            'title'  => '課金額を登録',
            'route'  => 'ai.credits.purchase',
            'tip'    => "OpenAI で課金した額を登録すると、残高の見込みに加えます。\n課金の直後に残高も見た場合は、「OpenAIの画面で見た残高を登録」だけでもかまいません。",
            'amount' => ['label' => '課金した額（米ドル）', 'tip' => '残高に加わった額（税を除く）。'],
            'at'     => '課金した日時（日本時間）',
            'submit' => '課金を登録する',
        ],
    ];
    $atTip = '日本時間。初めは、このポップアップを開いた時刻が入っています。前に見た・課金した場合は、その日時に直してください（今より後は登録できません）。';
@endphp

@foreach ($creditForms as $kind => $form)
    @php $creditFailed = old('_form') === 'credit-' . $kind && $errors->any(); @endphp

    <div
        id="credit-{{ $kind }}-modal"
        class="blog-switch-modal site-modal credit-modal"
        @if ($creditFailed) data-modal-autoopen @endif
    >

        <div
            class="blog-switch-dialog"
        >

            <h2>
                {{ $form['title'] }}
                @include('partials.tip', ['tip' => $form['tip']])
            </h2>

            @if ($creditFailed)
                <ul class="text-error">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            @endif

            <form
                method="POST"
                action="{{ route($form['route']) }}"
            >

                @csrf
                @include('partials.selected-blog-field')
                <input type="hidden" name="_form" value="credit-{{ $kind }}">

                <p>
                    <label>{{ $form['amount']['label'] }} $<input type="number" name="amount" step="0.01" min="0" value="{{ $creditFailed ? old('amount') : '' }}" style="width:120px;" required></label>
                    @include('partials.tip', ['tip' => $form['amount']['tip']])
                </p>
                <p>
                    <label>{{ $form['at'] }} <input type="datetime-local" name="occurred_at" value="{{ $creditFailed ? old('occurred_at') : now(config('blogos.display_timezone'))->format('Y-m-d\TH:i') }}" @unless ($creditFailed) data-default-now="{{ config('blogos.display_timezone') }}" @endunless></label>
                    @include('partials.tip', ['tip' => $atTip])
                </p>
                <p>
                    <label>メモ <input type="text" name="note" value="{{ $creditFailed ? old('note') : '' }}" style="width:100%; max-width:320px;"></label>
                </p>

                <div
                    class="blog-switch-actions"
                >

                    {{-- 登録 --}}
                    <button
                        type="submit"
                    >
                        {{ $form['submit'] }}
                    </button>

                    {{-- キャンセル --}}
                    <button
                        type="button"
                        class="btn-secondary"
                        data-modal-close
                    >
                        キャンセル
                    </button>

                </div>

            </form>

        </div>

    </div>
@endforeach
