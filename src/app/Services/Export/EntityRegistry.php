<?php

namespace App\Services\Export;

class EntityRegistry
{
    const ENTITIES = [
        'events' => [
            'table' => 'events',
            'entity_type' => 'event',
            'date_column' => 'occurred_at',
            'columns' => ['id', 'type', 'occurred_at', 'player_id', 'version_player_id'],
        ],
        'transactions' => [
            'table' => 'transactions',
            'entity_type' => 'transaction',
            'date_column' => 'occurred_at',
            'columns' => ['id', 'transaction_id', 'type', 'amount', 'currency', 'occurred_at', 'player_id', 'version_player_id'],
        ],
        'answers' => [
            'table' => 'answers',
            'entity_type' => 'answer',
            'date_column' => 'occurred_at',
            'columns' => ['id', 'question_id', 'question', 'answer', 'occurred_at', 'player_id', 'version_player_id'],
        ],
        'rewards' => [
            'table' => 'rewards',
            'entity_type' => 'reward',
            'date_column' => 'assigned_at',
            'columns' => ['id', 'reward_code', 'reward_type', 'assigned_at', 'player_id', 'version_player_id'],
        ],
        'version_players' => [
            'table' => 'version_players',
            'entity_type' => 'version_player',
            'date_column' => 'registered_at',
            'columns' => ['id', 'external_player_id', 'language', 'utm_source', 'company', 'marketing_optin', 'status', 'registered_at'],
        ],
    ];

    public static function has(string $name): bool
    {
        return isset(self::ENTITIES[$name]);
    }

    public static function get(string $name): array
    {
        return self::ENTITIES[$name];
    }

    public static function names(): array
    {
        return array_keys(self::ENTITIES);
    }
}
