<?php

namespace App\Services\Export;

use App\Models\PayloadField;
use App\Models\Version;
use Illuminate\Support\Facades\DB;

class MappingSheetBuilder
{
    private $valueColumns = [
        'integer' => 'value_integer',
        'decimal' => 'value_decimal',
        'boolean' => 'value_boolean',
        'datetime' => 'value_datetime',
        'text' => 'value_text',
        'string' => 'value_string',
    ];

    public function build(Version $version, array $template, array $sheet, $dateFrom = null, $dateTo = null)
    {
        $base = $template['base'] ?? null;
        if (! is_string($base) || ! EntityRegistry::has($base)) {
            return null;
        }

        if (! empty($sheet['group_by'])) {
            return $this->buildSummary($version, $template, $sheet, $dateFrom, $dateTo);
        }

        return $this->buildDetail($version, $template, $sheet, $dateFrom, $dateTo);
    }

    private function buildDetail(Version $version, array $template, array $sheet, $dateFrom, $dateTo)
    {
        $config = EntityRegistry::get($template['base']);
        $baseTable = $config['table'];
        $query = DB::table($baseTable)->where($baseTable.'.version_id', $version->id);

        $columns = $template['columns'] ?? [];
        $requested = $sheet['columns'] ?? array_keys($columns);
        if (! is_array($requested)) {
            $requested = array_keys($columns);
        }

        $ctx = ['joined' => [], 'eav' => [], 'agg' => []];
        $headers = [];
        $keys = [];
        $formats = [];
        $maps = [];
        $defaults = [];

        foreach ($requested as $name) {
            if (! is_string($name)) {
                continue;
            }
            $col = $this->resolveColumn($query, $config, $version, $template, $name, $ctx);
            if ($col === null) {
                continue;
            }
            $key = $this->safeKey($name);
            $query->selectRaw($col['ref'].' as `'.$key.'`');
            $headers[] = $name;
            $keys[] = $key;
            if ($col['format'] !== null) {
                $formats[$key] = $col['format'];
            }
            if (! empty($col['map'])) {
                $maps[$key] = $col['map'];
            }
            if ($col['default'] !== null) {
                $defaults[$key] = $col['default'];
            }
        }

        if (empty($headers)) {
            return null;
        }

        $this->applyDateRange($query, $config, $dateFrom, $dateTo);
        $this->applyFilters($query, $config, $version, $template, $sheet['filters'] ?? [], $ctx);
        $this->applySort($query, $config, $version, $template, $sheet['sort'] ?? [], $ctx);
        $query->orderBy($baseTable.'.id');

        return ['mode' => 'detail', 'headers' => $headers, 'keys' => $keys, 'formats' => $formats, 'maps' => $maps, 'defaults' => $defaults, 'query' => $query];
    }

    private function buildSummary(Version $version, array $template, array $sheet, $dateFrom, $dateTo)
    {
        $config = EntityRegistry::get($template['base']);
        $baseTable = $config['table'];
        $query = DB::table($baseTable)->where($baseTable.'.version_id', $version->id);

        $ctx = ['joined' => [], 'eav' => [], 'agg' => []];
        $headers = [];
        $keys = [];

        foreach ((array) ($sheet['group_by'] ?? []) as $field) {
            if (! is_string($field)) {
                continue;
            }
            $col = $this->resolveColumn($query, $config, $version, $template, $field, $ctx);
            if ($col === null || $col['kind'] !== 'scalar') {
                continue;
            }
            $key = $this->safeKey($field);
            $query->selectRaw($col['ref'].' as `'.$key.'`');
            $query->groupByRaw($col['ref']);
            $headers[] = $field;
            $keys[] = $key;
        }

        if (empty($keys)) {
            return null;
        }

        $metrics = $this->normalizeMetrics($sheet['metrics'] ?? []);
        $sqlMetrics = [];
        $postMetrics = [];
        foreach ($metrics as $metric) {
            $this->planMetric($metric, $sqlMetrics, $postMetrics);
        }
        foreach ($postMetrics as $metric) {
            if ($metric['fn'] === 'percentage') {
                $sqlMetrics['__count__'] = ['fn' => 'count'];
            }
        }

        foreach ($sqlMetrics as $name => $metric) {
            $expr = $this->metricSql($query, $config, $version, $template, $metric, $ctx);
            if ($expr === null) {
                unset($sqlMetrics[$name]);

                continue;
            }
            $query->selectRaw($expr.' as `'.$this->safeKey($name).'`');
        }

        $this->applyDateRange($query, $config, $dateFrom, $dateTo);
        $this->applyFilters($query, $config, $version, $template, $sheet['filters'] ?? [], $ctx);

        $rows = $query->get()->map(function ($row) {
            return (array) $row;
        })->all();

        $outputMetrics = [];
        foreach ($metrics as $metric) {
            $outputMetrics[] = $metric['name'];
        }
        $rows = $this->applyPostMetrics($rows, $postMetrics, $keys);

        foreach ($outputMetrics as $name) {
            $headers[] = $name;
            $keys[] = $this->safeKey($name);
        }

        return ['mode' => 'summary', 'headers' => $headers, 'keys' => $keys, 'formats' => [], 'rows' => $rows];
    }

