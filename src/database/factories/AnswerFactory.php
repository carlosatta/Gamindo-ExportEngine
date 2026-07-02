<?php

namespace Database\Factories;

use App\Models\Answer;
use App\Models\VersionPlayer;
use Illuminate\Database\Eloquent\Factories\Factory;

class AnswerFactory extends Factory
{
    protected $model = Answer::class;

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
            'question_id' => $this->faker->numerify('q#'),
            'question' => $this->faker->sentence(),
            'answer' => $this->faker->word(),
            'occurred_at' => $this->faker->dateTimeBetween('-30 days'),
        ];
    }
}
