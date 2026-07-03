<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('p4c_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('p4c_catagory_id')->nullable()->constrained('p4c_categories')->onDelete('set null');
            $table->string('name');
            $table->string('product_url')->unique();
            $table->string('image')->nullable();
            $table->string('price')->nullable();
            $table->string('sku')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('p4c_products');
    }
};
