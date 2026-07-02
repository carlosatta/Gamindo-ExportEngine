<?php

namespace Database\Factories;

use App\Models\Reward;
use App\Models\VersionPlayer;
use Illuminate\Database\Eloquent\Factories\Factory;

class RewardFactory extends Factory
{
    protected $model = Reward::class;

    public function definition()
    {
        $type = $this->faker->randomElement(['instant_win', 'coupon_5', 'coupon_10', 'gift_card']);

        return [
            'version_player_id' => VersionPlayer::factory(),
            'version_id' => function (array $attrs) {
                return VersionPlayer::find($attrs['version_player_id'])->version_id;
            },
            'player_id' => function (array $attrs) {
                return VersionPlayer::find($attrs['version_player_id'])->player_id;
            },
            'reward_code' => strtoupper($type).'-'.$this->faker->numberBetween(1000, 9999),
            'reward_type' => $type,
            'assigned_at' => $this->faker->dateTimeBetween('-30 days'),
        ];
    }
}
