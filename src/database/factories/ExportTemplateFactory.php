<?php

namespace Database\Factories;

use App\Models\ExportTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExportTemplateFactory extends Factory
{
    protected $model = ExportTemplate::class;

    public function definition()
    {
        return [
            'name' => $this->faker->unique()->word(),
            'definition' => [
                'base' => 'version_players',
                'columns' => [
                    'email' => ['source' => 'players.email'],
                    'status' => ['source' => 'version_players.status'],
                ],
            ],
        ];
    }
}
