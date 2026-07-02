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

            ExportTemplate::factory()->create(['version_id' => $version->id]);
        });
    }

    private function createPayloadFields(Version $version)
    {
        return collect(['score', 'level'])->mapWithKeys(function (string $code) use ($version) {
            $field = PayloadField::factory()->create([
                'version_id' => $version->id,
                'code' => $code,
                'label' => ucfirst($code),
            ]);

            return [$code => $field];
        });
    }

    private function createEvents(Version $version, Player $player, VersionPlayer $versionPlayer, $fields)
    {
        Event::factory()
            ->count(rand(2, 5))
            ->create($this->owner($version, $player, $versionPlayer))
            ->each(function (Event $event) use ($version, $fields) {
                foreach ($fields as $field) {
                    PayloadValue::factory()->create([
                        'version_id' => $version->id,
                        'payload_field_id' => $field->id,
                        'entity_type' => 'event',
                        'entity_id' => $event->id,
                    ]);
                }
            });
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
