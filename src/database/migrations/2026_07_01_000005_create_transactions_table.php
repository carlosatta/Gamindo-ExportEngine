<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTransactionsTable extends Migration
{
    public function up()
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('versions');
            $table->foreignId('player_id')->constrained('players');
            $table->foreignId('version_player_id')->constrained('version_players');
            $table->string('transaction_id');
            $table->string('type', 100);
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3);
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->unique(['version_id', 'transaction_id']);
            $table->index(['version_id', 'occurred_at']);
            $table->index(['version_id', 'player_id']);
            $table->index(['version_id', 'type']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('transactions');
    }
}
