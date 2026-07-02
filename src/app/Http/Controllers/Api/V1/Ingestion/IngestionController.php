<?php

namespace App\Http\Controllers\Api\V1\Ingestion;

use App\Http\Controllers\Api\V1\Ingestion\Concerns\HandlesRecords;
use App\Http\Controllers\Controller;
use App\Models\Version;
use App\Models\VersionPlayer;
use App\Services\Ingestion\PayloadWriter;
use App\Services\Ingestion\VersionPlayerResolver;
use Illuminate\Http\Request;

abstract class IngestionController extends Controller
{
    use HandlesRecords;

    protected $resolver;

    protected $payloadWriter;

    public function __construct(VersionPlayerResolver $resolver, PayloadWriter $payloadWriter)
    {
        $this->resolver = $resolver;
        $this->payloadWriter = $payloadWriter;
    }

    abstract protected function entityType(): string;

    abstract protected function rules(): array;

    abstract protected function persist(Version $version, VersionPlayer $versionPlayer, array $record);

    public function store(Request $request, Version $version)
    {
        return $this->processRecords($request, $this->rules(), function (array $record) use ($version) {
            $versionPlayer = $this->resolver->resolve($version, $record);
            $model = $this->persist($version, $versionPlayer, $record);
            if (isset($record['payload']) && is_array($record['payload'])) {
                $this->payloadWriter->write($version, $this->entityType(), $model->id, $record['payload']);
            }
        });
    }
}
