<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\PayloadField;
use App\Models\Player;
use App\Models\Version;
use App\Models\VersionPlayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_version_player_relations_work()
    {
        $version = Version::factory()->create();
        $player = Player::factory()->create();

        $versionPlayer = VersionPlayer::factory()->create([
            'version_id' => $version->id,
            'player_id' => $player->id,
        ]);

        $event = Event::create([
            'version_id' => $version->id,
            'player_id' => $player->id,
            'version_player_id' => $versionPlayer->id,
            'type' => 'opened',
            'occurred_at' => now(),
        ]);

        $this->assertDatabaseCount('events', 1);
        $this->assertEquals(1, $version->events()->count());
        $this->assertEquals(1, $version->versionPlayers()->count());
        $this->assertEquals($player->id, $event->player->id);
        $this->assertTrue($player->versions->contains($version));
    }

    public function test_version_player_unique_constraint()
    {
        $version = Version::factory()->create();
        $player = Player::factory()->create();

        VersionPlayer::factory()->create([
            'version_id' => $version->id,
            'player_id' => $player->id,
            'external_player_id' => 'ext-1',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        VersionPlayer::factory()->create([
            'version_id' => $version->id,
            'player_id' => $player->id,
            'external_player_id' => 'ext-2',
        ]);
    }

    public function test_payload_field_unique_blocks_duplicate_generic_event_type()
    {
        $version = Version::factory()->create();

        PayloadField::factory()->create([
            'version_id' => $version->id,
            'entity_type' => 'event',
            'event_type' => '',
            'code' => 'score',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        PayloadField::factory()->create([
            'version_id' => $version->id,
            'entity_type' => 'event',
            'event_type' => '',
            'code' => 'score',
        ]);
    }
}
