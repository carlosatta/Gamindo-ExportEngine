<?php

namespace App\Services\Ingestion;

use App\Models\PayloadField;
use App\Models\PayloadValue;
use App\Models\Version;

class PayloadWriter
{
    public function write(Version $version, string $entityType, int $entityId, array $payload): void
    {
        foreach ($payload as $code => $value) {
            if (is_array($value)) {
                continue;
            }

            $dataType = $this->inferType($value);
            $field = PayloadField::firstOrCreate(
                [
                    'version_id' => $version->id,
                    'entity_type' => $entityType,
                    'event_type' => '',
                    'code' => (string) $code,
                ],
                array_merge(
                    ['data_type' => $dataType, 'label' => ucfirst((string) $code)],
                    $this->defaultFlags($dataType)
                )
            );

            $column = $this->valueColumn($field->data_type);
            $values = array_merge($this->nullValues(), [
                'version_id' => $version->id,
                $column => $this->cast($field->data_type, $value),
            ]);

            PayloadValue::updateOrCreate(
                [
                    'payload_field_id' => $field->id,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                ],
                $values
            );
        }
    }

    private function inferType($value): string
    {
        if (is_bool($value)) {
            return 'boolean';
        }
        if (is_int($value)) {
            return 'integer';
        }
        if (is_float($value)) {
            return 'decimal';
        }
        if (is_string($value) && strlen($value) > 255) {
            return 'text';
        }

        return 'string';
    }

    private function cast(string $dataType, $value)
    {
        switch ($dataType) {
            case 'boolean':
                return $value ? 1 : 0;
            case 'integer':
                return (int) $value;
            case 'decimal':
                return (float) $value;
            default:
                return (string) $value;
        }
    }

    private function valueColumn(string $dataType): string
    {
        $map = [
            'integer' => 'value_integer',
            'decimal' => 'value_decimal',
            'boolean' => 'value_boolean',
            'datetime' => 'value_datetime',
            'text' => 'value_text',
            'string' => 'value_string',
        ];

        return $map[$dataType] ?? 'value_string';
    }

    private function defaultFlags(string $dataType): array
    {
        if ($dataType === 'text') {
            return ['is_filterable' => false, 'is_sortable' => false, 'is_aggregatable' => false];
        }
        if ($dataType === 'string') {
            return ['is_filterable' => true, 'is_sortable' => true, 'is_aggregatable' => false];
        }

        return ['is_filterable' => true, 'is_sortable' => true, 'is_aggregatable' => true];
    }

    private function nullValues(): array
    {
        return [
            'value_string' => null,
            'value_integer' => null,
            'value_decimal' => null,
            'value_boolean' => null,
            'value_datetime' => null,
            'value_text' => null,
        ];
    }
}
