<?php

namespace Tests\Feature;

use App\Jobs\GenerateExportJob;
use App\Models\Event;
use App\Models\ExportRequest;
use App\Models\PayloadField;
use App\Models\PayloadValue;
use App\Models\Player;
use App\Models\Transaction;
use App\Models\Version;
use App\Models\VersionPlayer;
use Database\Seeders\MappingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExportEndToEndTest extends TestCase
{
    use RefreshDatabase;

    public function test_multi_sheet_export_generates_expected_tabs()
    {
        Storage::fake('local');
        $this->seed(MappingSeeder::class);

        $version = Version::factory()->create();
        $player = Player::factory()->create();
        $versionPlayer = VersionPlayer::factory()->create([
            'version_id' => $version->id, 'player_id' => $player->id, 'language' => 'it',
        ]);
        $scoreField = PayloadField::factory()->create(['version_id' => $version->id, 'entity_type' => 'event', 'code' => 'score', 'data_type' => 'integer']);
        $langField = PayloadField::factory()->create(['version_id' => $version->id, 'entity_type' => 'event', 'code' => 'language', 'data_type' => 'string']);
        $event = Event::factory()->create(['version_id' => $version->id, 'player_id' => $player->id, 'version_player_id' => $versionPlayer->id, 'type' => 'opened']);
        PayloadValue::factory()->create(['version_id' => $version->id, 'payload_field_id' => $scoreField->id, 'entity_type' => 'event', 'entity_id' => $event->id, 'value_integer' => 100]);
        PayloadValue::factory()->create(['version_id' => $version->id, 'payload_field_id' => $langField->id, 'entity_type' => 'event', 'entity_id' => $event->id, 'value_string' => 'it', 'value_integer' => null]);
        Transaction::factory()->create(['version_id' => $version->id, 'player_id' => $player->id, 'version_player_id' => $versionPlayer->id, 'amount' => 10.0]);

        $export = ExportRequest::factory()->create([
            'version_id' => $version->id,
            'status' => 'pending',
            'request_payload' => [
                'format' => 'xlsx',
                'sheets' => [
                    ['name' => 'data_quality'],
                    ['name' => 'players', 'columns' => ['player_id', 'email', 'total_score', 'status']],
                    ['name' => 'readme'],
                    ['name' => 'events_summary', 'group_by' => ['event_type', 'language'], 'metrics' => [['fn' => 'count', 'as' => 'events_count']]],
                    ['name' => 'kpis'],
                    ['name' => 'ghost_sheet'],
                ],
            ],
        ]);

        (new GenerateExportJob($export->id))->handle();

        $export->refresh();
        $this->assertEquals('completed', $export->status);
        Storage::assertExists($export->file_path);

        $zip = new \ZipArchive();
        $zip->open(Storage::path($export->file_path));
        $workbook = $zip->getFromName('xl/workbook.xml');
        $kpisSheet = $zip->getFromName('xl/worksheets/sheet2.xml');
        $playersSheet = $zip->getFromName('xl/worksheets/sheet4.xml');
        $styles = $zip->getFromName('xl/styles.xml');
        $zip->close();

        preg_match_all('/name="([^"]+)"/', $workbook, $matches);
        $this->assertEquals(['README', 'KPIs', 'Configurazione_Richiesta', 'Players', 'Events_Summary', 'Data_Quality'], $matches[1]);
        $this->assertStringNotContainsString('ghost_sheet', $workbook);

        $this->assertStringNotContainsString('<f>', $kpisSheet);
        $this->assertStringContainsString('<v>', $kpisSheet);
        $this->assertStringContainsString('<autoFilter', $playersSheet);
        $this->assertStringContainsStringIgnoringCase('1F4E78', $styles);
        $this->assertStringContainsStringIgnoringCase('FFE699', $styles);
    }
}
