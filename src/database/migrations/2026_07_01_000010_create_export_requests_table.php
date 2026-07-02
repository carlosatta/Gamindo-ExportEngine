<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateExportRequestsTable extends Migration
{
    public function up()
    {
        Schema::create('export_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('versions');
            $table->string('status', 30);
            $table->string('format', 20);
            $table->longText('request_payload');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('file_path', 1024)->nullable();
            $table->text('error_message')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('next_retry_at')->nullable();
            $table->timestamps();

            $table->index(['version_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('export_requests');
    }
}
