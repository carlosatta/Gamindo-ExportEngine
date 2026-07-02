<?php

namespace Database\Factories;

use App\Models\PayloadField;
use App\Models\Version;
use Illuminate\Database\Eloquent\Factories\Factory;

class PayloadFieldFactory extends Factory
{
    protected $model = PayloadField::class;

    public function definition()
    {
        return [
            'version_id' => Version::factory(),
            'entity_type' => 'event',
            'event_type' => null,
            'code' => $this->faker->unique()->word(),
            'label' => $this->faker->words(2, true),
            'data_type' => 'integer',
            'is_filterable' => true,
            'is_sortable' => true,
            'is_aggregatable' => true,
        ];
    }
}
