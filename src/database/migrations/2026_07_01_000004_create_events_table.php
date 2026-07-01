<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEventsTable extends Migration
{
    public function up()
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('versions');
            $table->foreignId('player_id')->constrained('players');
            $table->foreignId('version_player_id')->constrained('version_players');
            $table->string('type', 100);
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->index(['version_id', 'occurred_at']);
            $table->index(['version_id', 'type']);
            $table->index(['version_id', 'player_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('events');
    }
}
