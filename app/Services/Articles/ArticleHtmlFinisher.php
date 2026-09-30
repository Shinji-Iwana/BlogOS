<?php

namespace App\Services\Articles;

use App\Enums\DraftState;
use App\Enums\MaterialKind;
use App\Models\ArticleDraft;
use App\Models\Blog;
use App\Models\Image;
use App\Models\Material;
use App\Models\Page;
use App\Models\Post;
use App\Services\Materials\AffiliateProgramService;
use App\Support\ArticlePlaceholders;

/**
 * 記事の本文の仕上げ（html-rules.md。D-34）。AIの改修案・新規記事の本文を、編集案に取り込むときと、人が画面で求めたときに行う。
 *
 * 1. 古い書き方を外す（FAQ の構造化データ、テーマの教材のショートコード、広告のショートコード、広告を含むことの表示）
 * 2. 目印を置き換える（[[教材:ID]]・[[記事:ID]]・[[画像:ID]]）。置き換えられない目印は残し、反映できないようにする
 * 3. 広告を含むことの表示・教材の枠の PR の印・広告のショートコードを、決まった位置に入れる
 *
 * 広告などの設定がないブログ（html-rules.md のないブログ）では、2 だけを行う。
 */
class ArticleHtmlFinisher
{
    /**
     * 教材の枠（収益導線。広告と続けて置かない）
     */
    protected const MATERIAL_BOX_CLASSES = ['book-box', 'udemy-box', 'school-box', 'cert-box', 'roadmap-book-box', 'roadmap-udemy-box', 'roadmap-school-box'];

    public function __construct(
        protected AffiliateProgramService $programs,
        protected ArticleLinkTextUpdater $linkTexts,
    ) {
    }

    /**
     * @return array{content: string, notes: list<string>}
     */
    public function finish(Blog $blog, string $content, bool $withAds = true): array
    {
        $settings = config('blogos.article_html.' . $blog->quality_profile);
        $notes = [];

        $content = str_replace("\r\n", "\n", $content);
        // 前の仕上げで、公開していなかったためタイトルだけにした記事は、目印に戻して置き換え直す（公開されていればリンクになる）
        $content = preg_replace('/<!-- blogos:記事:(\d+) -->.*?<!-- \/blogos -->/su', '[[記事:$1]]', $content) ?? $content;
        $content = preg_replace('/<!-- blogos:下書き:(\d+) -->.*?<!-- \/blogos -->/su', '[[記事:下書き$1]]', $content) ?? $content;
        $content = $this->removeOldMarkup($content, $notes);
        $content = $this->replacePlaceholders($blog, $content, $notes);
        // タイトルが変わった記事へのリンクの文字を、今のタイトルにする（D-46）
        $refreshed = $this->linkTexts->refresh($blog, $content);
        $content = $refreshed['content'];
        array_push($notes, ...$refreshed['notes']);

        if (is_array($settings)) {
            $content = $this->normalizeSections($content, $notes);
            $content = $this->addPrMarks($content, (string) ($settings['pr_note'] ?? ''));
            if ($withAds && ! empty($settings['ads'])) {
                $content = $this->insertAds($content, $settings, $notes);
            }
        }

        foreach ($this->orphanSupplements($content) as $term) {
            $notes[] = "本文に出てこない用語の補足があります：「{$term}」（補足を削除するか、補足の見出しの表記を本文に合わせてください）";
        }

        foreach (ArticlePlaceholders::remaining($content) as $placeholder) {
            $notes[] = "置き換えられなかった目印：{$placeholder}（このままでは反映できません。登録してから「目印を置き換え直す」を押すか、本文から削除してください）";
        }

        return ['content' => preg_replace("/\n{3,}/", "\n\n", trim($content)), 'notes' => array_values(array_unique($notes))];
    }

