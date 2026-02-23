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
    // Hanya buat tabel kalau tabelnya belum ada
    if (!Schema::hasTable('attendance_settings')) {
        Schema::create('attendance_settings', function (Blueprint $table) {
            $table->id();
            $table->time('start_time')->default('07:00:00');
            $table->time('late_threshold')->default('07:30:00');
            $table->time('end_time')->default('17:00:00');
            $table->string('work_days')->default('1,2,3,4,5');
            $table->timestamps();
        });
    }
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_settings');
    }
};
