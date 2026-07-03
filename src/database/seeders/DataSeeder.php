<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

abstract class DataSeeder extends Seeder
{
    protected $chunkSize = 1000;

    private $emailCounter = 0;

    protected function seedData(int $versionsCount, int $playersPerVersion, int $eventsPerPlayer)
    {
        $this->call(MappingSeeder::class);

        $dispatcher = DB::connection()->getEventDispatcher();
        DB::connection()->unsetEventDispatcher();

        try {
            $this->generate($versionsCount, $playersPerVersion, $eventsPerPlayer);
        } finally {
            if ($dispatcher) {
                DB::connection()->setEventDispatcher($dispatcher);
            }
        }
    }

    private function generate(int $versionsCount, int $playersPerVersion, int $eventsPerPlayer)
    {
        for ($v = 1; $v <= $versionsCount; $v++) {
            $now = Carbon::now()->toDateTimeString();

            $versionId = DB::table('versions')->insertGetId([
                'name' => 'Version '.$v,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $fields = $this->createPayloadFields($versionId, $now);
            $playerIds = $this->createPlayers($playersPerVersion, $now);
            $versionPlayers = $this->createVersionPlayers($versionId, $playerIds, $now);
            $eventCount = $this->createEventsWithPayload($versionId, $versionPlayers, $eventsPerPlayer, $fields, $now);
            $this->createSideEntities($versionId, $versionPlayers, $now);

            $this->command->info("Version {$versionId}: {$playersPerVersion} players, {$eventCount} events");
        }
    }

    private function createPayloadFields(int $versionId, string $now)
    {
        $definitions = [
            ['code' => 'score', 'data_type' => 'integer', 'flags' => [true, true, true]],
            ['code' => 'level', 'data_type' => 'integer', 'flags' => [true, true, true]],
            ['code' => 'difficulty_rating', 'data_type' => 'decimal', 'flags' => [true, true, true]],
            ['code' => 'language', 'data_type' => 'string', 'flags' => [true, true, false]],
            ['code' => 'utm_source', 'data_type' => 'string', 'flags' => [true, true, false]],
            ['code' => 'is_winner', 'data_type' => 'boolean', 'flags' => [true, true, true]],
            ['code' => 'last_action_at', 'data_type' => 'datetime', 'flags' => [true, true, true]],
            ['code' => 'note', 'data_type' => 'text', 'flags' => [false, false, false]],
        ];

        $fields = [];

        foreach ($definitions as $definition) {
            $id = DB::table('payload_fields')->insertGetId([
                'version_id' => $versionId,
                'entity_type' => 'event',
                'event_type' => '',
                'code' => $definition['code'],
                'label' => ucfirst(str_replace('_', ' ', $definition['code'])),
                'data_type' => $definition['data_type'],
                'is_filterable' => $definition['flags'][0],
                'is_sortable' => $definition['flags'][1],
                'is_aggregatable' => $definition['flags'][2],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $fields[$definition['code']] = ['id' => $id, 'data_type' => $definition['data_type'], 'code' => $definition['code']];
        }

        return $fields;
    }

    private function createPlayers(int $count, string $now)
    {
        $maxId = (int) DB::table('players')->max('id');
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'email' => sprintf('user%03d@example.test', ++$this->emailCounter),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) >= $this->chunkSize) {
                DB::table('players')->insert($rows);
                $rows = [];
            }
        }

        if (! empty($rows)) {
            DB::table('players')->insert($rows);
        }

        return DB::table('players')->where('id', '>', $maxId)->orderBy('id')->pluck('id')->all();
    }

    private function createVersionPlayers(int $versionId, array $playerIds, string $now)
    {
        $maxId = (int) DB::table('version_players')->max('id');
        $languages = ['it', 'en', 'es', 'de', 'fr'];
        $sources = ['google', 'direct', 'newsletter', 'partner', 'linkedin', 'qr_event'];
        $companies = ['Hooli', 'Umbrella', 'Globex', 'Stark', 'Wayne', 'Wonka', 'Initech', 'Acme'];
        $statuses = ['registered', 'started', 'completed', 'completed', 'completed'];
        $rows = [];
        $ext = 0;

        foreach ($playerIds as $playerId) {
            $rows[] = [
                'version_id' => $versionId,
                'player_id' => $playerId,
                'external_player_id' => 'ext_'.$versionId.'_'.(++$ext),
                'registered_at' => Carbon::create(2026, 1, 1)->addDays(rand(0, 27))->addMinutes(rand(0, 1439))->toDateTimeString(),
                'language' => $languages[array_rand($languages)],
                'utm_source' => $sources[array_rand($sources)],
                'company' => $companies[array_rand($companies)],
                'marketing_optin' => rand(0, 1),
                'status' => $statuses[array_rand($statuses)],
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) >= $this->chunkSize) {
                DB::table('version_players')->insert($rows);
                $rows = [];
            }
        }

        if (! empty($rows)) {
            DB::table('version_players')->insert($rows);
        }

        return DB::table('version_players')->where('id', '>', $maxId)->orderBy('id')->get(['id', 'player_id'])->all();
    }