    private function resolveColumn($query, array $config, Version $version, array $template, string $name, array &$ctx)
    {
        $columns = $template['columns'] ?? [];

        if (! isset($columns[$name])) {
            if (strpos($name, 'payload.') === 0) {
                $ref = $this->eavRef($query, $config, $version, substr($name, strlen('payload.')), $ctx['eav']);

                return $ref === null ? null : ['ref' => $ref, 'kind' => 'scalar', 'format' => null, 'map' => null, 'default' => null];
            }

            return null;
        }

        $def = $columns[$name];
        $format = $def['format'] ?? null;
        $map = $def['map'] ?? null;
        $default = $def['default'] ?? null;

        if (isset($def['source'])) {
            $ref = $this->resolveSource($query, $config, $def['source'], $ctx['joined']);

            return $ref === null ? null : ['ref' => $ref, 'kind' => 'scalar', 'format' => $format, 'map' => $map, 'default' => $default];
        }
        if (isset($def['payload'])) {
            $ref = $this->eavRef($query, $config, $version, $def['payload'], $ctx['eav']);

            return $ref === null ? null : ['ref' => $ref, 'kind' => 'scalar', 'format' => $format, 'map' => $map, 'default' => $default];
        }
        if (isset($def['agg'])) {
            $ref = $this->aggJoinRef($query, $version, $config, $def, $ctx['agg']);

            return $ref === null ? null : ['ref' => $ref, 'kind' => 'scalar', 'format' => $format, 'map' => $map, 'default' => $default];
        }

        return null;
    }

    private function resolveSource($query, array $config, string $source, array &$joined)
    {
        $parts = explode('.', $source, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$table, $column] = $parts;
        $baseTable = $config['table'];

        if ($table === $baseTable) {
            return in_array($column, $config['columns'], true) ? '`'.$baseTable.'`.`'.$column.'`' : null;
        }

        $relations = $config['to_one'] ?? [];
        if (! isset($relations[$table]) || ! EntityRegistry::has($table)) {
            return null;
        }
        if (! in_array($column, EntityRegistry::get($table)['columns'], true)) {
            return null;
        }
        if (! in_array($table, $joined, true)) {
            $rel = $relations[$table];
            $query->leftJoin($table, $table.'.'.$rel['foreign'], '=', $baseTable.'.'.$rel['local']);
            $joined[] = $table;
        }

        return '`'.$table.'`.`'.$column.'`';
    }

