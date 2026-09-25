<?php

namespace App\Enums;

/**
 * 連携するGoogleのサービス（blog_google_properties.service・google_fetch_runs.service）。BLOGOS_DATABASE.md 12章。
 */
enum GoogleService: string
{
    case Ga4 = 'ga4';
    case SearchConsole = 'search_console';
    case Adsense = 'adsense';

    public function label(): string
    {
        return match ($this) {
            self::Ga4           => 'Google Analytics 4',
            self::SearchConsole => 'Search Console',
            self::Adsense       => 'AdSense',
        };
    }

    /**
     * 取得の失敗を sync_issues に記録するときの resource_type
     */
    public function issueResourceType(): string
    {
        return "google_{$this->value}";
    }
}
