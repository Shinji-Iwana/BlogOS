<?php

namespace Tests\Unit;

use App\Enums\MaterialKind;
use App\Services\Materials\MaterialLinkScanner;
use App\Support\AffiliateLink;
use PHPUnit\Framework\TestCase;

/**
 * 教材のリンクの読み取りと、記事の本文からの検出（D-30）。
 */
class AffiliateLinkTest extends TestCase
{
    public function test_links_of_the_same_material_have_the_same_key(): void
    {
        // もしも経由の Amazon は、遷移先の ASIN で識別する（書き方の違いを吸収する）
        $moshimo = '//af.moshimo.com/af/c/click?a_id=5439454&p_id=170&pc_id=185&pl_id=4062&url=https%3A%2F%2Fwww.amazon.co.jp%2Fdp%2F4295005924';
        $this->assertSame('amazon:4295005924', AffiliateLink::key($moshimo));
        $this->assertSame('amazon:4295005924', AffiliateLink::key('https://www.amazon.co.jp/dp/4295005924/?tag=example-22'));
        $this->assertSame('amazon:4295005924', AffiliateLink::key(str_replace('&', '&amp;', $moshimo)));

        $this->assertSame('rakuten-books:15827907', AffiliateLink::key('//af.moshimo.com/af/c/click?a_id=1&p_id=54&pc_id=54&pl_id=616&url=https%3A%2F%2Fbooks.rakuten.co.jp%2Frb%2F15827907%2F'));
        $this->assertSame('udemy-trk:3kkdxr', AffiliateLink::key('https://trk.udemy.com/3kkdxr'));
        $this->assertSame('udemy-course:javascript-complete', AffiliateLink::key('https://www.udemy.com/course/javascript-complete/'));

        // 遷移先のないもしものリンク（スクールなど）は、提携先の広告で識別する（a_id は含めない）
        $this->assertSame('moshimo:1000-1380-72072', AffiliateLink::key('//af.moshimo.com/af/c/click?a_id=111&p_id=1000&pc_id=1380&pl_id=72072'));
        $this->assertSame('moshimo:1000-1380-72072', AffiliateLink::key('https://af.moshimo.com/af/c/click?a_id=222&p_id=1000&pc_id=1380&pl_id=72072'));

        $this->assertNull(AffiliateLink::key(''));
    }

    public function test_url_is_extracted_from_html(): void
    {
        $html = '<a href="//af.moshimo.com/af/c/click?a_id=1&amp;p_id=1000&amp;pc_id=1380&amp;pl_id=72072" rel="nofollow" referrerpolicy="no-referrer-when-downgrade">DMM WEBCAMP</a><img src="//i.moshimo.com/af/i/impression?a_id=1" width="1" height="1">';

        $this->assertSame('https://af.moshimo.com/af/c/click?a_id=1&p_id=1000&pc_id=1380&pl_id=72072', AffiliateLink::extractUrl($html));
        $this->assertSame('https://trk.udemy.com/abc', AffiliateLink::extractUrl(' https://trk.udemy.com/abc '));
        $this->assertNull(AffiliateLink::extractUrl('<p>リンクなし</p>'));
    }

