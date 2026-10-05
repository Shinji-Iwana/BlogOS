<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Http\Controllers\Controller;
use App\Services\Google\AdsenseReportService;
use Illuminate\Http\Request;

/**
 * 画面「AdSense」（選択中のブログの AdSense。推定収益額・残高・パフォーマンス・広告ユニットなどのカード。D-67）。
 *
 * 本日の数値と残高は、開いたときに AdSense API に問い合わせる（30分は使い回す。「最新にする」で取り直す）。
 * それ以外は、毎日の同期（Googleとの同期）で保存した数値から出す。
 */
class AdsenseController extends Controller
{
    use UsesSelectedBlog;

    public function __construct(
        protected AdsenseReportService $reports,
    ) {
    }

    public function index(Request $request)
    {
        $blog = $this->selectedBlog();
        $property = $this->reports->property($blog);

        return view('analytics.adsense', [
            'blog'     => $blog,
            'property' => $property,
            'report'   => $property !== null ? $this->reports->dashboard($blog, $property, $request->boolean('refresh')) : null,
        ]);
    }
}
