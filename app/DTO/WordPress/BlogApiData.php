<?php

namespace App\DTO\WordPress;

/**
 * WordPress REST API（/wp-json）から取得したブログ基本情報を保持するDTO。
 *
 * このクラスは、WordPress REST APIから取得した生の配列データを
 * BlogOS内部で扱うためのデータ構造に変換する役割を持つ。
 *
 * 主に以下の用途で使用する。
 *
 * 1. /wp-jsonのレスポンスからブログ基本情報を取得する
 * 2. BlogOSが必要とする必須項目が揃っているか確認する
 * 3. APIの生データをBlogApiDataオブジェクトとして保持する
 * 4. 必要に応じて配列形式へ戻す
 *
 * このクラス自体ではDBへの保存やAPIへのアクセスは行わない。
 * API通信やDB保存などの処理は、別の責務を持つクラスで行う。
 */
class BlogApiData
{
    /**
     * BlogApiDataを生成する。
     *
     * 各プロパティには、WordPress REST API（/wp-json）から取得した
     * ブログ基本情報を保持する。
     *
     * readonlyとしているため、BlogApiData生成後に値を変更することはできない。
     *
     * @param string $name
     *     WordPress REST APIの「name」。
     *     ブログ名を表す。
     *
     * @param string $description
     *     WordPress REST APIの「description」。
     *     ブログの説明・概要を表す。
     *
     * @param string $url
     *     WordPress REST APIの「url」。
     *     WordPressが認識しているサイトURLを表す。
     *
     * @param string $home
     *     WordPress REST APIの「home」。
     *     WordPressサイトのホームURLを表す。
     *     BlogOSがWordPress REST APIへアクセスする際の基準URLとして使用する。
     *     BlogOSではブログを一意に識別するURLとして使用する。
     *
     * @param string $gmtOffset
     *     WordPress REST APIの「gmt_offset」。
     *     GMT（UTC）からの時差を表す。
     *
     * @param string $timezoneString
     *     WordPress REST APIの「timezone_string」。
     *     WordPressで設定されているタイムゾーンを表す。
     *     例：Asia/Tokyo
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly string $url,
        public readonly string $home,
        public readonly string $gmtOffset,
        public readonly string $timezoneString,
    ) {
    }

    /**
     * BlogOSがブログ基本情報として必要とする必須API項目。
     *
     * WordPress REST API（/wp-json）のレスポンスに、
     * ここで定義した項目がすべて存在していることを確認する。
     *
     * 現在は以下の6項目を必須とする。
     *
     * name
     *     ブログ名
     *
     * description
     *     ブログの説明
     *
     * url
     *     WordPressが認識しているサイトURLを表す。
     *
     * home
     *     WordPressサイトのホームURL
     *
     * gmt_offset
     *     GMT（UTC）からの時差
     *
     * timezone_string
     *     WordPressで設定されているタイムゾーン
     *
     * APIの仕様変更などによって、これらの項目が取得できなくなった場合は、
     * BlogApiDataを正常に生成できないものとして扱う。
     */
    public const REQUIRED_FIELDS = [
        'name',
        'description',
        'url',
        'home',
        'gmt_offset',
        'timezone_string',
    ];

    /**
     * WordPress REST API（/wp-json）の生レスポンスから
     * BlogApiDataを生成する。
     *
     * APIレスポンスにBlogOSが必要とする必須項目がすべて存在し、
     * かつ値が空ではない場合のみBlogApiDataを生成する。
     *
     * 必須項目が1つでも存在しない、null、または空文字の場合は、
     * 正常なブログ情報として扱えないためnullを返す。
     *
     * この処理により、WordPress REST APIの仕様変更などによって
     * 必須項目が取得できなくなった場合でも、
     * 不完全なデータをBlogApiDataとして生成することを防ぐ。
     *
     * @param array $data
     *     WordPress REST API（/wp-json）から取得した生のレスポンス。
     *
     * @return self|null
     *     必須項目がすべて存在する場合はBlogApiDataを返す。
     *     必須項目が不足している場合はnullを返す。
     */
    public static function fromApiResponse(array $data): ?self
    {
        // BlogOSが必要とする必須項目を1項目ずつ確認する。
        foreach (self::REQUIRED_FIELDS as $field) {
            // 以下のいずれかに該当する場合は、その項目を取得できていないと判断する。
            //
            // ・APIレスポンスに項目自体が存在しない
            // ・項目の値がnull
            // ・項目の値が空文字
            if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                return null;
            }
        }

        // 必須項目がすべて存在していることを確認できたため、
        // APIレスポンスからBlogApiDataを生成する。
        return new self(
            name: (string) $data['name'],
            description: (string) $data['description'],
            url: (string) $data['url'],
            home: (string) $data['home'],
            gmtOffset: (string) $data['gmt_offset'],
            timezoneString: (string) $data['timezone_string'],
        );
    }

    /**
     * WordPress REST APIのレスポンスから、
     * 欠けている必須項目を検出する。
     *
     * fromApiResponse()では、必須項目が不足している場合に
     * nullを返して処理を終了する。
     *
     * 一方、このメソッドでは「どの項目が不足しているのか」を
     * 一覧として取得する。
     *
     * そのため、APIレスポンスに問題があった場合の
     * エラーメッセージやログ出力などに利用できる。
     *
     * 例：
     *
     * [
     *     'timezone_string',
     *     'gmt_offset'
     * ]
     *
     * のように、不足している項目名が返される。
     *
     * @param array $data
     *     WordPress REST API（/wp-json）から取得した生のレスポンス。
     *
     * @return array
     *     不足している必須項目名の一覧。
     *     すべて存在する場合は空配列を返す。
     */
    public static function findMissingFields(array $data): array
    {
        // 不足している項目名を格納する配列。
        $missing = [];

        // BlogOSが必要とする必須項目を1項目ずつ確認する。
        foreach (self::REQUIRED_FIELDS as $field) {
            // 項目が存在しない、null、空文字の場合は
            // 「取得できていない項目」として記録する。
            if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                $missing[] = $field;
            }
        }

        // 不足している項目の一覧を返す。
        return $missing;
    }

    /**
     * BlogApiDataを配列へ変換する。
     *
     * BlogApiData内部では、APIのフィールド名と
     * BlogOS内部で使用するプロパティ名が一部異なる。
     *
     * 例えば、
     *
     * API：
     *     gmt_offset
     *     timezone_string
     *
     * PHPプロパティ：
     *     gmtOffset
     *     timezoneString
     *
     * となっているため、このメソッドでは
     * WordPress REST APIのフィールド名に合わせた配列へ戻す。
     *
     * APIレスポンスと同じ形式のデータが必要な場合や、
     * DB保存処理などで配列として扱う場合に利用する。
     *
     * @return array
     *     WordPress REST APIのフィールド名に合わせたブログ基本情報。
     */
    public function toArray(): array
    {
        return [
            // ブログ名
            'name'            => $this->name,

            // ブログの説明
            'description'     => $this->description,

            // WordPressが認識しているサイトURLを表す。
            'url'             => $this->url,

            // WordPressサイトのホームURL
            'home'            => $this->home,

            // GMT（UTC）からの時差
            'gmt_offset'      => $this->gmtOffset,

            // WordPressで設定されているタイムゾーン
            'timezone_string' => $this->timezoneString,
        ];
    }
}
