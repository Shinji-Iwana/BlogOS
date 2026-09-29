<?php

namespace App\Http\Controllers\Images;

use App\Enums\AiExecutionMethod;
use App\Enums\AiMode;
use App\Enums\ImageKind;
use App\Enums\ImageSource;
use App\Enums\ImageStatus;
use App\Http\Controllers\Concerns\ResolvesArticleTarget;
use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Http\Controllers\Controller;
use App\Models\AiGeneration;
use App\Models\Blog;
use App\Models\Image;
use App\Models\Page;
use App\Models\Post;
use App\Repositories\ImageRepository;
use App\Services\Ai\AiApiPolicy;
use App\Services\Ai\AiException;
use App\Services\Ai\AiRunService;
use App\Services\Images\ImageException;
use App\Services\Images\ImageGenerationService;
use App\Services\Images\ImagePromptValues;
use App\Services\Images\ImageService;
use App\Services\Images\ImageUploadService;
use App\Services\Push\PushException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * 記事で使う画像（D-32）：一覧・図の作成・アップロード・確認・画像モデルでの生成・WordPress への登録。
 */
class ImageController extends Controller
{
    use ResolvesArticleTarget;
    use UsesSelectedBlog;

    public function __construct(
        protected ImageRepository $images,
        protected ImageService $service,
        protected AiRunService $runService,
        protected ImageGenerationService $generator,
        protected ImageUploadService $uploader,
        protected AiApiPolicy $apiPolicy,
    ) {
    }

    public function index(Request $request)
    {
        $blog = $this->selectedBlog();
        $kind = ImageKind::tryFrom((string) $request->query('kind'));
        $status = ImageStatus::tryFrom((string) $request->query('status'));

        return view('images.index', [
            'blog'    => $blog,
            'images'  => $this->images->listForBlog($blog->id, $kind, $status),
            'kind'    => $kind,
            'status'  => $status,
            'api'     => $this->apiSummary(),
            'formats' => ImagePromptValues::FORMATS,
            'target'  => $request->query('target'),
        ]);
    }

    /**
     * AI で図を作る（形式は AI が選ぶか、指定する）
     */
    public function design(Request $request)
    {
        $blog = $this->selectedBlog();
        $validated = $request->validate([
            'title'            => ['required', 'string', 'max:255'],
            'description'      => ['required', 'string', 'max:5000'],
            'format'           => ['required', Rule::in(array_keys(ImagePromptValues::FORMATS))],
            'target'           => ['nullable', 'string'],
            'notes'            => ['nullable', 'string', 'max:5000'],
            'execution_method' => ['required', Rule::enum(AiExecutionMethod::class)],
            'model'            => ['nullable', 'string', 'max:100'],
            'reasoning_effort' => ['nullable', 'string', 'max:30'],
        ]);
        ['article' => $article] = $this->resolveTarget($blog->id, $validated['target'] ?? null);

        $image = $this->service->create($blog, $validated['format'] === 'illustration' ? ImageKind::Illustration : ImageKind::Diagram,
            $validated['title'], $validated['description'], $request->user()?->id);

        try {
            $generation = $this->startDesign($blog, $image, $validated['format'], $validated['notes'] ?? null, $article, $request, $validated);
        } catch (AiException $e) {
            $this->service->delete($image);

            return back()->withErrors(['ai' => $e->getMessage()])->withInput();
        }

        return redirect()->route('ai.generations.show', ['id' => $generation->id]);
    }

    /**
     * 画像をアップロードして登録する（スクリーンショット・ChatGPT 等で作った画像）
     */
    public function store(Request $request)
    {
        $blog = $this->selectedBlog();
        $validated = $request->validate([
            'kind'        => ['required', Rule::enum(ImageKind::class)],
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'file'        => ['required', 'file', 'mimetypes:image/png,image/jpeg,image/webp', 'max:' . (int) config('blogos.ai.image.max_upload_kb')],
        ]);

        $image = $this->service->create($blog, ImageKind::from($validated['kind']), $validated['title'], $validated['description'] ?? null, $request->user()?->id);
        try {
            $this->service->upload($image, $request->file('file'));
        } catch (ImageException $e) {
            $this->service->delete($image);

            return back()->withErrors(['file' => $e->getMessage()])->withInput();
        }

        return redirect()->route('images.show', ['id' => $image->id])->with('status', '画像を登録しました。alt（画像の代わりの文章）とファイル名を入れて、確認済みにしてください。');
    }

    public function show(int $id)
    {
        $blog = $this->selectedBlog();
        $image = $this->findOr404($blog->id, $id);

        return view('images.show', [
            'blog'      => $blog,
            'image'     => $image,
            'api'       => $this->apiSummary(),
            'qualities' => array_keys($this->apiPolicy->imageQualities()),
            'imageCost' => filled($image->image_prompt) ? $this->apiPolicy->imageMaxCost((string) config('blogos.ai.image.model'), (string) $image->image_prompt, (string) config('blogos.ai.image.quality')) : null,
        ]);
    }

