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

    public function build(string $name, Version $version, array $request, array $layouts = [])
    {
        switch ($name) {
            case 'readme':
                return $this->readme($version, $request);
            case 'configurazione_richiesta':
                return $this->configurazione($request);
            case 'kpis':
                return $this->kpis($layouts);
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

    private function kpis(array $layouts): array
    {
        $max = 100000;

        $rows = [];
        if (isset($layouts['players'])) {
            $rows[] = ['kpi' => 'Player esportati', 'value' => $this->counta($layouts, 'players', $max), 'formula' => 'Conteggio righe player'];
            $rows[] = ['kpi' => 'Player completati', 'value' => $this->countifStatus($layouts, 'players', 'completed', $max), 'formula' => 'Status completed'];
            $rows[] = ['kpi' => 'Completion rate', 'value' => '=IF(B2=0,0,B3/B2)', 'formula' => 'Player completati / player esportati'];
            $rows[] = ['kpi' => 'Score totale', 'value' => $this->sumCol($layouts, 'players', 'total_score', $max), 'formula' => 'Somma score'];
            $rows[] = ['kpi' => 'Score medio', 'value' => $this->avgCol($layouts, 'players', 'total_score', $max), 'formula' => 'Media score'];
        }
        if (isset($layouts['events_summary'])) {
            $rows[] = ['kpi' => 'Eventi totali aggregati', 'value' => $this->sumCol($layouts, 'events_summary', 'events_count', $max), 'formula' => 'Somma eventi aggregati'];
        }
        if (isset($layouts['transactions'])) {
            $rows[] = ['kpi' => 'Transazioni totali', 'value' => $this->counta($layouts, 'transactions', $max), 'formula' => 'Conteggio transazioni'];
            $rows[] = ['kpi' => 'Valore transazioni', 'value' => $this->sumCol($layouts, 'transactions', 'amount', $max), 'formula' => 'Somma amount'];
        }
        if (isset($layouts['answers'])) {
            $rows[] = ['kpi' => 'Risposte totali', 'value' => $this->sumCol($layouts, 'answers', 'answers_count', $max), 'formula' => 'Somma risposte'];
        }
        if (isset($layouts['data_quality'])) {
            $rows[] = ['kpi' => 'Errori data quality', 'value' => $this->sumifErrors($layouts, $max), 'formula' => 'Somma anomalie error'];
        }
        if (empty($rows)) {
            $rows[] = ['kpi' => 'Nessun dato', 'value' => 0, 'formula' => 'Nessun foglio dati richiesto'];
        }

        return $this->pairs(['KPI', 'Valore', 'Formula / origine'], $rows);
    }

    private function counta(array $layouts, string $sheet, int $max)
    {
        return isset($layouts[$sheet]) ? "=COUNTA('".self::displayName($sheet)."'!A2:A".$max.')' : 0;
    }

    private function sumCol(array $layouts, string $sheet, string $col, int $max)
    {
        $letter = $this->colLetter($layouts, $sheet, $col);

        return $letter === null ? 0 : "=SUM('".self::displayName($sheet)."'!".$letter.'2:'.$letter.$max.')';
    }

    private function avgCol(array $layouts, string $sheet, string $col, int $max)
    {
        $letter = $this->colLetter($layouts, $sheet, $col);

        return $letter === null ? 0 : "=AVERAGE('".self::displayName($sheet)."'!".$letter.'2:'.$letter.$max.')';
    }

    private function countifStatus(array $layouts, string $sheet, string $value, int $max)
    {
        $letter = $this->colLetter($layouts, $sheet, 'status');

        return $letter === null ? 0 : "=COUNTIF('".self::displayName($sheet)."'!".$letter.'2:'.$letter.$max.',"'.$value.'")';
    }

    private function sumifErrors(array $layouts, int $max)
    {
        $sev = $this->colLetter($layouts, 'data_quality', 'severity');
        $cnt = $this->colLetter($layouts, 'data_quality', 'occurrences');
        if ($sev === null || $cnt === null) {
            return 0;
        }
        $dq = self::displayName('data_quality');

        return "=SUMIF('".$dq."'!".$sev.'2:'.$sev.$max.',"error",\''.$dq.'\'!'.$cnt.'2:'.$cnt.$max.')';
    }

    private function colLetter(array $layouts, string $sheet, string $column)
    {
        if (! isset($layouts[$sheet])) {
            return null;
        }
        $index = array_search($column, $layouts[$sheet], true);
        if ($index === false) {
            return null;
        }
        $n = $index + 1;
        $letter = '';
        while ($n > 0) {
            $mod = ($n - 1) % 26;
            $letter = chr(65 + $mod).$letter;
            $n = intdiv($n - 1, 26);
        }

        return $letter;
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
