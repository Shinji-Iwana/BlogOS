<?php

namespace App\Http\Controllers\Images;

use App\Enums\ImageKind;
use App\Enums\PushState;
use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Http\Controllers\Controller;
use App\Models\Image;
use App\Models\Page;
use App\Models\Post;
use App\Repositories\ArticleRepository;
use App\Services\Images\ImageException;
use App\Services\Images\ImageService;
use App\Services\Images\LegacyImageService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 古い画像（/wp-content/img/…）の移し替え（D-90）：記事ごとの取り込み・確認・登録・置き換えと、古いファイルの削除の一覧。
 */
class LegacyImageController extends Controller
{
    use UsesSelectedBlog;

    public function __construct(
        protected LegacyImageService $service,
        protected ImageService $images,
        protected ArticleRepository $articles,
    ) {
    }

    /**
     * 古い画像の削除の一覧と、古い画像が残っている記事
     */
    public function index()
    {
        $blog = $this->selectedBlog();

        return view('images.legacy', [
            'blog'      => $blog,
            'remaining' => $this->service->remaining($blog),
            'deletion'  => $this->service->deletion($blog),
        ]);
    }

    /**
     * 古い画像を取り込む（path を指定しなければ、記事の古い画像をまとめて）
     */
    public function import(Request $request, string $type, int $id)
    {
        $blog = $this->selectedBlog();
        $article = $this->article($blog->id, $type, $id);
        $validated = $request->validate(['path' => ['nullable', 'string', 'max:255']]);

        if (filled($validated['path'] ?? null)) {
            abort_unless(in_array($validated['path'], $this->service->paths($blog, (string) $article->content_raw), true), 404);
            try {
                $this->service->import($blog, $validated['path'], $request->user()?->id);
            } catch (ImageException $e) {
                return $this->back($type, $id)->withErrors(['legacy' => $e->getMessage()]);
            }

            return $this->back($type, $id)->with('status', '画像を取り込みました。名前・alt・ファイル名を入れて、確認済みにしてください。');
        }

        $result = $this->service->importAll($blog, $article, $request->user()?->id);
        $redirect = $this->back($type, $id)->with('status', "画像を {$result['imported']}件取り込みました。" . ($result['imported'] === LegacyImageService::IMPORT_LIMIT ? '（1回に ' . LegacyImageService::IMPORT_LIMIT . '件までです。残りは、もう一度押してください）' : ''));

        return $result['errors'] === [] ? $redirect : $redirect->withErrors(['legacy' => $result['errors']]);
    }

    /**
     * 名前・alt・ファイル名・種類を保存して、確認済みにする
     */
    public function update(Request $request, string $type, int $id, int $imageId)
    {
        $blog = $this->selectedBlog();
        $this->article($blog->id, $type, $id);
        $image = Image::where('blog_id', $blog->id)->whereNotNull('legacy_path')->findOrFail($imageId);
        abort_if($image->media_id !== null, 422, 'WordPress に登録した画像は、ここでは直せません。');
        $validated = $request->validate([
            'title'    => ['required', 'string', 'max:255'],
            'alt'      => ['required', 'string', 'max:1000'],
            'filename' => ['required', 'string', 'max:100'],
            'kind'     => ['required', Rule::in([ImageKind::Screenshot->value, ImageKind::Illustration->value, ImageKind::Diagram->value])],
        ]);

        $this->images->updateInfo($image, [
            'title'    => $validated['title'],
            'alt'      => $validated['alt'],
            'filename' => $validated['filename'],
            'kind'     => ImageKind::from($validated['kind']),
        ]);
        try {
            $this->images->markReady($image->fresh());
        } catch (ImageException $e) {
            return $this->back($type, $id)->withErrors(['legacy' => $e->getMessage()]);
        }

        return $this->back($type, $id)->with('status', "「{$image->legacy_path}」を確認済みにしました。");
    }

    /**
     * 確認済みの画像を、WordPress のメディアにまとめて登録する（人の承認）
     */
    public function upload(Request $request, string $type, int $id)
    {
        $blog = $this->selectedBlog();
        $article = $this->article($blog->id, $type, $id);

        $result = $this->service->uploadReady($blog, $article, $request->user()?->id);
        $redirect = $this->back($type, $id)->with('status', "WordPress のメディアに {$result['uploaded']}件登録しました。");

        return $result['errors'] === [] ? $redirect : $redirect->withErrors(['legacy' => $result['errors']]);
    }

    /**
     * 置き換えの確認（公開中の記事の URL の置き換えと、置き換える作業中の編集案）
     */
    public function confirm(string $type, int $id)
    {
        $blog = $this->selectedBlog();
        $article = $this->article($blog->id, $type, $id);

        return view('images.legacy-replace', [
            'blog'     => $blog,
            'type'     => $type,
            'article'  => $article,
            'replaced' => $this->service->replacements($blog, (string) $article->content_raw)['replaced'],
            'drafts'   => $this->service->draftsToUpdate($blog),
        ]);
    }

    /**
     * 置き換える（公開中の記事は URL だけを反映し、作業中の編集案も置き換える）
     */
    public function replace(Request $request, string $type, int $id)
    {
        $blog = $this->selectedBlog();
        $article = $this->article($blog->id, $type, $id);

        try {
            $result = $this->service->replace($blog, $article, $request->user()?->id);
        } catch (ImageException $e) {
            return $this->back($type, $id)->withErrors(['legacy' => $e->getMessage()]);
        }

        $drafts = $result['drafts'] > 0 ? "作業中の編集案 {$result['drafts']}件の URL も置き換えました。" : '';
        if ($result['operation']?->state === PushState::Completed) {
            return $this->back($type, $id)->with('status', "公開中の記事の画像 {$result['replaced']}件を、メディアの画像に置き換えて反映しました。{$drafts}");
        }

        $message = $result['operation'] !== null && $result['error'] === null
            ? "反映の結果を確かめられませんでした（{$result['operation']->state->label()}）。反映記録 #{$result['operation']->id} を確認してください。"
            : "公開中の記事に反映できませんでした：{$result['error']}";

        return $this->back($type, $id)->withErrors(['legacy' => array_values(array_filter([$message, $drafts]))]);
    }

    protected function article(int $blogId, string $type, int $id): Post|Page
    {
        $article = $this->articles->find($blogId, $type, $id);
        abort_if($article === null || $article->wordpress_deleted_at !== null, 404);

        return $article;
    }

    protected function back(string $type, int $id)
    {
        return redirect()->to(route('articles.show', ['type' => $type, 'id' => $id]) . '#legacy-images');
    }
}
