<?php

namespace Tests\Feature;

use App\Http\Controllers\Articles\DraftController;
use App\Support\DatabaseColumns;
use App\Support\PostStatus;
use App\Support\WordPressColumns;
use Tests\TestCase;

/**
 * WordPress が決めている値の日本語は、WordPress の名前に揃える（D-119-01）
 */
class WordPressValueLabelTest extends TestCase
{
    // WordPress の日本語の名前（WordPress から同期した statuses.name）
    private const WORDPRESS_NAMES = [
        'publish' => '公開済み',
        'future'  => '予約済み',
        'draft'   => '下書き',
        'pending' => 'レビュー待ち',
        'private' => '非公開',
        'trash'   => 'ゴミ箱内',
    ];

    public function test_post_status_labels_match_wordpress_names(): void
    {
        foreach (self::WORDPRESS_NAMES as $status => $name) {
            $this->assertSame($name, PostStatus::label($status), $status);
            if (isset(DraftController::STATUSES[$status])) {
                $this->assertSame($name, DraftController::STATUSES[$status], "編集案の {$status}");
            }
        }
    }

    public function test_column_descriptions_use_wordpress_names(): void
    {
        $texts = [
            DatabaseColumns::describe('article_drafts', 'status'),
            WordPressColumns::describe('posts', 'status'),
            file_get_contents(app_path('Support/WordPressApiFields.php')),
            file_get_contents(app_path('Support/WordPressDatabaseTables.php')),
            file_get_contents(resource_path('views/wordpress-api/show.blade.php')),
        ];
        foreach ($texts as $text) {
            $this->assertStringContainsString('publish：公開済み', $text);
            $this->assertDoesNotMatchRegularExpression('/publish：公開[、）]|future：予約[、）]/u', $text);
        }
    }
}
