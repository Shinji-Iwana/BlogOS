{{--
    OpenAI の残高の見込みのお知らせ（D-31-04）。データは AppServiceProvider の View Composer が渡す（$creditStatus・$creditApiConfigured）。
    APIキーがない場合は表示しない。
--}}
@if ($creditApiConfigured)
    @switch ($creditStatus['level'])
        @case ('unknown')
            <p class="text-warn">
                OpenAI の残高が登録されていません。OpenAI の画面（Billing）の Credit balance を、<a href="{{ route('ai.credits.index') }}" data-modal-open="credit-balance-modal">OpenAIの画面で見た残高を登録</a>から登録してください（残高の見込みで、API実行を止めるかを判断します）。
            </p>
            @break
        @case ('critical')
            <p class="text-error">
                <strong>OpenAI の残高の見込みが ${{ number_format($creditStatus['balance'], 2) }} です。API実行は止まっているか、まもなく止まります（自動の再評価・定期チェックを含む）。</strong>
                OpenAI の画面で残高を確認して課金し、<a href="{{ route('ai.credits.index') }}" data-modal-open="credit-purchase-modal">課金した額を登録</a>してください。
            </p>
            @break
        @case ('warning')
            <p class="text-warn">
                OpenAI の残高の見込みが ${{ number_format($creditStatus['balance'], 2) }} になりました（知らせる基準 ${{ number_format($creditStatus['warning'], 2) }}）。
                OpenAI の画面で残高を確認し、必要なら課金して、<a href="{{ route('ai.credits.index') }}" data-modal-open="credit-balance-modal">残高</a>・<a href="{{ route('ai.credits.index') }}" data-modal-open="credit-purchase-modal">課金した額</a>を登録してください。
            </p>
            @break
    @endswitch
@endif
