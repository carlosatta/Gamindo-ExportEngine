<?php

namespace App\Services\Export;

use App\Models\PayloadField;
use App\Models\Version;
use Illuminate\Support\Facades\DB;

class SpecialSheetBuilder
{
    const NAMES = ['readme', 'configurazione_richiesta', 'kpis', 'data_quality'];

    public static function handles(string $name): bool
    {
        return in_array($name, self::NAMES, true);
    }

    public static function displayName(string $name): string
    {
        $overrides = ['readme' => 'README', 'kpis' => 'KPIs'];
        if (isset($overrides[$name])) {
            return $overrides[$name];
        }
        $parts = explode('_', $name);
        foreach ($parts as $i => $part) {
            $parts[$i] = ucfirst($part);
        }

        return implode('_', $parts);
    }

    public function build(string $name, Version $version, array $request)
    {
        switch ($name) {
            case 'readme':
                return $this->readme($version, $request);
            case 'configurazione_richiesta':
                return $this->configurazione($request);
            case 'kpis':
                return $this->kpis($version, $request);
            case 'data_quality':
                return $this->dataQuality($version);
            default:
                return null;
        }
    }

    private function pairs(array $headers, array $rows): array
    {
        return ['headers' => $headers, 'keys' => array_keys($rows[0] ?? array_fill_keys($headers, null)), 'formats' => [], 'rows' => $rows];
    }

    private function readme(Version $version, array $request): array
    {
        $from = $request['date_from'] ?? null;
        $to = $request['date_to'] ?? null;
        $periodo = ($from || $to) ? (($from ?: '...').' - '.($to ?: '...')) : 'completo';
        $players = DB::table('version_players')->where('version_id', $version->id)
            ->when(! empty($from), function ($q) use ($from) {
                $q->where('registered_at', '>=', $from);
            })
            ->when(! empty($to), function ($q) use ($to) {
                $q->where('registered_at', '<=', $to);
            })
            ->count();

        $rows = [
            ['campo' => 'Version ID', 'valore' => (string) $version->id],
            ['campo' => 'Formato', 'valore' => 'XLSX multi-sheet'],
            ['campo' => 'Generato il', 'valore' => now()->toDateTimeString()],
            ['campo' => 'Periodo', 'valore' => $periodo],
            ['campo' => 'Filtro lingua', 'valore' => 'tutte'],
            ['campo' => 'Filtro sorgente', 'valore' => 'tutte'],
            ['campo' => 'Righe player', 'valore' => (string) $players],
            ['campo' => 'Note', 'valore' => 'Export multi-sheet: KPI, dati grezzi, aggregazioni, qualita dati, transazioni.'],
        ];

        return $this->pairs(['Export', 'Statistiche versione gioco/campagna'], $rows);
    }

    private function configurazione(array $request): array
    {
        $rows = [
            ['parametro' => 'format', 'valore' => (string) ($request['format'] ?? 'xlsx')],
            ['parametro' => 'date_from', 'valore' => (string) ($request['date_from'] ?? '')],
            ['parametro' => 'date_to', 'valore' => (string) ($request['date_to'] ?? '')],
        ];

        foreach (($request['sheets'] ?? []) as $sheet) {
            if (! is_array($sheet) || ! isset($sheet['name'])) {
                continue;
            }
            $name = $sheet['name'];
            foreach (['columns', 'group_by', 'sort'] as $key) {
                if (! empty($sheet[$key]) && is_array($sheet[$key])) {
                    $rows[] = ['parametro' => $name.'.'.$key, 'valore' => implode(',', $sheet[$key])];
                }
            }
            if (! empty($sheet['metrics']) && is_array($sheet['metrics'])) {
                $labels = [];
                foreach ($sheet['metrics'] as $metric) {
                    if (is_array($metric)) {
                        $labels[] = (string) ($metric['as'] ?? $metric['fn'] ?? 'metric');
                    } else {
                        $labels[] = (string) $metric;
                    }
                }
                $rows[] = ['parametro' => $name.'.metrics', 'valore' => implode(',', $labels)];
            }
            if (! empty($sheet['filters']) && is_array($sheet['filters'])) {
                foreach ($sheet['filters'] as $field => $value) {
                    $rows[] = ['parametro' => $name.'.filters.'.$field, 'valore' => (string) $value];
                }
            }
        }

        return $this->pairs(['parametro', 'valore'], $rows);
    }

