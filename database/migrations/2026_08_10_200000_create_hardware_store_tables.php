<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Variantes (tornillo M6x50 zincado, etc.)
        Schema::create('product_variants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku')->index();
            $table->string('barcode')->nullable()->index();
            $table->json('attributes')->nullable(); // {size:"M6x50", color:"zincado"}
            $table->decimal('price', 15, 2)->nullable();
            $table->decimal('cost', 15, 2)->nullable();
            $table->decimal('stock', 15, 3)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['business_id', 'sku']);
        });

        // Unidades múltiples (unidad, caja, metro, kg, saco…)
        Schema::create('product_units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->string('unit_code', 30); // 'caja', 'metro', 'kg'
            $table->string('label');         // 'Caja 100 uds'
            $table->decimal('factor', 15, 6); // Cuántas unidades base equivalen (caja=100)
            $table->decimal('price', 15, 2)->nullable(); // precio a esta unidad
            $table->string('barcode')->nullable()->index();
            $table->boolean('is_base')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['business_id', 'product_id', 'unit_code']);
        });

        // Ubicaciones físicas
        Schema::create('product_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('aisle')->nullable();  // pasillo
            $table->string('rack')->nullable();   // estante
            $table->string('bin')->nullable();    // bin
            $table->string('zone')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'code']);
        });

        // Pivote producto-ubicación (un producto puede estar en múltiples ubicaciones)
        Schema::create('product_location_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('location_id')->constrained('product_locations')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->unique(['product_id', 'location_id']);
        });

        // Kits / combos (BOM)
        Schema::create('product_kit_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('kit_product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('component_product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->timestamps();
            $table->unique(['kit_product_id', 'component_product_id']);
        });

        // Productos sustitutos / equivalentes
        Schema::create('product_substitutes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('substitute_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedTinyInteger('priority')->default(0);
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'substitute_id']);
        });

        // Listas de precios (público, mayorista, contratista…)
        Schema::create('price_lists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('customer_segment')->nullable(); // publico, mayorista, contratista
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->decimal('global_markup_pct', 8, 3)->nullable(); // % sobre costo
            $table->decimal('global_discount_pct', 8, 3)->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'code']);
        });

        Schema::create('price_list_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('price_list_id')->constrained('price_lists')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->decimal('price', 15, 2);
            $table->decimal('min_quantity', 15, 3)->default(1);
            $table->timestamps();
            $table->unique(['price_list_id', 'product_id', 'variant_id', 'min_quantity'], 'pli_uniq');
        });

        // Números de serie / IMEI
        Schema::create('product_serials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('serial_number')->index();
            $table->string('status', 20)->default('available'); // available, sold, returned, defective
            $table->foreignUuid('sold_in_sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->timestamp('sold_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'serial_number']);
        });

        // Cotizaciones
        Schema::create('quotes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->string('code')->index();
            $table->foreignUuid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name')->nullable();
            $table->foreignUuid('price_list_id')->nullable()->constrained('price_lists')->nullOnDelete();
            $table->string('status', 20)->default('draft'); // draft, sent, accepted, rejected, expired, converted
            $table->date('issue_date');
            $table->date('valid_until')->nullable();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(18);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->foreignUuid('converted_sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['business_id', 'code']);
        });

        Schema::create('quote_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('product_name');
            $table->foreignUuid('unit_id')->nullable()->constrained('product_units')->nullOnDelete();
            $table->string('unit_code', 30)->nullable();
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('subtotal', 15, 2);
            $table->timestamps();
        });

        // Añadir columnas a products / sales / customers / sale_items
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'is_kit')) {
                $table->boolean('is_kit')->default(false)->after('inventory_behavior');
            }
            if (! Schema::hasColumn('products', 'tracks_serial')) {
                $table->boolean('tracks_serial')->default(false)->after('is_kit');
            }
            if (! Schema::hasColumn('products', 'brand')) {
                $table->string('brand')->nullable()->after('name');
            }
            if (! Schema::hasColumn('products', 'supplier_code')) {
                $table->string('supplier_code')->nullable()->after('sku');
            }
            if (! Schema::hasColumn('products', 'max_stock')) {
                $table->decimal('max_stock', 15, 3)->nullable()->after('min_stock');
            }
        });

        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'segment')) {
                $table->string('segment', 30)->nullable()->after('name'); // particular | mayorista | contratista
            }
            if (! Schema::hasColumn('customers', 'price_list_id')) {
                $table->foreignUuid('price_list_id')->nullable()->constrained('price_lists')->nullOnDelete();
            }
        });

        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'price_list_id')) {
                $table->foreignUuid('price_list_id')->nullable()->constrained('price_lists')->nullOnDelete();
            }
            if (! Schema::hasColumn('sales', 'quote_id')) {
                $table->foreignUuid('quote_id')->nullable()->constrained('quotes')->nullOnDelete();
            }
        });

        Schema::table('sale_items', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_items', 'variant_id')) {
                $table->foreignUuid('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            }
            if (! Schema::hasColumn('sale_items', 'unit_id')) {
                $table->foreignUuid('unit_id')->nullable()->constrained('product_units')->nullOnDelete();
            }
            if (! Schema::hasColumn('sale_items', 'unit_factor')) {
                $table->decimal('unit_factor', 15, 6)->default(1);
            }
            if (! Schema::hasColumn('sale_items', 'unit_code')) {
                $table->string('unit_code', 30)->nullable();
            }
            if (! Schema::hasColumn('sale_items', 'parent_sale_item_id')) {
                $table->foreignUuid('parent_sale_item_id')->nullable()
                    ->constrained('sale_items')->nullOnDelete();
            }
            if (! Schema::hasColumn('sale_items', 'kit_line')) {
                $table->boolean('kit_line')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $t) {
            foreach (['variant_id','unit_id','unit_factor','unit_code','parent_sale_item_id','kit_line'] as $c) {
                if (Schema::hasColumn('sale_items', $c)) $t->dropColumn($c);
            }
        });
        Schema::table('sales', function (Blueprint $t) {
            foreach (['price_list_id','quote_id'] as $c) if (Schema::hasColumn('sales', $c)) $t->dropColumn($c);
        });
        Schema::table('customers', function (Blueprint $t) {
            foreach (['segment','price_list_id'] as $c) if (Schema::hasColumn('customers', $c)) $t->dropColumn($c);
        });
        Schema::table('products', function (Blueprint $t) {
            foreach (['is_kit','tracks_serial','brand','supplier_code','max_stock'] as $c) if (Schema::hasColumn('products', $c)) $t->dropColumn($c);
        });
        Schema::dropIfExists('quote_items');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('product_serials');
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
        Schema::dropIfExists('product_substitutes');
        Schema::dropIfExists('product_kit_items');
        Schema::dropIfExists('product_location_assignments');
        Schema::dropIfExists('product_locations');
        Schema::dropIfExists('product_units');
        Schema::dropIfExists('product_variants');
    }
};
