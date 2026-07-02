<?php

namespace App\Http\Controllers\Api\V1\Ingestion;

use App\Models\Reward;
use App\Models\Version;
use App\Models\VersionPlayer;

class RewardController extends IngestionController
{
    protected function entityType(): string
    {
        return 'reward';
    }

    protected function rules(): array
    {
        return [
            'external_player_id' => ['required', 'string'],
            'email' => ['nullable', 'email'],
            'reward_type' => ['required', 'string', 'max:100'],
            'reward_code' => ['nullable', 'string', 'max:255'],
            'assigned_at' => ['required', 'date'],
            'payload' => ['nullable', 'array'],
        ];
    }

    protected function persist(Version $version, VersionPlayer $versionPlayer, array $record)
    {
        return Reward::create([
            'version_id' => $version->id,
            'player_id' => $versionPlayer->player_id,
            'version_player_id' => $versionPlayer->id,
            'reward_code' => $record['reward_code'] ?? null,
            'reward_type' => $record['reward_type'],
            'assigned_at' => $record['assigned_at'],
        ]);
    }
}
