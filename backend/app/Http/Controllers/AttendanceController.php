<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Attendance;
use App\Models\Holiday;
use App\Models\AttendanceSetting;
use Carbon\Carbon;

class AttendanceController extends Controller
{
    // ==========================================
    // FUNGSI: ABSEN MASUK
    // ==========================================
    public function clockIn(Request $request)
    {
        $request->validate([
            'user_id' => 'required',
            'face_descriptor' => 'required|array',
        ]);

        $user = User::find($request->user_id);
        if (!$user) return response()->json(['success' => false, 'message' => 'User tidak ditemukan!'], 404);

        $today = Carbon::today();
        $alreadyPresensi = Attendance::where('user_id', $user->id)->whereDate('date', $today)->exists();

        if ($alreadyPresensi) {
            return response()->json(['success' => false, 'message' => 'Anda sudah absen masuk hari ini!'], 400);
        }

        $storedFace = json_decode($user->face_descriptor, true);
        $currentFace = $request->face_descriptor;

        if (!$storedFace || !is_array($storedFace)) {
            return response()->json(['success' => false, 'message' => 'Data wajah asli Anda belum terdaftar di sistem.'], 404);
        }

        $distance = $this->calculateDistance($storedFace, $currentFace);

        if ($distance > 0.45) {
            return response()->json([
                'success' => false,
                'message' => 'Wajah tidak cocok! (Jarak: ' . round($distance, 2) . '). Pastikan pencahayaan terang dan tidak memakai masker.'
            ], 401);
        }

        Attendance::create([
            'user_id' => $user->id,
            'clock_in' => Carbon::now(),
            'status' => 'present',
            'date' => $today->toDateString()
        ]);

        return response()->json(['success' => true, 'message' => 'Absen Masuk Berhasil! Selamat bekerja.']);
    }

    // ==========================================
    // FUNGSI: ABSEN PULANG
    // ==========================================
    public function clockOut(Request $request)
    {
        $request->validate([
            'user_id' => 'required',
            'face_descriptor' => 'required|array',
        ]);

        $user = User::find($request->user_id);

        $attendance = Attendance::where('user_id', $request->user_id)
                        ->whereDate('date', Carbon::today())
                        ->whereNull('clock_out')
                        ->first();

        if (!$attendance) {
            return response()->json(['success' => false, 'message' => 'Data absen masuk tidak ditemukan atau Anda sudah pulang.'], 404);
        }

        if ($attendance->office_status === 'keluar_sementara') {
            return response()->json(['success' => false, 'message' => 'Anda harus kembali ke kantor dulu sebelum absen pulang!'], 403);
        }

        $storedFace = json_decode($user->face_descriptor, true);
        $currentFace = $request->face_descriptor;

        $distance = $this->calculateDistance($storedFace, $currentFace);

        if ($distance > 0.45) {
            return response()->json(['success' => false, 'message' => 'Wajah tidak cocok! (Jarak: ' . round($distance, 2) . ').'], 401);
        }

        $attendance->update(['clock_out' => Carbon::now()]);

        return response()->json(['success' => true, 'message' => 'Berhasil absen pulang! Hati-hati di jalan.']);
    }

    // ==========================================
    // FUNGSI BANTUAN (PRIVATE HELPER)
    // ==========================================
    private function calculateDistance($face1, $face2)
    {
        if (!is_array($face1) || !is_array($face2) || count($face1) !== 128 || count($face2) !== 128) {
            return 999;
        }

        $distance = 0;
        for ($i = 0; $i < 128; $i++) {
            $distance += pow($face1[$i] - $face2[$i], 2);
        }

        return sqrt($distance);
    }

    // ==========================================
    // FUNGSI: CEK STATUS HARI INI
    // ==========================================
    public function checkToday($user_id)
    {
        $today = now();
        $todayString = $today->toDateString();

        $attendance = Attendance::where('user_id', $user_id)->whereDate('date', $todayString)->first();
        $settings = AttendanceSetting::first();
        $workDays = $settings ? explode(',', $settings->work_days) : ['1','2','3','4','5'];

        $isWeekend = !in_array($today->dayOfWeekIso, $workDays);
        $holiday = Holiday::where('holiday_date', $todayString)->first();

        return response()->json([
            'success' => true,
            'attendance' => $attendance,
            'is_weekend' => $isWeekend,
            'holiday' => $holiday
        ]);
    }

    // ==========================================
    // FUNGSI: TOGGLE STATUS LOKASI
    // ==========================================
    public function toggleStatus(Request $request)
    {
        $request->validate(['user_id' => 'required']);

        $attendance = Attendance::where('user_id', $request->user_id)->whereDate('date', Carbon::today())->first();

        if (!$attendance) {
            return response()->json(['success' => false, 'message' => 'Anda harus absen masuk terlebih dahulu!'], 404);
        }

        $newStatus = ($attendance->office_status === 'keluar_sementara') ? 'di_kantor' : 'keluar_sementara';
        $attendance->update(['office_status' => $newStatus]);

        return response()->json([
            'success' => true,
            'message' => 'Status berhasil diubah menjadi ' . ($newStatus === 'di_kantor' ? 'Di Kantor' : 'Keluar Sementara'),
            'office_status' => $newStatus
        ]);
    }

