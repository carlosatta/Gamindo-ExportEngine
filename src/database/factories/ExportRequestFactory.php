<?php

namespace Database\Factories;

use App\Models\ExportRequest;
use App\Models\Version;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExportRequestFactory extends Factory
{
    protected $model = ExportRequest::class;

    public function definition()
    {
        return [
            'version_id' => Version::factory(),
            'status' => 'pending',
            'format' => 'xlsx',
            'request_payload' => [
                'format' => 'xlsx',
                'sheets' => [['name' => 'version_players', 'columns' => ['external_player_id', 'status']]],
            ],
            'progress' => 0,
            'attempts' => 0,
        ];
    }
}
