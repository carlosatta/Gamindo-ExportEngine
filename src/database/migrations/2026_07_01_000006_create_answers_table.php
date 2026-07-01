<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAnswersTable extends Migration
{
    public function up()
    {
        Schema::create('answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('versions');
            $table->foreignId('player_id')->constrained('players');
            $table->foreignId('version_player_id')->constrained('version_players');
            $table->string('question_id');
            $table->text('question')->nullable();
            $table->text('answer');
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->index(['version_id', 'question_id']);
            $table->index(['version_id', 'occurred_at']);
            $table->index(['version_id', 'player_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('answers');
    }
}