    private function kpis(Version $version, array $request): array
    {
        $from = $request['date_from'] ?? null;
        $to = $request['date_to'] ?? null;
        $requested = [];
        foreach (($request['sheets'] ?? []) as $sheet) {
            if (is_array($sheet) && isset($sheet['name']) && is_string($sheet['name'])) {
                $requested[$sheet['name']] = true;
            }
        }

        $rows = [];

        if (isset($requested['players'])) {
            $players = $this->periodCount('version_players', 'registered_at', $version, $from, $to);
            $completed = $this->periodQuery('version_players', 'registered_at', $version, $from, $to)->where('status', 'completed')->count();
            $totalScore = (int) $this->eventPayloadSum($version, $from, $to, 'score');
            $rows[] = ['kpi' => 'Player esportati', 'value' => $players, 'formula' => 'Conteggio righe player'];
            $rows[] = ['kpi' => 'Player completati', 'value' => $completed, 'formula' => 'Status completed'];
            $rows[] = ['kpi' => 'Completion rate', 'value' => $players > 0 ? round($completed / $players, 4) : 0, 'formula' => 'Player completati / player esportati'];
            $rows[] = ['kpi' => 'Score totale', 'value' => $totalScore, 'formula' => 'Somma payload.score'];
            $rows[] = ['kpi' => 'Score medio', 'value' => $players > 0 ? round($totalScore / $players, 2) : 0, 'formula' => 'Score totale / player'];
        }
        if (isset($requested['events_summary'])) {
            $rows[] = ['kpi' => 'Eventi totali', 'value' => $this->periodCount('events', 'occurred_at', $version, $from, $to), 'formula' => 'Conteggio eventi'];
        }
        if (isset($requested['transactions'])) {
            $rows[] = ['kpi' => 'Transazioni totali', 'value' => $this->periodCount('transactions', 'occurred_at', $version, $from, $to), 'formula' => 'Conteggio transazioni'];
            $rows[] = ['kpi' => 'Valore transazioni', 'value' => round((float) $this->periodQuery('transactions', 'occurred_at', $version, $from, $to)->sum('amount'), 2), 'formula' => 'Somma amount'];
        }
        if (isset($requested['answers'])) {
            $rows[] = ['kpi' => 'Risposte totali', 'value' => $this->periodCount('answers', 'occurred_at', $version, $from, $to), 'formula' => 'Conteggio risposte'];
        }
        $rows[] = ['kpi' => 'Errori data quality', 'value' => $this->dataQualityErrors($version), 'formula' => 'Anomalie di severita error'];

        return $this->pairs(['KPI', 'Valore', 'Formula / origine'], $rows);
    }

    private function periodQuery(string $table, string $dateColumn, Version $version, $from, $to)
    {
        return DB::table($table)->where($table.'.version_id', $version->id)
            ->when(! empty($from), function ($q) use ($table, $dateColumn, $from) {
                $q->where($table.'.'.$dateColumn, '>=', $from);
            })
            ->when(! empty($to), function ($q) use ($table, $dateColumn, $to) {
                $q->where($table.'.'.$dateColumn, '<=', $to);
            });
    }

    private function periodCount(string $table, string $dateColumn, Version $version, $from, $to): int
    {
        return $this->periodQuery($table, $dateColumn, $version, $from, $to)->count();
    }

