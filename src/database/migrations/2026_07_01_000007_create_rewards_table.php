<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRewardsTable extends Migration
{
    public function up()
    {
        Schema::create('rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('versions');
            $table->foreignId('player_id')->constrained('players');
            $table->foreignId('version_player_id')->constrained('version_players');
            $table->string('reward_code')->nullable();
            $table->string('reward_type', 100);
            $table->dateTime('assigned_at');
            $table->timestamps();

            $table->index(['version_id', 'assigned_at']);
            $table->index(['version_id', 'player_id']);
            $table->index(['version_id', 'reward_type']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('rewards');
    }
}
