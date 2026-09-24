<?php

namespace App\DTO\WordPress;

class TypeApiDto
{
    public function __construct(public readonly array $data,)
    {
    }

    public const REQUIRED_FIELDS = [
        'name',
        'slug',
        'rest_base',
        'description',
        'hierarchical',
        'labels',
    ];

    public static function fromApiResponse(array $data): self
    {
        $validTypes = [];

        foreach ($data as $type => $typeData) {
            if (!is_array($typeData)) {
                continue;
            }

            $isMissingRequiredField = false;

            foreach (self::REQUIRED_FIELDS as $field) {
                if (
                    !array_key_exists($field, $typeData)
                    || $typeData[$field] === null
                    || $typeData[$field] === ''
                ) {
                    $isMissingRequiredField = true;
                    break;
                }
            }

            if ($isMissingRequiredField) {
                continue;
            }

            $validTypes[$type] = $typeData;
        }

        return new self(
            data: $validTypes,
        );
    }

    public static function findMissingFields(array $data): array
    {
        $missing = [];

        foreach ($data as $type => $typeData) {
            if (!is_array($typeData)) {
                $missing[$type] = self::REQUIRED_FIELDS;
                continue;
            }

            $typeMissing = [];

            foreach (self::REQUIRED_FIELDS as $field) {
                if (
                    !array_key_exists($field, $typeData)
                    || $typeData[$field] === null
                    || $typeData[$field] === ''
                ) {
                    $typeMissing[] = $field;
                }
            }

            if ($typeMissing !== []) {
                $missing[$type] = $typeMissing;
            }
        }

        return $missing;
    }

    public function toArray(): array
    {
        return $this->data;
    }
}