    /**
     * 決まった形の部品を、html-rules.md の枠に直す（AIが枠を使わずに書いた場合。D-35-03）
     *
     * - FAQ：「よくある質問」「FAQ」の見出しと、質問（h3）・回答を、faq-box の形にする。faq-box の直前の重複した見出しは外す
     * - まとめ：枠の外の「まとめ」の見出しを、summary-box の中に入れる（枠がなければ枠を作る）
     * - 次に読む記事・関連記事：見出しとリストだけの場合は、next-article-box・related-box で囲む
     *
     * @param list<string> $notes
     */
    protected function normalizeSections(string $content, array &$notes): string
    {
        // faq-box の直前の、重複した「よくある質問」の見出し
        $content = preg_replace('/<h2\b[^>]*>[^<]*(?:よくある質問|FAQ)[^<]*<\/h2>\s*(?:<!--.*?-->\s*)*(?=<div class="faq-box">)/su', '', $content, -1, $count) ?? $content;
        if ($count > 0) {
            $notes[] = 'FAQ の枠の前の、重複した見出しを外しました。';
        }

        if (! str_contains($content, 'class="faq-box"')) {
            $content = $this->wrapFaq($content, $notes);
        }

        // 枠の外の「まとめ」の見出し
        $content = preg_replace_callback('/(<h2\b[^>]*>\s*まとめ.*?<\/h2>)\s*(?:<!--.*?-->\s*)*<div class="summary-box">(\s*)(<h2\b)?/su', function ($m) use (&$notes) {
            $notes[] = 'まとめの見出しを、まとめの枠の中に入れました。';

            return filled($m[3] ?? null) ? "<div class=\"summary-box\">{$m[2]}<h2" : "<div class=\"summary-box\">\n  {$m[1]}{$m[2]}";
        }, $content) ?? $content;

        // 枠のない「まとめ」「次に読む記事」「関連記事」（一番外側の見出しから、次の見出し・枠の前まで）
        $boxes = ['summary-box' => '/^まとめ/u', 'next-article-box' => '/^(次に読む(べき)?記事|次のステップ)/u', 'related-box' => '/^関連記事/u'];
        foreach ($boxes as $class => $pattern) {
            if (str_contains($content, "class=\"{$class}\"")) {
                continue;
            }
            $blocks = $this->topLevel($content);
            foreach ($blocks['h2'] as $h2) {
                if (! preg_match($pattern, $h2['text'])) {
                    continue;
                }
                $end = $this->sectionEnd($blocks, $h2['end'], $content);
                $section = rtrim(substr($content, $h2['start'], $end - $h2['start']));
                $content = substr($content, 0, $h2['start']) . "<div class=\"{$class}\">\n{$section}\n</div>\n\n" . ltrim(substr($content, $end));
                $notes[] = "「{$h2['text']}」を、{$class} の枠で囲みました。";

                break;
            }
        }

        if (! str_contains($content, 'class="point-box"')) {
            $notes[] = '「この記事で分かること」（ロードマップは「このページの使い方」）の枠（point-box）がありません。記事の冒頭に加えてください。';
        }

        return $content;
    }

    /**
     * 枠のない FAQ（見出し・質問の h3・回答）を、faq-box の形にする
     *
     * @param list<string> $notes
     */
    protected function wrapFaq(string $content, array &$notes): string
    {
        $blocks = $this->topLevel($content);
        foreach ($blocks['h2'] as $h2) {
            if (! preg_match('/よくある質問|FAQ/u', $h2['text'])) {
                continue;
            }

            $end = $this->sectionEnd($blocks, $h2['end'], $content);
            $parts = preg_split('/<h3\b[^>]*>(.*?)<\/h3>/su', substr($content, $h2['end'], $end - $h2['end']), -1, PREG_SPLIT_DELIM_CAPTURE);
            if (count($parts) < 3) {
                return $content;
            }

            $html = "<div class=\"faq-box\">\n  <h2>" . htmlspecialchars($h2['text'], ENT_QUOTES) . "</h2>\n";
            if (trim($parts[0]) !== '') {
                $html .= '  ' . trim($parts[0]) . "\n";
            }
            for ($i = 1, $number = 1; $i < count($parts); $i += 2, $number++) {
                $answer = trim($parts[$i + 1] ?? '');
                if (! preg_match('/^<(p|ul|ol|div|table|pre)\b/i', $answer)) {
                    $answer = "<p>{$answer}</p>";
                }
                $html .= "\n  <div class=\"faq-item\">\n    <div class=\"faq-q\">\n      <span class=\"faq-label\">Q{$number}</span>\n      <p>" . trim(strip_tags($parts[$i], '<code><strong>'))
                    . "</p>\n    </div>\n    <div class=\"faq-a\">\n      <span class=\"faq-label-a\">A</span>\n      {$answer}\n    </div>\n  </div>\n";
            }
            $html .= "</div>\n\n";

            $notes[] = 'FAQ を、FAQ の枠（faq-box）の形に直しました。';

            return substr($content, 0, $h2['start']) . $html . ltrim(substr($content, $end));
        }

        return $content;
    }

