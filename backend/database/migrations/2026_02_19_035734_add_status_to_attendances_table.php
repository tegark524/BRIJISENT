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
        Schema::table('attendances', function (Blueprint $table) {
            $table->string('permit_reason')->nullable()->after('logbook');
            $table->enum('office_status', ['di_kantor', 'keluar_sementara'])->default('di_kantor')->after('permit_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            //
        });
    }
};
