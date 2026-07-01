<?php

namespace Database\Factories;

use App\Models\Version;
use Illuminate\Database\Eloquent\Factories\Factory;

class VersionFactory extends Factory
{
    protected $model = Version::class;

    public function definition()
    {
        return [
            'name' => $this->faker->unique()->words(3, true),
        ];
    }
}
