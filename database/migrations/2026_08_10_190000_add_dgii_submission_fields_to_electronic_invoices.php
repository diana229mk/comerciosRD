<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('electronic_invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('electronic_invoices', 'signed_xml_path')) {
                $table->string('signed_xml_path')->nullable()->after('xml_content');
            }
            if (! Schema::hasColumn('electronic_invoices', 'submission_environment')) {
                $table->string('submission_environment', 20)->nullable()->after('signed_xml_path');
            }
            if (! Schema::hasColumn('electronic_invoices', 'last_submission_at')) {
                $table->timestamp('last_submission_at')->nullable();
            }
            if (! Schema::hasColumn('electronic_invoices', 'submission_attempts')) {
                $table->unsignedInteger('submission_attempts')->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('electronic_invoices', function (Blueprint $table) {
            $table->dropColumn(['signed_xml_path', 'submission_environment', 'last_submission_at', 'submission_attempts']);
        });
    }
};
