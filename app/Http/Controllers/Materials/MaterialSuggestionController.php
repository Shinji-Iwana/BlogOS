<?php

namespace App\Http\Controllers\Materials;

use App\Enums\MaterialKind;
use App\Enums\MaterialSuggestionType;
use App\Enums\SuggestionStatus;
use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Material;
use App\Models\MaterialSuggestion;
use App\Repositories\MaterialRepository;
use App\Services\Materials\MaterialService;
use App\Support\AffiliateLink;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * AIが作った教材の案を、人が確認して登録する（D-30）。
 *
 * 教材の情報の案：写す項目を選び、値を直してから教材に写す。
 * 新しい教材の候補：値を直し、アフィリエイトのリンクを付けて、教材として登録する。
 */
class MaterialSuggestionController extends Controller
{
    use UsesSelectedBlog;

    /**
     * 画面で確認する項目（列の名前 => 表示名）
     */
    public const FIELDS = [
        'name'            => '名前',
        'creator'         => '著者・講師・運営',
        'publisher'       => '出版社・提供元',
        'edition'         => '版',
        'published_on'    => '出版日（Udemyは最終更新日）',
        'isbn'            => 'ISBN',
        'product_url'     => '商品ページ（書籍は出版社などのページ）',
        'amazon_product_url'  => 'Amazonの商品ページ（書籍）',
        'rakuten_product_url' => '楽天の商品ページ（書籍）',
        'category_ids'    => 'カテゴリ',
        'topics'          => '分野の語句',
        'target_versions' => '対象のバージョン',
        'levels'          => '対象のレベル',
        'scenes'          => '向いている場面',
        'summary'         => '学べる内容',
        'target_readers'  => '向いている人',
        'not_for'         => '向いていない人',
        'merits'          => 'メリット',
        'cautions'        => '注意点',
        'cost_note'       => '費用の目安（スクール）',
        'duration_note'   => '学習期間（スクール）',
        'sources'         => '根拠のURL',
    ];

    /**
     * 1行に1つずつ入力する項目
     */
    public const LIST_FIELDS = ['topics', 'target_versions', 'merits', 'cautions', 'sources'];

    public function __construct(
        protected MaterialRepository $materials,
        protected MaterialService $service,
    ) {
    }

    public function index()
    {
        $blog = $this->selectedBlog();

        return view('materials.suggestions.index', [
            'blog'        => $blog,
            'suggestions' => $this->materials->pendingSuggestions($blog->id),
        ]);
    }

    public function show(int $id)
    {
        $blog = $this->selectedBlog();
        $suggestion = $this->materials->findPendingSuggestion($blog->id, $id);
        abort_if($suggestion === null, 404);

        return view('materials.suggestions.show', [
            'blog'       => $blog,
            'suggestion' => $suggestion,
            'material'   => $suggestion->material?->load('categories'),
            'categories' => Category::where('blog_id', $blog->id)->whereNull('wordpress_deleted_at')->orderBy('name')->pluck('name', 'id'),
            'fields'     => self::FIELDS,
            'listFields' => self::LIST_FIELDS,
        ]);
    }

    /**
     * 教材の情報の案を写す（写す項目にチェックした項目だけ）
     */
    public function apply(Request $request, int $id)
    {
        $blog = $this->selectedBlog();
        $suggestion = $this->materials->findPendingSuggestion($blog->id, $id);
        abort_if($suggestion === null || $suggestion->type !== MaterialSuggestionType::Research, 404);

        $validated = $request->validate($this->valueRules($blog->id) + [
            'apply'   => ['required', 'array', 'min:1'],
            'apply.*' => [Rule::in(array_keys(self::FIELDS))],
        ], [
            'apply.required' => '教材に写す項目を、1つ以上チェックしてください。',
        ]);

        $values = $this->values($validated);
        if (in_array('name', $validated['apply'], true) && blank($values['name'] ?? null)) {
            throw ValidationException::withMessages(['values.name' => '名前を写す場合は、名前を入力してください。']);
        }
        $columns = $validated['apply'];
        if (in_array('cost_note', $columns, true)) {
            $columns[] = 'cost_checked_on';
        }
        $this->service->applyResearch($suggestion, $values, $columns, $request->user()?->id);

        return redirect()->route('materials.suggestions.index')->with('status', "教材「{$suggestion->material?->name}」に、選んだ項目を写しました。");
    }

