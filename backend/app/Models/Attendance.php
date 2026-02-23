<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    protected $fillable = [
    'user_id',
        'date',
        'clock_in',
        'clock_out',
        'status',
        'logbook',
        'permit_reason',   // <--- WAJIB TAMBAH INI
        'evidence_path',   // <--- WAJIB TAMBAH INI
        'office_status',
        'method'
];
    public function user()
{
    return $this->belongsTo(User::class);
}

// Tambahkan ini agar User bisa punya banyak data Absen

}
