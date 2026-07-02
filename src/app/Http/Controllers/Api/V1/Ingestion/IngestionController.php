<?php

namespace App\Http\Controllers\Api\V1\Ingestion;

use App\Http\Controllers\Controller;
use App\Models\Version;
use App\Models\VersionPlayer;
use App\Services\Ingestion\PayloadWriter;
use App\Services\Ingestion\VersionPlayerResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

abstract class IngestionController extends Controller
{
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
        $records = $this->records($request);
        if ($records === null) {
            return $this->respond(['message' => 'Payload non valido'], 422);
        }

        $saved = 0;
        $errors = [];

        foreach ($records as $index => $record) {
            try {
                if (! is_array($record)) {
                    throw new \RuntimeException('record non valido');
                }
                Validator::make($record, $this->rules())->validate();
                DB::transaction(function () use ($version, $record) {
                    $versionPlayer = $this->resolver->resolve($version, $record);
                    $model = $this->persist($version, $versionPlayer, $record);
                    if (isset($record['payload']) && is_array($record['payload'])) {
                        $this->payloadWriter->write($version, $this->entityType(), $model->id, $record['payload']);
                    }
                });
                $saved++;
            } catch (ValidationException $e) {
                $errors[] = ['index' => $index, 'errors' => $e->errors()];
            } catch (\Throwable $e) {
                $errors[] = ['index' => $index, 'error' => $e->getMessage()];
            }
        }

        return $this->ingestionResponse($saved, $errors);
    }

    private function records(Request $request)
    {
        $data = $request->json()->all();
        if (! is_array($data) || $data === []) {
            return null;
        }
        $isList = array_keys($data) === range(0, count($data) - 1);

        return $isList ? $data : [$data];
    }
}
