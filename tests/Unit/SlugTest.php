<?php

namespace Tests\Unit;

use App\Support\Slug;
use PHPUnit\Framework\TestCase;

/**
 * スラッグの表示と比較（D-29）。
 */
class SlugTest extends TestCase
{
    public function test_encoded_japanese_slug_is_displayed_readable(): void
    {
        $this->assertSame('データベース', Slug::display('%e3%83%87%e3%83%bc%e3%82%bf%e3%83%99%e3%83%bc%e3%82%b9'));
        $this->assertSame('aws-clf-と-saa', Slug::display('aws-clf-%e3%81%a8-saa'));
        $this->assertSame('php-basics', Slug::display('php-basics'));
        $this->assertNull(Slug::display(null));

        // 符号化として正しくない値は、そのまま表示する
        $this->assertSame('100%-guide', Slug::display('100%-guide'));
        $this->assertSame('%ff%fe', Slug::display('%ff%fe'));
    }

    public function test_encoded_and_readable_forms_are_the_same(): void
    {
        $this->assertTrue(Slug::same('%e3%83%87%e3%83%bc%e3%82%bf', 'データ'));
        $this->assertTrue(Slug::same('%E3%83%87%E3%83%BC%E3%82%BF', '%e3%83%87%e3%83%bc%e3%82%bf'));
        $this->assertTrue(Slug::same('PHP', 'php'));
        $this->assertFalse(Slug::same('データ', 'データベース'));
        $this->assertFalse(Slug::same(null, ''));
    }
}
