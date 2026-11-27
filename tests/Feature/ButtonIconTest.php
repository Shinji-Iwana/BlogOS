<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ButtonIcon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * ボタンの名前の左のアイコン（D-121-01。docs/BLOGOS_UI.md 4-4）：WordPress を変えるボタンは WordPress、BlogOS の中だけを変えるボタンはアークリアクター。
 */
class ButtonIconTest extends TestCase
{
    use RefreshDatabase;

    /**
     * WordPress を変える送るボタン（画面のファイル => 数）。確認画面「WordPress を変えるボタンの名前の一覧」の13個から、記事のゴミ箱へ移動を除いた12個
     * （記事のゴミ箱へ移動は、なくした。D-124-01）
     */
    private const WORDPRESS_BUTTONS = [
        'articles/show.blade.php'          => 1, // 確認済みの画像を WordPress に登録する
        'articles/delete.blade.php'        => 1, // 完全に削除する
        'drafts/link-switch.blade.php'     => 1, // チェックした編集案を反映する
        'drafts/push.blade.php'            => 1, // WordPressへ反映する
        'images/legacy-replace.blade.php'  => 1, // 承認して反映する
        'images/show.blade.php'            => 1, // WordPress のメディアに登録する
        'launches/index.blade.php'         => 1, // WordPress に作る
        'launches/show.blade.php'          => 3, // 選んだものを公開する・⑧ 親ロードマップの編集案を作る・親ロードマップを公開する
        'terms/edit.blade.php'             => 2, // WordPressへ反映する・完全に削除する
    ];

    public function test_icon_kinds_and_tooltips(): void
    {
        $this->assertSame([ButtonIcon::WORDPRESS, ButtonIcon::BLOGOS], ButtonIcon::ALL);

        $wordpress = view('partials.button-icon', ['to' => ButtonIcon::WORDPRESS])->render();
        $this->assertStringContainsString('<span class="button-icon" data-button-icon="wordpress" title="' . ButtonIcon::LABELS[ButtonIcon::WORDPRESS] . '"><svg', $wordpress);
        // 1つのページに何度も出すため、切り抜き（mask）の id は1つずつ変える
        $again = view('partials.button-icon', ['to' => ButtonIcon::WORDPRESS])->render();
        preg_match('/<mask id="([^"]+)"/', $wordpress, $first);
        preg_match('/<mask id="([^"]+)"/', $again, $second);
        $this->assertNotSame($first[1], $second[1]);
        $this->assertStringContainsString('mask="url(#' . $second[1] . ')"', $again);

        $blogos = view('partials.button-icon', ['to' => ButtonIcon::BLOGOS])->render();
        $this->assertStringContainsString('<span class="button-icon" data-button-icon="blogos" title="' . ButtonIcon::LABELS[ButtonIcon::BLOGOS] . '"><svg', $blogos);
        // アークリアクター：内側の点線のリングと、逆三角（▽）
        $this->assertStringContainsString('stroke-dasharray', $blogos);
        $this->assertStringContainsString('<path d="M7.5 9.3h9L12 17.1z"', $blogos);
        // 色はボタンの文字の色
        $this->assertStringNotContainsString('#8fe9ff', $blogos);
        $this->assertStringContainsString('currentColor', $blogos);

        $css = file_get_contents(public_path('css/blogos.css'));
        $this->assertMatchesRegularExpression('/\.button-icon svg \{[^}]*width: 1\.2em;/', $css);

        $this->expectException(\InvalidArgumentException::class);
        ButtonIcon::label('google');
    }

    /**
     * 全ての画面の送るボタンに、どちらを変えるかのアイコンが付いている。付けないのは、GET のフォーム（絞り込み・取得）とログインだけ
     */
    public function test_every_submit_button_is_classified(): void
    {
        $wordpress = [];
        $missing = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            $source = $file->getContents();
            $offset = 0;
            while (($start = strpos($source, '<button', $offset)) !== false) {
                $end = $this->tagEnd($source, $start);
                $offset = $end + 1;
                $tag = substr($source, $start, $end - $start + 1);
                if (str_contains($tag, 'type="button"') || preg_match('/blog-switch-button|logout-button|voice-button/', $tag)) {
                    continue;
                }
                $after = substr($source, $end + 1, 80);
                if (preg_match("/^@include\('partials\.button-icon', \['to' => '(wordpress|blogos)'\]\)/", $after, $m)) {
                    if ($m[1] === 'wordpress') {
                        $wordpress[$relative] = ($wordpress[$relative] ?? 0) + 1;
                    }

                    continue;
                }
                // 付けない：GET のフォーム・ログイン・ポップアップの送るボタン（partials/modal-submit は自分で付ける）
                $before = substr($source, 0, $start);
                $form = strrpos($before, '<form');
                $closed = strrpos($before, '</form>');
                $method = 'GET';
                if ($form !== false && ($closed === false || $form > $closed)) {
                    preg_match('/method="(\w+)"/i', substr($source, $form, strpos($source, '>', $form) - $form), $mm);
                    $method = strtoupper($mm[1] ?? 'GET');
                }
                if ($method === 'GET' || $relative === 'auth/login-form.blade.php' || $relative === 'partials/modal-submit.blade.php') {
                    continue;
                }
                $missing[] = $relative . '：' . trim(strip_tags(substr($source, $end + 1, 60)));
            }
        }

        $this->assertSame([], $missing, 'アイコンのない送るボタン');
        ksort($wordpress);
        $expected = self::WORDPRESS_BUTTONS;
        ksort($expected);
        $this->assertSame($expected, $wordpress);
        // 12か所・12個のボタン（記事のゴミ箱へ移動は、なくした。D-124-01）
        $this->assertSame(12, array_sum($expected));
    }

    public function test_icons_are_rendered_in_pages(): void
    {
        $this->actingAs(User::factory()->create());
        $html = $this->get(route('scheduled-tasks.runs'))->assertOk()->getContent();

        // ポップアップの送るボタン（BlogOS）
        $this->assertStringContainsString('data-button-icon="blogos"', $html);
        // 絞り込み（GET）のボタンには付けない
        $this->assertMatchesRegularExpression('/<noscript><button type="submit" class="btn-secondary">絞り込む<\/button><\/noscript>/u', $html);
        // ページの中の WordPress のアイコンの切り抜きの id は、重ならない
        preg_match_all('/<mask id="([^"]+)"/', $html, $ids);
        $this->assertSame(count($ids[1]), count(array_unique($ids[1])));
    }

    /**
     * 開始タグの「>」の位置（属性の中の @disabled(…->…) や {{ … }} の「>」は飛ばす）
     */
    private function tagEnd(string $source, int $start): int
    {
        $depth = 0;
        $braces = 0;
        $length = strlen($source);
        for ($i = $start; $i < $length; $i++) {
            if (substr($source, $i, 2) === '{{') {
                $braces++;
                $i++;
            } elseif (substr($source, $i, 2) === '}}') {
                $braces--;
                $i++;
            } elseif ($source[$i] === '(') {
                $depth++;
            } elseif ($source[$i] === ')') {
                $depth--;
            } elseif ($source[$i] === '>' && $depth === 0 && $braces === 0) {
                return $i;
            }
        }

        return $length - 1;
    }
}