    private function aggJoinRef($query, Version $version, array $baseConfig, array $def, array &$aggCtx)
    {
        $aggTable = $def['table'] ?? null;
        $fn = $def['agg'] ?? null;
        $on = $def['on'] ?? null;

        if (! is_string($aggTable) || ! isset($baseConfig['to_many'][$aggTable]) || ! EntityRegistry::has($aggTable)) {
            return null;
        }
        $rel = $baseConfig['to_many'][$aggTable];
        $baseTable = $baseConfig['table'];
        $aggConfig = EntityRegistry::get($aggTable);
        $foreign = $rel['foreign'];
        $local = $rel['local'];

        $signature = $aggTable.'|'.$fn.'|'.(is_string($on) ? $on : '');
        if (isset($aggCtx[$signature])) {
            return $aggCtx[$signature];
        }

        $eavJoin = '';
        if ($fn === 'count') {
            $expr = 'count(*)';
        } elseif ($fn === 'last') {
            if (! is_string($on) || ! in_array($on, $aggConfig['columns'], true)) {
                return null;
            }
            $order = $aggConfig['date_column'] ?? 'id';
            $expr = "substring_index(group_concat(`".$aggTable.'`.`'.$on."` order by `".$aggTable.'`.`'.$order."` desc), ',', 1)";
        } else {
            if (! in_array($fn, ['sum', 'avg', 'min', 'max', 'count_distinct'], true)) {
                return null;
            }
            if (is_string($on) && strpos($on, 'payload.') === 0) {
                $field = $this->payloadField($version, $aggConfig['entity_type'], substr($on, strlen('payload.')));
                if ($field === null) {
                    return null;
                }
                $valueColumn = $this->valueColumns[$field->data_type] ?? 'value_string';
                $eavJoin = " join `payload_values` `pv` on `pv`.`entity_type` = '".$aggConfig['entity_type']
                    ."' and `pv`.`entity_id` = `".$aggTable."`.`id` and `pv`.`payload_field_id` = ".(int) $field->id;
                $col = '`pv`.`'.$valueColumn.'`';
            } elseif (is_string($on) && in_array($on, $aggConfig['columns'], true)) {
                $col = '`'.$aggTable.'`.`'.$on.'`';
            } else {
                return null;
            }
            $expr = $fn === 'count_distinct' ? 'count(distinct '.$col.')' : strtoupper($fn).'('.$col.')';
        }

        $alias = 'agg_'.md5($signature);
        $derived = 'select `'.$aggTable.'`.`'.$foreign.'` as `k`, '.$expr.' as `v` from `'.$aggTable.'`'.$eavJoin
            .' where `'.$aggTable.'`.`version_id` = '.(int) $version->id
            .' group by `'.$aggTable.'`.`'.$foreign.'`';

        $query->leftJoin(DB::raw('('.$derived.') as `'.$alias.'`'), $alias.'.k', '=', $baseTable.'.'.$local);
        $ref = '`'.$alias.'`.`v`';
        $aggCtx[$signature] = $ref;

        return $ref;
    }

    private function normalizeMetrics($metrics): array
    {
        if (! is_array($metrics)) {
            return [];
        }
        $out = [];
        foreach ($metrics as $metric) {
            if (is_string($metric)) {
                $out[] = ['name' => $metric, 'fn' => $metric];
            } elseif (is_array($metric) && isset($metric['fn'])) {
                $metric['name'] = $metric['as'] ?? $metric['fn'];
                $out[] = $metric;
            }
        }

        return $out;
    }

    private function planMetric(array $metric, array &$sqlMetrics, array &$postMetrics): void
    {
        $fn = $metric['fn'];
        if (in_array($fn, ['count', 'count_distinct', 'avg', 'sum', 'min', 'max'], true)) {
            $sqlMetrics[$metric['name']] = $metric;
        } elseif (in_array($fn, ['ratio', 'percentage'], true)) {
            $postMetrics[$metric['name']] = $metric;
        }
    }

    private function metricSql($query, array $config, Version $version, array $template, array $metric, array &$ctx)
    {
        $fn = $metric['fn'];
        if ($fn === 'count') {
            return 'count(*)';
        }
        $on = $metric['on'] ?? null;
        if (! is_string($on)) {
            return null;
        }
        $col = $this->resolveColumn($query, $config, $version, $template, $on, $ctx);
        if ($col === null || $col['kind'] !== 'scalar') {
            return null;
        }
        if ($fn === 'count_distinct') {
            return 'count(distinct '.$col['ref'].')';
        }

        return strtoupper($fn).'('.$col['ref'].')';
    }

