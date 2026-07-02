<?php

namespace App\Http\Controllers\Api\V1\Ingestion;

use App\Models\Event;
use App\Models\Version;
use App\Models\VersionPlayer;

class EventController extends IngestionController
{
    protected function entityType(): string
    {
        return 'event';
    }

    protected function rules(): array
    {
        return [
            'external_player_id' => ['required', 'string'],
            'email' => ['nullable', 'email'],
            'type' => ['required', 'string', 'max:100'],
            'occurred_at' => ['required', 'date'],
            'payload' => ['nullable', 'array'],
        ];
    }

    protected function persist(Version $version, VersionPlayer $versionPlayer, array $record)
    {
        return Event::create([
            'version_id' => $version->id,
            'player_id' => $versionPlayer->player_id,
            'version_player_id' => $versionPlayer->id,
            'type' => $record['type'],
            'occurred_at' => $record['occurred_at'],
        ]);
    }
}
