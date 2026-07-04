<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreExportRequest;
use App\Http\Resources\ExportResource;
use App\Jobs\GenerateExportJob;
use App\Models\ExportRequest;
use App\Models\ExportTemplate;
use App\Models\Version;
use App\Services\Export\MappingSheetBuilder;
use App\Services\Export\SpecialSheetBuilder;
use Illuminate\Support\Facades\Storage;

class ExportController extends Controller
{
    public function all()
    {
        return ExportResource::collection(
            ExportRequest::query()->latest()->paginate(15)
        );
    }

    public function index(Version $version)
    {
        return ExportResource::collection(
            $version->exportRequests()->latest()->paginate(15)
        );
    }

    public function store(StoreExportRequest $request, Version $version)
    {
        $export = ExportRequest::create([
            'version_id' => $version->id,
            'status' => 'pending',
            'format' => $request->input('format', 'xlsx'),
            'request_payload' => $request->validated(),
            'progress' => 0,
            'attempts' => 0,
        ]);

        GenerateExportJob::dispatch($export->id);

        return (new ExportResource($export))->response()->setStatusCode(202);
    }

    public function show(ExportRequest $export)
    {
        return new ExportResource($export);
    }

    public function download(ExportRequest $export)
    {
        if ($export->status !== 'completed' || $export->file_path === null) {
            return $this->respond(['message' => 'Export non pronto per il download'], 404);
        }
        if (! Storage::exists($export->file_path)) {
            return $this->respond(['message' => 'File non trovato'], 404);
        }

        return Storage::download($export->file_path, "export-{$export->id}.xlsx");
    }

    public function destroy(ExportRequest $export)
    {
        if ($export->file_path !== null && Storage::exists($export->file_path)) {
            Storage::delete($export->file_path);
        }

        $export->update(['status' => 'deleted']);
        $export->delete();

        return $this->respond(['message' => 'Export eliminato'], 200);
    }

    public function preview(StoreExportRequest $request, Version $version)
    {
        $payload = $request->validated();
        $dateFrom = $payload['date_from'] ?? null;
        $dateTo = $payload['date_to'] ?? null;
        $limit = 100;

        $mapping = new MappingSheetBuilder();
        $special = new SpecialSheetBuilder();
        $sheets = [];

        foreach (($payload['sheets'] ?? []) as $sheetConfig) {
            $name = is_array($sheetConfig) ? ($sheetConfig['name'] ?? null) : null;
            if (! is_string($name)) {
                continue;
            }

            if (SpecialSheetBuilder::handles($name)) {
                $built = $special->build($name, $version, $payload);
                $sheets[] = ['name' => $name, 'headers' => $built['headers'], 'rows' => $mapping->previewRows($built, $limit)];

                continue;
            }

            $template = ExportTemplate::where('name', $name)->first();
            if ($template === null) {
                $sheets[] = ['name' => $name, 'headers' => [], 'rows' => [], 'note' => 'template sconosciuto'];

                continue;
            }

            $built = $mapping->build($version, $template->definition, $sheetConfig, $dateFrom, $dateTo);
            if ($built === null) {
                $sheets[] = ['name' => $name, 'headers' => [], 'rows' => []];

                continue;
            }

            $sheets[] = ['name' => $name, 'headers' => $built['headers'], 'rows' => $mapping->previewRows($built, $limit)];
        }

        return response()->json(['data' => ['version_id' => $version->id, 'limit' => $limit, 'sheets' => $sheets]]);
    }
}