    /**
     * 画像のファイル（BlogOS の画面で表示する）
     */
    public function file(int $id)
    {
        $blog = $this->selectedBlog();
        $image = $this->findOr404($blog->id, $id);
        abort_unless($image->hasFile() && Storage::disk('local')->exists($image->path), 404);

        return Storage::disk('local')->response($image->path, basename($image->path), [
            'Content-Type'            => $image->mime_type,
            'X-Content-Type-Options'  => 'nosniff',
            'Cache-Control'           => 'private, max-age=60',
        ]);
    }

    /**
     * タイトル・alt・キャプション・ファイル名・種類を直す
     */
    public function update(Request $request, int $id)
    {
        $blog = $this->selectedBlog();
        $image = $this->findOr404($blog->id, $id);
        $validated = $request->validate([
            'title'    => ['required', 'string', 'max:255'],
            'alt'      => ['nullable', 'string', 'max:1000'],
            'caption'  => ['nullable', 'string', 'max:1000'],
            'filename' => ['nullable', 'string', 'max:100'],
            'kind'     => ['required', Rule::enum(ImageKind::class)],
        ]);

        $this->service->updateInfo($image, [
            'title'    => $validated['title'],
            'alt'      => $validated['alt'] ?? null,
            'caption'  => $validated['caption'] ?? null,
            'filename' => $validated['filename'] ?? '',
            'kind'     => ImageKind::from($validated['kind']),
        ]);

        return redirect()->route('images.show', ['id' => $image->id])->with('status', '画像の情報を保存しました。');
    }

    /**
     * 図解の SVG を直す（PNG にし直す必要がある）
     */
    public function updateSvg(Request $request, int $id)
    {
        $blog = $this->selectedBlog();
        $image = $this->findOr404($blog->id, $id);
        $validated = $request->validate(['svg' => ['required', 'string', 'max:500000']]);

        try {
            $this->service->saveSvg($image, $validated['svg']);
        } catch (ImageException $e) {
            return back()->withErrors(['svg' => $e->getMessage()])->withInput();
        }

        return redirect()->route('images.show', ['id' => $image->id])->with('status', 'SVG を保存しました。「PNG にして保存する」を押してください。');
    }

