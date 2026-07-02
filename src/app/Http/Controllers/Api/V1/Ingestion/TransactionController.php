<?php

namespace App\Http\Controllers\Api\V1\Ingestion;

use App\Models\Transaction;
use App\Models\Version;
use App\Models\VersionPlayer;

class TransactionController extends IngestionController
{
    protected function entityType(): string
    {
        return 'transaction';
    }

    protected function rules(): array
    {
        return [
            'external_player_id' => ['required', 'string'],
            'email' => ['nullable', 'email'],
            'transaction_id' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric'],
            'currency' => ['required', 'string', 'size:3'],
            'occurred_at' => ['required', 'date'],
            'payload' => ['nullable', 'array'],
        ];
    }

    protected function persist(Version $version, VersionPlayer $versionPlayer, array $record)
    {
        return Transaction::updateOrCreate(
            [
                'version_id' => $version->id,
                'transaction_id' => $record['transaction_id'],
            ],
            [
                'player_id' => $versionPlayer->player_id,
                'version_player_id' => $versionPlayer->id,
                'type' => $record['type'],
                'amount' => $record['amount'],
                'currency' => $record['currency'],
                'occurred_at' => $record['occurred_at'],
            ]
        );
    }
}
