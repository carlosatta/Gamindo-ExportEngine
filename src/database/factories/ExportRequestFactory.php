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
                'sheets' => [['name' => 'players', 'columns' => ['player_id', 'email']]],
            ],
            'progress' => 0,
            'attempts' => 0,
        ];
    }
}
