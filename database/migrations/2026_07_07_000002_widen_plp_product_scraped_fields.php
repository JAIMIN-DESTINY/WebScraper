<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('plp_products')) {
            return;
        }

        if ($this->hasIndex('plp_products', 'plp_products_product_url_hash_unique')) {
            Schema::table('plp_products', function (Blueprint $table): void {
                $table->dropUnique('plp_products_product_url_hash_unique');
            });
        }

        if (Schema::hasColumn('plp_products', 'product_url_hash')) {
            Schema::table('plp_products', function (Blueprint $table): void {
                $table->dropColumn('product_url_hash');
            });
        }

        if ($this->hasIndex('plp_products', 'plp_products_product_url_unique')) {
            Schema::table('plp_products', function (Blueprint $table): void {
                $table->dropUnique('plp_products_product_url_unique');
            });
        }

        Schema::table('plp_products', function (Blueprint $table): void {
            if (Schema::getColumnType('plp_products', 'name') !== 'text') {
                $table->text('name')->change();
            }

            $table->string('product_url', 768)->change();

            if (Schema::getColumnType('plp_products', 'image') !== 'text') {
                $table->text('image')->nullable()->change();
            }
        });

        if (!$this->hasIndex('plp_products', 'plp_products_product_url_unique')) {
            Schema::table('plp_products', function (Blueprint $table): void {
                $table->unique('product_url');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('plp_products')) {
            return;
        }

        Schema::table('plp_products', function (Blueprint $table): void {
            if ($this->hasIndex('plp_products', 'plp_products_product_url_unique')) {
                $table->dropUnique('plp_products_product_url_unique');
            }

            $table->string('name')->change();
            $table->string('product_url')->change();
            $table->string('image')->nullable()->change();
            $table->unique('product_url');
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => ($index['name'] ?? null) === $indexName);
    }
};
