<?php

namespace App\Http\Controllers\Api\V1\Ingestion\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

trait HandlesRecords
{
    protected function processRecords(Request $request, array $rules, callable $handler)
    {
        $records = $this->normalizeRecords($request);
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
                Validator::make($record, $rules)->validate();
                DB::transaction(function () use ($record, $handler) {
                    $handler($record);
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

    private function normalizeRecords(Request $request)
    {
        $data = $request->json()->all();
        if (! is_array($data) || $data === []) {
            return null;
        }
        $isList = array_keys($data) === range(0, count($data) - 1);

        return $isList ? $data : [$data];
    }
}