    /**
     * 新しい教材の候補を、アフィリエイトのリンクを付けて登録する
     */
    public function register(Request $request, int $id)
    {
        $blog = $this->selectedBlog();
        $suggestion = $this->materials->findPendingSuggestion($blog->id, $id);
        abort_if($suggestion === null || $suggestion->type !== MaterialSuggestionType::Candidate, 404);

        $validated = $request->validate($this->valueRules($blog->id) + [
            'values.name'   => ['required', 'string', 'max:255'],
            'amazon_url'    => ['nullable', 'string', 'max:5000'],
            'rakuten_url'   => ['nullable', 'string', 'max:5000'],
            'affiliate_url' => ['nullable', 'string', 'max:5000'],
        ]);

        $isBook = $suggestion->kind === MaterialKind::Book;
        $links = array_filter([
            'amazon_url'    => $isBook ? AffiliateLink::extractUrl($validated['amazon_url'] ?? null) : null,
            'rakuten_url'   => $isBook ? AffiliateLink::extractUrl($validated['rakuten_url'] ?? null) : null,
            'affiliate_url' => ! $isBook ? AffiliateLink::extractUrl($validated['affiliate_url'] ?? null) : null,
        ]);
        if ($links === []) {
            throw ValidationException::withMessages(['affiliate_url' => $isBook ? 'Amazon か楽天のアフィリエイトのリンクを、1つ以上入力してください。' : 'アフィリエイトのリンクを入力してください。']);
        }
        foreach ($links as $column => $url) {
            if (! preg_match('#^(https?:)?//#i', $url)) {
                throw ValidationException::withMessages([$column => 'リンクは、URL（https://…）か、<a href="…"> を含むHTMLを入力してください。']);
            }
        }

        $values = $this->values($validated);
        $categoryIds = (array) ($values['category_ids'] ?? []);
        unset($values['category_ids']);

        $material = $this->service->registerCandidate($suggestion, $values + $links, $categoryIds, $request->user()?->id);

        return redirect()->route('materials.edit', ['id' => $material->id])->with('status', "教材「{$material->name}」を登録しました。");
    }

    public function reject(Request $request, int $id)
    {
        $blog = $this->selectedBlog();
        $suggestion = $this->materials->findPendingSuggestion($blog->id, $id);
        abort_if($suggestion === null, 404);

        $this->materials->markSuggestionReviewed($suggestion, SuggestionStatus::Rejected, $request->user()?->id);

        return redirect()->route('materials.suggestions.index')->with('status', "「{$suggestion->name}」の案を不採用にしました。");
    }

    protected function valueRules(int $blogId): array
    {
        return [
            'values'                  => ['required', 'array'],
            'values.name'             => ['nullable', 'string', 'max:255'],
            'values.creator'          => ['nullable', 'string', 'max:255'],
            'values.publisher'        => ['nullable', 'string', 'max:255'],
            'values.edition'          => ['nullable', 'string', 'max:50'],
            'values.published_on'     => ['nullable', 'date'],
            'values.isbn'             => ['nullable', 'string', 'max:20'],
            'values.product_url'      => ['nullable', 'string', 'max:2000'],
            'values.amazon_product_url'  => ['nullable', 'string', 'max:2000'],
            'values.rakuten_product_url' => ['nullable', 'string', 'max:2000'],
            'values.category_ids'     => ['nullable', 'array'],
            'values.category_ids.*'   => ['integer', Rule::exists('categories', 'id')->where('blog_id', $blogId)],
            'values.topics'           => ['nullable', 'string', 'max:5000'],
            'values.target_versions'  => ['nullable', 'string', 'max:5000'],
            'values.levels'           => ['nullable', 'array'],
            'values.levels.*'         => [Rule::in(array_keys(Material::LEVELS))],
            'values.scenes'           => ['nullable', 'array'],
            'values.scenes.*'         => [Rule::in(array_keys(Material::SCENES))],
            'values.summary'          => ['nullable', 'string', 'max:5000'],
            'values.target_readers'   => ['nullable', 'string', 'max:2000'],
            'values.not_for'          => ['nullable', 'string', 'max:2000'],
            'values.merits'           => ['nullable', 'string', 'max:5000'],
            'values.cautions'         => ['nullable', 'string', 'max:5000'],
            'values.cost_note'        => ['nullable', 'string', 'max:255'],
            'values.duration_note'    => ['nullable', 'string', 'max:255'],
            'values.sources'          => ['nullable', 'string', 'max:10000'],
        ];
    }

    /**
     * 画面の入力を、教材の列の値にする
     *
     * @return array<string, mixed>
     */
    protected function values(array $validated): array
    {
        $values = (array) $validated['values'];
        foreach (self::LIST_FIELDS as $field) {
            $values[$field] = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) ($values[$field] ?? ''))), fn ($line) => $line !== ''));
        }
        $values['levels'] = (array) ($values['levels'] ?? []);
        $values['scenes'] = (array) ($values['scenes'] ?? []);
        $values['category_ids'] = array_map('intval', (array) ($values['category_ids'] ?? []));
        // 調べた日（費用の目安を写すとき）
        if (filled($values['cost_note'] ?? null)) {
            $values['cost_checked_on'] = now(config('blogos.display_timezone'))->toDateString();
        }

        return $values;
    }

    /**
     * 画面に表示する値（リストは1行に1つ）
     */
    public static function display(MaterialSuggestion|Material|null $source, string $field, array $names = []): string
    {
        if ($source === null) {
            return '';
        }
        $value = $source instanceof MaterialSuggestion
            ? ($source->data[$field] ?? null)
            : ($field === 'category_ids' ? $source->categories->pluck('id')->all() : $source->getAttribute($field));

        return match (true) {
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            $field === 'category_ids'            => implode('、', array_map(fn ($id) => $names[$id] ?? "#{$id}", (array) $value)),
            $field === 'levels'                  => implode('、', array_map(fn ($v) => Material::LEVELS[$v] ?? $v, (array) $value)),
            $field === 'scenes'                  => implode('、', array_map(fn ($v) => Material::SCENES[$v] ?? $v, (array) $value)),
            is_array($value)                     => implode("\n", $value),
            default                              => (string) $value,
        };
    }
}
