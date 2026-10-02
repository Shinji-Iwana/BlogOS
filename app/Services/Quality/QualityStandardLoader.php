<?php

namespace App\Services\Quality;

use App\Support\QualityProfiles;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * 品質基準のファイル（resources/quality/）を読み込む（D-06-08、D-07-05、D-14-02〜D-14-06、D-47）。
 *
 * 採点項目・必須条件・バージョン・対象外の項目・観点・記事の型の項目は、品質基準のファイルが正本であり、コードに書かない。
 * ファイルの表の形（キーを `...` で書いた行）から読み取る。
 */
class QualityStandardLoader
{
    /** @var array<string, QualityStandard> */
    protected array $loaded = [];

    public function load(?string $profile): QualityStandard
    {
        $cacheKey = $profile ?? '';

        return $this->loaded[$cacheKey] ??= $this->read($profile);
    }

    protected function read(?string $profile): QualityStandard
    {
        $scoring = $this->file('common/scoring.md');

        [$required, $items, $categories, $axes] = $this->parseScoring($scoring);

        if ($items === [] || $required === []) {
            throw new RuntimeException('品質基準（common/scoring.md）から採点項目・必須条件を読み取れませんでした。');
        }

        $profile = $profile !== null && in_array($profile, QualityProfiles::available(), true) ? $profile : null;

        $typeExclusions = [];
        $blogExclusions = [];
        $articleTypes = [];
        $typeItems = [];
        $importance = [];
        $profileVersion = null;

        if ($profile !== null) {
            $profileText = $this->file("blogs/{$profile}/profile.md");
            $profileVersion = $this->version($profileText);
            $blogExclusions = $this->parseBlogExclusions($profileText, array_keys($items));

            $types = QualityProfiles::articleTypes($profile);
            $articleTypes = $types['types'];

            if (File::exists(resource_path("quality/blogs/{$profile}/article-types.md"))) {
                $text = $this->file("blogs/{$profile}/article-types.md");
                $typeExclusions = $this->parseTypeExclusions($text, $articleTypes, array_keys($items));
                $typeItems = $this->parseTypeItems($text, $this->typeCategory($categories));
                $importance = $this->parseImportance($text, $articleTypes, array_keys($axes));
            }
        }

        return new QualityStandard(
            $this->version($this->file('common/principles.md')) ?? $this->version($scoring) ?? 'unknown',
            $profile,
            $profileVersion,
            $required,
            $items,
            $categories,
            $typeExclusions,
            $blogExclusions,
            $articleTypes,
            $axes,
            $typeItems,
            $importance,
        );
    }

    /**
     * common/scoring.md の「2. 必須条件」「3. 採点項目」「4. 観点」の表
     */
    protected function parseScoring(string $text): array
    {
        $required = [];
        $items = [];
        $categories = [];
        $axes = [];
        $section = null;
        $category = null;

        foreach ($this->lines($text) as $line) {
            if (preg_match('/^## 2\./u', $line)) {
                $section = 'required';
            } elseif (preg_match('/^## 3\./u', $line)) {
                $section = 'items';
            } elseif (preg_match('/^## 4\. 観点/u', $line)) {
                $section = 'axes';
            } elseif (preg_match('/^## /u', $line)) {
                $section = null;
            } elseif ($section === 'items' && preg_match('/^### (\S+)\s+(.+?)（\d+点）/u', $line, $matches)) {
                $category = "{$matches[1]} {$matches[2]}";
                $categories[$category] = $category;
            } elseif ($section === 'required' && preg_match('/^\|\s*`(req\.[a-z_]+)`\s*\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|/u', $line, $matches)) {
                $required[$matches[1]] = ['label' => $matches[2], 'ai' => str_contains($matches[3], 'AI')];
            } elseif ($section === 'items' && $category !== null && ($row = $this->row($line)) !== null && preg_match('/^`([a-z_]+\.[a-z_]+)`/u', $row[0], $key)) {
                $items[$key[1]] = [
                    'category' => $category,
                    'label'    => $row[1] ?? '',
                    'points'   => (int) ($row[2] ?? 0),
                    'ai'       => str_contains($row[3] ?? '', 'AI'),
                    'criteria' => filled($row[4] ?? null) ? $row[4] : null,
                    'axes'     => $this->keys($row[5] ?? ''),
                    'required' => false,
                ];
            } elseif ($section === 'axes' && ($row = $this->row($line)) !== null && preg_match('/^`([a-z_]+)`$/u', $row[0], $key)) {
                $axes[$key[1]] = ['label' => $row[1] ?? $key[1], 'description' => $row[2] ?? ''];
            }
        }

        return [$required, $items, $categories, $axes];
    }

    /**
     * article-types.md の記事の型の項目（見出しの `値` が記事の型：記事種類、または集客記事の細分類）
     *
     * @return array<string, array<string, array<string, mixed>>> 型 => キー => 項目
     */
    protected function parseTypeItems(string $text, ?string $category): array
    {
        $result = [];
        $form = null;

        foreach ($this->lines($text) as $line) {
            if (preg_match('/^#{2,3} /u', $line)) {
                $form = preg_match('/^### .*（`([a-z_]+)`）/u', $line, $matches) ? $matches[1] : null;

                continue;
            }
            if ($form === null || ($row = $this->row($line)) === null || ! preg_match('/^`(type\.[a-z_]+)`/u', $row[0], $key)) {
                continue;
            }
            $result[$form][$key[1]] = [
                'category' => $category ?? '② 記事の型',
                'label'    => $row[1] ?? '',
                'points'   => (int) ($row[2] ?? 0),
                'ai'       => true,
                'required' => str_contains($row[3] ?? '', '★'),
                'criteria' => filled($row[4] ?? null) ? $row[4] : null,
                'axes'     => array_values(array_unique(array_merge(['type'], $this->keys($row[5] ?? '')))),
            ];
        }

        return $result;
    }

