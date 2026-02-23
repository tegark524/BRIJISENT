<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    Schema::create('attendances', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->onDelete('cascade');
        $table->date('date');
        $table->time('clock_in')->nullable();
        $table->time('clock_out')->nullable();
        // Pastikan baris ini persis seperti ini:
        $table->string('status')->default('present');
        $table->string('method')->default('face_scan'); // face_scan atau hr_bypass
        $table->timestamps();
    });
}
};
