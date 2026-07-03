<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('p4c_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url')->unique();
            $table->unsignedTinyInteger('is_sync')->default(0)->index();
            $table->unsignedInteger('product_count')->default(0);
            $table->dateTime('process_start_date')->nullable();
            $table->dateTime('process_end_date')->nullable();
            $table->decimal('sync_minutes', 10, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('p4c_categories');
    }
};
