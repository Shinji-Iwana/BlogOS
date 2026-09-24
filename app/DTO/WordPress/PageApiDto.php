<?php

namespace App\DTO\WordPress;

class PageApiDto
{
    public function __construct(public readonly array $data,)
    {
    }

    public const REQUIRED_FIELDS = [
        'id',
        'date',
        'date_gmt',
        'modified',
        'modified_gmt',
        'slug',
        'status',
        'type',
        'link',
        'title',
        'content',
        'author',
        'featured_media',
        'parent',
        'menu_order',
        'comment_status',
        'ping_status',
        'template',
        'meta',
    ];

    public static function fromApiResponse(array $data): ?self
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                return null;
            }
        }

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
}
