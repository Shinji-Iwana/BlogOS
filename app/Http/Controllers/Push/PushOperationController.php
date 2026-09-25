<?php

namespace App\Http\Controllers\Push;

use App\Clients\WordPress\WordPressApiException;
use App\Enums\PushOperationType;
use App\Enums\PushState;
use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Http\Controllers\Controller;
use App\Repositories\WordPressPushOperationRepository;
use App\Services\Push\PushRecoveryService;
use App\Services\Push\PushException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 反映記録の一覧・詳細と、結果が不明な反映の確認（WORDPRESS_API 24章）。
 */
class PushOperationController extends Controller
{
    use UsesSelectedBlog;

    public function __construct(
        protected WordPressPushOperationRepository $operations,
        protected PushRecoveryService $pushService,
    ) {
    }

    public function index()
    {
        $blog = $this->selectedBlog();

        return view('push-operations.index', [
            'blog'       => $blog,
            'operations' => $this->operations->listForBlog($blog->id),
        ]);
    }

    public function show(int $id)
    {
        $blog = $this->selectedBlog();
        $operation = $this->operations->findForBlog($blog->id, $id);
        abort_if($operation === null, 404);

        // 結果が不明な新規作成は、照合の候補をWordPressから取得して示す（WORDPRESS_API 24-3）
        $candidates = [];
        $candidateError = null;
        if ($operation->state === PushState::Unknown && $operation->operation === PushOperationType::Create) {
            try {
                $candidates = $this->pushService->candidates($operation);
            } catch (WordPressApiException|ConnectionException $e) {
                $candidateError = $e->getMessage();
            }
        }

        return view('push-operations.show', [
            'operation'      => $operation,
            'candidates'     => $candidates,
            'candidateError' => $candidateError,
        ]);
    }

    public function resolve(Request $request, int $id)
    {
        $blog = $this->selectedBlog();
        $operation = $this->operations->findForBlog($blog->id, $id);
        abort_if($operation === null, 404);

        $validated = $request->validate([
            'result'       => ['required', Rule::in(['applied', 'not_applied'])],
            'wordpress_id' => [
                $request->input('result') === 'applied' && $operation->operation === PushOperationType::Create ? 'required' : 'nullable',
                'integer',
            ],
        ], [
            'wordpress_id.required' => '作成された記事を選んでください。',
        ]);

        try {
            if ($validated['result'] === 'applied') {
                $this->pushService->resolveAsApplied($operation, isset($validated['wordpress_id']) ? (int) $validated['wordpress_id'] : null, $request->user()?->id);
            } else {
                $this->pushService->resolveAsNotApplied($operation, $request->user()?->id);
            }
        } catch (PushException|WordPressApiException|ConnectionException $e) {
            return back()->withErrors(['resolve' => $e->getMessage()]);
        }

        return redirect()->route('push-operations.show', ['id' => $id])->with('status', '反映記録を確定しました。');
    }
}
