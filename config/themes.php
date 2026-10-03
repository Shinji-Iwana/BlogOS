<?php

/*
 * 画面のテーマ（D-16-01・D-49）
 *
 * テーマは見た目だけを担当し、データ・業務処理・ルートはテーマによって変えない。
 * 使うテーマは画面「設定」で選ぶ（system_settings の theme）。選んでいない・登録が消えた場合は default を使う。
 *
 * テーマを追加する手順（resources/views/themes/README.md）：
 *   1. public/themes/{テーマ名}/css/style.css と js/script.js を作る（全画面で読み込む）
 *   2. 画面を作り替える場合だけ、resources/views/themes/{テーマ名}/ に、共通の画面と同じ名前の View を置く
 *      （例：dashboard/index.blade.php、layouts/app.blade.php）。置いていない画面は、共通の画面をそのまま使う
 *   3. 下の themes に1行を追加する
 */
return [

    // 選んでいない場合のテーマ
    'default' => 'blank',

    // 選べるテーマ。キーはフォルダの名前（英小文字・数字・ハイフン）
    'themes' => [
        'blank' => [
            'label'       => 'blank（装飾なし）',
            'description' => '機能を作って確かめるための、装飾のない見た目。',
            'status'      => 'ready',
        ],
        'ironman' => [
            'label'       => 'ironman',
            'description' => '映画「アイアンマン」の世界観（アークリアクター・HUD）の見た目。',
            'status'      => 'wip',
            // アークリアクターの形（D-49-06）。1＝基本、2＝コアの外側の三角の金属と、コアの背面の逆回転のコイルを足した形
            'reactor_variant' => 2,
        ],
    ],

];
