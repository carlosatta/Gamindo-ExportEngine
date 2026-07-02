<?php

namespace App\Jobs;

use App\Models\ExportRequest;
use App\Services\Export\EntityRegistry;
use App\Services\Export\ExportQueryBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        $sheets = $payload['sheets'] ?? [];
        $dateFrom = $payload['date_from'] ?? null;
        $dateTo = $payload['date_to'] ?? null;

        Storage::makeDirectory('exports');
        $relative = 'exports/export-'.$export->id.'.xlsx';

        $writer = WriterEntityFactory::createXLSXWriter();
        $writer->openToFile(Storage::path($relative));

        $builder = new ExportQueryBuilder();
        $usedNames = [];

        $plans = [];
        $totalRows = 0;
        foreach ($sheets as $sheetConfig) {
            $built = is_array($sheetConfig) ? $builder->build($version, $sheetConfig, $dateFrom, $dateTo) : null;
            if ($built === null) {
                continue;
            }
            $count = DB::table(EntityRegistry::get($built['name'])['table'])
                ->where('version_id', $version->id)
                ->count();
            $plans[] = ['built' => $built, 'count' => $count];
            $totalRows += $count;
        }
        $totalRows = max(1, $totalRows);

        $progress = $this->progressConnection();
        $pdo = DB::connection()->getPdo();
        $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

        try {
            $written = 0;
            $done = 0;
            foreach ($plans as $plan) {
                $built = $plan['built'];
                $currentSheet = $written === 0
                    ? $writer->getCurrentSheet()
                    : $writer->addNewSheetAndMakeItCurrent();
                $currentSheet->setName($this->sheetName($built['name'], $usedNames));
                $writer->addRow(WriterEntityFactory::createRowFromArray($built['headers']));

                foreach ($built['query']->cursor() as $row) {
                    $values = [];
                    foreach ($built['keys'] as $key) {
                        $values[] = $row->{$key} ?? null;
                    }
                    $writer->addRow(WriterEntityFactory::createRowFromArray($values));

                    $done++;
                    if ($done % 5000 === 0) {
                        $progress->table('export_requests')
                            ->where('id', $export->id)
                            ->update(['progress' => (int) min(95, floor($done / $totalRows * 95))]);
                    }
                }

                $written++;
            }
        } finally {
            $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }

        $writer->close();

        return $relative;
    }

    private function progressConnection()
    {
        $name = 'export_progress';
        config(['database.connections.'.$name => config('database.connections.'.config('database.default'))]);

        return DB::connection($name);
    }

    private function sheetName(string $name, array &$usedNames): string
    {
        $base = substr($name, 0, 31);
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
