<?php

namespace App\Services\Export;

use App\Models\PayloadField;
use App\Models\Version;
use Illuminate\Support\Facades\DB;

class ExportQueryBuilder
{
    private $valueColumns = [
        'integer' => 'value_integer',
        'decimal' => 'value_decimal',
        'boolean' => 'value_boolean',
        'datetime' => 'value_datetime',
        'text' => 'value_text',
        'string' => 'value_string',
    ];

    private $operators = ['=', '!=', '>', '>=', '<', '<=', 'like'];

    public function build(Version $version, array $sheet, $dateFrom = null, $dateTo = null)
    {
        $name = $sheet['name'] ?? null;
        if (! is_string($name) || ! EntityRegistry::has($name)) {
            return null;
        }

        $config = EntityRegistry::get($name);
        $table = $config['table'];
        $query = DB::table($table)->where($table.'.version_id', $version->id);

        $joined = [];
        $select = [];
        $headers = [];
        $keys = [];

        $requested = $sheet['columns'] ?? $config['columns'];
        if (! is_array($requested)) {
            $requested = $config['columns'];
        }

        foreach ($requested as $column) {
            if (! is_string($column)) {
                continue;
            }
            if ($this->isPayload($column)) {
                $code = $this->payloadCode($column);
                $join = $this->resolvePayload($query, $version, $config, $code, $joined);
                if ($join['field'] === null) {
                    continue;
                }
                $key = 'payload_'.$code;
                $select[] = $join['alias'].'.'.$join['value_column'].' as '.$key;
                $headers[] = $column;
                $keys[] = $key;
            } elseif (in_array($column, $config['columns'], true)) {
                $select[] = $table.'.'.$column;
                $headers[] = $column;
                $keys[] = $column;
            }
        }

        if (empty($select)) {
            foreach ($config['columns'] as $column) {
                $select[] = $table.'.'.$column;
                $headers[] = $column;
                $keys[] = $column;
            }
        }
        $query->select($select);

        $dateColumn = $table.'.'.$config['date_column'];
        if (! empty($dateFrom)) {
            $query->where($dateColumn, '>=', $dateFrom);
        }
        if (! empty($dateTo)) {
            $query->where($dateColumn, '<=', $dateTo);
        }

        foreach ($this->asArray($sheet, 'filters') as $filter) {
            $this->applyFilter($query, $version, $config, $filter, $joined);
        }
        foreach ($this->asArray($sheet, 'sort') as $sort) {
            $this->applySort($query, $version, $config, $sort, $joined);
        }

        $query->orderBy($table.'.id');

        return ['name' => $name, 'headers' => $headers, 'keys' => $keys, 'query' => $query];
    }

    private function asArray(array $sheet, string $key): array
    {
        return is_array($sheet[$key] ?? null) ? $sheet[$key] : [];
    }

    private function isPayload(string $field): bool
    {
        return strpos($field, 'payload.') === 0;
    }

    private function payloadCode(string $field): string
    {
        return substr($field, strlen('payload.'));
    }

    private function resolvePayload($query, Version $version, array $config, string $code, array &$joined): array
    {
        if (isset($joined[$code])) {
            return $joined[$code];
        }

        $field = PayloadField::where('version_id', $version->id)
            ->where('entity_type', $config['entity_type'])
            ->where('code', $code)
            ->first();

        $info = ['alias' => null, 'value_column' => null, 'field' => $field];

        if ($field !== null) {
            $alias = 'pv_'.md5($code);
            $table = $config['table'];
            $entityType = $config['entity_type'];
            $fieldId = $field->id;
            $query->leftJoin('payload_values as '.$alias, function ($join) use ($alias, $table, $entityType, $fieldId) {
                $join->on($alias.'.entity_id', '=', $table.'.id')
                    ->where($alias.'.entity_type', '=', $entityType)
                    ->where($alias.'.payload_field_id', '=', $fieldId);
            });
            $info['alias'] = $alias;
            $info['value_column'] = $this->valueColumns[$field->data_type] ?? 'value_string';
        }

        $joined[$code] = $info;

        return $info;
    }

    private function applyFilter($query, Version $version, array $config, $filter, array &$joined): void
    {
        if (! is_array($filter)) {
            return;
        }
        $field = $filter['field'] ?? null;
        if (! is_string($field)) {
            return;
        }
        $operator = $filter['operator'] ?? '=';
        if (! in_array($operator, $this->operators, true)) {
            return;
        }
        $column = $this->resolveColumn($query, $version, $config, $field, $joined, 'is_filterable');
        if ($column === null) {
            return;
        }
        $query->where($column, $operator, $filter['value'] ?? null);
    }

    private function applySort($query, Version $version, array $config, $sort, array &$joined): void
    {
        if (! is_array($sort)) {
            return;
        }
        $field = $sort['field'] ?? null;
        if (! is_string($field)) {
            return;
        }
        $direction = strtolower($sort['direction'] ?? 'asc');
        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'asc';
        }
        $column = $this->resolveColumn($query, $version, $config, $field, $joined, 'is_sortable');
        if ($column === null) {
            return;
        }
        $query->orderBy($column, $direction);
    }

    private function resolveColumn($query, Version $version, array $config, string $field, array &$joined, string $flag)
    {
        if ($this->isPayload($field)) {
            $code = $this->payloadCode($field);
            $join = $this->resolvePayload($query, $version, $config, $code, $joined);
            if ($join['field'] === null || ! $join['field']->{$flag}) {
                return null;
            }

            return $join['alias'].'.'.$join['value_column'];
        }

        if (! in_array($field, $config['columns'], true)) {
            return null;
        }

        return $config['table'].'.'.$field;
    }
}