    private function eventPayloadSum(Version $version, $from, $to, string $code)
    {
        $field = PayloadField::where('version_id', $version->id)
            ->where('entity_type', 'event')
            ->where('code', $code)
            ->first();
        if ($field === null) {
            return 0;
        }

        return $this->periodQuery('events', 'occurred_at', $version, $from, $to)
            ->join('payload_values', function ($join) use ($field) {
                $join->on('payload_values.entity_id', '=', 'events.id')
                    ->where('payload_values.entity_type', '=', 'event')
                    ->where('payload_values.payload_field_id', '=', $field->id);
            })
            ->sum('payload_values.value_integer');
    }

    private function dataQualityErrors(Version $version): int
    {
        $invalidOrder = DB::table('events')
            ->join('version_players', 'version_players.id', '=', 'events.version_player_id')
            ->where('events.version_id', $version->id)
            ->whereIn('events.type', ['game_completed', 'level_completed'])
            ->whereColumn('events.occurred_at', '<', 'version_players.registered_at')
            ->count();
        $orphan = DB::table('events')
            ->leftJoin('version_players', 'version_players.id', '=', 'events.version_player_id')
            ->where('events.version_id', $version->id)
            ->whereNull('version_players.id')
            ->count();

        return $invalidOrder + $orphan;
    }

    private function dataQuality(Version $version): array
    {
        $languageField = PayloadField::where('version_id', $version->id)
            ->where('entity_type', 'event')
            ->where('code', 'language')
            ->first();

        $missingLanguage = DB::table('events')->where('events.version_id', $version->id)
            ->when($languageField !== null, function ($q) use ($languageField) {
                $q->whereNotExists(function ($sub) use ($languageField) {
                    $sub->from('payload_values')
                        ->whereColumn('payload_values.entity_id', 'events.id')
                        ->where('payload_values.entity_type', 'event')
                        ->where('payload_values.payload_field_id', $languageField->id);
                });
            })
            ->count();

        $invalidOrder = DB::table('events')
            ->join('version_players', 'version_players.id', '=', 'events.version_player_id')
            ->where('events.version_id', $version->id)
            ->whereIn('events.type', ['game_completed', 'level_completed'])
            ->whereColumn('events.occurred_at', '<', 'version_players.registered_at')
            ->count();

        $orphanEvents = DB::table('events')
            ->leftJoin('version_players', 'version_players.id', '=', 'events.version_player_id')
            ->where('events.version_id', $version->id)
            ->whereNull('version_players.id')
            ->count();

        $emptyPayload = DB::table('events')->where('events.version_id', $version->id)
            ->whereNotExists(function ($sub) {
                $sub->from('payload_values')
                    ->whereColumn('payload_values.entity_id', 'events.id')
                    ->where('payload_values.entity_type', 'event');
            })
            ->count();

        $duplicateEmail = DB::table('version_players')
            ->join('players', 'players.id', '=', 'version_players.player_id')
            ->where('version_players.version_id', $version->id)
            ->select('players.email')
            ->groupBy('players.email')
            ->havingRaw('count(distinct version_players.player_id) > 1')
            ->get()->count();

        $rows = [
            ['check' => 'duplicate_player_email', 'severity' => 'warning', 'occurrences' => (string) $duplicateEmail, 'description' => 'Player con stessa email nella stessa version'],
            ['check' => 'missing_language', 'severity' => 'warning', 'occurrences' => (string) $missingLanguage, 'description' => 'Eventi senza payload.language'],
            ['check' => 'invalid_event_order', 'severity' => 'error', 'occurrences' => (string) $invalidOrder, 'description' => 'Completamento avvenuto prima della registrazione'],
            ['check' => 'orphan_event', 'severity' => 'error', 'occurrences' => (string) $orphanEvents, 'description' => 'Eventi riferiti a player non presente in players'],
            ['check' => 'empty_payload', 'severity' => 'info', 'occurrences' => (string) $emptyPayload, 'description' => 'Eventi con payload vuoto'],
        ];

        return $this->pairs(['check', 'severity', 'occurrences', 'description'], $rows);
    }
}
