<?php

namespace Database\Factories;

use App\Models\Player;
use App\Models\Version;
use App\Models\VersionPlayer;
use Illuminate\Database\Eloquent\Factories\Factory;

class VersionPlayerFactory extends Factory
{
    protected $model = VersionPlayer::class;

    public function definition()
    {
        return [
            'version_id' => Version::factory(),
            'player_id' => Player::factory(),
            'external_player_id' => $this->faker->uuid(),
            'registered_at' => $this->faker->dateTime(),
            'language' => $this->faker->languageCode(),
            'utm_source' => $this->faker->word(),
            'company' => $this->faker->company(),
            'marketing_optin' => $this->faker->boolean(),
            'status' => 'active',
        ];
    }
}
