<?php

namespace Tests\Unit;

use App\Services\WordPressApi\ApiInspectionService;
use Tests\TestCase;

/**
 * WordPress API情報の詳細の画面の題名に付ける名前（D-72-11）
 */
class ApiInspectionNameTest extends TestCase
{
    public function test_name_is_title_or_name(): void
    {
        $service = app(ApiInspectionService::class);

        $this->assertSame('JavaScript', $service->name('categories', ['id' => 1, 'name' => 'JavaScript']));
        $this->assertSame('はじめての DOM', $service->name('posts', ['title' => ['raw' => 'はじめての DOM', 'rendered' => 'はじめての&nbsp;DOM']]));
        $this->assertSame('A & B', $service->name('media', ['title' => ['rendered' => '<b>A &amp; B</b>']]));
        $this->assertNull($service->name('categories', ['id' => 1]));
        $this->assertNull($service->name('root', ['name' => 'サイト']));
    }
}
