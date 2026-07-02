<?php

namespace Tests\Feature;

use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VersionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_version()
    {
        $response = $this->postJson('/api/v1/versions', ['name' => 'Summer Campaign']);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Summer Campaign');

        $this->assertDatabaseHas('versions', ['name' => 'Summer Campaign']);
    }

    public function test_create_version_requires_name()
    {
        $response = $this->postJson('/api/v1/versions', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_can_list_versions()
    {
        Version::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/versions');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_can_show_version()
    {
        $version = Version::factory()->create();

        $response = $this->getJson('/api/v1/versions/'.$version->id);

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $version->id);
    }

    public function test_show_missing_version_returns_404()
    {
        $response = $this->getJson('/api/v1/versions/999999');

        $response->assertStatus(404);
    }
}
