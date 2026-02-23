<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    if (!Schema::hasColumn('attendances', 'office_status')) {
        Schema::table('attendances', function (Blueprint $table) {
            $table->string('office_status')->default('di_kantor')->after('status');
        });
    }
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            // Ini penting supaya kalau kamu rollback, kolomnya dihapus lagi
            $table->dropColumn('office_status');
        });
    }
};
