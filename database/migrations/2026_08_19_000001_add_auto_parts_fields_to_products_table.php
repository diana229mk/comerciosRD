<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'part_brand')) {
                $table->string('part_brand')->nullable()->after('name');
            }

            if (! Schema::hasColumn('products', 'oem_code')) {
                $table->string('oem_code')->nullable()->after('part_brand');
            }

            if (! Schema::hasColumn('products', 'alt_codes')) {
                $table->text('alt_codes')->nullable()->after('oem_code');
            }

            if (! Schema::hasColumn('products', 'bin_location')) {
                $table->string('bin_location')->nullable()->after('alt_codes');
            }

            if (! Schema::hasColumn('products', 'wholesale_price')) {
                $table->decimal('wholesale_price', 12, 2)->nullable()->after('bin_location');
            }

            if (! Schema::hasColumn('products', 'vehicle_make')) {
                $table->string('vehicle_make')->nullable()->after('wholesale_price');
            }

            if (! Schema::hasColumn('products', 'vehicle_model')) {
                $table->string('vehicle_model')->nullable()->after('vehicle_make');
            }

            if (! Schema::hasColumn('products', 'year_from')) {
                $table->unsignedSmallInteger('year_from')->nullable()->after('vehicle_model');
            }

            if (! Schema::hasColumn('products', 'year_to')) {
                $table->unsignedSmallInteger('year_to')->nullable()->after('year_from');
            }

            if (! Schema::hasColumn('products', 'engine')) {
                $table->string('engine')->nullable()->after('year_to');
            }

            if (! Schema::hasColumn('products', 'position')) {
                $table->string('position')->nullable()->after('engine');
            }
        });

        Schema::table('products', function (Blueprint $table) {
            try {
                $table->index(['business_id', 'oem_code'], 'products_biz_oem_code_idx');
            } catch (\Throwable) {
            }

            try {
                $table->index(['business_id', 'vehicle_make', 'vehicle_model'], 'products_biz_vehicle_idx');
            } catch (\Throwable) {
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            try {
                $table->dropIndex('products_biz_oem_code_idx');
            } catch (\Throwable) {
            }

            try {
                $table->dropIndex('products_biz_vehicle_idx');
            } catch (\Throwable) {
            }

            $columns = [
                'part_brand',
                'oem_code',
                'alt_codes',
                'bin_location',
                'wholesale_price',
                'vehicle_make',
                'vehicle_model',
                'year_from',
                'year_to',
                'engine',
                'position',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
