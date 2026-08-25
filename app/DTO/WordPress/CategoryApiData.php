<?php

namespace App\DTO\WordPress;

/**
 * WordPress REST API（/wp-json/wp/v2/categories）から取得した
 * カテゴリ情報を保持するDTO。
 *
 * このクラスは、WordPress REST APIから取得した生の配列データを
 * BlogOS内部で扱うためのデータ構造に変換する役割を持つ。
 *
 * 主に以下の用途で使用する。
 *
 * 1. /wp-json/wp/v2/categoriesのレスポンスからカテゴリ情報を取得する
 * 2. BlogOSが必要とするカテゴリ情報が揃っているか確認する
 * 3. APIの生データをCategoryApiDataオブジェクトとして保持する
 * 4. 必要に応じて配列形式へ戻す
 *
 * このクラス自体ではDBへの保存やAPIへのアクセスは行わない。
 * API通信やDB保存などの処理は、別の責務を持つクラスで行う。
 */
class CategoryApiData
{
    /**
     * CategoryApiDataを生成する。
     *
     * 各プロパティには、WordPress REST API
     * （/wp-json/wp/v2/categories）から取得した
     * カテゴリ情報を保持する。
     *
     * readonlyとしているため、CategoryApiData生成後に
     * 値を変更することはできない。
     *
     * @param int $id
     *     WordPress REST APIの「id」。
     *     WordPress上でカテゴリを一意に識別するIDを表す。
     *
     * @param string $name
     *     WordPress REST APIの「name」。
     *     カテゴリ名を表す。
     *
     * @param string $slug
     *     WordPress REST APIの「slug」。
     *     カテゴリのスラッグを表す。
     *
     * @param int $parent
     *     WordPress REST APIの「parent」。
     *     親カテゴリのIDを表す。
     *     親カテゴリが存在しない場合は0となる。
     *
     * @param string $link
     *     WordPress REST APIの「link」。
     *     カテゴリページのURLを表す。
     *
     * @param string $description
     *     WordPress REST APIの「description」。
     *     カテゴリの説明を表す。
     *
     * @param int $count
     *     WordPress REST APIの「count」。
     *     そのカテゴリに属する投稿数を表す。
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly int $parent,
        public readonly string $link,
        public readonly string $description,
        public readonly int $count,
    ) {
    }

    /**
     * BlogOSがカテゴリ情報として必要とする必須API項目。
     *
     * WordPress REST API（/wp-json/wp/v2/categories）のレスポンスに、
     * ここで定義した項目がすべて存在していることを確認する。
     *
     * 現在は以下の7項目を必須とする。
     *
     * id
     *     WordPress上でカテゴリを一意に識別するID
     *
     * name
     *     カテゴリ名
     *
     * slug
     *     カテゴリのスラッグ
     *
     * parent
     *     親カテゴリのID
     *
     * link
     *     カテゴリページのURL
     *
     * description
     *     カテゴリの説明
     *
     * count
     *     そのカテゴリに属する投稿数
     *
     * APIの仕様変更などによって、これらの項目が取得できなくなった場合は、
     * CategoryApiDataを正常に生成できないものとして扱う。
     */
    public const REQUIRED_FIELDS = [
        'id',
        'name',
        'slug',
        'parent',
        'link',
        'description',
        'count',
    ];

    /**
     * WordPress REST API（/wp-json/wp/v2/categories）の
     * 生レスポンスからCategoryApiDataを生成する。
     *
     * APIレスポンスにBlogOSが必要とする必須項目がすべて存在し、
     * かつ文字列項目について値が空ではない場合のみ
     * CategoryApiDataを生成する。
     *
     * 必須項目が1つでも存在しない、null、または空文字の場合は、
     * 正常なカテゴリ情報として扱えないためnullを返す。
     *
     * なお、parentはWordPress APIの仕様上、
     * 親カテゴリが存在しない場合に0が返されるため、
     * 0は有効な値として扱う。
     *
     * countについても、カテゴリに属する投稿が0件の場合は0となるため、
     * 0は有効な値として扱う。
     *
     * この処理により、WordPress REST APIの仕様変更などによって
     * 必須項目が取得できなくなった場合でも、
     * 不完全なデータをCategoryApiDataとして生成することを防ぐ。
     *
     * @param array $data
     *     WordPress REST API（/wp-json/wp/v2/categories）から
     *     取得した生のレスポンス。
     *
     * @return self|null
     *     必須項目がすべて存在する場合はCategoryApiDataを返す。
     *     必須項目が不足している場合はnullを返す。
     */
    public static function fromApiResponse(array $data): ?self
    {
        // BlogOSが必要とする必須項目を1項目ずつ確認する。
        foreach (self::REQUIRED_FIELDS as $field) {
            // 以下のいずれかに該当する場合は、
            // その項目を取得できていないと判断する。
            //
            // ・APIレスポンスに項目自体が存在しない
            // ・項目の値がnull
            //
            // 空文字については、id、parent、countなどの数値項目には
            // 通常発生しないが、文字列項目の不正な値も検出できるよう
            // BlogApiDataと同じ条件で確認する。
            if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                return null;
            }
        }

        // 必須項目がすべて存在していることを確認できたため、
        // APIレスポンスからCategoryApiDataを生成する。
        return new self(
            id: (int) $data['id'],
            name: (string) $data['name'],
            slug: (string) $data['slug'],
            parent: (int) $data['parent'],
            link: (string) $data['link'],
            description: (string) $data['description'],
            count: (int) $data['count'],
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
     *     'description',
     *     'count'
     * ]
     *
     * のように、不足している項目名が返される。
     *
     * @param array $data
     *     WordPress REST API（/wp-json/wp/v2/categories）から
     *     取得した生のレスポンス。
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
     * CategoryApiDataを配列へ変換する。
     *
     * CategoryApiData内部では、APIのフィールド名と
     * BlogOS内部で使用するプロパティ名が一部異なる。
     *
     * 例えば、APIの「id」はPHPプロパティでも「id」を使用するが、
     * 将来的にAPIフィールド名とBlogOS内部のプロパティ名を
     * 分離する場合には、このメソッドで変換する。
     *
     * 現在はカテゴリ情報のAPIフィールド名と
     * PHPプロパティ名が同じ構成となっているため、
     * そのまま対応する値を配列へ格納する。
     *
     * APIレスポンスと同じ形式のデータが必要な場合や、
     * DB保存処理などで配列として扱う場合に利用する。
     *
     * @return array
     *     WordPress REST APIのフィールド名に合わせたカテゴリ情報。
     */
    public function toArray(): array
    {
        return [
            // WordPress上でカテゴリを一意に識別するID
            'id'          => $this->id,

            // カテゴリ名
            'name'        => $this->name,

            // カテゴリのスラッグ
            'slug'        => $this->slug,

            // 親カテゴリのID
            // 親カテゴリが存在しない場合は0
            'parent'      => $this->parent,

            // カテゴリページのURL
            'link'        => $this->link,

            // カテゴリの説明
            'description' => $this->description,

            // そのカテゴリに属する投稿数
            'count'       => $this->count,
        ];
    }
}
