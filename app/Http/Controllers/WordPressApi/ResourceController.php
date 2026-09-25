<?php

namespace App\Http\Controllers\WordPressApi;

use App\Http\Controllers\Controller;
use App\Repositories\BlogRepository;
use App\Services\WordPressApi\ApiInspectionService;
use Illuminate\Http\Request;

/**
 * API確認画面（BLOGOS_ARCHITECTURE.md 6-3・21-2、D-11-02）。
 *
 * 選択中のブログのWordPress APIを、その場で呼び出して表示する。取得結果はDBに保存しない。
 */
class ResourceController extends Controller
{
    public function __construct(
        protected BlogRepository $blogRepository,
        protected ApiInspectionService $inspectionService
    ) {
    }

    public function home()
    {
        return view('wordpress-api.home', [
            'resources' => ApiInspectionService::RESOURCES,
        ]);
    }

    public function index(Request $request, string $resource)
    {
        return $this->render($request, $resource, null);
    }

    public function show(Request $request, string $resource, string $id)
    {
        return $this->render($request, $resource, $id);
    }

    protected function render(Request $request, string $resource, ?string $id)
    {
        abort_unless(array_key_exists($resource, ApiInspectionService::RESOURCES), 404);

        $blog = $this->blogRepository->findSelected();
        abort_if($blog === null, 404, 'ブログが選択されていません。');

        $query = $request->only(ApiInspectionService::QUERY_KEYS);
        if ($id === null && ApiInspectionService::RESOURCES[$resource]['list'] === 'list') {
            $query['per_page'] ??= 20;
        }

        $result = $this->inspectionService->fetch($blog, $resource, $id, $query);

        return view('wordpress-api.show', [
            'resource'   => $resource,
            'definition' => ApiInspectionService::RESOURCES[$resource],
            'id'         => $id,
            'result'     => $result,
            'rows'       => $id === null ? $this->inspectionService->rows($resource, $result['body']) : [],
            'query'      => $query,
            'hasAuth'    => $blog->loadMissing('credential')->credential !== null,
        ]);
    }
}
