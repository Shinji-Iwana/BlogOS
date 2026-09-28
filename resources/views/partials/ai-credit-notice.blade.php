{{--
    OpenAI の残高の見込みのお知らせ（D-31-04）。データは AppServiceProvider の View Composer が渡す（$creditStatus・$creditApiConfigured）。
    APIキーがない場合は表示しない。
--}}
@if ($creditApiConfigured)
    @switch ($creditStatus['level'])
        @case ('unknown')
            <p style="color:#b60;">
                OpenAI の残高が登録されていません。OpenAI の画面（Billing）の Credit balance を、<a href="{{ route('ai.credits.index') }}">AIの費用と残高</a>に登録してください（残高の見込みで、API実行を止めるかを判断します）。
            </p>
            @break
        @case ('critical')
            <p style="color:#b00;">
                <strong>OpenAI の残高の見込みが ${{ number_format($creditStatus['balance'], 2) }} です。API実行は止まっているか、まもなく止まります（自動の再評価・定期チェックを含む）。</strong>
                OpenAI の画面で残高を確認して課金し、<a href="{{ route('ai.credits.index') }}">AIの費用と残高</a>に課金した額を登録してください。
            </p>
            @break
        @case ('warning')
            <p style="color:#b60;">
                OpenAI の残高の見込みが ${{ number_format($creditStatus['balance'], 2) }} になりました（知らせる基準 ${{ number_format($creditStatus['warning'], 2) }}）。
                OpenAI の画面で残高を確認し、必要なら課金して、<a href="{{ route('ai.credits.index') }}">AIの費用と残高</a>に登録してください。
            </p>
            @break
    @endswitch
@endif
