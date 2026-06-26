<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ms_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('category_name')->nullable();
            $table->text('message')->nullable();
            $table->unsignedTinyInteger('status')->default(0)->index();
            $table->text('link')->nullable();
            $table->timestamps();

            $table->index('category_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ms_sync_logs');
    }
};
