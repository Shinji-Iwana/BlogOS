<?php

namespace Tests\Unit;

use App\Support\SvgSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * 図解の SVG を安全な形にする（D-32-02）。
 */
class SvgSanitizerTest extends TestCase
{
    public function test_unsafe_parts_are_removed_and_drawing_is_kept(): void
    {
        $svg = <<<'SVG'
            ```svg
            <svg width="800" height="400" viewBox="0 0 800 400" onload="alert(1)">
              <defs><marker id="arrow" markerWidth="10" markerHeight="10"><path d="M0,0 L10,5 L0,10 z" fill="#1e6fd9"/></marker></defs>
              <script>alert(1)</script>
              <foreignObject><div>html</div></foreignObject>
              <image href="https://example.test/tracker.png"/>
              <style>@import url(https://example.test/font.css); text { fill: #333333; }</style>
              <rect x="10" y="10" width="200" height="80" rx="8" fill="#e8f0fb" onclick="steal()"/>
              <a href="https://example.test/"><text x="20" y="50">クリック</text></a>
              <line x1="210" y1="50" x2="400" y2="50" stroke="#1e6fd9" marker-end="url(#arrow)"/>
              <use href="#arrow"/>
              <text x="420" y="50" font-family="'Noto Sans JP',sans-serif">イベントの発生</text>
            </svg>
            ```
            SVG;

        $clean = SvgSanitizer::sanitize($svg);

        $this->assertStringStartsWith('<svg', $clean);
        $this->assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $clean);
        foreach (['<script', 'alert(1)', 'foreignObject', 'onload', 'onclick', 'tracker.png', '@import', 'https://example.test/', '<a '] as $removed) {
            $this->assertStringNotContainsString($removed, $clean, $removed);
        }
        foreach (['イベントの発生', '<marker id="arrow"', 'marker-end="url(#arrow)"', '<use href="#arrow"', 'rx="8"', 'fill: #333333'] as $kept) {
            $this->assertStringContainsString($kept, $clean, $kept);
        }
        $this->assertSame([800, 400], SvgSanitizer::size($clean));
    }

    public function test_size_falls_back_to_view_box_and_invalid_svg_is_rejected(): void
    {
        $this->assertSame([640, 360], SvgSanitizer::size('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 360"></svg>'));

        $this->expectException(\InvalidArgumentException::class);
        SvgSanitizer::sanitize('<html><body>not svg</body></html>');
    }

    public function test_external_entities_are_not_loaded(): void
    {
        $svg = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>';

        $clean = SvgSanitizer::sanitize($svg);

        $this->assertStringNotContainsString('root:', $clean);
        $this->assertStringNotContainsString('DOCTYPE', $clean);
    }
}
