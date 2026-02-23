<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Holiday;
use Carbon\Carbon;

class AutoAbsentInterns extends Command
{
    // Nama perintah yang nanti dipanggil di terminal
    protected $signature = 'brijisent:auto-alpa';

    // Deskripsi perintah
    protected $description = 'Otomatis memberikan status Alpa (absent) bagi intern yang tidak absen hari ini.';

    public function handle()
    {
        $today = Carbon::today();
        $todayString = $today->toDateString();

        $this->info("Menjalankan pengecekan Auto-Alpa untuk tanggal: " . $todayString);

        // 1. Ambil Pengaturan Hari Kerja
        $settings = AttendanceSetting::first();
        $workDays = $settings ? explode(',', $settings->work_days) : ['1','2','3','4','5']; // Default Senin-Jumat

        // 2. Cek apakah hari ini Akhir Pekan?
        if (!in_array($today->dayOfWeekIso, $workDays)) {
            $this->info("Hari ini akhir pekan. Eksekusi dibatalkan.");
            return Command::SUCCESS;
        }

        // 3. Cek apakah hari ini Libur Nasional?
        if (Holiday::where('holiday_date', $todayString)->exists()) {
            $this->info("Hari ini libur nasional. Eksekusi dibatalkan.");
            return Command::SUCCESS;
        }

        // 4. Jika hari kerja normal, cari semua intern
        $interns = User::where('role', 'intern')->get();
        $alpaCount = 0;

        foreach ($interns as $intern) {
            // Cek apakah intern ini punya data absen hari ini (Entah itu Hadir atau Izin)
            $hasAttended = Attendance::where('user_id', $intern->id)
                                     ->whereDate('date', $todayString)
                                     ->exists();

            // Jika TIDAK ADA data sama sekali, berarti dia murni bolos (Alpa)
            if (!$hasAttended) {
                Attendance::create([
                    'user_id' => $intern->id,
                    'date' => $todayString,
                    'status' => 'absent', // Status Alpa
                    'logbook' => 'Tidak ada keterangan (Alpa otomatis)',
                    'method' => 'system' // Penanda kalau ini dibikin oleh sistem, bukan klik manual
                ]);

                $alpaCount++;
                $this->line("- " . $intern->name . " ditandai sebagai Alpa.");
            }
        }

        $this->info("Selesai! Total intern yang di-Alpa-kan: " . $alpaCount);
        return Command::SUCCESS;
    }
}
