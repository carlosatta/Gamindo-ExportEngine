<?php

namespace App\Jobs;

use App\Models\ExportRequest;
use App\Models\VersionPlayer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;

class GenerateExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 7;

    public $timeout = 120;

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
        Storage::makeDirectory('exports');
        $relative = 'exports/export-'.$export->id.'.xlsx';

        $writer = WriterEntityFactory::createXLSXWriter();
        $writer->openToFile(Storage::path($relative));

        $writer->getCurrentSheet()->setName('README');
        $writer->addRow(WriterEntityFactory::createRowFromArray(['Export Engine']));
        $writer->addRow(WriterEntityFactory::createRowFromArray(['version_id', $version->id]));
        $writer->addRow(WriterEntityFactory::createRowFromArray(['version_name', $version->name]));
        $writer->addRow(WriterEntityFactory::createRowFromArray(['generated_at', now()->toIso8601String()]));
        $writer->addRow(WriterEntityFactory::createRowFromArray(['format', $export->format]));

        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Players');
        $writer->addRow(WriterEntityFactory::createRowFromArray(['email', 'external_player_id', 'status', 'registered_at']));

        $query = VersionPlayer::where('version_id', $version->id)->with('player');
        $total = (clone $query)->count();
        $done = 0;

        foreach ($query->lazy(500) as $versionPlayer) {
            $writer->addRow(WriterEntityFactory::createRowFromArray([
                optional($versionPlayer->player)->email,
                $versionPlayer->external_player_id,
                $versionPlayer->status,
                $versionPlayer->registered_at ? $versionPlayer->registered_at->toIso8601String() : null,
            ]));

            $done++;
            if ($total > 0 && $done % 200 === 0) {
                $export->update(['progress' => (int) min(95, floor($done / $total * 95))]);
            }
        }

        $writer->close();

        return $relative;
    }
}
