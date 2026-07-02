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
                ['name' => 'Players', 'columns' => ['email']],
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

    public function test_job_generates_xlsx_and_completes()
    {
        Storage::fake('local');
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
}
