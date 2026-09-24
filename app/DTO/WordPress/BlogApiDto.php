<?php

namespace App\DTO\WordPress;

class BlogApiDto
{
    public function __construct(public readonly array $data,)
    {
    }

    public const REQUIRED_FIELDS = [
        'name',
        'description',
        'url',
        'home',
        'gmt_offset',
        'timezone_string',
    ];

    public static function fromApiResponse(array $data): ?self
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                return null;
            }
        }

        // gmt_offsetはここで正規化しておき、以降（DB保存・比較・表示）は
        // 都度変換せずこの正規化済みの値をそのまま使う。
        $data['gmt_offset'] = self::normalizeGmtOffset((string) $data['gmt_offset']);

        return new self(
            data: $data,
        );
    }

    public static function findMissingFields(array $data): array
    {
        $missing = [];

        foreach (self::REQUIRED_FIELDS as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    public function toArray(): array
    {
        return $this->data;
    }

    protected static function normalizeGmtOffset(string $value): string
    {
        return number_format((float) $value, 2);
    }
}
