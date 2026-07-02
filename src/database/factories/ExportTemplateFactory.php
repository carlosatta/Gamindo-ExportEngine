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
