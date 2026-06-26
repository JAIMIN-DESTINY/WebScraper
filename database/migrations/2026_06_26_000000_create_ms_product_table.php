<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ms_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ms_category_id')->constrained('ms_categories')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('price')->nullable();
            $table->text('img')->nullable();
            $table->string('product_url')->nullable();
            $table->string('sku')->nullable();
            $table->longText('description')->nullable();
            $table->timestamps();

            $table->unique('product_url');
            $table->unique('sku');
            $table->index('ms_category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ms_product');
    }
};
