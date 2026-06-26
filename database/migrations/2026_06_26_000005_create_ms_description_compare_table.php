<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ms_description_compare', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ms_category_id')->constrained('ms_categories')->cascadeOnDelete();
            $table->string('product_name')->nullable();
            $table->string('product_url')->nullable();
            $table->string('sku')->nullable();
            $table->longText('old_description')->nullable();
            $table->longText('new_description')->nullable();
            $table->unsignedBigInteger('old_product_id')->nullable();
            $table->unsignedBigInteger('new_product_id')->nullable();
            $table->date('compare_date')->index();
            $table->timestamps();

            $table->index(['ms_category_id', 'compare_date']);
            $table->index('product_url');
            $table->index('sku');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ms_description_compare');
    }
};
