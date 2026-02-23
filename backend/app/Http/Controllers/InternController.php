<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Holiday;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class InternController extends Controller
{
    // ==========================================
    // 1. READ DATA (GET)
    // ==========================================

    public function index()
    {
        $interns = User::where('role', 'intern')
                        ->select('id', 'name', 'nickname', 'is_active', 'face_descriptor')
                        ->orderBy('created_at', 'desc')
                        ->get();

        return response()->json(['success' => true, 'data' => $interns]);
    }

    public function getAllUsers($role)
    {
        $users = User::where('role', $role)->get();
        return response()->json(['success' => true, 'data' => $users]);
    }

    // ==========================================
    // 2. CREATE DATA (POST)
    // ==========================================

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6',
            'role' => 'required|in:hr,intern'
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone ?? null,
            'role' => $request->role,
            'password' => bcrypt($request->password),
            'is_active' => $request->role === 'hr' ? true : false
        ]);

        return response()->json(['success' => true, 'message' => 'User berhasil ditambahkan!', 'data' => $user]);
    }

    public function storeHR(Request $request)
    {
        $request->validate([
            'name' => 'required',
            'nickname' => 'required|unique:users,nickname',
            'email' => 'required|email|unique:users,email',
            'pin' => 'required|min:6',
        ]);

        User::create([
            'name' => $request->name,
            'nickname' => $request->nickname,
            'email' => $request->email,
            'role' => 'hr',
            'pin' => $request->pin,
            'password' => bcrypt($request->pin),
            'is_active' => true
        ]);

        return response()->json(['success' => true, 'message' => 'Admin HR baru berhasil ditambahkan!']);
    }

    public function registerFace(Request $request)
    {
        try {
            $request->validate([
                'user_id' => 'required',
                'face_descriptor' => 'required',
            ]);

            $user = User::find($request->user_id);
            if (!$user) return response()->json(['success' => false, 'message' => 'User tidak ditemukan'], 404);

            $user->face_descriptor = json_encode($request->face_descriptor);
            $user->is_active = true;
            $user->save();

            return response()->json(['success' => true, 'message' => 'Wajah berhasil didaftarkan!']);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Server Error: ' . $e->getMessage()], 500);
        }
    }

    // ==========================================
    // 3. UPDATE & DELETE
    // ==========================================

    public function updateUser(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $request->validate([
            'name' => 'required',
            'email' => 'required|email|unique:users,email,' . $id,
        ]);

        $user->name = $request->name;
        $user->email = $request->email;
        if ($request->has('phone')) {
            $user->phone = $request->phone;
        }

        $user->save();
        return response()->json(['success' => true, 'message' => 'Data profil berhasil diperbarui!']);
    }

    public function deleteUser($id)
    {
        User::destroy($id);
        return response()->json(['success' => true, 'message' => 'User berhasil dihapus!']);
    }

    // ==========================================
    // 4. AUTH & OTP FEATURES
    // ==========================================

    public function generateOTP($id) {
        $otp = rand(111111, 999999);
        User::where('id', $id)->update(['reset_code' => $otp]);
        return response()->json(['success' => true, 'otp' => $otp]);
    }

    public function sendOtpEmail(Request $request)
    {
        if (!$request->email) {
            return response()->json(['message' => 'Email wajib diisi!'], 400);
        }

        $user = User::where('email', trim($request->email))->first();

        if (!$user) {
            return response()->json(['message' => 'Email ' . $request->email . ' tidak ditemukan di database! Cek input manualmu.'], 404);
        }

        $otp = rand(100000, 999999);
        $user->update(['reset_code' => $otp]);

        try {
            Mail::raw("Kode OTP kamu: $otp", function ($message) use ($user) {
                $message->to($user->email)->subject('Reset PIN');
            });
            return response()->json(['success' => true, 'message' => 'OTP terkirim!']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'User ketemu, tapi GAGAL kirim email. Error: ' . $e->getMessage()], 500);
        }
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required',
            'new_password' => 'required|min:6'
        ]);

        $user = User::where('email', trim($request->email))
                    ->where('reset_code', trim($request->otp))
                    ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'OTP salah atau sudah kedaluwarsa!'
            ], 400);
        }

        $user->password = bcrypt($request->new_password);
        $user->reset_code = null;
        $user->save();

        return response()->json(['success' => true, 'message' => 'Password berhasil direset! Silakan login.']);
    }

    // ==========================================
    // 5. FITUR HR REALTIME & SETTINGS
    // ==========================================

   public function getRealtimeStatus()
    {
        $settings = AttendanceSetting::first();
        // Fallback jika database setting kosong
        $lateThreshold = $settings ? $settings->late_threshold : '07:30:00';
        $workDays = $settings ? $settings->work_days : '1,2,3,4,5'; // 1=Senin, 5=Jumat

        $today = now();
        $todayString = $today->toDateString();

        // Cek apakah hari ini termasuk hari kerja (Carbon dayOfWeekIso: 1=Senin ... 7=Minggu)
        $isWorkDay = in_array($today->dayOfWeekIso, explode(',', $workDays));

        // Cek apakah hari ini ada di tabel libur nasional (tanggal merah)
        $isHoliday = Holiday::where('holiday_date', $todayString)->exists();

        $status = User::where('role', 'intern')
            ->with(['attendances' => function($query) use ($todayString) {
                $query->whereDate('date', $todayString);
            }])
            ->get()
            ->map(function($user) use ($lateThreshold, $isWorkDay, $isHoliday) {
                $attendance = $user->attendances->first();
                $checkIn = $attendance?->clock_in;

                // 1. Tentukan Status Dasar Hari Ini
                if (!$isWorkDay) {
                    $kehadiran = 'Libur Akhir Pekan';
                } elseif ($isHoliday) {
                    $kehadiran = 'Libur Nasional';
                } else {
                    $kehadiran = 'Belum Absen';
                }

                // 2. Timpa status jika intern ternyata absen (misal: lembur hari Sabtu)
                if ($attendance) {
                    if ($attendance->status === 'permit') {
                        $kehadiran = 'Izin';
                    } elseif ($checkIn) {
                        $jamMasuk = date('H:i:s', strtotime($checkIn));
                        $kehadiran = ($jamMasuk > $lateThreshold) ? 'Terlambat' : 'Hadir';
                    }
                }

                $statusLokasi = '-';
                if ($attendance && $checkIn) {
                    $statusLokasi = ($attendance->office_status === 'keluar_sementara') ? 'Keluar Kantor' : 'Di Kantor';
                }

                return [
                    'id' => $user->id,
                    'nama' => $user->name,
                    'kehadiran' => $kehadiran,
                    'status_lokasi' => $statusLokasi,
                    'jam_masuk' => $checkIn ? date('H:i', strtotime($checkIn)) : '--:--',
                    'logbook' => $attendance?->logbook ?? '-',
                    
                ];
            });

        return response()->json(['success' => true, 'data' => $status]);
    }

    // FITUR GET: Ambil data settings
    public function getSettings() {
        return response()->json([
            'settings' => AttendanceSetting::first(),
            'holidays' => Holiday::orderBy('holiday_date', 'asc')->get()
        ]);
    }

    // FITUR POST: Simpan/Update Jam Kerja
    public function updateSettings(Request $request) {
        $settings = AttendanceSetting::first();
        if (!$settings) {
            $settings = new AttendanceSetting();
        }

        $settings->start_time = $request->start_time;
        $settings->late_threshold = $request->late_threshold;
        $settings->end_time = $request->end_time;
        $settings->save();

        return response()->json(['success' => true, 'message' => 'Pengaturan jam kerja diperbarui!']);
    }

    // FITUR POST: Tambah Hari Libur
    public function storeHoliday(Request $request) {
        $request->validate([
            'date' => 'required|date|unique:holidays,holiday_date',
            'desc' => 'required|string'
        ]);

        Holiday::create([
            'holiday_date' => $request->date,
            'description' => $request->desc
        ]);

        return response()->json(['success' => true, 'message' => 'Hari libur berhasil ditambahkan!']);
    }

    // FITUR DELETE: Hapus Hari Libur
    public function destroyHoliday($id) {
        Holiday::destroy($id);
        return response()->json(['success' => true, 'message' => 'Hari libur dihapus!']);
    }
}
