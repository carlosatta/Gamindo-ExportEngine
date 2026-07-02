<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePayloadFieldsTable extends Migration
{
    public function up()
    {
        Schema::create('payload_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('versions');
            $table->string('entity_type', 50);
            $table->string('event_type', 100)->default('');
            $table->string('code');
            $table->string('label')->nullable();
            $table->string('data_type', 30);
            $table->boolean('is_filterable');
            $table->boolean('is_sortable');
            $table->boolean('is_aggregatable');
            $table->timestamps();

            $table->unique(['version_id', 'entity_type', 'event_type', 'code']);
            $table->index(['version_id', 'entity_type', 'code']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('payload_fields');
    }
}
