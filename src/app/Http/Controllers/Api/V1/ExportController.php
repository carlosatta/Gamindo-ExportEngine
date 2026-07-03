<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreExportRequest;
use App\Http\Resources\ExportResource;
use App\Jobs\GenerateExportJob;
use App\Models\ExportRequest;
use App\Models\Version;
use Illuminate\Http\Request;
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

    public function preview(Request $request, Version $version)
    {
        return $this->notImplemented();
    }
}
