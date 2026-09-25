<?php

namespace Tests\Unit;

use App\Services\Articles\ContentExtractor;
use App\Support\ArticlePath;
use PHPUnit\Framework\TestCase;

/**
 * 本文からの内部リンク・画像の抽出（BLOGOS_DATABASE.md 7章）。
 */
class ContentExtractorTest extends TestCase
{
    public function test_only_internal_article_links_are_extracted(): void
    {
        $html = <<<'HTML'
            <!-- wp:paragraph -->
            <p><a href="https://example.com/php/basics/">PHP  の
            基本</a>
            <a href="http://EXAMPLE.com/laravel/?utm=1#top">Laravel</a>
            <a href="/about/">運営者</a>
            <a href="https://other.example.org/x/">外部</a>
            <a href="#section">ページ内</a>
            <a href="mailto:a@example.com">メール</a>
            <a href="https://example.com/wp-content/uploads/a.png">画像</a>
            <a href="https://example.com/wp-admin/">管理</a>
            <a href="relative/path">相対</a></p>
            <!-- /wp:paragraph -->
            HTML;

        $links = (new ContentExtractor())->internalLinks($html, 'https://example.com');

        $this->assertSame(
            ['https://example.com/php/basics/', 'http://EXAMPLE.com/laravel/?utm=1#top', '/about/'],
            array_column($links, 'url')
        );
        $this->assertSame('PHP の 基本', $links[0]['anchor_text']);
    }

    public function test_images_with_media_id_class(): void
    {
        $html = '<figure><img src="https://example.com/wp-content/uploads/a-300x200.png" class="wp-image-42 size-medium" alt="日本語"></figure>'
            . '<img src="/wp-content/img/b.png">'
            . '<img src="//i.moshimo.com/af/i/impression?a_id=1">'
            . '<img src="https://cdn.other.example.org/c.png">';

        $images = (new ContentExtractor())->images($html, 'https://example.com');

        // 外部の画像（計測用の画像など）は除く
        $this->assertSame([
            ['url' => 'https://example.com/wp-content/uploads/a-300x200.png', 'wordpress_media_id' => 42],
            ['url' => '/wp-content/img/b.png', 'wordpress_media_id' => null],
        ], $images);
    }

    public function test_empty_content(): void
    {
        $this->assertSame([], (new ContentExtractor())->internalLinks(null, 'https://example.com'));
        $this->assertSame([], (new ContentExtractor())->images('', 'https://example.com'));
    }

    public function test_article_path(): void
    {
        $this->assertSame('/php/入門', ArticlePath::fromUrl('https://example.com/php/%E5%85%A5%E9%96%80/?x=1#a'));
        $this->assertSame('/', ArticlePath::fromUrl('https://example.com'));
        $this->assertNull(ArticlePath::fromUrl(''));
    }
}