    public function test_product_pages_are_made_from_affiliate_links(): void
    {
        // 書籍の商品ページは、Amazon と楽天で別のURL（D-30-09）
        $amazon = '//af.moshimo.com/af/c/click?a_id=1&p_id=170&pc_id=185&pl_id=4062&url=https%3A%2F%2Fwww.amazon.co.jp%2Fdp%2F4295005924';
        $rakuten = '//af.moshimo.com/af/c/click?a_id=2&p_id=54&pc_id=54&pl_id=616&url=https%3A%2F%2Fbooks.rakuten.co.jp%2Frb%2F15827907%2F';
        $this->assertSame('https://www.amazon.co.jp/dp/4295005924', AffiliateLink::amazonProductUrl($amazon));
        $this->assertSame('https://www.amazon.co.jp/dp/4295005924', AffiliateLink::amazonProductUrl('https://www.amazon.co.jp/gp/product/4295005924/?tag=x-22'));
        $this->assertSame('https://books.rakuten.co.jp/rb/15827907/', AffiliateLink::rakutenProductUrl($rakuten));
        $this->assertNull(AffiliateLink::amazonProductUrl($rakuten));
        $this->assertNull(AffiliateLink::rakutenProductUrl('https://book.impress.co.jp/books/1119101030'));

        // Udemy・スクール：リンクに遷移先が入っていれば、それが商品ページ
        $this->assertSame('https://zero2one.jp/product/aws-training-clf/', AffiliateLink::landingUrl('//af.moshimo.com/af/c/click?a_id=3&p_id=5256&pc_id=14256&pl_id=68863&url=https%3A%2F%2Fzero2one.jp%2Fproduct%2Faws-training-clf%2F'));
        $this->assertNull(AffiliateLink::landingUrl('//af.moshimo.com/af/c/click?a_id=3&p_id=1000&pc_id=1380&pl_id=72072'));
        $this->assertNull(AffiliateLink::landingUrl('https://trk.udemy.com/3kkdxr'));
    }

    public function test_isbn13_is_calculated_from_book_asin(): void
    {
        $this->assertSame('9784295005926', AffiliateLink::isbn13FromAsin('4295005924'));
        $this->assertSame('9784297148201', AffiliateLink::isbn13FromAsin('429714820X'));
        // 書籍以外の ASIN
        $this->assertNull(AffiliateLink::isbn13FromAsin('B0BN9XD2NQ'));
    }

    public function test_scanner_detects_only_affiliate_links_and_groups_books(): void
    {
        $content = <<<'HTML'
            <div class="book-box">
              <h3>いちばんやさしいJavaScriptの教本 第2版</h3>
              <p>説明</p>
              <div class="book-links">
                <a href="//af.moshimo.com/af/c/click?a_id=1&p_id=170&pc_id=185&pl_id=4062&url=https%3A%2F%2Fwww.amazon.co.jp%2Fdp%2F4295005924" rel="nofollow sponsored">Amazonで見る</a>
                <a href="//af.moshimo.com/af/c/click?a_id=2&p_id=54&pc_id=54&pl_id=616&url=https%3A%2F%2Fbooks.rakuten.co.jp%2Frb%2F15827907%2F" rel="nofollow sponsored">楽天で見る</a>
              </div>
            </div>
            <div class="udemy-box">
              <p>→ <a href="https://trk.udemy.com/3kkdxr" rel="nofollow sponsored">超JavaScript 完全ガイド 2026（Udemy）</a></p>
            </div>
            <p><a href="//af.moshimo.com/af/c/click?a_id=3&p_id=1000&pc_id=1380&pl_id=72072">DMM WEBCAMP 学習コース（無料相談はこちら）</a></p>
            <p><a href="http://snnsk.com/81.html">Excel初心者向けの入門講座</a></p>
            <p><a href="https://example.com/">例</a></p>
            HTML;

        $links = (new MaterialLinkScanner())->scan($content);

        $this->assertCount(4, $links);
        [$amazon, $rakuten, $udemy, $school] = $links;

        // 「Amazonで見る」「楽天で見る」は、見出しの書名を名前にし、同じ教材にまとめる
        $this->assertSame(MaterialKind::Book, $amazon['kind']);
        $this->assertSame('いちばんやさしいJavaScriptの教本 第2版', $amazon['name']);
        $this->assertSame('amazon', $amazon['link_type']);
        $this->assertSame('rakuten', $rakuten['link_type']);
        $this->assertSame($amazon['group'], $rakuten['group']);

        $this->assertSame(MaterialKind::Udemy, $udemy['kind']);
        $this->assertSame('超JavaScript 完全ガイド 2026', $udemy['name']);

        $this->assertSame(MaterialKind::School, $school['kind']);
        $this->assertSame('DMM WEBCAMP 学習コース', $school['name']);
        $this->assertNotSame($udemy['group'], $school['group']);
    }
}
