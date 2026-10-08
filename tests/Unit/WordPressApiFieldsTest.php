<?php

namespace Tests\Unit;

use App\Support\WordPressApiFields;
use Tests\TestCase;

/**
 * WordPress API情報の画面の「詳細」の表（D-72-10）
 */
class WordPressApiFieldsTest extends TestCase
{
    public function test_leaf_values_and_nested_groups(): void
    {
        $this->assertSame('12', WordPressApiFields::leaf('id', 12));
        $this->assertSame('3、5', WordPressApiFields::leaf('categories', [3, 5]));
        $this->assertSame('false', WordPressApiFields::leaf('sticky', false));
        $this->assertSame('（なし）', WordPressApiFields::leaf('meta', []));
        // 入れ子のまとまりは、押して開く行にする（null）
        $this->assertNull(WordPressApiFields::leaf('title', ['raw' => 'タイトル', 'rendered' => 'タイトル']));
        $this->assertNull(WordPressApiFields::leaf('_links', ['self' => [['href' => 'https://example.test/']]]));
        // API Root の routes は、まとめて1行（JSON）
        $this->assertStringContainsString('"methods"', WordPressApiFields::leaf('routes', ['/' => ['methods' => ['GET']]]));
    }

    public function test_nested_groups_open_one_level_at_a_time(): void
    {
        $html = view('wordpress-api.fields', [
            'fields'   => ['id' => 12, '_links' => ['self' => [['href' => 'https://example.test/wp-json/wp/v2/posts/12']]]],
            'resource' => 'posts',
            'path'     => '',
        ])->render();

        $this->assertStringContainsString('<strong>_links</strong>', $html);
        $this->assertStringContainsString('<strong>self</strong>', $html);
        $this->assertStringContainsString('<strong>0</strong>', $html);
        $this->assertStringContainsString('https://example.test/wp-json/wp/v2/posts/12', $html);
        // 説明の「?」は、入れ子でない一番上の項目だけ
        $this->assertMatchesRegularExpression('/id\s*<span class="tip"/', $html);
        $this->assertDoesNotMatchRegularExpression('/href\s*<span class="tip"/', $html);
        $this->assertStringContainsString('<summary><strong>_links</strong></summary>', $html);
    }

    public function test_describe_uses_field_or_group_description(): void
    {
        $this->assertSame('タイトル（表示用に整えた HTML）。', WordPressApiFields::describe('posts', 'title.rendered'));
        $this->assertSame('親カテゴリ（WordPress の ID。0 は親なし）。', WordPressApiFields::describe('categories', 'parent'));
        $this->assertStringStartsWith('関連する API の URL', WordPressApiFields::describe('posts', '_links.self.0.href'));
        $this->assertSame('サイトのアドレス（サイトを見る人が開く URL）。', WordPressApiFields::describe('root', 'home'));
    }
}
