{{--
    API実行の費用の目安と、OpenAI の残高の見込み（1行。D-31-04）。$creditStatus は View Composer が渡す。
    $api：spent（今月の費用の目安）・budget（月の支出の上限。なければ null）
--}}
今月の費用の目安：${{ number_format($api['spent'], 2) }}
・OpenAI の残高の見込み：@if ($creditStatus['balance'] !== null)<span @style(['color:#b00' => $creditStatus['level'] !== 'ok'])>${{ number_format($creditStatus['balance'], 2) }}</span>@else<span style="color:#b60;">未登録</span>@endif
@if (($api['budget'] ?? null) !== null)・月の支出の上限 ${{ number_format($api['budget'], 2) }}@endif
（<a href="{{ route('ai.credits.index') }}">AIの費用と残高</a>）
