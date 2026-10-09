<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * PageSpeed Insights の応答の全部（D-78）。URL×携帯・デスクトップごとに最新の1回だけ（測り直したら置き換える）。
 * 後で使いたい項目が出たときに読めるよう残す。応答は大きいため、gzip で圧縮して base64 にして保存する
 */
class PageSpeedResponse extends Model
{
    protected $table = 'pagespeed_responses';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fetched_at' => 'datetime',
        ];
    }

    /**
     * 画面の写真（画像のデータ。応答の半分以上を占め、圧縮しても小さくならない）と、表示用の文言の表（i18n）は除いて残す
     */
    public static function pack(array $response): string
    {
        unset(
            $response['lighthouseResult']['fullPageScreenshot'],
            $response['lighthouseResult']['audits']['final-screenshot'],
            $response['lighthouseResult']['audits']['screenshot-thumbnails'],
            $response['lighthouseResult']['i18n'],
        );

        return base64_encode(gzencode(json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 9));
    }

    /**
     * @return array<string, mixed>
     */
    public function unpack(): array
    {
        $json = gzdecode((string) base64_decode($this->response, true));

        return $json === false ? [] : (array) json_decode($json, true);
    }
}
