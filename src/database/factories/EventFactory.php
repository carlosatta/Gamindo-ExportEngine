<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\VersionPlayer;
use Illuminate\Database\Eloquent\Factories\Factory;

class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition()
    {
        return [
            'version_player_id' => VersionPlayer::factory(),
            'version_id' => function (array $attrs) {
                return VersionPlayer::find($attrs['version_player_id'])->version_id;
            },
            'player_id' => function (array $attrs) {
                return VersionPlayer::find($attrs['version_player_id'])->player_id;
            },
            'type' => $this->faker->randomElement(['opened', 'registered', 'completed', 'answer_submitted']),
            'occurred_at' => $this->faker->dateTimeBetween('-30 days'),
        ];
    }
}
