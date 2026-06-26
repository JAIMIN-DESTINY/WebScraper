<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ms_categories', function (Blueprint $table) {
            $table->id();
            $table->string('maincatagory')->nullable();
            $table->string('name');
            $table->string('url')->unique();
            $table->unsignedTinyInteger('is_sync')->default(0)->index();
            $table->unsignedInteger('product_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ms_categories');
    }
};
