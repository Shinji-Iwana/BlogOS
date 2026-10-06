<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Repositories\AiPriceRepository;
use App\Services\Ai\AiPriceCheckService;
use Illuminate\Http\Request;

/**
 * API実行の料金表（D-31-03）：今すぐ照合する、値下がりを確認して反映する・反映しない。
 *
 * 料金表は全ブログ共通（OpenAIのAPIキーが共通のため）。画面はAIの設定の中に表示する。
 */
class AiPriceController extends Controller
{
    public function __construct(
        protected AiPriceRepository $prices,
        protected AiPriceCheckService $service,
    ) {
    }

    /**
     * OpenAI API料金表との同期履歴（料金表の変更の記録。メニューの「履歴 → OpenAI API料金表との同期履歴」。
     * 以前はAIの設定の画面の「料金表の変更の記録」。全ブログ共通のため、ブログを選んでいなくても開ける。D-63-27）
     */
    public function history()
    {
        return view('ai.prices.history', [
            'priceHistory'     => $this->prices->recentChanges(200),
            'latestPriceCheck' => $this->prices->latestCheck(),
        ]);
    }

    public function check()
    {
        $result = $this->service->check();

        $message = $result['status'] === 'failed'
            ? '照合できなかった料金があります（下の「最後の照合」を確認してください）。'
            : ($result['applied'] + $result['pending'] === 0 ? '料金表は公式のページと同じです。' : "値上がり（自動で反映）{$result['applied']}件・値下がり（確認待ち）{$result['pending']}件がありました。");

        return redirect()->route('ai.settings.edit')->with('status', "料金表を公式のページと照合しました。{$message}");
    }

    public function apply(Request $request, int $id)
    {
        $change = $this->prices->findPending($id);
        abort_if($change === null, 404);
        $price = $this->prices->find($change->price_key);
        abort_if($price === null, 404);

        $this->prices->apply($price, $change->field, $change->new_value, $request->user()?->id, $change);

        return redirect()->route('ai.settings.edit')->with('status', '値下がりを料金表に反映しました。');
    }

    public function reject(Request $request, int $id)
    {
        $change = $this->prices->findPending($id);
        abort_if($change === null, 404);

        $this->prices->reject($change, $request->user()?->id);

        return redirect()->route('ai.settings.edit')->with('status', '値下がりを反映しないことにしました（料金表は今のままです）。');
    }
}
