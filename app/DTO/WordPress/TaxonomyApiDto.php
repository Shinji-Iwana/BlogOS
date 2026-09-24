<?php

namespace App\DTO\WordPress;

class TaxonomyApiDto
{
    public function __construct(public readonly array $data,)
    {
    }

    public const REQUIRED_FIELDS = [
        'name',
        'label',
        'description',
        'public',
        'hierarchical',
        'rest_base',
        'rest_namespace',
        'types',
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
