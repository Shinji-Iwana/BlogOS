<?php

namespace App\DTO\WordPress;

class AuthorApiDto
{
    public function __construct(public readonly array $data,)
    {
    }

    public const REQUIRED_FIELDS = [
        'id',
        'name',
        'slug',
        'link',
    ];

    public static function fromApiResponse(array $data): ?self
    {
        foreach ($data as $author => $authorData) {
            if (!is_array($authorData)) {
                return null;
            }

            foreach (self::REQUIRED_FIELDS as $field) {
                if (
                    !array_key_exists($field, $authorData)
                    || $authorData[$field] === null
                    || $authorData[$field] === ''
                ) {
                    return null;
                }
            }
        }

        return new self(
            data: $data,
        );
    }

    public static function findMissingFields(array $data): array
    {
        $missing = [];

        foreach ($data as $author => $authorData) {
            if (!is_array($authorData)) {
                $missing[$author] = self::REQUIRED_FIELDS;
                continue;
            }

            $authorMissing = [];

            foreach (self::REQUIRED_FIELDS as $field) {
                if (
                    !array_key_exists($field, $authorData)
                    || $authorData[$field] === null
                    || $authorData[$field] === ''
                ) {
                    $authorMissing[] = $field;
                }
            }

            if ($authorMissing !== []) {
                $missing[$author] = $authorMissing;
            }
        }

        return $missing;
    }

    public function toArray(): array
    {
        return $this->data;
    }
}
