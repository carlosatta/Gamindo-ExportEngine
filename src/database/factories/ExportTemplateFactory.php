<?php

namespace Database\Factories;

use App\Models\ExportTemplate;
use App\Models\Version;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExportTemplateFactory extends Factory
{
    protected $model = ExportTemplate::class;

    public function definition()
    {
        return [
            'version_id' => Version::factory(),
            'name' => $this->faker->words(2, true),
            'request_payload' => [
                'format' => 'xlsx',
                'sheets' => [
                    [
                        'name' => 'Players',
                        'columns' => ['email', 'total_score', 'events_count'],
                        'filters' => [],
                        'sort' => [],
                    ],
                ],
            ],
        ];
    }
}
