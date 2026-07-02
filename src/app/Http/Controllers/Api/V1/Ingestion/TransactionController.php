<?php

namespace App\Http\Controllers\Api\V1\Ingestion;

use App\Http\Controllers\Controller;
use App\Models\Version;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function store(Request $request, Version $version)
    {
        return $this->notImplemented();
    }
}