    /**
     * 見出しの節の終わり：次の一番外側の見出し、または節に含めない枠（補足・コード・ポイント・注意の枠は含める）の始まり
     *
     * @param array{h2: list<array{start: int, end: int, text: string}>, divs: list<array{start: int, end: int, class: string}>} $blocks
     */
    protected function sectionEnd(array $blocks, int $from, string $content): int
    {
        $end = strlen($content);
        foreach ($blocks['h2'] as $h2) {
            if ($h2['start'] >= $from) {
                $end = min($end, $h2['start']);
            }
        }
        foreach ($blocks['divs'] as $div) {
            if ($div['start'] >= $from && ! in_array($div['class'], ['supplement-box', 'code-block', 'graybox', 'caution-box'], true)) {
                $end = min($end, $div['start']);
            }
        }

        // 節の終わりの直前にある区切りのコメント（次の部品のもの）と広告は、節に含めない
        while (preg_match('/(?:<!--(?:(?!-->).)*-->|\[quads\s+id=\d+\])\s*$/su', substr($content, $from, $end - $from), $m)) {
            $end -= strlen($m[0]);
        }

        return $end;
    }

    /**
     * 本文（その補足の枠の外）に出てこない用語の補足（html-rules.md 3-4。D-35-02）。
     * 「補足：DOM（ドム）とは？」は、「DOM」か括弧の中の「ドム」のどちらかが本文にあればよい
     *
     * @return list<string> 用語
     */
    protected function orphanSupplements(string $content): array
    {
        preg_match_all('/<div class="supplement-box">.*?<\/div>/su', $content, $boxes, PREG_OFFSET_CAPTURE);

        $orphans = [];
        foreach ($boxes[0] as [$box, $offset]) {
            if (! preg_match('/補足[：:]\s*(.+?)\s*(?:とは)?\s*[？?]?\s*<\/strong>/u', $box, $m)) {
                continue;
            }
            $term = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES));
            $candidates = array_filter(array_map('trim', preg_split('/[（）()・／\/]/u', $term)), fn ($value) => mb_strlen($value) >= 2);
            $rest = html_entity_decode(strip_tags(substr($content, 0, $offset) . ' ' . substr($content, $offset + strlen($box))), ENT_QUOTES);

