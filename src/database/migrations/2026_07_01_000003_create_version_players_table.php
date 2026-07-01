<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateVersionPlayersTable extends Migration
{
    public function up()
    {
        Schema::create('version_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('versions');
            $table->foreignId('player_id')->constrained('players');
            $table->string('external_player_id')->nullable();
            $table->dateTime('registered_at')->nullable();
            $table->string('language', 10)->nullable();
            $table->string('utm_source', 100)->nullable();
            $table->string('company')->nullable();
            $table->boolean('marketing_optin')->nullable();
            $table->string('status', 50)->nullable();
            $table->timestamps();

            $table->unique(['version_id', 'player_id']);
            $table->unique(['version_id', 'external_player_id']);
            $table->index(['version_id', 'registered_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('version_players');
    }
}
