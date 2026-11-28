<?php

namespace App\Support;

/**
 * DB確認の画面で、決まった値の横に出す「（日本語の名前）」（D-133-02）。
 *
 * 名前は、列の説明（「?」。DatabaseColumns・WordPressColumns・WordPressDatabaseTables）に「値：意味」で書いたものから読む
 * （説明は、決まった値を「値：意味」で全て挙げる。D-133-01）。説明と画面の名前を1か所で決めるため、名前の一覧を別に持たない。
 * 例：「コメントを受け付けるか（open：受け付ける、closed：受け付けない）。」→ open は「受け付ける」。
 * 意味の中の「。」より後ろ（例：「1：持てる。カテゴリなど」の「カテゴリなど」）は補足のため、名前に入れない。
 */
class ValueLabels
{
    /**
     * 値（「（」か「、」の直後に書いたもの）：英数字と記号。空白をはさんだ英語の値（Organic Search など）も読む。
     * 「1 以上」（範囲）と「空」も、意味の区切りのために値として読む（画面の値とは合わないため、名前は出ない）
     */
    protected const VALUE = '\d+ 以上|空|[A-Za-z0-9_\-\.\/]+(?: [A-Z][A-Za-z0-9_\-]*)*';

    /** @var array<string, array<string, string>> 読んだ説明を使い回す */
    protected static array $memo = [];

    /**
     * 説明から「値 => 意味」を読む
     *
     * @return array<string, string>
     */
    public static function parse(?string $description): array
    {
        if ($description === null || $description === '') {
            return [];
        }
        if (isset(self::$memo[$description])) {
            return self::$memo[$description];
        }

        preg_match_all('/(?<=[（、])(' . self::VALUE . ')：/u', $description, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        $labels = [];
        foreach ($matches as $i => $match) {
            // 意味は、コロンの後ろから、次の値の前（「、」の前）まで。最後の値は、値の一覧を閉じる「）」の前まで
            $start = $match[0][1] + strlen($match[0][0]);
            $end = isset($matches[$i + 1]) ? $matches[$i + 1][0][1] : strlen($description);
            $labels[$match[1][0]] = self::meaning(substr($description, $start, $end - $start));
        }

        return self::$memo[$description] = array_filter($labels, fn ($label) => $label !== '');
    }

    /**
     * 値の横に出す名前（説明に値がなければ null）。値が JSON の一覧（例：["administrator"]）なら、1つずつの名前を「 / 」でつなぐ（D-132-02）
     */
    public static function label(?string $description, mixed $value): ?string
    {
        if ($value === null || $value === '' || is_array($value) || is_object($value)) {
            return null;
        }
        $labels = self::parse($description);
        if ($labels === []) {
            return null;
        }
        if (isset($labels[(string) $value])) {
            return $labels[(string) $value];
        }

        $list = is_string($value) && str_starts_with($value, '[') ? json_decode($value, true) : null;
        if (is_array($list) && $list !== [] && array_is_list($list)) {
            $names = array_map(fn ($item) => is_scalar($item) ? ($labels[(string) $item] ?? null) : null, $list);

            return in_array(null, $names, true) ? null : implode(' / ', $names);
        }

        return null;
    }

    /**
     * 意味の文を整える：括弧の外の「。」より後ろと、値の一覧を閉じる「）」から後ろを外し、終わりの「、」を外す
     */
    protected static function meaning(string $text): string
    {
        $depth = 0;
        $out = '';
        foreach (mb_str_split($text) as $char) {
            if ($char === '（') {
                $depth++;
            } elseif ($char === '）') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($char === '。' && $depth === 0) {
                break;
            }
            $out .= $char;
        }

        return trim(rtrim(trim($out), '、'));
    }
}
