<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePayloadValuesTable extends Migration
{
    public function up()
    {
        Schema::create('payload_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('versions');
            $table->foreignId('payload_field_id')->constrained('payload_fields');
            $table->string('entity_type', 50);
            $table->unsignedBigInteger('entity_id');
            $table->string('value_string')->nullable();
            $table->bigInteger('value_integer')->nullable();
            $table->decimal('value_decimal', 18, 6)->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->dateTime('value_datetime')->nullable();
            $table->text('value_text')->nullable();
            $table->timestamps();

            $table->unique(['payload_field_id', 'entity_type', 'entity_id']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['version_id', 'payload_field_id']);
            $table->index(['payload_field_id', 'value_string']);
            $table->index(['payload_field_id', 'value_integer']);
            $table->index(['payload_field_id', 'value_decimal']);
            $table->index(['payload_field_id', 'value_datetime']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('payload_values');
    }
}