    private function applyPostMetrics(array $rows, array $postMetrics, array $groupKeys): array
    {
        $totals = [];
        foreach ($postMetrics as $name => $metric) {
            if ($metric['fn'] === 'percentage') {
                $partitionKey = $this->safeKey((string) ($metric['partition'] ?? ''));
                foreach ($rows as $row) {
                    $bucket = in_array($partitionKey, $groupKeys, true) ? ($row[$partitionKey] ?? '') : '';
                    $totals[$name][$bucket] = ($totals[$name][$bucket] ?? 0) + ($row['__count__'] ?? 0);
                }
            }
        }

        $output = [];
        foreach ($rows as $row) {
            foreach ($postMetrics as $name => $metric) {
                $key = $this->safeKey($name);
                if ($metric['fn'] === 'ratio') {
                    $num = $row[$this->safeKey((string) ($metric['num'] ?? ''))] ?? 0;
                    $den = $row[$this->safeKey((string) ($metric['den'] ?? ''))] ?? 0;
                    $row[$key] = $den > 0 ? round($num / $den, 2) : 0;
                } elseif ($metric['fn'] === 'percentage') {
                    $partitionKey = $this->safeKey((string) ($metric['partition'] ?? ''));
                    $bucket = in_array($partitionKey, $groupKeys, true) ? ($row[$partitionKey] ?? '') : '';
                    $total = $totals[$name][$bucket] ?? 0;
                    $row[$key] = $total > 0 ? round(($row['__count__'] ?? 0) / $total, 4) : 0;
                }
            }
            unset($row['__count__']);
            $output[] = $row;
        }

        return $output;
    }

    private function applyDateRange($query, array $config, $dateFrom, $dateTo): void
    {
        $dateColumn = $config['date_column'] ?? null;
        if ($dateColumn === null) {
            return;
        }
        $ref = $config['table'].'.'.$dateColumn;
        if (! empty($dateFrom)) {
            $query->where($ref, '>=', $dateFrom);
        }
        if (! empty($dateTo)) {
            $query->where($ref, '<=', $dateTo);
        }
    }

    private function applyFilters($query, array $config, Version $version, array $template, $filters, array &$ctx): void
    {
        if (! is_array($filters)) {
            return;
        }
        foreach ($filters as $field => $value) {
            if ($value === '*' || $value === null || ! is_string($field)) {
                continue;
            }
            $col = $this->resolveColumn($query, $config, $version, $template, $field, $ctx);
            if ($col === null || $col['kind'] !== 'scalar') {
                continue;
            }
            $query->whereRaw($col['ref'].' = ?', [$value]);
        }
    }

    private function applySort($query, array $config, Version $version, array $template, $sort, array &$ctx): void
    {
        if (! is_array($sort)) {
            return;
        }
        foreach ($sort as $entry) {
            if (! is_string($entry)) {
                continue;
            }
            $parts = explode(':', $entry, 2);
            $direction = strtolower($parts[1] ?? 'asc');
            if (! in_array($direction, ['asc', 'desc'], true)) {
                $direction = 'asc';
            }
            $col = $this->resolveColumn($query, $config, $version, $template, $parts[0], $ctx);
            if ($col === null || $col['kind'] !== 'scalar') {
                continue;
            }
            $query->orderByRaw($col['ref'].' '.$direction);
        }
    }

    private function eavRef($query, array $config, Version $version, string $code, array &$eav)
    {
        if (array_key_exists($code, $eav)) {
            return $eav[$code];
        }
        $field = $this->payloadField($version, $config['entity_type'] ?? '', $code);
        if ($field === null) {
            $eav[$code] = null;

            return null;
        }
        $alias = 'pv_'.md5($code);
        $valueColumn = $this->valueColumns[$field->data_type] ?? 'value_string';
        $baseTable = $config['table'];
        $entityType = $config['entity_type'];
        $fieldId = (int) $field->id;
        $query->leftJoin('payload_values as '.$alias, function ($join) use ($alias, $baseTable, $entityType, $fieldId) {
            $join->on($alias.'.entity_id', '=', $baseTable.'.id')
                ->where($alias.'.entity_type', '=', $entityType)
                ->where($alias.'.payload_field_id', '=', $fieldId);
        });
        $ref = '`'.$alias.'`.`'.$valueColumn.'`';
        $eav[$code] = $ref;

        return $ref;
    }

    private function safeKey(string $name): string
    {
        $key = preg_replace('/[^A-Za-z0-9_]/', '_', $name);
        if ($key === '' || preg_match('/^[0-9]/', $key)) {
            $key = 'c_'.$key;
        }

        return substr($key, 0, 64);
    }

    private function payloadField(Version $version, string $entityType, string $code)
    {
        return PayloadField::where('version_id', $version->id)
            ->where('entity_type', $entityType)
            ->where('code', $code)
            ->first();
    }
}