    private function createEventsWithPayload(int $versionId, array $versionPlayers, int $eventsPerPlayer, array $fields, string $now)
    {
        $types = ['opened', 'registered', 'level_completed', 'game_completed', 'answer_submitted'];
        $rows = [];
        $total = 0;

        $flush = function () use (&$rows, $versionId, $fields, $now) {
            if (empty($rows)) {
                return;
            }

            $maxId = (int) DB::table('events')->max('id');
            DB::table('events')->insert($rows);
            $rows = [];

            $eventIds = DB::table('events')->where('id', '>', $maxId)->orderBy('id')->pluck('id');
            $values = [];

            foreach ($eventIds as $eventId) {
                foreach ($fields as $field) {
                    $values[] = $this->payloadValueRow($versionId, $field, $eventId, $now);
                    if (count($values) >= $this->chunkSize) {
                        DB::table('payload_values')->insert($values);
                        $values = [];
                    }
                }
            }

            if (! empty($values)) {
                DB::table('payload_values')->insert($values);
            }
        };

        foreach ($versionPlayers as $vp) {
            for ($e = 0; $e < $eventsPerPlayer; $e++) {
                $rows[] = [
                    'version_id' => $versionId,
                    'player_id' => $vp->player_id,
                    'version_player_id' => $vp->id,
                    'type' => $types[array_rand($types)],
                    'occurred_at' => Carbon::create(2026, 1, 1)->addDays(rand(0, 29))->addMinutes(rand(0, 1439))->toDateTimeString(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $total++;

                if (count($rows) >= $this->chunkSize) {
                    $flush();
                }
            }
        }

        $flush();

        return $total;
    }

    private function payloadValueRow(int $versionId, array $field, int $eventId, string $now)
    {
        $row = [
            'version_id' => $versionId,
            'payload_field_id' => $field['id'],
            'entity_type' => 'event',
            'entity_id' => $eventId,
            'value_string' => null,
            'value_integer' => null,
            'value_decimal' => null,
            'value_boolean' => null,
            'value_datetime' => null,
            'value_text' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        switch ($field['data_type']) {
            case 'integer':
                $row['value_integer'] = rand(1, 1000);
                break;
            case 'decimal':
                $row['value_decimal'] = rand(0, 10000) / 100;
                break;
            case 'boolean':
                $row['value_boolean'] = rand(0, 1);
                break;
            case 'datetime':
                $row['value_datetime'] = Carbon::create(2026, 1, 1)->addDays(rand(0, 29))->toDateTimeString();
                break;
            case 'text':
                $row['value_text'] = 'Note '.rand(1, 99999);
                break;
            default:
                $row['value_string'] = $this->stringValueFor($field['code']);
                break;
        }

        return $row;
    }

    private function stringValueFor(string $code): string
    {
        $pools = [
            'language' => ['it', 'en', 'es', 'de', 'fr'],
            'utm_source' => ['google', 'direct', 'newsletter', 'partner', 'linkedin', 'qr_event'],
        ];
        $pool = $pools[$code] ?? ['a', 'b', 'c'];

        return $pool[array_rand($pool)];
    }

    private function createSideEntities(int $versionId, array $versionPlayers, string $now)
    {
        $txTypes = ['purchase', 'lead_qualified', 'reward_assigned', 'coupon_redeemed'];
        $rewardTypes = ['instant_win', 'coupon_5', 'coupon_10', 'gift_card'];
        $transactions = [];
        $answers = [];
        $rewards = [];
        $txCounter = 0;

        foreach ($versionPlayers as $vp) {
            $txCount = rand(0, 2);
            for ($t = 0; $t < $txCount; $t++) {
                $transactions[] = [
                    'version_id' => $versionId,
                    'player_id' => $vp->player_id,
                    'version_player_id' => $vp->id,
                    'transaction_id' => 'txn_'.$versionId.'_'.(++$txCounter),
                    'type' => $txTypes[array_rand($txTypes)],
                    'amount' => rand(100, 20000) / 100,
                    'currency' => 'EUR',
                    'occurred_at' => Carbon::create(2026, 1, 1)->addDays(rand(0, 29))->toDateTimeString(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $answerCount = rand(0, 3);
            for ($a = 1; $a <= $answerCount; $a++) {
                $answers[] = [
                    'version_id' => $versionId,
                    'player_id' => $vp->player_id,
                    'version_player_id' => $vp->id,
                    'question_id' => 'q'.$a,
                    'question' => 'Question '.$a,
                    'answer' => 'Answer '.rand(1, 4),
                    'occurred_at' => Carbon::create(2026, 1, 1)->addDays(rand(0, 29))->toDateTimeString(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (rand(0, 100) < 70) {
                $type = $rewardTypes[array_rand($rewardTypes)];
                $rewards[] = [
                    'version_id' => $versionId,
                    'player_id' => $vp->player_id,
                    'version_player_id' => $vp->id,
                    'reward_code' => strtoupper($type).'-'.rand(1000, 9999),
                    'reward_type' => $type,
                    'assigned_at' => Carbon::create(2026, 1, 1)->addDays(rand(0, 29))->toDateTimeString(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (count($transactions) >= $this->chunkSize) {
                DB::table('transactions')->insert($transactions);
                $transactions = [];
            }
            if (count($answers) >= $this->chunkSize) {
                DB::table('answers')->insert($answers);
                $answers = [];
            }
            if (count($rewards) >= $this->chunkSize) {
                DB::table('rewards')->insert($rewards);
                $rewards = [];
            }
        }

        if (! empty($transactions)) {
            DB::table('transactions')->insert($transactions);
        }
        if (! empty($answers)) {
            DB::table('answers')->insert($answers);
        }
        if (! empty($rewards)) {
            DB::table('rewards')->insert($rewards);
        }
    }
}