    /**
     * article-types.md の「観点の重要度」の表（見出しの行の記事種類の名前 → 値に変換する）
     *
     * @param array<string, string> $articleTypes 値 => 名前
     * @param list<string> $axisKeys
     * @return array<string, array<string, string>> 記事種類 => 観点 => 重要度（重要・推奨）
     */
    protected function parseImportance(string $text, array $articleTypes, array $axisKeys): array
    {
        $byLabel = array_flip($articleTypes);
        $columns = null;
        $inSection = false;
        $result = [];

        foreach ($this->lines($text) as $line) {
            if (preg_match('/^#{2,3} /u', $line)) {
                $inSection = str_contains($line, '観点の重要度');
                $columns = null;

                continue;
            }
            if (! $inSection || ($row = $this->row($line)) === null) {
                continue;
            }
            if ($columns === null) {
                $columns = array_map(fn ($label) => $byLabel[$label] ?? null, array_slice($row, 1));

                continue;
            }
            if (! preg_match('/^`([a-z_]+)`$/u', $row[0], $key) || ! in_array($key[1], $axisKeys, true)) {
                continue;
            }
            foreach ($columns as $index => $type) {
                if ($type !== null && filled($row[$index + 1] ?? null)) {
                    $result[$type][$key[1]] = $row[$index + 1];
                }
            }
        }

        return $result;
    }

    /**
     * @param array<string, string> $categories
     */
    protected function typeCategory(array $categories): ?string
    {
        foreach ($categories as $category) {
            if (str_contains($category, '記事の型')) {
                return $category;
            }
        }

        return null;
    }

    /**
     * ブログ別の定義の「採点項目の適用」の節で、「対象外」とした行に書かれた採点項目のキー
     *
     * @param array<int, string> $knownKeys
     * @return array<int, string>
     */
    protected function parseBlogExclusions(string $text, array $knownKeys): array
    {
        $keys = [];
        $inSection = false;

        foreach ($this->lines($text) as $line) {
            if (preg_match('/^## /u', $line)) {
                $inSection = str_contains($line, '採点項目の適用');

                continue;
            }
            if ($inSection && str_contains($line, '対象外') && preg_match_all('/`([a-z_]+\.[a-z_]+)`/u', $line, $matches)) {
                array_push($keys, ...array_intersect($matches[1], $knownKeys));
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * article-types.md の「記事種類ごとの採点の扱い」の表（記事種類の名前 → 値に変換する）
     *
     * @param array<string, string> $articleTypes 値 => 名前
     * @param array<int, string> $knownKeys
     * @return array<string, array<int, string>>
     */
    protected function parseTypeExclusions(string $text, array $articleTypes, array $knownKeys): array
    {
        $byLabel = array_flip($articleTypes);
        $result = [];
        $inSection = false;

        foreach ($this->lines($text) as $line) {
            if (preg_match('/^###? /u', $line)) {
                $inSection = str_contains($line, '記事種類ごとの採点の扱い');

                continue;
            }
            if (! $inSection || ! preg_match('/^\|\s*([^|`]+?)\s*\|\s*([^|]*)\|/u', $line, $matches)) {
                continue;
            }

            $type = $byLabel[$matches[1]] ?? null;
            if ($type === null) {
                continue;
            }

            preg_match_all('/`([a-z_]+\.[a-z_]+)`/u', $matches[2], $keys);
            $result[$type] = array_values(array_intersect($keys[1], $knownKeys));
        }

        return $result;
    }

    /**
     * 表の行のセル（区切りの行・表でない行は null）
     *
     * @return list<string>|null
     */
    protected function row(string $line): ?array
    {
        $line = trim($line);
        if (! str_starts_with($line, '|') || preg_match('/^\|[\s|:-]+\|$/', $line)) {
            return null;
        }

        return array_map('trim', explode('|', trim($line, '|')));
    }

    /**
     * セルの中の `キー` を並べたもの
     *
     * @return list<string>
     */
    protected function keys(string $cell): array
    {
        preg_match_all('/`([a-z_]+)`/u', $cell, $matches);

        return $matches[1];
    }

    protected function version(string $text): ?string
    {
        return preg_match('/バージョン:\*\*\s*([0-9]+\.[0-9]+\.[0-9]+)/u', $text, $matches) ? $matches[1] : null;
    }

    protected function file(string $path): string
    {
        $fullPath = resource_path("quality/{$path}");

        if (! File::exists($fullPath)) {
            throw new RuntimeException("品質基準のファイルが見つかりません：resources/quality/{$path}");
        }

        return File::get($fullPath);
    }

    /**
     * @return array<int, string>
     */
    protected function lines(string $text): array
    {
        return preg_split('/\r\n|\n/', $text);
    }
}
