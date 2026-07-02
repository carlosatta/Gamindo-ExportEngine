<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\Version;
use App\Models\VersionPlayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngestionTest extends TestCase
{
    use RefreshDatabase;

    private function versionWithPlayer(string $externalId = 'ext-1'): array
    {
        $version = Version::factory()->create();
        $player = Player::factory()->create();
        $versionPlayer = VersionPlayer::factory()->create([
            'version_id' => $version->id,
            'player_id' => $player->id,
            'external_player_id' => $externalId,
        ]);

        return [$version, $versionPlayer];
    }

    public function test_ingest_events_array_success()
    {
        [$version] = $this->versionWithPlayer();

        $response = $this->postJson("/api/v1/versions/{$version->id}/events", [
            ['external_player_id' => 'ext-1', 'type' => 'opened', 'occurred_at' => '2026-07-01T10:00:00Z'],
            ['external_player_id' => 'ext-1', 'type' => 'completed', 'occurred_at' => '2026-07-01T10:05:00Z'],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('saved', 2);

        $this->assertDatabaseCount('events', 2);
    }

    public function test_ingest_single_object()
    {
        [$version] = $this->versionWithPlayer();

        $response = $this->postJson("/api/v1/versions/{$version->id}/events", [
            'external_player_id' => 'ext-1',
            'type' => 'opened',
            'occurred_at' => '2026-07-01T10:00:00Z',
        ]);

        $response->assertStatus(200)->assertJsonPath('saved', 1);
        $this->assertDatabaseCount('events', 1);
    }

    public function test_partial_success_on_unknown_player()
    {
        [$version] = $this->versionWithPlayer();

        $response = $this->postJson("/api/v1/versions/{$version->id}/events", [
            ['external_player_id' => 'ext-1', 'type' => 'opened', 'occurred_at' => '2026-07-01T10:00:00Z'],
            ['external_player_id' => 'ext-unknown', 'type' => 'opened', 'occurred_at' => '2026-07-01T10:00:00Z'],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'partial_success')
            ->assertJsonPath('saved', 1);

        $this->assertDatabaseCount('events', 1);
        $this->assertCount(1, $response->json('errors'));
    }

    public function test_validation_error_recorded()
    {
        [$version] = $this->versionWithPlayer();

        $response = $this->postJson("/api/v1/versions/{$version->id}/events", [
            ['external_player_id' => 'ext-1', 'occurred_at' => '2026-07-01T10:00:00Z'],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'partial_success')
            ->assertJsonPath('saved', 0);
    }

    public function test_event_payload_normalized_to_eav()
    {
        [$version] = $this->versionWithPlayer();

        $this->postJson("/api/v1/versions/{$version->id}/events", [
            'external_player_id' => 'ext-1',
            'type' => 'completed',
            'occurred_at' => '2026-07-01T10:00:00Z',
            'payload' => ['score' => 150, 'language' => 'it', 'is_winner' => true],
        ])->assertStatus(200)->assertJsonPath('saved', 1);

        $this->assertDatabaseHas('payload_fields', ['version_id' => $version->id, 'code' => 'score', 'data_type' => 'integer']);
        $this->assertDatabaseHas('payload_fields', ['version_id' => $version->id, 'code' => 'language', 'data_type' => 'string']);
        $this->assertDatabaseHas('payload_fields', ['version_id' => $version->id, 'code' => 'is_winner', 'data_type' => 'boolean']);
        $this->assertDatabaseHas('payload_values', ['entity_type' => 'event', 'value_integer' => 150]);
        $this->assertDatabaseCount('payload_values', 3);
    }

    public function test_transaction_idempotent()
    {
        [$version] = $this->versionWithPlayer();

        $body = [
            'external_player_id' => 'ext-1',
            'transaction_id' => 'txn-1',
            'type' => 'purchase',
            'amount' => 9.99,
            'currency' => 'EUR',
            'occurred_at' => '2026-07-01T10:00:00Z',
        ];

        $this->postJson("/api/v1/versions/{$version->id}/transactions", $body)->assertStatus(200);
        $this->postJson("/api/v1/versions/{$version->id}/transactions", $body)->assertStatus(200);

        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_auto_create_player_via_email()
    {
        $version = Version::factory()->create();

        $response = $this->postJson("/api/v1/versions/{$version->id}/events", [
            'external_player_id' => 'ext-new',
            'email' => 'new@player.test',
            'type' => 'opened',
            'occurred_at' => '2026-07-01T10:00:00Z',
        ]);

        $response->assertStatus(200)->assertJsonPath('saved', 1);
        $this->assertDatabaseHas('players', ['email' => 'new@player.test']);
        $this->assertDatabaseHas('version_players', ['version_id' => $version->id, 'external_player_id' => 'ext-new']);
        $this->assertDatabaseCount('events', 1);
    }

    public function test_ingest_rewards_and_answers()
    {
        [$version] = $this->versionWithPlayer();

        $this->postJson("/api/v1/versions/{$version->id}/rewards", [
            'external_player_id' => 'ext-1', 'reward_type' => 'coupon_10', 'reward_code' => 'SAVE10', 'assigned_at' => '2026-07-01T10:00:00Z',
        ])->assertStatus(200)->assertJsonPath('saved', 1);

        $this->postJson("/api/v1/versions/{$version->id}/answers", [
            'external_player_id' => 'ext-1', 'question_id' => 'q1', 'answer' => 'blue', 'occurred_at' => '2026-07-01T10:00:00Z',
        ])->assertStatus(200)->assertJsonPath('saved', 1);

        $this->assertDatabaseCount('rewards', 1);
        $this->assertDatabaseCount('answers', 1);
    }
}