            $found = false;
            foreach ($candidates as $candidate) {
                if (mb_stripos($rest, $candidate) !== false) {
                    $found = true;
                    break;
                }
            }
            if (! $found && $candidates !== []) {
                $orphans[] = $term;
            }
        }

        return $orphans;
    }

    /**
     * @param list<string> $notes
     */
    protected function removeOldMarkup(string $content, array &$notes): string
    {
        // FAQ の構造化データ（書かない。html-rules.md 3-11）と、その区切りのコメント
        $content = preg_replace('/<script\s+type=["\']application\/ld\+json["\'][^>]*>.*?<\/script>\s*/is', '', $content, -1, $count) ?? $content;
        if ($count > 0) {
            $notes[] = 'FAQ の構造化データ（JSON-LD）を外しました。';
        }
        $content = preg_replace('/<!--[^>]*構造化データ[^>]*-->\s*/u', '', $content) ?? $content;

        // テーマの教材のショートコード（記事ごとの教材の目印にする。html-rules.md 3-10）
        $content = preg_replace('/\[[a-z]+_(?:book|udemy|school|cert)_box[a-z_]*\]\s*/', '', $content, -1, $count) ?? $content;
        if ($count > 0) {
            $notes[] = "テーマの教材のショートコードを {$count}件外しました。教材の紹介が残っているか確認してください。";
        }

        // 広告のショートコードと、その区切りのコメント（BlogOS が決まった位置に入れ直す。html-rules.md 4章）
        $content = preg_replace('/\[quads\s+id=\d+\]\s*/', '', $content) ?? $content;
        $content = preg_replace('/<!--[^>]*(?:AdSense|広告（BlogOS|広告ここまで)[^>]*-->\s*/u', '', $content) ?? $content;

        // 広告を含むことの表示・PR の印（BlogOS が入れ直す）
        $content = preg_replace('/<p class="pr-note">.*?<\/p>\s*/su', '', $content) ?? $content;

        return preg_replace('/\s*<span class="pr-label">PR<\/span>/u', '', $content) ?? $content;
    }

    /**
     * @param list<string> $notes
     */
    protected function replacePlaceholders(Blog $blog, string $content, array &$notes): string
    {
        return ArticlePlaceholders::replace($content, function (string $type, string $id) use ($blog, &$notes) {
            return match ($type) {
                '教材' => $this->materialHtml($blog, $id, $notes),
                '記事' => $this->articleLink($blog, $id, $notes),
                '画像' => $this->imageHtml($blog, $id, $notes),
                default => null,
            };
        });
    }

    /**
     * @param list<string> $notes
     */
    protected function materialHtml(Blog $blog, string $id, array &$notes): ?string
    {
        $material = ctype_digit($id) ? Material::where('blog_id', $blog->id)->find((int) $id) : null;
        if ($material === null) {
            $notes[] = "登録のない教材の目印です：[[教材:{$id}]]";

            return null;
        }

        $links = $this->programs->usableLinks($material);
        if (! $material->isActive() || $links === []) {
            $notes[] = "紹介に使えない教材です（使わない・提携中でない）：[[教材:{$id}]]「{$material->name}」";

            return null;
        }

        $e = fn (string $value) => htmlspecialchars($value, ENT_QUOTES);
        if ($material->kind === MaterialKind::Book) {
            $buttons = [];
            foreach (['Amazon' => ['amazon-btn', 'Amazonで見る'], '楽天' => ['rakuten-btn', '楽天で見る']] as $label => [$class, $text]) {
                if (isset($links[$label])) {
                    $buttons[] = "    <a href=\"{$e($links[$label])}\" rel=\"nofollow sponsored\" class=\"{$class}\">{$text}</a>";
                }
            }

            return "<div class=\"book-links\">\n" . implode("\n", $buttons) . "\n</div>";
        }

        $url = $links['リンク'] ?? reset($links);
        $suffix = $material->kind === MaterialKind::Udemy ? '（Udemy）' : '';

        return "<p>→ <a href=\"{$e($url)}\" rel=\"nofollow sponsored\">{$e($material->name)}{$suffix}</a></p>";
    }

    /**
     * 記事へのリンク。公開中の記事はリンクに、公開していない記事はタイトルだけにする（公開されたら、置き換え直すとリンクになる）
     *
     * @param list<string> $notes
     */
    protected function articleLink(Blog $blog, string $id, array &$notes): ?string
    {
        // まだ WordPress にない新規記事の編集案（[[記事:下書き123]]。D-39）。反映済みなら、その記事として扱う
        if (preg_match('/^下書き(\d+)$/u', $id, $m)) {
            $draft = ArticleDraft::with(['post:id,wordpress_id', 'page:id,wordpress_id'])->where('blog_id', $blog->id)->find((int) $m[1]);
            if ($draft === null || $draft->state === DraftState::Discarded) {
                $notes[] = "ない・破棄した編集案の目印です：[[記事:{$id}]]";

                return null;
            }
            $published = $draft->post ?? $draft->page;
            if ($published === null) {
                $notes[] = "まだ公開していない新しい記事は、タイトルだけにしました：「{$draft->title_raw}」（公開されると、BlogOS がリンクに切り替える編集案を作ります）";

                return "<!-- blogos:下書き:{$m[1]} -->" . htmlspecialchars((string) $draft->title_raw, ENT_QUOTES) . '<!-- /blogos -->';
            }
            $id = (string) $published->wordpress_id;
        }

        $article = null;
        if (ctype_digit($id)) {
            foreach ([Post::class, Page::class] as $modelClass) {
                $article ??= $modelClass::where('blog_id', $blog->id)->where('wordpress_id', (int) $id)->existing()->first(['id', 'title_raw', 'status', 'link', 'normalized_path']);
            }
        }
        if ($article === null) {
            $notes[] = "WordPress にない記事の目印です：[[記事:{$id}]]";

            return null;
        }

        $title = htmlspecialchars((string) $article->title_raw, ENT_QUOTES);
        if ($article->status !== 'publish') {
            $notes[] = "公開していない記事は、タイトルだけにしました：「{$article->title_raw}」（公開した後に「目印を置き換え直す」を押すと、リンクになります）";

            return "<!-- blogos:記事:{$id} -->{$title}<!-- /blogos -->";
        }

        $path = $article->normalized_path ?: (string) parse_url((string) $article->link, PHP_URL_PATH);

        return '<a href="' . htmlspecialchars($path ?: (string) $article->link, ENT_QUOTES) . "\">{$title}</a>";
    }

    /**
     * @param list<string> $notes
     */
    protected function imageHtml(Blog $blog, string $id, array &$notes): ?string
    {
        $image = ctype_digit($id) ? Image::with('media')->where('blog_id', $blog->id)->find((int) $id) : null;
        if ($image === null) {
            $notes[] = "登録のない画像の目印です：[[画像:{$id}]]";

            return null;
        }
        if ($image->media === null || blank($image->media->source_url)) {
            // 画像の依頼から作った画像は、WordPress に登録するまで目印のまま（反映できない）
            return null;
        }

        $media = $image->media;
        $attributes = [
            'src'    => $media->source_url,
            'alt'    => $image->alt ?: $media->alt_text,
            'width'  => $media->width ?: null,
            'height' => $media->height ?: null,
            'class'  => "wp-image-{$media->wordpress_id}",
        ];

        return '<p><img ' . implode(' ', array_map(fn ($name, $value) => $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES) . '"',
            array_keys(array_filter($attributes, fn ($value) => $value !== null)), array_filter($attributes, fn ($value) => $value !== null))) . '></p>';
    }

    /**
     * 広告を含むことの表示（教材の紹介がある記事だけ、記事の冒頭）と、教材の枠の見出しの PR の印（html-rules.md 3-1・3-10）
     */
    protected function addPrMarks(string $content, string $prNote): string
    {
        $classes = implode('|', array_map('preg_quote', self::MATERIAL_BOX_CLASSES));
        $content = preg_replace_callback('/(<div class="(?:' . $classes . ')">\s*(?:<!--.*?-->\s*)*<(h[234])>)(.*?)(<\/\2>)/su',
            fn ($m) => $m[1] . rtrim($m[3]) . ' <span class="pr-label">PR</span>' . $m[4], $content) ?? $content;

        if ($prNote !== '' && str_contains($content, 'rel="nofollow sponsored"')) {
            $content = '<p class="pr-note">' . htmlspecialchars($prNote, ENT_QUOTES) . "</p>\n\n" . ltrim($content);
        }

        return $content;
    }

    /**
     * 広告のショートコードを入れる（html-rules.md 4章）
     *
     * @param array{ads: array<string, int>, long_article?: array{chars: int, h2: int}} $settings
     * @param list<string> $notes
     */
    protected function insertAds(string $content, array $settings, array &$notes): string
    {
        $ads = $settings['ads'];
        $blocks = $this->topLevel($content);

        // 本文の範囲：最初の H2 から、教材の枠・FAQ・まとめのどれかが始まるまで
        $end = strlen($content);
        foreach ($blocks['divs'] as $div) {
            if (in_array($div['class'], [...self::MATERIAL_BOX_CLASSES, 'faq-box', 'summary-box'], true)) {
                $end = min($end, $div['start']);
            }
        }
        foreach ($blocks['h2'] as $h2) {
            if (str_starts_with($h2['text'], 'まとめ')) {
                $end = min($end, $h2['start']);
            }
        }
        $bodyH2 = array_values(array_filter($blocks['h2'], fn ($h2) => $h2['start'] < $end));

        $inserts = [];
        if ($bodyH2 === []) {
            $notes[] = '本文に H2 がないため、記事の上と途中の広告は入れませんでした。';
        } else {
            $inserts[$bodyH2[0]['start']] = $ads['top'] ?? null;

            $count = count($bodyH2);
            $long = $settings['long_article'] ?? null;
            $isLong = $long !== null && isset($ads['middle2'])
                && mb_strlen(preg_replace('/\s+/u', '', strip_tags($content))) >= (int) $long['chars'] && count($blocks['h2']) >= (int) $long['h2'];

            $positions = $isLong ? [(int) round($count / 3) => $ads['middle'] ?? null, (int) round($count * 2 / 3) => $ads['middle2']]
                : ($count >= 3 ? [intdiv($count, 2) => $ads['middle'] ?? null] : []);
            foreach ($positions as $index => $adId) {
                if ($index >= 1 && $index < $count && ! isset($inserts[$bodyH2[$index]['start']])) {
                    $inserts[$bodyH2[$index]['start']] = $adId;
                }
            }
        }

        // FAQ の後（なければ、まとめの前。直前が教材の枠なら入れない）
        if (isset($ads['bottom'])) {
            $faq = collect($blocks['divs'])->firstWhere('class', 'faq-box');
            $summary = collect($blocks['divs'])->firstWhere('class', 'summary-box') ?? collect($blocks['h2'])->first(fn ($h2) => str_starts_with($h2['text'], 'まとめ'));
            if ($faq !== null) {
                $inserts[$faq['end']] = $ads['bottom'];
            } elseif ($summary !== null && ! $this->followsMaterialBox($blocks['divs'], $summary['start'], $content)) {
                $inserts[$summary['start']] = $ads['bottom'];
            }
        }

        krsort($inserts);
        foreach ($inserts as $offset => $adId) {
            if ($adId === null) {
                continue;
            }
            $block = "\n<!-- ▼▼ 広告（BlogOS が入れる） ▼▼ -->\n[quads id={$adId}]\n<!-- ▲▲ 広告ここまで ▲▲ -->\n\n";
            $content = substr($content, 0, $offset) . $block . substr($content, $offset);
        }

        return $content;
    }

    /**
     * 一番外側の H2 と div（枠の中の見出しは除く）
     *
     * @return array{h2: list<array{start: int, end: int, text: string}>, divs: list<array{start: int, end: int, class: string}>}
     */
    protected function topLevel(string $content): array
    {
        preg_match_all('/<div\b[^>]*>|<\/div>|<h2\b[^>]*>(.*?)<\/h2>|<!--.*?-->/su', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $depth = 0;
        $h2 = [];
        $divs = [];
        $open = null;
        foreach ($matches as $match) {
            [$token, $offset] = $match[0];
            if (str_starts_with($token, '<!--')) {
                continue;
            }
            if (str_starts_with($token, '<h2')) {
                if ($depth === 0) {
                    $h2[] = ['start' => $offset, 'end' => $offset + strlen($token), 'text' => trim(strip_tags($match[1][0] ?? ''))];
                }
            } elseif (str_starts_with($token, '</div')) {
                $depth = max(0, $depth - 1);
                if ($depth === 0 && $open !== null) {
                    $divs[] = $open + ['end' => $offset + strlen($token)];
                    $open = null;
                }
            } else {
                if ($depth === 0) {
                    $open = ['start' => $offset, 'class' => preg_match('/class="([^"]*)"/', $token, $class) ? trim(explode(' ', $class[1])[0]) : ''];
                }
                $depth++;
            }
        }

        return ['h2' => $h2, 'divs' => $divs];
    }

    /**
     * @param list<array{start: int, end: int, class: string}> $divs
     */
    protected function followsMaterialBox(array $divs, int $offset, string $content): bool
    {
        foreach ($divs as $div) {
            if (in_array($div['class'], self::MATERIAL_BOX_CLASSES, true) && $div['end'] <= $offset
                && trim(preg_replace('/<!--.*?-->/s', '', substr($content, $div['end'], $offset - $div['end']))) === '') {
                return true;
            }
        }

        return false;
    }
}
