<?php

namespace App\Http\Controllers\Google;

use App\Enums\GoogleIndexCategory;
use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Http\Controllers\Controller;
use App\Jobs\InspectGoogleIndexJob;
use App\Models\GoogleIndexStatus;
use App\Services\Google\GoogleIndexInspectionService;
use Illuminate\Http\Request;

/**
 * 記事のインデックスの登録状態（D-37）。
 */
class GoogleIndexController extends Controller
{
    use UsesSelectedBlog;

    public function index(Request $request, GoogleIndexInspectionService $service)
    {
        $blog = $this->selectedBlog();
        $category = GoogleIndexCategory::tryFrom((string) $request->query('category'));

        $statuses = GoogleIndexStatus::with(['post:id,title_raw,link,status,wordpress_modified_gmt', 'page:id,title_raw,link,status,wordpress_modified_gmt'])
            ->where('blog_id', $blog->id)->get()
            ->filter(fn (GoogleIndexStatus $status) => ($status->post ?? $status->page)?->status === 'publish');

        return view('google.index-status', [
            'blog'       => $blog,
            'configured' => $service->isConfigured($blog),
            'category'   => $category,
            'counts'     => $statuses->countBy(fn ($status) => $status->category?->value ?? 'error'),
            'statuses'   => $statuses
                ->filter(fn ($status) => $category !== null ? $status->category === $category : $status->category !== GoogleIndexCategory::Indexed)
                ->sortBy(fn ($status) => [$status->category?->value ?? 'zzz', ($status->post ?? $status->page)?->title_raw])
                ->values(),
            'due'        => $service->isConfigured($blog) ? $service->due($blog)->count() : 0,
        ]);
    }

    /**
     * 今すぐ調べる（Queue で動かす）
     */
    public function run(Request $request, GoogleIndexInspectionService $service)
    {
        $blog = $this->selectedBlog();
        if (! $service->isConfigured($blog)) {
            return back()->withErrors(['google' => 'Search Console のプロパティが設定されていません。Google連携の設定で選んでください。']);
        }

        InspectGoogleIndexJob::dispatch($blog->id, $request->boolean('all'));

        return redirect()->route('google.index-status')->with('status', '記事のインデックスの登録状態を調べ始めました（1件あたり1秒ほどかかります。しばらくしてから、この画面を開き直してください）。');
    }
}
