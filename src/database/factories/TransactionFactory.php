<?php

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\VersionPlayer;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

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
            'transaction_id' => $this->faker->unique()->uuid(),
            'type' => $this->faker->randomElement(['purchase', 'lead_qualified', 'reward_assigned', 'coupon_redeemed']),
            'amount' => $this->faker->randomFloat(2, 1, 200),
            'currency' => 'EUR',
            'occurred_at' => $this->faker->dateTimeBetween('-30 days'),
        ];
    }
}
