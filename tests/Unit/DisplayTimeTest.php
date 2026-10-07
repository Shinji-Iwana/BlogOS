<?php

namespace Tests\Unit;

use App\Support\DisplayTime;
use Tests\TestCase;

/**
 * 画面の日時は日本時間で出す（D-72-04）
 */
class DisplayTimeTest extends TestCase
{
    public function test_value_converts_only_utc_columns(): void
    {
        $this->assertSame('2026-10-07 13:30:00', DisplayTime::value('archived_at', '2026-10-07 04:30:00'));
        $this->assertSame('2026-10-07 13:30:00', DisplayTime::value('wordpress_modified_gmt', '2026-10-07 04:30:00'));
        // WordPress のサイトのタイムゾーンの時刻・日時でない値は、そのまま
        $this->assertSame('2026-10-07 13:30:00', DisplayTime::value('wordpress_modified', '2026-10-07 13:30:00'));
        $this->assertSame('タイトル', DisplayTime::value('title', 'タイトル'));
        $this->assertNull(DisplayTime::value('archived_at', null));
    }

    public function test_api_body_shows_japan_time(): void
    {
        $body = DisplayTime::apiBody([
            ['id' => 1, 'date' => '2026-10-07T13:30:00', 'date_gmt' => '2026-10-07T04:30:00', 'modified' => '2026-10-07T13:30:00', 'modified_gmt' => null, 'title' => ['rendered' => '2026-10-07T04:30:00']],
            ['registered_date' => '2026-10-07T04:30:00+00:00'],
        ]);

        $this->assertSame('2026-10-07 13:30:00（日本時間）', $body[0]['date']);
        $this->assertSame('2026-10-07 13:30:00（日本時間）', $body[0]['date_gmt']);
        // _gmt がない・null のものと、日時の名前でない値は、そのまま
        $this->assertSame('2026-10-07T13:30:00', $body[0]['modified']);
        $this->assertSame('2026-10-07T04:30:00', $body[0]['title']['rendered']);
        $this->assertSame('2026-10-07 13:30:00（日本時間）', $body[1]['registered_date']);
    }

    public function test_api_headers_show_japan_time(): void
    {
        $headers = DisplayTime::apiHeaders(['Date' => ['Wed, 07 Oct 2026 04:30:00 GMT'], 'X-WP-Total' => ['3']]);

        $this->assertSame(['2026-10-07 13:30:00（日本時間）'], $headers['Date']);
        $this->assertSame(['3'], $headers['X-WP-Total']);
    }
}
