<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /**
     * XServer情報の画面を、BlogOS の DB のテーブルの一覧の全ページ分つないで返す
     * （テーブルの一覧は50件ずつのページ送り。D-98-01）
     */
    protected function serverPageWithAllTables(): TestResponse
    {
        $pageName = 'tables_' . preg_replace('/\W/', '_', DB::connection()->getDatabaseName());
        $first = $this->get(route('server.index'))->assertOk();
        $html = $first->getContent();
        $pages = (int) ceil(count(DB::select('SHOW TABLES')) / \App\Support\ListPage::PER_PAGE);
        for ($page = 2; $page <= $pages; $page++) {
            $html .= $this->get(route('server.index', [$pageName => $page]))->assertOk()->getContent();
        }

        return TestResponse::fromBaseResponse(response($html));
    }
}
