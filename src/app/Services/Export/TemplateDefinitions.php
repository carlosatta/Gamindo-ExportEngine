<?php

namespace App\Services\Export;

class TemplateDefinitions
{
    public static function all(): array
    {
        return [
            'players' => self::players(),
            'transactions' => self::transactions(),
            'answers' => self::answers(),
            'events_summary' => self::eventsSummary(),
        ];
    }

    public static function players(): array
    {
        return [
            'base' => 'version_players',
            'columns' => [
                'player_id' => ['source' => 'players.id', 'format' => 'P%06d'],
                'email' => ['source' => 'players.email'],
                'registered_at' => ['source' => 'version_players.registered_at'],
                'language' => ['source' => 'version_players.language'],
                'utm_source' => ['source' => 'version_players.utm_source'],
                'company' => ['source' => 'version_players.company'],
                'total_score' => ['agg' => 'sum', 'table' => 'events', 'on' => 'payload.score', 'default' => 0],
                'levels_completed' => ['agg' => 'count_distinct', 'table' => 'events', 'on' => 'payload.level', 'default' => 0],
                'events_count' => ['agg' => 'count', 'table' => 'events', 'default' => 0],
                'reward' => ['agg' => 'last', 'table' => 'rewards', 'on' => 'reward_type', 'default' => 'none'],
                'status' => ['source' => 'version_players.status'],
                'marketing_optin' => ['source' => 'version_players.marketing_optin', 'map' => ['1' => 'yes', '0' => 'no']],
                'revenue' => ['agg' => 'sum', 'table' => 'transactions', 'on' => 'amount', 'default' => 0],
            ],
        ];
    }

    public static function transactions(): array
    {
        return [
            'base' => 'transactions',
            'columns' => [
                'transaction_id' => ['source' => 'transactions.transaction_id'],
                'player_id' => ['source' => 'players.id', 'format' => 'P%06d'],
                'email' => ['source' => 'players.email'],
                'type' => ['source' => 'transactions.type'],
                'amount' => ['source' => 'transactions.amount'],
                'language' => ['source' => 'version_players.language'],
                'utm_source' => ['source' => 'version_players.utm_source'],
                'occurred_at' => ['source' => 'transactions.occurred_at'],
            ],
        ];
    }

    public static function answers(): array
    {
        return [
            'base' => 'answers',
            'columns' => [
                'question_id' => ['source' => 'answers.question_id'],
                'question' => ['source' => 'answers.question'],
                'answer' => ['source' => 'answers.answer'],
                'player_id' => ['source' => 'players.id', 'format' => 'P%06d'],
                'email' => ['source' => 'players.email'],
                'occurred_at' => ['source' => 'answers.occurred_at'],
            ],
        ];
    }

    public static function eventsSummary(): array
    {
        return [
            'base' => 'events',
            'columns' => [
                'event_type' => ['source' => 'events.type'],
                'occurred_at' => ['source' => 'events.occurred_at'],
                'player_id' => ['source' => 'events.player_id'],
                'language' => ['payload' => 'language'],
                'utm_source' => ['payload' => 'utm_source'],
                'score' => ['payload' => 'score'],
                'level' => ['payload' => 'level'],
            ],
        ];
    }
}
