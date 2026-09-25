<?php

namespace App\Http\Controllers\Terms;

use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Repositories\TermRepository;
use App\Services\Push\PushException;
use App\Services\Push\TermPushService;
use Illuminate\Http\Request;

/**
 * カテゴリ・タグ・メディアの情報の更新と削除（WORDPRESS_API 21-3・22章、D-15-05）。
 * {type} は categories / tags / media。
 */
class TermController extends Controller
{
    use UsesSelectedBlog;

    public function __construct(
        protected TermRepository $terms,
        protected TermPushService $pushService,
    ) {
    }

    public function edit(string $type, int $id)
    {
        $blog = $this->selectedBlog();
        $record = $this->terms->find($blog->id, $type, $id);
        abort_if($record === null, 404);

        return view('terms.edit', [
            'type'            => $type,
            'record'          => $record,
            'fields'          => TermPushService::FIELDS[TermPushService::typeOf($record)->value],
            'current'         => $this->pushService->currentValues($record),
            'parents'         => $record instanceof Category ? $this->terms->parentCandidates($record) : collect(),
            'linkedPostCount' => $this->terms->linkedPostCount($record),
        ]);
    }

    public function update(Request $request, string $type, int $id)
    {
        $blog = $this->selectedBlog();
        $record = $this->terms->find($blog->id, $type, $id);
        abort_if($record === null, 404);

        $validated = $request->validate([
            'values'             => ['required', 'array'],
            'values.name'        => ['sometimes', 'required', 'string', 'max:200'],
            'values.slug'        => ['sometimes', 'nullable', 'string', 'max:200'],
            'values.description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'values.parent'      => ['sometimes', 'integer', 'min:0'],
            'values.title'       => ['sometimes', 'nullable', 'string', 'max:1000'],
            'values.alt_text'    => ['sometimes', 'nullable', 'string', 'max:1000'],
            'values.caption'     => ['sometimes', 'nullable', 'string', 'max:10000'],
            'base'               => ['required', 'array'],
            'approved'           => ['accepted'],
        ], [
            'approved.accepted' => '反映の内容を確認し、承認してください。',
        ]);

        try {
            $operation = $this->pushService->update($record, $validated['values'], $request->input('base', []), $request->user()?->id);
        } catch (PushException $e) {
            return back()->withErrors(['push' => $e->getMessage()])->withInput();
        }

        return redirect()->route('push-operations.show', ['id' => $operation->id]);
    }

    public function destroy(Request $request, string $type, int $id)
    {
        $blog = $this->selectedBlog();
        $record = $this->terms->find($blog->id, $type, $id);
        abort_if($record === null, 404);

        $request->validate([
            'confirm_name' => ['required', 'string'],
            'confirmed'    => ['accepted'],
        ]);

        // 取り消せない操作のため、名前を入力させて確かめる
        if ($request->input('confirm_name') !== TermPushService::label($record)) {
            return back()->withErrors(['confirm_name' => '入力した名前が一致しません。']);
        }

        try {
            $operation = $this->pushService->delete($record, $request->user()?->id);
        } catch (PushException $e) {
            return back()->withErrors(['push' => $e->getMessage()]);
        }

        return redirect()->route('push-operations.show', ['id' => $operation->id]);
    }
}
