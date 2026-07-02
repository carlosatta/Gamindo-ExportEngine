<?php

namespace App\Services\Ingestion;

use App\Models\Player;
use App\Models\Version;
use App\Models\VersionPlayer;
use RuntimeException;

class VersionPlayerResolver
{
    public function resolve(Version $version, array $record): VersionPlayer
    {
        $externalId = $record['external_player_id'] ?? null;

        if ($externalId !== null && $externalId !== '') {
            $existing = VersionPlayer::where('version_id', $version->id)
                ->where('external_player_id', $externalId)
                ->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        $email = $record['email'] ?? null;
        if ($email === null || $email === '') {
            throw new RuntimeException('player non risolvibile: external_player_id sconosciuto e email assente');
        }

        $player = Player::firstOrCreate(['email' => $email]);
        $attributes = $this->anagraphic($record, $player->id);

        if ($externalId !== null && $externalId !== '') {
            return VersionPlayer::updateOrCreate(
                ['version_id' => $version->id, 'external_player_id' => $externalId],
                $attributes
            );
        }

        return VersionPlayer::updateOrCreate(
            ['version_id' => $version->id, 'player_id' => $player->id],
            $attributes
        );
    }

    private function anagraphic(array $record, int $playerId): array
    {
        $fields = [
            'player_id' => $playerId,
            'language' => $record['language'] ?? null,
            'utm_source' => $record['utm_source'] ?? null,
            'company' => $record['company'] ?? null,
            'marketing_optin' => $record['marketing_optin'] ?? null,
            'status' => $record['status'] ?? null,
            'registered_at' => $record['registered_at'] ?? null,
        ];

        return array_filter($fields, function ($value) {
            return $value !== null;
        });
    }
}
