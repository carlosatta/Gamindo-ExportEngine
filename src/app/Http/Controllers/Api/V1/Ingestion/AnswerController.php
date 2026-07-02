<?php

namespace App\Http\Controllers\Api\V1\Ingestion;

use App\Models\Answer;
use App\Models\Version;
use App\Models\VersionPlayer;

class AnswerController extends IngestionController
{
    protected function entityType(): string
    {
        return 'answer';
    }

    protected function rules(): array
    {
        return [
            'external_player_id' => ['required', 'string'],
            'email' => ['nullable', 'email'],
            'question_id' => ['required', 'string', 'max:255'],
            'question' => ['nullable', 'string'],
            'answer' => ['required', 'string'],
            'occurred_at' => ['required', 'date'],
            'payload' => ['nullable', 'array'],
        ];
    }

    protected function persist(Version $version, VersionPlayer $versionPlayer, array $record)
    {
        return Answer::create([
            'version_id' => $version->id,
            'player_id' => $versionPlayer->player_id,
            'version_player_id' => $versionPlayer->id,
            'question_id' => $record['question_id'],
            'question' => $record['question'] ?? null,
            'answer' => $record['answer'],
            'occurred_at' => $record['occurred_at'],
        ]);
    }
}
