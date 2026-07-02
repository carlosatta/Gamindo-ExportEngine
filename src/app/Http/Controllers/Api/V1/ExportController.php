<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ExportRequest;
use App\Models\Version;
use Illuminate\Http\Request;

class ExportController extends Controller
{
    public function index(Version $version)
    {
        return $this->notImplemented();
    }

    public function store(Request $request, Version $version)
    {
        return $this->notImplemented();
    }

    public function preview(Request $request, Version $version)
    {
        return $this->notImplemented();
    }

    public function fromTemplate(Request $request, Version $version)
    {
        return $this->notImplemented();
    }

    public function show(ExportRequest $export)
    {
        return $this->notImplemented();
    }

    public function download(ExportRequest $export)
    {
        return $this->notImplemented();
    }

    public function destroy(ExportRequest $export)
    {
        return $this->notImplemented();
    }
}
