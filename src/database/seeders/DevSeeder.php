<?php

namespace Database\Seeders;

use App\Models\Answer;
use App\Models\Event;
use App\Models\ExportTemplate;
use App\Models\PayloadField;
use App\Models\PayloadValue;
use App\Models\Player;
use App\Models\Reward;
use App\Models\Transaction;
use App\Models\Version;
use App\Models\VersionPlayer;
use Illuminate\Database\Seeder;

class DevSeeder extends Seeder
{
    public function run()
    {
        Version::factory()->count(2)->create()->each(function (Version $version) {
            $fields = $this->createPayloadFields($version);

            Player::factory()->count(15)->create()->each(function (Player $player) use ($version, $fields) {
                $versionPlayer = VersionPlayer::factory()->create([
                    'version_id' => $version->id,
                    'player_id' => $player->id,
                ]);

                $this->createEvents($version, $player, $versionPlayer, $fields);

                Transaction::factory()->count(rand(0, 2))->create($this->owner($version, $player, $versionPlayer));
                Answer::factory()->count(rand(0, 3))->create($this->owner($version, $player, $versionPlayer));

                if (rand(0, 100) < 70) {
                    Reward::factory()->create($this->owner($version, $player, $versionPlayer));
                }
            });
        });

        ExportTemplate::factory()->count(2)->create();
    }

    private function createPayloadFields(Version $version)
    {
        $definitions = [
            ['code' => 'score', 'data_type' => 'integer'],
            ['code' => 'level', 'data_type' => 'integer'],
            ['code' => 'difficulty_rating', 'data_type' => 'decimal'],
            ['code' => 'language', 'data_type' => 'string'],
            ['code' => 'utm_source', 'data_type' => 'string'],
            ['code' => 'is_winner', 'data_type' => 'boolean'],
            ['code' => 'last_action_at', 'data_type' => 'datetime'],
            ['code' => 'note', 'data_type' => 'text'],
        ];

        return collect($definitions)->mapWithKeys(function (array $definition) use ($version) {
            $field = PayloadField::factory()->create(array_merge([
                'version_id' => $version->id,
                'code' => $definition['code'],
                'label' => ucfirst(str_replace('_', ' ', $definition['code'])),
                'data_type' => $definition['data_type'],
            ], $this->flagsFor($definition['data_type'])));

            return [$definition['code'] => $field];
        });
    }

    private function flagsFor(string $dataType)
    {
        if ($dataType === 'text') {
            return ['is_filterable' => false, 'is_sortable' => false, 'is_aggregatable' => false];
        }

        if ($dataType === 'string') {
            return ['is_filterable' => true, 'is_sortable' => true, 'is_aggregatable' => false];
        }

        return ['is_filterable' => true, 'is_sortable' => true, 'is_aggregatable' => true];
    }

    private function createEvents(Version $version, Player $player, VersionPlayer $versionPlayer, $fields)
    {
        Event::factory()
            ->count(rand(2, 5))
            ->create($this->owner($version, $player, $versionPlayer))
            ->each(function (Event $event) use ($version, $fields) {
                foreach ($fields as $field) {
                    PayloadValue::factory()->create(array_merge([
                        'version_id' => $version->id,
                        'payload_field_id' => $field->id,
                        'entity_type' => 'event',
                        'entity_id' => $event->id,
                    ], $this->valueFor($field->data_type)));
                }
            });
    }

    private function valueFor(string $dataType)
    {
        $columns = [
            'value_string' => null,
            'value_integer' => null,
            'value_decimal' => null,
            'value_boolean' => null,
            'value_datetime' => null,
            'value_text' => null,
        ];

        switch ($dataType) {
            case 'integer':
                $columns['value_integer'] = rand(1, 1000);
                break;
            case 'decimal':
                $columns['value_decimal'] = rand(0, 10000) / 100;
                break;
            case 'boolean':
                $columns['value_boolean'] = (bool) rand(0, 1);
                break;
            case 'datetime':
                $columns['value_datetime'] = now()->subDays(rand(0, 30))->format('Y-m-d H:i:s');
                break;
            case 'text':
                $columns['value_text'] = 'Note '.rand(1, 9999);
                break;
            default:
                $columns['value_string'] = ['it', 'en', 'es', 'google', 'facebook', 'organic'][array_rand(['it', 'en', 'es', 'google', 'facebook', 'organic'])];
                break;
        }

        return $columns;
    }

    private function owner(Version $version, Player $player, VersionPlayer $versionPlayer)
    {
        return [
            'version_id' => $version->id,
            'player_id' => $player->id,
            'version_player_id' => $versionPlayer->id,
        ];
    }
}
