<?php

namespace App\DTO\Google;

class AnalyticsPageData
{
    public function __construct(
        public readonly string $pagePath,
        public readonly array $metrics, // ['screenPageViews' => '123', 'activeUsers' => '45', ...]
    ) {
    }
}
