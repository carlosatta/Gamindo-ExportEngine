<?php

namespace App\Http\Controllers\Api\V1\Ingestion;

use App\Http\Controllers\Api\V1\Ingestion\Concerns\HandlesRecords;
use App\Http\Controllers\Controller;
use App\Models\Version;
use App\Services\Ingestion\PayloadWriter;
use App\Services\Ingestion\VersionPlayerResolver;
use Illuminate\Http\Request;

class PlayerController extends Controller
{
    use HandlesRecords;

    protected $resolver;

    protected $payloadWriter;

    public function __construct(VersionPlayerResolver $resolver, PayloadWriter $payloadWriter)
    {
        $this->resolver = $resolver;
        $this->payloadWriter = $payloadWriter;
    }

    public function store(Request $request, Version $version)
    {
        return $this->processRecords($request, $this->rules(), function (array $record) use ($version) {
            $versionPlayer = $this->resolver->register($version, $record);
            if (isset($record['payload']) && is_array($record['payload'])) {
                $this->payloadWriter->write($version, 'version_player', $versionPlayer->id, $record['payload']);
            }
        });
    }

    private function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'external_player_id' => ['nullable', 'string', 'max:255'],
            'language' => ['nullable', 'string', 'max:10'],
            'utm_source' => ['nullable', 'string', 'max:100'],
            'company' => ['nullable', 'string', 'max:255'],
            'marketing_optin' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', 'max:50'],
            'registered_at' => ['nullable', 'date'],
            'payload' => ['nullable', 'array'],
        ];
    }
}
