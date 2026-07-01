<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateExportTemplatesTable extends Migration
{
    public function up()
    {
        Schema::create('export_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('versions');
            $table->string('name');
            $table->longText('request_payload');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('export_templates');
    }
}
