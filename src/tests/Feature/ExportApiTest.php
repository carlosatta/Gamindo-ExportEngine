<?php

namespace Tests\Feature;

use App\Jobs\GenerateExportJob;
use App\Models\ExportRequest;
use App\Models\Player;
use App\Models\Version;
use App\Models\VersionPlayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExportApiTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return [
            'format' => 'xlsx',
            'sheets' => [
                ['name' => 'players', 'columns' => ['player_id', 'email']],
            ],
        ];
    }

    public function test_create_export_returns_202_and_dispatches_job()
    {
        Queue::fake();
        $version = Version::factory()->create();

        $response = $this->postJson("/api/v1/versions/{$version->id}/exports", $this->payload());

        $response->assertStatus(202)
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('export_requests', [
            'version_id' => $version->id,
            'status' => 'pending',
        ]);
        Queue::assertPushed(GenerateExportJob::class);
    }

    public function test_create_export_requires_sheets()
    {
        $version = Version::factory()->create();

        $this->postJson("/api/v1/versions/{$version->id}/exports", ['format' => 'xlsx'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sheets');
    }

    public function test_show_export()
    {
        $export = ExportRequest::factory()->create(['status' => 'pending']);

        $this->getJson("/api/v1/exports/{$export->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'pending');
    }

    public function test_list_all_exports()
    {
        ExportRequest::factory()->count(3)->create();

        $this->getJson('/api/v1/exports')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_download_not_ready_returns_404()
    {
        $export = ExportRequest::factory()->create(['status' => 'pending', 'file_path' => null]);

        $this->getJson("/api/v1/exports/{$export->id}/download")->assertStatus(404);
    }

    public function test_download_completed_returns_file()
    {
        Storage::fake('local');
        Storage::put('exports/export-x.xlsx', 'binary-content');
        $export = ExportRequest::factory()->create([
            'status' => 'completed',
            'file_path' => 'exports/export-x.xlsx',
        ]);

        $this->get("/api/v1/exports/{$export->id}/download")->assertStatus(200);
    }

    public function test_destroy_removes_record_and_file()
    {
        Storage::fake('local');
        Storage::put('exports/export-x.xlsx', 'binary-content');
        $export = ExportRequest::factory()->create([
            'status' => 'completed',
            'file_path' => 'exports/export-x.xlsx',
        ]);

        $this->deleteJson("/api/v1/exports/{$export->id}")->assertStatus(200);

        $this->assertDatabaseMissing('export_requests', ['id' => $export->id]);
        Storage::assertMissing('exports/export-x.xlsx');
    }

    public function test_preview_returns_first_rows_synchronously()
    {
        $this->seed(\Database\Seeders\MappingSeeder::class);
        $version = Version::factory()->create();
        $player = Player::factory()->create(['email' => 'p@x.io']);
        VersionPlayer::factory()->create(['version_id' => $version->id, 'player_id' => $player->id]);

        $response = $this->postJson("/api/v1/versions/{$version->id}/exports/preview", [
            'sheets' => [
                ['name' => 'players', 'columns' => ['player_id', 'email']],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.limit', 100)
            ->assertJsonPath('data.sheets.0.name', 'players')
            ->assertJsonPath('data.sheets.0.headers.0', 'player_id')
            ->assertJsonPath('data.sheets.0.rows.0.email', 'p@x.io');

        $this->assertDatabaseCount('export_requests', 0);
    }

    public function test_job_generates_xlsx_and_completes()
    {
        Storage::fake('local');
        $this->seed(\Database\Seeders\MappingSeeder::class);
        $version = Version::factory()->create();
        $player = Player::factory()->create();
        VersionPlayer::factory()->create(['version_id' => $version->id, 'player_id' => $player->id]);

        $export = ExportRequest::factory()->create([
            'version_id' => $version->id,
            'status' => 'pending',
        ]);

        (new GenerateExportJob($export->id))->handle();

        $export->refresh();
        $this->assertEquals('completed', $export->status);
        $this->assertEquals(100, $export->progress);
        $this->assertNotNull($export->file_path);
        Storage::assertExists($export->file_path);
    }

    public function test_job_tolerates_garbage_payload()
    {
        Storage::fake('local');
        $this->seed(\Database\Seeders\MappingSeeder::class);
        $version = Version::factory()->create();
        $player = Player::factory()->create();
        VersionPlayer::factory()->create(['version_id' => $version->id, 'player_id' => $player->id]);

        $export = ExportRequest::factory()->create([
            'version_id' => $version->id,
            'status' => 'pending',
            'request_payload' => [
                'format' => 'xlsx',
                'sheets' => [
                    ['name' => 'does_not_exist', 'columns' => ['nope']],
                    ['name' => 'players', 'columns' => ['player_id', 'ghost', 'payload.missing'], 'filters' => ['unknown_field' => 'x', 'language' => 'it'], 'sort' => ['ghost:desc', 'total_score:desc']],
                    ['name' => 'events_summary', 'group_by' => ['nonexistent', 'payload.missing'], 'metrics' => [['fn' => 'frobnicate', 'as' => 'weird'], ['fn' => 'count', 'as' => 'n']]],
                    ['name' => 'transactions', 'columns' => ['ghost_only']],
                ],
            ],
        ]);

        (new GenerateExportJob($export->id))->handle();

        $export->refresh();
        $this->assertEquals('completed', $export->status);
        Storage::assertExists($export->file_path);
    }

    public function test_preview_tolerates_garbage_payload()
    {
        $this->seed(\Database\Seeders\MappingSeeder::class);
        $version = Version::factory()->create();
        $player = Player::factory()->create();
        VersionPlayer::factory()->create(['version_id' => $version->id, 'player_id' => $player->id]);

        $this->postJson("/api/v1/versions/{$version->id}/exports/preview", [
            'sheets' => [
                ['name' => 'ghost_table', 'columns' => ['nope']],
                ['name' => 'players', 'columns' => ['player_id', 'ghost', 'payload.nope'], 'filters' => ['bogus' => 'x'], 'sort' => ['bogus:desc']],
                ['name' => 'events_summary', 'group_by' => ['bogus'], 'metrics' => [['fn' => 'nonsense', 'as' => 'z']]],
            ],
        ])->assertStatus(200)->assertJsonPath('data.limit', 100);
    }

    public function test_export_validation_rejects_malformed_shapes()
    {
        $version = Version::factory()->create();

        $this->postJson("/api/v1/versions/{$version->id}/exports", ['format' => 'xlsx', 'sheets' => [['name' => 'players', 'columns' => 'not-an-array']]])
            ->assertStatus(422)->assertJsonValidationErrors('sheets.0.columns');

        $this->postJson("/api/v1/versions/{$version->id}/exports", ['format' => 'xlsx', 'sheets' => [['columns' => ['a']]]])
            ->assertStatus(422)->assertJsonValidationErrors('sheets.0.name');

        $this->postJson("/api/v1/versions/{$version->id}/exports", ['format' => 'pdf', 'sheets' => [['name' => 'players']]])
            ->assertStatus(422)->assertJsonValidationErrors('format');

        $this->postJson("/api/v1/versions/{$version->id}/exports", ['format' => 'xlsx', 'date_from' => 'not-a-date', 'sheets' => [['name' => 'players']]])
            ->assertStatus(422)->assertJsonValidationErrors('date_from');
    }

    public function test_job_tolerates_unknown_sheet_and_columns()
    {
        Storage::fake('local');
        $this->seed(\Database\Seeders\MappingSeeder::class);
        $version = Version::factory()->create();
        $player = Player::factory()->create();
        VersionPlayer::factory()->create(['version_id' => $version->id, 'player_id' => $player->id]);

        $export = ExportRequest::factory()->create([
            'version_id' => $version->id,
            'status' => 'pending',
            'request_payload' => [
                'format' => 'xlsx',
                'sheets' => [
                    ['name' => 'bogus_table', 'columns' => ['whatever']],
                    ['name' => 'players', 'columns' => ['email', 'ghost_column']],
                ],
            ],
        ]);

        (new GenerateExportJob($export->id))->handle();

        $export->refresh();
        $this->assertEquals('completed', $export->status);
        Storage::assertExists($export->file_path);
    }
}