    /**
     * ブラウザで SVG から作った PNG を保存する
     */
    public function storePng(Request $request, int $id)
    {
        $blog = $this->selectedBlog();
        $image = $this->findOr404($blog->id, $id);
        $request->validate(['file' => ['required', 'file', 'mimetypes:image/png', 'max:' . (int) config('blogos.ai.image.max_upload_kb')]]);
        abort_if(blank($image->svg_source), 422, 'SVG がありません。');

        try {
            $this->service->storeBytes($image, (string) file_get_contents($request->file('file')->getRealPath()), ImageSource::AiSvg);
        } catch (ImageException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'PNG を保存しました。', 'redirect' => route('images.show', ['id' => $image->id])]);
    }

    /**
     * 画像のファイルを差し替える（ChatGPT 等で作ったイラスト、撮り直したスクリーンショット）
     */
    public function replaceFile(Request $request, int $id)
    {
        $blog = $this->selectedBlog();
        $image = $this->findOr404($blog->id, $id);
        $request->validate(['file' => ['required', 'file', 'mimetypes:image/png,image/jpeg,image/webp', 'max:' . (int) config('blogos.ai.image.max_upload_kb')]]);
        abort_if($image->media_id !== null, 422, 'WordPress に登録した画像は差し替えられません。新しい画像として登録してください。');

        try {
            $this->service->upload($image, $request->file('file'));
        } catch (ImageException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return redirect()->route('images.show', ['id' => $image->id])->with('status', '画像を差し替えました。');
    }

    public function markReady(int $id)
    {
        $blog = $this->selectedBlog();
        $image = $this->findOr404($blog->id, $id);

        try {
            $this->service->markReady($image);
        } catch (ImageException $e) {
            return back()->withErrors(['image' => $e->getMessage()]);
        }

        return redirect()->route('images.show', ['id' => $image->id])->with('status', '確認済みにしました。');
    }

    /**
     * 画像モデルで作る（作り直す）。料金がかかる
     */
    public function generate(Request $request, int $id)
    {
        $blog = $this->selectedBlog();
        $image = $this->findOr404($blog->id, $id);
        $validated = $request->validate([
            'prompt'  => ['nullable', 'string', 'max:4000'],
            'quality' => ['nullable', Rule::in(array_keys($this->apiPolicy->imageQualities()))],
        ]);
        abort_if($image->media_id !== null, 422, 'WordPress に登録した画像は作り直せません。');

        try {
            $generation = $this->generator->start($image, $validated['prompt'] ?? null, $validated['quality'] ?? null, $request->user()?->id);
        } catch (AiException $e) {
            return back()->withErrors(['ai' => $e->getMessage()])->withInput();
        }

        return redirect()->route('ai.generations.show', ['id' => $generation->id]);
    }

    /**
     * 登録済みの図解の画像で、図を作る（記事の「画像の依頼」から作った画像など。D-34）
     */
    public function redesign(Request $request, int $id)
    {
        $blog = $this->selectedBlog();
        $image = $this->findOr404($blog->id, $id);
        abort_if($image->kind !== ImageKind::Diagram, 404);
        $validated = $request->validate([
            'execution_method' => ['required', Rule::enum(AiExecutionMethod::class)],
            'model'            => ['nullable', 'string', 'max:100'],
            'reasoning_effort' => ['nullable', 'string', 'max:30'],
        ]);

        try {
            $generation = $this->startDesign($blog, $image, 'svg', null, null, $request, $validated);
        } catch (AiException $e) {
            return back()->withErrors(['ai' => $e->getMessage()]);
        }

        return redirect()->route('ai.generations.show', ['id' => $generation->id]);
    }

    /**
     * もう一方の形式（SVG の図／イラスト）でも作る。比べて、使う方を選ぶ
     */
    public function variant(Request $request, int $id)
    {
        $blog = $this->selectedBlog();
        $image = $this->findOr404($blog->id, $id);
        $toSvg = $image->kind !== ImageKind::Diagram;
        $validated = $toSvg ? $request->validate([
            'execution_method' => ['required', Rule::enum(AiExecutionMethod::class)],
            'model'            => ['nullable', 'string', 'max:100'],
            'reasoning_effort' => ['nullable', 'string', 'max:30'],
        ]) : [];

        $variant = $this->service->create($blog, $toSvg ? ImageKind::Diagram : ImageKind::Illustration,
            $image->title . ($toSvg ? '（SVG の図）' : '（イラスト）'), $image->description, $request->user()?->id, [
                'variant_of_image_id' => $image->id,
                'image_prompt'        => $image->image_prompt,
                'alt'                 => $image->alt,
                'caption'             => $image->caption,
                'filename'            => $image->filename ? $image->filename . ($toSvg ? '-diagram' : '-illustration') : null,
            ]);

        try {
            if ($toSvg) {
                $generation = $this->startDesign($blog, $variant, 'svg', null, null, $request, $validated);
            } else {
                $generation = $this->generator->start($variant, $image->image_prompt, null, $request->user()?->id);
            }
        } catch (AiException $e) {
            $this->service->delete($variant);

            return back()->withErrors(['ai' => $e->getMessage()]);
        }

        return redirect()->route('ai.generations.show', ['id' => $generation->id]);
    }

    /**
     * 確認済みの画像を、WordPress のメディアに登録する
     */
    public function uploadToWordPress(Request $request, int $id)
    {
        $blog = $this->selectedBlog();
        $image = $this->findOr404($blog->id, $id);

        try {
            $operation = $this->uploader->upload($image, $request->user()?->id);
        } catch (PushException $e) {
            return back()->withErrors(['image' => $e->getMessage()]);
        }

        if ($operation->media_id === null) {
            return redirect()->route('push-operations.show', ['id' => $operation->id])->withErrors(['image' => 'WordPress への登録が完了しませんでした。反映記録を確認してください。']);
        }

        return redirect()->route('images.show', ['id' => $image->id])->with('status', 'WordPress のメディアに登録しました。');
    }

    public function destroy(int $id)
    {
        $blog = $this->selectedBlog();
        $image = $this->findOr404($blog->id, $id);
        abort_if($image->media_id !== null, 422, 'WordPress に登録した画像は、BlogOS からは削除しません（WordPress のメディアは、DB確認の画面から削除できます）。');

        $title = $image->title;
        $this->service->delete($image);

        return redirect()->route('images.index')->with('status', "画像「{$title}」を削除しました。");
    }

    /**
     * @throws AiException
     */
    protected function startDesign(Blog $blog, Image $image, string $format, ?string $notes, Post|Page|null $article, Request $request, array $validated): AiGeneration
    {
        return $this->runService->start(
            AiMode::ImageDesign, $blog, $article, null,
            array_filter([
                '形式'     => ImagePromptValues::FORMATS[$format] ?? $format,
                '形式の値' => $format,
                '補足'     => $notes,
            ], fn ($value) => filled($value)),
            null, $request->user()?->id,
            AiExecutionMethod::from($validated['execution_method']), $validated['model'] ?? null, $validated['reasoning_effort'] ?? null,
            image: $image,
        );
    }

    protected function findOr404(int $blogId, int $id): Image
    {
        $image = $this->images->findForBlog($blogId, $id);
        abort_if($image === null, 404);

        return $image;
    }

    protected function apiSummary(): array
    {
        return [
            'configured' => $this->apiPolicy->isConfigured(),
            'models'     => $this->apiPolicy->models(),
            'defaults'   => $this->apiPolicy->defaults(AiMode::ImageDesign),
            'spent'      => $this->apiPolicy->spentThisMonth(),
            'budget'     => $this->apiPolicy->monthlyBudget(),
            'imageModel' => (string) config('blogos.ai.image.model'),
            'quality'    => (string) config('blogos.ai.image.quality'),
        ];
    }
}
