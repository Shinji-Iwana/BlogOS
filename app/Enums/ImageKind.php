<?php

namespace App\Enums;

/**
 * 画像の種類（images.kind）。種類ごとに作り方を分ける（D-32-01）。
 */
enum ImageKind: string
{
    // 図解（フロー図・シーケンス図・構造図など）。AI が SVG で作り、ブラウザで PNG にする
    case Diagram = 'diagram';

    // イラスト（例え話・概念のイメージ）。画像モデルで作るか、アップロード
    case Illustration = 'illustration';

    // アイキャッチ。技術（カテゴリ）ごとの共通の画像を使い、新しい技術のときだけ作る
    case Eyecatch = 'eyecatch';

    // スクリーンショット・実行結果。人が撮ってアップロードする（AI が作った画面は事実ではないため）
    case Screenshot = 'screenshot';

    public function label(): string
    {
        return match ($this) {
            self::Diagram      => '図解',
            self::Illustration => 'イラスト',
            self::Eyecatch     => 'アイキャッチ',
            self::Screenshot   => 'スクリーンショット',
        };
    }
}
