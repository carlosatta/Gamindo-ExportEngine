<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlayersTable extends Migration
{
    public function up()
    {
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->timestamps();

            $table->unique('email');
        });
    }

    public function down()
    {
        Schema::dropIfExists('players');
    }
}
