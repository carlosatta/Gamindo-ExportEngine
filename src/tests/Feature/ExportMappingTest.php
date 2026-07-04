<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\ExportTemplate;
use App\Models\PayloadField;
use App\Models\PayloadValue;
use App\Models\Player;
use App\Models\Transaction;
use App\Models\Version;
use App\Models\VersionPlayer;
use App\Services\Export\MappingSheetBuilder;
use Database\Seeders\MappingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportMappingTest extends TestCase
{
    use RefreshDatabase;

    private function playersMapping(): array
    {
        $this->seed(MappingSeeder::class);

        return ExportTemplate::where('name', 'players')->first()->definition;
    }

    public function test_players_detail_with_joins_and_aggregates()
    {
        $mapping = $this->playersMapping();

        $version = Version::factory()->create();
        $player = Player::factory()->create(['email' => 'p@x.io']);
        $versionPlayer = VersionPlayer::factory()->create([
            'version_id' => $version->id,
            'player_id' => $player->id,
            'language' => 'it',
        ]);

        $scoreField = PayloadField::factory()->create([
            'version_id' => $version->id,
            'entity_type' => 'event',
            'code' => 'score',
            'data_type' => 'integer',
        ]);
        foreach ([100, 50] as $score) {
            $event = Event::factory()->create([
                'version_id' => $version->id,
                'player_id' => $player->id,
                'version_player_id' => $versionPlayer->id,
            ]);
            PayloadValue::factory()->create([
                'version_id' => $version->id,
                'payload_field_id' => $scoreField->id,
                'entity_type' => 'event',
                'entity_id' => $event->id,
                'value_integer' => $score,
            ]);
        }
        foreach ([9.99, 5.01] as $amount) {
            Transaction::factory()->create([
                'version_id' => $version->id,
                'player_id' => $player->id,
                'version_player_id' => $versionPlayer->id,
                'amount' => $amount,
            ]);
        }

        $built = (new MappingSheetBuilder())->build($version, $mapping, [
            'columns' => ['player_id', 'email', 'total_score', 'events_count', 'revenue'],
            'filters' => ['language' => 'it'],
            'sort' => ['registered_at:desc'],
        ]);

        $this->assertEquals(['player_id', 'email', 'total_score', 'events_count', 'revenue'], $built['headers']);
        $this->assertArrayHasKey('player_id', $built['formats']);

        $row = $built['query']->first();
        $this->assertEquals('p@x.io', $row->email);
        $this->assertEquals(150, $row->total_score);
        $this->assertEquals(2, $row->events_count);
        $this->assertEquals(15.0, (float) $row->revenue);
    }

    public function test_filter_excludes_non_matching()
    {
        $mapping = $this->playersMapping();
        $version = Version::factory()->create();
        $player = Player::factory()->create();
        VersionPlayer::factory()->create([
            'version_id' => $version->id,
            'player_id' => $player->id,
            'language' => 'en',
        ]);

        $built = (new MappingSheetBuilder())->build($version, $mapping, [
            'columns' => ['email'],
            'filters' => ['language' => 'it'],
        ]);

        $this->assertCount(0, $built['query']->get());
    }

    public function test_unknown_columns_dropped()
    {
        $mapping = $this->playersMapping();
        $version = Version::factory()->create();

        $built = (new MappingSheetBuilder())->build($version, $mapping, [
            'columns' => ['email', 'ghost_column'],
        ]);

        $this->assertEquals(['email'], $built['headers']);
    }

    public function test_alias_is_sanitized_against_injection()
    {
        $this->seed(MappingSeeder::class);
        $mapping = ExportTemplate::where('name', 'events_summary')->first()->definition;
        $version = Version::factory()->create();
        $player = Player::factory()->create();
        $versionPlayer = VersionPlayer::factory()->create(['version_id' => $version->id, 'player_id' => $player->id]);
        Event::factory()->create(['version_id' => $version->id, 'player_id' => $player->id, 'version_player_id' => $versionPlayer->id, 'type' => 'opened']);

        $built = (new MappingSheetBuilder())->build($version, $mapping, [
            'group_by' => ['event_type'],
            'metrics' => [['fn' => 'count', 'as' => 'evil`) as x, (select 1)']],
        ]);

        $this->assertEquals('summary', $built['mode']);
        foreach ($built['keys'] as $key) {
            $this->assertStringNotContainsString('`', $key);
        }
        $this->assertNotEmpty($built['rows']);
    }

    public function test_bare_name_uses_default_columns()
    {
        $mapping = $this->playersMapping();
        $version = Version::factory()->create();
        PayloadField::factory()->create(['version_id' => $version->id, 'entity_type' => 'event', 'code' => 'score', 'data_type' => 'integer']);
        PayloadField::factory()->create(['version_id' => $version->id, 'entity_type' => 'event', 'code' => 'level', 'data_type' => 'integer']);

        $built = (new MappingSheetBuilder())->build($version, $mapping, []);

        $this->assertEquals('detail', $built['mode']);
        $this->assertContains('player_id', $built['headers']);
        $this->assertContains('revenue', $built['headers']);
        $this->assertCount(13, $built['headers']);
    }

    public function test_events_summary_group_by_and_metrics()
    {
        $this->seed(MappingSeeder::class);
        $mapping = ExportTemplate::where('name', 'events_summary')->first()->definition;

        $version = Version::factory()->create();
        $langField = PayloadField::factory()->create([
            'version_id' => $version->id, 'entity_type' => 'event', 'code' => 'language', 'data_type' => 'string',
        ]);
        $scoreField = PayloadField::factory()->create([
            'version_id' => $version->id, 'entity_type' => 'event', 'code' => 'score', 'data_type' => 'integer',
        ]);

        $rows = [
            ['score' => 100], ['score' => 200], ['score' => 300],
        ];
        $players = [];
        foreach ([0, 1] as $i) {
            $p = Player::factory()->create();
            $players[$i] = VersionPlayer::factory()->create(['version_id' => $version->id, 'player_id' => $p->id]);
        }
        foreach ([[0, 100], [0, 200], [1, 300]] as $pair) {
            [$playerIndex, $score] = $pair;
            $vp = $players[$playerIndex];
            $event = Event::factory()->create([
                'version_id' => $version->id, 'player_id' => $vp->player_id, 'version_player_id' => $vp->id, 'type' => 'opened',
            ]);
            PayloadValue::factory()->create(['version_id' => $version->id, 'payload_field_id' => $langField->id, 'entity_type' => 'event', 'entity_id' => $event->id, 'value_string' => 'it', 'value_integer' => null]);
            PayloadValue::factory()->create(['version_id' => $version->id, 'payload_field_id' => $scoreField->id, 'entity_type' => 'event', 'entity_id' => $event->id, 'value_integer' => $score]);
        }

        $built = (new MappingSheetBuilder())->build($version, $mapping, [
            'group_by' => ['event_type', 'language'],
            'metrics' => [
                ['fn' => 'count', 'as' => 'events_count'],
                ['fn' => 'count_distinct', 'on' => 'player_id', 'as' => 'unique_players'],
                ['fn' => 'ratio', 'num' => 'events_count', 'den' => 'unique_players', 'as' => 'events_per_player'],
                ['fn' => 'avg', 'on' => 'score', 'as' => 'avg_score'],
            ],
        ]);

        $this->assertEquals('summary', $built['mode']);
        $this->assertCount(1, $built['rows']);
        $row = $built['rows'][0];
        $this->assertEquals('opened', $row['event_type']);
        $this->assertEquals('it', $row['language']);
        $this->assertEquals(3, $row['events_count']);
        $this->assertEquals(2, $row['unique_players']);
        $this->assertEquals(1.5, $row['events_per_player']);
        $this->assertEquals(200, (int) $row['avg_score']);
    }

    public function test_eav_extraction_covers_all_payload_types()
    {
        $version = Version::factory()->create();
        $player = Player::factory()->create();
        $vp = VersionPlayer::factory()->create(['version_id' => $version->id, 'player_id' => $player->id]);
        $event = Event::factory()->create([
            'version_id' => $version->id, 'player_id' => $player->id, 'version_player_id' => $vp->id, 'type' => 'opened',
        ]);

        $defs = [
            'p_int' => ['data_type' => 'integer', 'column' => 'value_integer', 'value' => 42],
            'p_dec' => ['data_type' => 'decimal', 'column' => 'value_decimal', 'value' => 3.14],
            'p_bool' => ['data_type' => 'boolean', 'column' => 'value_boolean', 'value' => 1],
            'p_dt' => ['data_type' => 'datetime', 'column' => 'value_datetime', 'value' => '2026-01-15 10:00:00'],
            'p_str' => ['data_type' => 'string', 'column' => 'value_string', 'value' => 'hello'],
            'p_txt' => ['data_type' => 'text', 'column' => 'value_text', 'value' => 'a long note'],
        ];

        $columns = [];
        foreach ($defs as $code => $def) {
            $field = PayloadField::factory()->create([
                'version_id' => $version->id, 'entity_type' => 'event', 'code' => $code, 'data_type' => $def['data_type'],
            ]);
            $attrs = [
                'version_id' => $version->id, 'payload_field_id' => $field->id, 'entity_type' => 'event', 'entity_id' => $event->id,
                'value_string' => null, 'value_integer' => null, 'value_decimal' => null,
                'value_boolean' => null, 'value_datetime' => null, 'value_text' => null,
            ];
            $attrs[$def['column']] = $def['value'];
            PayloadValue::factory()->create($attrs);
            $columns[$code] = ['payload' => $code];
        }

        $template = ['base' => 'events', 'columns' => $columns];
        $built = (new MappingSheetBuilder())->build($version, $template, ['columns' => array_keys($columns)]);

        $this->assertEquals('detail', $built['mode']);
        $this->assertEquals(array_keys($defs), $built['headers']);

        $row = (array) $built['query']->get()->first();
        $this->assertEquals(42, (int) $row['p_int']);
        $this->assertEquals(3.14, (float) $row['p_dec']);
        $this->assertEquals(1, (int) $row['p_bool']);
        $this->assertEquals('2026-01-15 10:00:00', $row['p_dt']);
        $this->assertEquals('hello', $row['p_str']);
        $this->assertEquals('a long note', $row['p_txt']);
    }

    public function test_eav_flags_gate_filter_sort_and_aggregate()
    {
        $version = Version::factory()->create();
        $player = Player::factory()->create();
        $vp = VersionPlayer::factory()->create(['version_id' => $version->id, 'player_id' => $player->id]);
        $tier = PayloadField::factory()->create([
            'version_id' => $version->id, 'entity_type' => 'event', 'code' => 'tier', 'data_type' => 'string',
            'is_filterable' => false, 'is_sortable' => false, 'is_aggregatable' => false,
        ]);
        $price = PayloadField::factory()->create([
            'version_id' => $version->id, 'entity_type' => 'event', 'code' => 'price', 'data_type' => 'decimal',
            'is_aggregatable' => false,
        ]);
        foreach ([['gold', 10.0], ['silver', 20.0]] as $pair) {
            [$tierValue, $priceValue] = $pair;
            $event = Event::factory()->create(['version_id' => $version->id, 'player_id' => $player->id, 'version_player_id' => $vp->id, 'type' => 'purchase']);
            PayloadValue::factory()->create(['version_id' => $version->id, 'payload_field_id' => $tier->id, 'entity_type' => 'event', 'entity_id' => $event->id, 'value_string' => $tierValue, 'value_integer' => null]);
            PayloadValue::factory()->create(['version_id' => $version->id, 'payload_field_id' => $price->id, 'entity_type' => 'event', 'entity_id' => $event->id, 'value_decimal' => $priceValue, 'value_integer' => null]);
        }

        $template = ['base' => 'events', 'columns' => ['tier' => ['payload' => 'tier'], 'price' => ['payload' => 'price']]];

        $filtered = (new MappingSheetBuilder())->build($version, $template, ['columns' => ['tier', 'price'], 'filters' => ['tier' => 'gold']]);
        $this->assertEquals(2, $filtered['query']->count());

        $summary = (new MappingSheetBuilder())->build($version, $template, [
            'group_by' => ['tier'],
            'metrics' => [['fn' => 'avg', 'on' => 'price', 'as' => 'avg_price'], ['fn' => 'count', 'as' => 'n']],
        ]);
        $this->assertNotContains('avg_price', $summary['headers']);
        $this->assertContains('n', $summary['headers']);
    }

    public function test_eav_aggregate_and_group_by_on_typed_payload()
    {
        $version = Version::factory()->create();
        $player = Player::factory()->create();
        $vp = VersionPlayer::factory()->create(['version_id' => $version->id, 'player_id' => $player->id]);
        $tier = PayloadField::factory()->create(['version_id' => $version->id, 'entity_type' => 'event', 'code' => 'tier', 'data_type' => 'string']);
        $price = PayloadField::factory()->create(['version_id' => $version->id, 'entity_type' => 'event', 'code' => 'price', 'data_type' => 'decimal']);

        foreach ([['gold', 10.0], ['gold', 20.0], ['silver', 5.0]] as $pair) {
            [$tierValue, $priceValue] = $pair;
            $event = Event::factory()->create(['version_id' => $version->id, 'player_id' => $player->id, 'version_player_id' => $vp->id, 'type' => 'purchase']);
            PayloadValue::factory()->create(['version_id' => $version->id, 'payload_field_id' => $tier->id, 'entity_type' => 'event', 'entity_id' => $event->id, 'value_string' => $tierValue, 'value_integer' => null]);
            PayloadValue::factory()->create(['version_id' => $version->id, 'payload_field_id' => $price->id, 'entity_type' => 'event', 'entity_id' => $event->id, 'value_decimal' => $priceValue, 'value_integer' => null]);
        }

        $template = ['base' => 'events', 'columns' => ['tier' => ['payload' => 'tier'], 'price' => ['payload' => 'price']]];
        $built = (new MappingSheetBuilder())->build($version, $template, [
            'group_by' => ['tier'],
            'metrics' => [['fn' => 'sum', 'on' => 'price', 'as' => 'total'], ['fn' => 'avg', 'on' => 'price', 'as' => 'mean']],
        ]);

        $this->assertEquals('summary', $built['mode']);
        $byTier = [];
        foreach ($built['rows'] as $r) {
            $byTier[$r['tier']] = $r;
        }
        $this->assertEquals(30.0, (float) $byTier['gold']['total']);
        $this->assertEquals(15.0, (float) $byTier['gold']['mean']);
        $this->assertEquals(5.0, (float) $byTier['silver']['total']);
    }
}
