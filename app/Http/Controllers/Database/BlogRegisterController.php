<?php

namespace App\Http\Controllers\Database;

use App\Http\Controllers\Controller;
use App\DTO\WordPress\BlogApiDto;
use App\Models\Blog;
use App\Repositories\BlogRepository;
use App\Services\WordPress\BlogService;
use Illuminate\Http\Request;

class BlogRegisterController extends Controller
{
    protected BlogRepository $blogRepository;

    public function __construct(
        BlogRepository $blogRepository
    ) {
        $this->blogRepository = $blogRepository;
    }

    public function index()
    {
        return view('database.blog-register');
    }

    public function check(Request $request)
    {
        $request->validate([
            'url' => ['required', 'string'],
        ]);

        $baseUrl = rtrim($request->input('url'), '/');

        // まだDBに登録されていないURLの疎通確認用の、未保存のBlogインスタンス。
        // WordPressApiClientはhomeしか参照しないため、この用途で問題ない。
        $tentativeBlog = new Blog(['home' => $baseUrl]);

        $client = new BlogService($tentativeBlog);

        $rawData = $client->getSite();

        if ($rawData === null || !is_array($rawData)) {
            return response()->json([
                'success' => false,
                'message' => "「{$baseUrl}/wp-json」への接続に失敗しました。URLが正しいか、サイトが公開されているかご確認ください。",
            ]);
        }

        $missingFields = BlogApiDto::findMissingFields($rawData);

        if (!empty($missingFields)) {
            return response()->json([
                'success' => false,
                'message' => "「{$baseUrl}/wp-json」への接続はできましたが、以下の項目が取得できませんでした：" . implode(', ', $missingFields),
            ]);
        }

        $blogApiDto = BlogApiDto::fromApiResponse($rawData);

        return response()->json([
            'success' => true,
            'data'    => $blogApiDto->toArray(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'             => ['required', 'string'],
            'description'      => ['nullable', 'string'],
            'url'              => ['required', 'string'],
            'home'             => ['required', 'string'],
            'gmt_offset'       => ['required', 'string'],
            'timezone_string'  => ['required', 'string'],
            'confirmed'        => ['nullable', 'boolean'],
        ]);

        $blogApiDto = BlogApiDto::fromApiResponse([
            'name'            => $validated['name'],
            'description'     => $validated['description'] ?? '',
            'url'             => $validated['url'],
            'home'            => $validated['home'],
            'gmt_offset'      => $validated['gmt_offset'],
            'timezone_string' => $validated['timezone_string'],
        ]);

        if ($blogApiDto === null) {
            return response()->json([
                'success' => false,
                'message' => '入力内容に不備があります。',
            ]);
        }

        $existing = $this->blogRepository->findByHome($blogApiDto->data['home']);

        if ($existing === null) {
            $this->blogRepository->createFromApiData($blogApiDto, '手動更新');

            return response()->json([
                'success' => true,
                'type'    => 'created',
                'message' => '新しいブログとして登録しました。',
            ]);
        }

        $diff = $this->blogRepository->diff($existing, $blogApiDto);

        if (empty($diff)) {
            return response()->json([
                'success' => false,
                'type'    => 'duplicate',
                'message' => 'このサイトは既に登録済みです（内容も完全に一致しています）。',
            ]);
        }

        if (!$request->boolean('confirmed')) {
            return response()->json([
                'success' => false,
                'type'    => 'diff',
                'message' => '既に登録されているサイトですが、内容に差分があります。更新しますか？',
                'diff'    => $this->formatDiffForResponse($diff),
            ]);
        }

        $this->blogRepository->updateWithHistory($existing, $diff, '手動更新');

        return response()->json([
            'success' => true,
            'type'    => 'updated',
            'message' => 'ブログ情報を更新しました。',
        ]);
    }

    protected function formatDiffForResponse(array $diff): array
    {
        $formatted = [];

        foreach ($diff as $field => $values) {
            $formatted[] = [
                'field'     => $field,
                'label'     => BlogRepository::FIELD_LABELS[$field] ?? $field,
                'old_value' => $values['old'],
                'new_value' => $values['new'],
            ];
        }

        return $formatted;
    }
}
