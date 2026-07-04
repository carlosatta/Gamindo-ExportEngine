<?php

namespace App\Jobs;

use App\Models\ExportRequest;
use App\Models\ExportTemplate;
use App\Services\Export\MappingSheetBuilder;
use App\Services\Export\SpecialSheetBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;

class GenerateExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 7;

    public $timeout = 360;

    private $exportId;

    public function __construct($exportId)
    {
        $this->exportId = $exportId;
    }

    public function backoff()
    {
        return [60, 60, 120, 180, 300, 480];
    }

    public function handle()
    {
        $export = ExportRequest::find($this->exportId);
        if ($export === null || $export->status === 'deleted') {
            return;
        }

        $export->update([
            'status' => 'processing',
            'started_at' => now(),
            'attempts' => $this->attempts(),
            'next_retry_at' => null,
        ]);

        try {
            $path = $this->generate($export);

            if ($export->fresh()->status === 'deleted') {
                Storage::delete($path);

                return;
            }

            $export->update([
                'status' => 'completed',
                'file_path' => $path,
                'progress' => 100,
                'completed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            if ($this->attempts() < $this->tries) {
                $delays = $this->backoff();
                $delay = $delays[min($this->attempts() - 1, count($delays) - 1)];
                $export->update([
                    'status' => 'retrying',
                    'next_retry_at' => now()->addSeconds($delay),
                    'error_message' => $e->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    public function failed(\Throwable $e)
    {
        $export = ExportRequest::find($this->exportId);
        if ($export === null || $export->status === 'deleted') {
            return;
        }
        if ($export->file_path !== null) {
            Storage::delete($export->file_path);
        }
        $export->update([
            'status' => 'failed',
            'error_message' => $e->getMessage(),
            'next_retry_at' => null,
            'file_path' => null,
        ]);
    }

    private function generate(ExportRequest $export): string
    {
        $version = $export->version;
        $payload = $export->request_payload ?? [];
        $sheets = $this->orderSheets($payload['sheets'] ?? []);
        $dateFrom = $payload['date_from'] ?? null;
        $dateTo = $payload['date_to'] ?? null;

        Storage::makeDirectory('exports');
        $relative = 'exports/export-'.$export->id.'.xlsx';
        $writer = WriterEntityFactory::createXLSXWriter();
        $writer->openToFile(Storage::path($relative));

        $headerStyle = (new Style())->setFontBold()->setFontColor(Color::WHITE)->setBackgroundColor(Color::rgb(31, 78, 120));
        $highlightStyle = (new Style())->setBackgroundColor(Color::rgb(255, 230, 153));
        $mappingBuilder = new MappingSheetBuilder();
        $specialBuilder = new SpecialSheetBuilder();
        $usedNames = [];
        $written = 0;
        $processed = 0;
        $totalSheets = max(1, count($sheets));
        $autoFilters = [];

        $builtList = [];
        foreach ($sheets as $idx => $sheetConfig) {
            $builtList[$idx] = $this->buildSheet($mappingBuilder, $specialBuilder, $version, $payload, $sheetConfig, $dateFrom, $dateTo);
        }

        foreach ($sheets as $idx => $sheetConfig) {
            $processed++;
            $built = $builtList[$idx] ?? null;

            if ($built !== null) {
                $currentSheet = $written === 0 ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
                $currentSheet->setName($this->sheetName($sheetConfig['name'], $usedNames));
                $writer->addRow(WriterEntityFactory::createRowFromArray($built['headers'], $headerStyle));
                $rowCount = 1;

                if (isset($built['query'])) {
                    $rowCount += $this->streamDetail($writer, $built);
                } else {
                    $highlightFirst = ($sheetConfig['name'] ?? null) === 'data_quality';
                    foreach ($built['rows'] as $rowIndex => $row) {
                        $values = $this->rowValues($row, $built);
                        if ($highlightFirst && $rowIndex === 0) {
                            $writer->addRow(WriterEntityFactory::createRowFromArray($values, $highlightStyle));
                        } else {
                            $writer->addRow(WriterEntityFactory::createRowFromArray($values));
                        }
                        $rowCount++;
                    }
                }

                if (! SpecialSheetBuilder::handles($sheetConfig['name']) && ! empty($built['headers'])) {
                    $autoFilters[$written + 1] = ['cols' => count($built['headers']), 'rows' => $rowCount];
                }
                $written++;
            }

            $export->update(['progress' => (int) min(95, floor($processed / $totalSheets * 95))]);
        }

        $writer->close();
        $this->addAutoFilters(Storage::path($relative), $autoFilters);

        return $relative;
    }

    private function orderSheets(array $sheets): array
    {
        $early = ['readme', 'kpis', 'configurazione_richiesta'];
        $late = ['data_quality'];
        $byName = [];
        foreach ($sheets as $sheet) {
            if (is_array($sheet) && isset($sheet['name']) && is_string($sheet['name'])) {
                $byName[$sheet['name']][] = $sheet;
            }
        }

        $result = [];
        foreach ($early as $name) {
            if (! empty($byName[$name])) {
                foreach ($byName[$name] as $sheet) {
                    $result[] = $sheet;
                }
            } else {
                $result[] = ['name' => $name];
            }
        }
        foreach ($sheets as $sheet) {
            if (is_array($sheet) && isset($sheet['name']) && is_string($sheet['name'])
                && ! in_array($sheet['name'], array_merge($early, $late), true)) {
                $result[] = $sheet;
            }
        }
        foreach ($late as $name) {
            if (! empty($byName[$name])) {
                foreach ($byName[$name] as $sheet) {
                    $result[] = $sheet;
                }
            } else {
                $result[] = ['name' => $name];
            }
        }

        return $result;
    }

    private function addAutoFilters(string $path, array $filters): void
    {
        if (empty($filters)) {
            return;
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return;
        }
        foreach ($filters as $index => $dim) {
            $entry = 'xl/worksheets/sheet'.$index.'.xml';
            $xml = $zip->getFromName($entry);
            if ($xml === false || strpos($xml, '</sheetData>') === false) {
                continue;
            }
            $ref = 'A1:'.$this->colLetter($dim['cols']).$dim['rows'];
            $xml = str_replace('</sheetData>', '</sheetData><autoFilter ref="'.$ref.'"/>', $xml);
            $zip->addFromString($entry, $xml);
        }
        $zip->close();
    }

    private function colLetter(int $n): string
    {
        $letter = '';
        while ($n > 0) {
            $mod = ($n - 1) % 26;
            $letter = chr(65 + $mod).$letter;
            $n = intdiv($n - 1, 26);
        }

        return $letter;
    }

    private function buildSheet($mappingBuilder, $specialBuilder, $version, array $payload, $sheetConfig, $dateFrom, $dateTo)
    {
        if (! is_array($sheetConfig) || ! isset($sheetConfig['name']) || ! is_string($sheetConfig['name'])) {
            return null;
        }
        $name = $sheetConfig['name'];

        if (SpecialSheetBuilder::handles($name)) {
            return $specialBuilder->build($name, $version, $payload);
        }

        $template = ExportTemplate::where('name', $name)->first();
        if ($template === null) {
            return null;
        }

        return $mappingBuilder->build($version, $template->definition, $sheetConfig, $dateFrom, $dateTo);
    }

    private function streamDetail($writer, array $built): int
    {
        $count = 0;
        $pdo = DB::connection()->getPdo();
        $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        try {
            foreach ($built['query']->cursor() as $row) {
                $writer->addRow(WriterEntityFactory::createRowFromArray($this->rowValues((array) $row, $built)));
                $count++;
            }
        } finally {
            $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }

        return $count;
    }

    private function rowValues(array $row, array $built): array
    {
        $formats = $built['formats'] ?? [];
        $maps = $built['maps'] ?? [];
        $defaults = $built['defaults'] ?? [];
        $values = [];
        foreach ($built['keys'] as $key) {
            $value = $row[$key] ?? null;
            if ($value === null && array_key_exists($key, $defaults)) {
                $value = $defaults[$key];
            }
            if ($value === null) {
                $values[] = null;

                continue;
            }
            if (isset($maps[$key])) {
                $value = $maps[$key][(string) $value] ?? $value;
            } elseif (isset($formats[$key])) {
                $value = sprintf($formats[$key], $value);
            } elseif (is_numeric($value)) {
                $value = $value + 0;
            }
            $values[] = $value;
        }

        return $values;
    }

    private function progressConnection()
    {
        $name = 'export_progress';
        config(['database.connections.'.$name => config('database.connections.'.config('database.default'))]);

        return DB::connection($name);
    }

    private function sheetName(string $name, array &$usedNames): string
    {
        $base = substr(SpecialSheetBuilder::displayName($name), 0, 31);
        $candidate = $base;
        $suffix = 2;
        while (in_array($candidate, $usedNames, true)) {
            $candidate = substr($base, 0, 29).'_'.$suffix;
            $suffix++;
        }
        $usedNames[] = $candidate;

        return $candidate;
    }
}
