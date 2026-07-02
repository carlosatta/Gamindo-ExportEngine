<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVersionRequest;
use App\Http\Resources\VersionResource;
use App\Models\Version;

class VersionController extends Controller
{
    public function index()
    {
        return VersionResource::collection(Version::query()->latest()->paginate(15));
    }

    public function store(StoreVersionRequest $request)
    {
        $version = Version::create($request->validated());

        return (new VersionResource($version))->response()->setStatusCode(201);
    }

    public function show(Version $version)
    {
        return new VersionResource($version);
    }
}
