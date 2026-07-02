<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\PayloadField;
use App\Models\PayloadValue;
use Illuminate\Database\Eloquent\Factories\Factory;

class PayloadValueFactory extends Factory
{
    protected $model = PayloadValue::class;

    public function definition()
    {
        return [
            'payload_field_id' => PayloadField::factory(),
            'version_id' => function (array $attrs) {
                return PayloadField::find($attrs['payload_field_id'])->version_id;
            },
            'entity_type' => 'event',
            'entity_id' => Event::factory(),
            'value_integer' => $this->faker->numberBetween(1, 1000),
        ];
    }
}
