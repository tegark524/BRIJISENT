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
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');              // Nama Lengkap
        $table->string('email')->unique();   // Login & OTP
        $table->string('phone')->nullable(); // No HP
        $table->string('password');          // Password hash
        $table->enum('role', ['hr', 'intern'])->default('intern');

        $table->string('reset_code')->nullable();
        $table->boolean('is_active')->default(false);
        $table->text('face_descriptor')->nullable();
        $table->timestamps();
    });

    // Catatan: Kalau di bawahnya ada kodingan untuk tabel 'password_reset_tokens'
    // atau 'sessions', biarkan saja jangan dihapus.
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
