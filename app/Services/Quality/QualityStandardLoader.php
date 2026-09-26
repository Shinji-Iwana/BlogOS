<?php

namespace App\Services\Quality;

use App\Support\QualityProfiles;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * 品質基準のファイル（resources/quality/）を読み込む（D-06-08、D-07-05、D-14-02〜D-14-06）。
 *
 * 採点項目・必須条件・バージョン・対象外の項目は、品質基準のファイルが正本であり、コードに書かない。
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

        [$required, $items, $categories] = $this->parseScoring($scoring);

        if ($items === [] || $required === []) {
            throw new RuntimeException('品質基準（common/scoring.md）から採点項目・必須条件を読み取れませんでした。');
        }

        $profile = $profile !== null && in_array($profile, QualityProfiles::available(), true) ? $profile : null;

        $typeExclusions = [];
        $blogExclusions = [];
        $articleTypes = [];
        $profileVersion = null;

        if ($profile !== null) {
            $profileText = $this->file("blogs/{$profile}/profile.md");
            $profileVersion = $this->version($profileText);
            $blogExclusions = $this->parseBlogExclusions($profileText, array_keys($items));

            $types = QualityProfiles::articleTypes($profile);
            $articleTypes = $types['types'];

            if (File::exists(resource_path("quality/blogs/{$profile}/article-types.md"))) {
                $typeExclusions = $this->parseTypeExclusions($this->file("blogs/{$profile}/article-types.md"), $articleTypes, array_keys($items));
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
        );
    }

    /**
     * common/scoring.md の「2. 必須条件」と「3. 採点項目」の表
     */
    protected function parseScoring(string $text): array
    {
        $required = [];
        $items = [];
        $categories = [];
        $section = null;
        $category = null;

        foreach ($this->lines($text) as $line) {
            if (preg_match('/^## 2\./u', $line)) {
                $section = 'required';
            } elseif (preg_match('/^## 3\./u', $line)) {
                $section = 'items';
            } elseif (preg_match('/^## /u', $line)) {
                $section = null;
            } elseif ($section === 'items' && preg_match('/^### (\S+)\s+(.+?)（\d+点）/u', $line, $matches)) {
                $category = "{$matches[1]} {$matches[2]}";
                $categories[$category] = $category;
            } elseif ($section === 'required' && preg_match('/^\|\s*`(req\.[a-z_]+)`\s*\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|/u', $line, $matches)) {
                $required[$matches[1]] = ['label' => $matches[2], 'ai' => str_contains($matches[3], 'AI')];
            } elseif ($section === 'items' && $category !== null && preg_match('/^\|\s*`([a-z_]+\.[a-z_]+)`\s*\|\s*([^|]+?)\s*\|\s*(\d+)\s*\|\s*([^|]+?)\s*\|/u', $line, $matches)) {
                $items[$matches[1]] = [
                    'category' => $category,
                    'label'    => $matches[2],
                    'points'   => (int) $matches[3],
                    'ai'       => str_contains($matches[4], 'AI'),
                ];
            }
        }

        return [$required, $items, $categories];
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
     * article-types.md の「6-1. 記事種類ごとの採点の扱い」の表（記事種類の名前 → 値に変換する）
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
