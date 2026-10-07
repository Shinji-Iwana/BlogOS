<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Repositories\AiPriceRepository;
use App\Services\Ai\AiApiPolicy;
use Illuminate\Http\Request;

/**
 * API実行の料金表（D-31-03）：料金表・変更の記録の画面と、値下がりを確認して反映する・反映しない。
 *
 * 料金表は全ブログ共通（OpenAIのAPIキーが共通のため）。照合は、定期実行とメニューの「設定 → 即時実行 → OpenAI API料金表との同期」
 * （AIの設定の画面の「今すぐ公式のページと照合する」はなくした。D-63-28）。値下がりの確認待ちは、AIの設定の画面に表示する。
 */
class AiPriceController extends Controller
{
    public function __construct(
        protected AiPriceRepository $prices,
    ) {
    }

    /**
     * OpenAI API料金表情報（メニューの「情報 → OpenAI API料金表情報」。以前はAIの設定の画面の「API実行の料金表」。
     * 全ブログ共通のため、ブログを選んでいなくても開ける。D-63-28）
     */
    public function index(AiApiPolicy $apiPolicy)
    {
        return view('ai.prices.index', [
            'priceModels'      => $apiPolicy->models(),
            'webSearchPrice'   => $apiPolicy->webSearch()['cost_per_call'],
            'imagePriceModels' => $apiPolicy->imageModels(),
            'storedPrices'     => $this->prices->all(),
            'latestPriceCheck' => $this->prices->latestCheck(),
        ]);
    }

    /**
     * OpenAI API料金表との同期履歴（料金表の変更の記録。メニューの「履歴 → OpenAI API料金表との同期履歴」。
     * 以前はAIの設定の画面の「料金表の変更の記録」。全ブログ共通のため、ブログを選んでいなくても開ける。D-63-27）
     */
    public function history()
    {
        return view('ai.prices.history', [
            'priceHistory'     => $this->prices->paginateChanges(\App\Support\HistoryPage::PER_PAGE),
            'latestPriceCheck' => $this->prices->latestCheck(),
        ]);
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
