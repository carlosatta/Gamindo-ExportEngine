<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\PayloadField;
use App\Models\PayloadValue;
use App\Models\Player;
use App\Models\Version;
use App\Models\VersionPlayer;
use App\Services\Export\ExportQueryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportQueryBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function makeEvent(Version $version, string $type = 'opened'): Event
    {
        $player = Player::factory()->create();
        $versionPlayer = VersionPlayer::factory()->create([
            'version_id' => $version->id,
            'player_id' => $player->id,
        ]);

        return Event::factory()->create([
            'version_id' => $version->id,
            'player_id' => $player->id,
            'version_player_id' => $versionPlayer->id,
            'type' => $type,
        ]);
    }

    public function test_own_columns()
    {
        $version = Version::factory()->create();
        $this->makeEvent($version);
        $this->makeEvent($version);

        $built = (new ExportQueryBuilder())->build($version, [
            'name' => 'events',
            'columns' => ['type', 'occurred_at'],
        ]);

        $this->assertEquals(['type', 'occurred_at'], $built['headers']);
        $this->assertCount(2, $built['query']->get());
    }

    public function test_payload_eav_column()
    {
        $version = Version::factory()->create();
        $event = $this->makeEvent($version);
        $field = PayloadField::factory()->create([
            'version_id' => $version->id,
            'entity_type' => 'event',
            'code' => 'score',
            'data_type' => 'integer',
        ]);
        PayloadValue::factory()->create([
            'version_id' => $version->id,
            'payload_field_id' => $field->id,
            'entity_type' => 'event',
            'entity_id' => $event->id,
            'value_integer' => 150,
        ]);

        $built = (new ExportQueryBuilder())->build($version, [
            'name' => 'events',
            'columns' => ['type', 'payload.score'],
        ]);

        $this->assertEquals(150, $built['query']->first()->payload_score);
    }

    public function test_filter_and_version_scope()
    {
        $version = Version::factory()->create();
        $other = Version::factory()->create();
        $this->makeEvent($version, 'completed');
        $this->makeEvent($version, 'opened');
        $this->makeEvent($other, 'completed');

        $built = (new ExportQueryBuilder())->build($version, [
            'name' => 'events',
            'columns' => ['type'],
            'filters' => [['field' => 'type', 'operator' => '=', 'value' => 'completed']],
        ]);

        $this->assertCount(1, $built['query']->get());
    }

    public function test_unknown_entity_returns_null()
    {
        $version = Version::factory()->create();

        $this->assertNull((new ExportQueryBuilder())->build($version, ['name' => 'not_an_entity']));
    }

    public function test_unknown_own_column_is_dropped()
    {
        $version = Version::factory()->create();
        $this->makeEvent($version);

        $built = (new ExportQueryBuilder())->build($version, [
            'name' => 'events',
            'columns' => ['type', 'ghost_column', 'occurred_at'],
        ]);

        $this->assertEquals(['type', 'occurred_at'], $built['headers']);
    }

    public function test_unknown_payload_column_is_dropped()
    {
        $version = Version::factory()->create();
        $this->makeEvent($version);

        $built = (new ExportQueryBuilder())->build($version, [
            'name' => 'events',
            'columns' => ['type', 'payload.ghost'],
        ]);

        $this->assertEquals(['type'], $built['headers']);
        $this->assertCount(1, $built['query']->get());
    }

    public function test_all_invalid_columns_fall_back_to_defaults()
    {
        $version = Version::factory()->create();
        $this->makeEvent($version);

        $built = (new ExportQueryBuilder())->build($version, [
            'name' => 'events',
            'columns' => ['ghost', 'payload.nope'],
        ]);

        $this->assertNotEmpty($built['headers']);
        $this->assertContains('type', $built['headers']);
    }

    public function test_unknown_filter_field_is_ignored()
    {
        $version = Version::factory()->create();
        $this->makeEvent($version, 'completed');
        $this->makeEvent($version, 'opened');

        $built = (new ExportQueryBuilder())->build($version, [
            'name' => 'events',
            'columns' => ['type'],
            'filters' => [['field' => 'ghost_column', 'operator' => '=', 'value' => 'x']],
        ]);

        $this->assertCount(2, $built['query']->get());
    }

    public function test_non_filterable_payload_filter_is_ignored()
    {
        $version = Version::factory()->create();
        $this->makeEvent($version);
        $this->makeEvent($version);
        PayloadField::factory()->create([
            'version_id' => $version->id,
            'entity_type' => 'event',
            'code' => 'secret',
            'is_filterable' => false,
        ]);

        $built = (new ExportQueryBuilder())->build($version, [
            'name' => 'events',
            'columns' => ['type'],
            'filters' => [['field' => 'payload.secret', 'operator' => '=', 'value' => 'no-match']],
        ]);

        $this->assertCount(2, $built['query']->get());
    }

    public function test_bad_operator_filter_is_ignored()
    {
        $version = Version::factory()->create();
        $this->makeEvent($version, 'completed');
        $this->makeEvent($version, 'opened');

        $built = (new ExportQueryBuilder())->build($version, [
            'name' => 'events',
            'columns' => ['type'],
            'filters' => [['field' => 'type', 'operator' => 'DROP TABLE', 'value' => 'completed']],
        ]);

        $this->assertCount(2, $built['query']->get());
    }
}
