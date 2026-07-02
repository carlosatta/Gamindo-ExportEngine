<?php

namespace Tests\Feature;

use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerIngestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingest_players_array()
    {
        $version = Version::factory()->create();

        $response = $this->postJson("/api/v1/versions/{$version->id}/players", [
            ['email' => 'a@test.dev', 'external_player_id' => 'ext-1', 'language' => 'it'],
            ['email' => 'b@test.dev', 'external_player_id' => 'ext-2', 'language' => 'en'],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('saved', 2);

        $this->assertDatabaseCount('players', 2);
        $this->assertDatabaseCount('version_players', 2);
        $this->assertDatabaseHas('version_players', ['version_id' => $version->id, 'external_player_id' => 'ext-1']);
    }

    public function test_email_required()
    {
        $version = Version::factory()->create();

        $response = $this->postJson("/api/v1/versions/{$version->id}/players", [
            ['external_player_id' => 'ext-1'],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'partial_success')
            ->assertJsonPath('saved', 0);
    }

    public function test_reingestion_is_idempotent_and_updates_anagraphic()
    {
        $version = Version::factory()->create();

        $this->postJson("/api/v1/versions/{$version->id}/players", [
            'email' => 'a@test.dev', 'external_player_id' => 'ext-1', 'status' => 'active',
        ])->assertStatus(200);

        $this->postJson("/api/v1/versions/{$version->id}/players", [
            'email' => 'a@test.dev', 'external_player_id' => 'ext-1', 'status' => 'completed',
        ])->assertStatus(200);

        $this->assertDatabaseCount('players', 1);
        $this->assertDatabaseCount('version_players', 1);
        $this->assertDatabaseHas('version_players', ['external_player_id' => 'ext-1', 'status' => 'completed']);
    }

    public function test_same_email_shared_across_versions()
    {
        $versionA = Version::factory()->create();
        $versionB = Version::factory()->create();

        $this->postJson("/api/v1/versions/{$versionA->id}/players", [
            'email' => 'shared@test.dev', 'external_player_id' => 'ext-1',
        ])->assertStatus(200);

        $this->postJson("/api/v1/versions/{$versionB->id}/players", [
            'email' => 'shared@test.dev', 'external_player_id' => 'ext-1',
        ])->assertStatus(200);

        $this->assertDatabaseCount('players', 1);
        $this->assertDatabaseCount('version_players', 2);
    }
}