    // ==========================================
    // FUNGSI: IZIN TIDAK MASUK (PAKAI LINK GDRIVE)
    // ==========================================
    public function permit(Request $request)
    {
        $request->validate([
            'user_id' => 'required',
            'tanggal' => 'required|date',
            'alasan'  => 'required|string',
            'bukti'   => 'nullable|string' // Sekarang menerima URL string, bukan file upload
        ]);

        $existingAttendance = Attendance::where('user_id', $request->user_id)->whereDate('date', $request->tanggal)->first();

        if ($existingAttendance && $existingAttendance->status === 'present') {
            return response()->json(['success' => false, 'message' => 'Anda sudah terlanjur absen masuk pada tanggal tersebut!'], 400);
        }

        Attendance::updateOrCreate(
            ['user_id' => $request->user_id, 'date' => $request->tanggal],
            [
                'status' => 'permit',
                'permit_reason' => $request->alasan,
                'evidence_path' => $request->bukti, // Simpan URL Google Drive
                'logbook' => 'IZIN: ' . $request->alasan,
                'method' => 'system'
            ]
        );

        return response()->json(['success' => true, 'message' => 'Izin berhasil diajukan!']);
    }

    // ==========================================
    // FUNGSI: RIWAYAT & LOGBOOK
    // ==========================================
    public function history($user_id)
    {
        $history = Attendance::where('user_id', $user_id)->orderBy('date', 'desc')->get();

        if ($history->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'Belum ada riwayat absensi.', 'data' => []], 200);
        }

        return response()->json(['success' => true, 'data' => $history, 'message' => 'Riwayat berhasil dimuat.'], 200);
    }

    public function saveLogbook(Request $request)
    {
        $request->validate([
            'user_id' => 'required',
            'logbook' => 'required'
        ]);

        $targetDate = $request->has('date') ? $request->date : now()->toDateString();
        $attendance = Attendance::where('user_id', $request->user_id)->whereDate('date', $targetDate)->first();

        if ($attendance) {
            $attendance->logbook = $request->logbook;
            $attendance->save();
            return response()->json(['success' => true, 'message' => 'Logbook berhasil disimpan!']);
        }

        return response()->json(['success' => false, 'message' => 'Anda belum absen pada tanggal ini.'], 400);
    }

    // ==========================================
    // FUNGSI: HR DASHBOARD & DOWNLOAD
    // ==========================================
    public function downloadReport($user_id)
    {
        $attendances = Attendance::where('user_id', $user_id)->orderBy('date', 'desc')->get();
        $fileName = 'Laporan_Ringkas_BRIJISENT_' . date('Ymd') . '.csv';

        $headers = [
            "Content-type"        => "text/csv",
            "Content-Disposition" => "attachment; filename=$fileName",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];

        $columns = ['Hari / Tanggal', 'Status Presensi', 'Logbook / Kegiatan'];

        $callback = function() use($attendances, $columns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns);

            foreach ($attendances as $row) {
                $statusText = match($row->status) {
                    'present' => 'HADIR',
                    'permit'  => 'IZIN: ' . ($row->permit_reason ?? '-'),
                    'absent'  => 'ALPA',
                    default   => strtoupper($row->status)
                };
                fputcsv($file, [$row->date, $statusText, $row->logbook ?? '-']);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function getDailyRekap()
    {
        $rekap = User::where('role', 'intern')->with(['attendances' => function($query) {
            $query->whereDate('date', Carbon::today());
        }])->get();

        return response()->json(['success' => true, 'data' => $rekap]);
    }

    public function getHRDashboardSummary()
    {
        $totalInterns = User::where('role', 'intern')->count();
        $presentToday = Attendance::whereDate('date', Carbon::today())->whereNotNull('clock_in')->count();

        $rekap = User::where('role', 'intern')->with(['attendances' => function($query) {
            $query->whereDate('date', Carbon::today());
        }])->get();

        return response()->json([
            'success' => true,
            'summary' => ['total_interns' => $totalInterns, 'present_today' => $presentToday],
            'interns' => $rekap
        ]);
    }

    // ==========================================
    // FUNGSI: AMBIL SEMUA HISTORY (UNTUK HR)
    // ==========================================
    public function getAllHistory()
    {
        $history = Attendance::with('user:id,name')
                    ->orderBy('date', 'desc')
                    ->get()
                    ->map(function($att) {
                        return [
                            'id' => $att->id,
                            'nama' => $att->user->name ?? 'Unknown',
                            'tanggal' => $att->date,
                            'status' => $att->status,
                            'office_status' => $att->office_status,
                            'clock_in' => $att->clock_in ? date('H:i', strtotime($att->clock_in)) : '--:--',
                            'clock_out' => $att->clock_out ? date('H:i', strtotime($att->clock_out)) : '--:--',
                            'logbook' => $att->logbook ?? '-',
                            'permit_reason' => $att->permit_reason,
                            'evidence_path' => $att->evidence_path // <--- Link GDrive terkirim ke Vue HR
                        ];
                    });

        return response()->json(['success' => true, 'data' => $history]);
    }
}
