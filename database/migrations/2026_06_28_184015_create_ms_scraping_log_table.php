<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ms_scraping_log', function (Blueprint $table) {
            $table->id();
            $table->date('log_date')->unique()->comment('One scraping log row per date.');
            $table->dateTime('start_time')->comment('Scraping process start time.');
            $table->dateTime('end_time')->nullable()->comment('Scraping process end time.');
            $table->unsignedInteger('product_count')->default(0)->comment('Total products synced during scraping.');
            $table->unsignedInteger('category_count')->default(0)->comment('Total categories available for scraping.');
            $table->unsignedInteger('processing')->default(0)->comment('Total categories processed/synced during scraping.');
            $table->unsignedTinyInteger('status')->default(0)->comment('0 = processing, 1 = completed, 2 = issue.');
            $table->timestamps();

            $table->index('status');
            $table->index('start_time');
            $table->index('end_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ms_scraping_log');
    }
};
