<script setup>
import { ref, onMounted, onUnmounted, computed, nextTick, watch } from 'vue'
import { useAuthStore } from '../../stores/authStore'
import axios from 'axios'
import Swal from 'sweetalert2'
import * as faceapi from 'face-api.js'

const authStore = useAuthStore()
const user = computed(() => authStore.user || {})

// ==========================================
// STATE UI & NAVIGATION
// ==========================================
const activeMenu = ref('beranda')
const isMobile = ref(false) 
const isSidebarOpen = ref(true)

const toggleSidebar = () => isSidebarOpen.value = !isSidebarOpen.value

// STATE ATTENDANCE & LOGBOOK
const todayAttendance = ref(null) 
const logbookText = ref('')
const isWeekend = ref(false)
const currentHoliday = ref(null)

// DATA HISTORY
const historyAbsen = ref([])
const currentTime = ref(new Date())
let timer = null
const updateTime = () => { currentTime.value = new Date() }

// ==========================================
// STATE KAMERA & IZIN
// ==========================================
const showCameraModal = ref(false)
const showRegistrationModal = ref(false)
const isScanning = ref(false)
const jenisAbsen = ref('') 
const listKamera = ref([])
const kameraTerpilih = ref(null)
const videoElement = ref(null)
const videoElementReg = ref(null)
let streamSaatIni = null

const showIzinModal = ref(false)
const formIzin = ref({ tanggal: '', alasan: '', bukti: null })

// ==========================================
// FUNGSI KAMERA & VERIFIKASI WAJAH
// ==========================================
const loadModels = async () => {
  try {
    await faceapi.tf.setBackend('webgl'); 
    await faceapi.tf.ready();
    await faceapi.nets.tinyFaceDetector.loadFromUri('/models')
    await faceapi.nets.faceLandmark68Net.loadFromUri('/models')
    await faceapi.nets.faceRecognitionNet.loadFromUri('/models')
    console.log('Model Face API loaded!')
  } catch (error) {
    console.error('Gagal memuat model:', error)
  }
}

const prosesRegistrasiWajah = async () => {
  if (!videoElementReg.value) return;
  isScanning.value = true;

  Swal.fire({ 
    title: 'Menganalisis Wajah...', 
    html: 'Mohon diam sejenak, sistem sedang mengunci biometrik Anda...', 
    allowOutsideClick: false, 
    didOpen: () => Swal.showLoading() 
  });

  try {
    const options = new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.5 });
    const detection = await faceapi.detectSingleFace(videoElementReg.value, options).withFaceLandmarks().withFaceDescriptor();

    // MATIKAN KAMERA SEGERA
    if (streamSaatIni) streamSaatIni.getTracks().forEach(track => track.stop());
    showRegistrationModal.value = false;

    if (!detection) throw new Error('Wajah tidak terdeteksi. Pastikan cahaya terang.');

    const faceDescriptorArray = Array.from(detection.descriptor);
    await axios.post('/face-register', { 
      user_id: user.value.id, 
      face_descriptor: faceDescriptorArray 
    });
    
    // UPDATE PERSISTENCE
    authStore.user.face_descriptor = JSON.stringify(faceDescriptorArray);
    authStore.user.is_active = 1;
    localStorage.setItem('user', JSON.stringify(authStore.user));
    
    Swal.fire('Berhasil!', 'Wajah berhasil didaftarkan. Selamat datang!', 'success');
    isScanning.value = false;
    await fetchTodayData();
    
  } catch (error) {
    isScanning.value = false;
    const pesan = error.response?.data?.message || error.message || 'Gagal menyimpan wajah.';
    await Swal.fire('Registrasi Gagal', pesan, 'error');
    showRegistrationModal.value = true;
    await nextTick();
    await initKameraReg();
  }
};

const prosesAbsenDariKamera = async () => {
  if (!videoElement.value) return;
  isScanning.value = true;

  Swal.fire({ title: 'Memindai Biometrik...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

  try {
    const options = new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.5 });
    const detection = await faceapi.detectSingleFace(videoElement.value, options).withFaceLandmarks().withFaceDescriptor();

    tutupKamera(); 
    isScanning.value = false;

    if (!detection) throw new Error('Wajah tidak terdeteksi.');

    const endpoint = jenisAbsen.value === 'masuk' ? '/attendances/clock-in' : '/attendances/clock-out';
    const faceDescriptorArray = Array.from(detection.descriptor);
    
    await axios.post(endpoint, { 
        user_id: user.value.id, 
        face_descriptor: faceDescriptorArray 
    });
    
    Swal.fire('Berhasil', `Absen ${jenisAbsen.value} sukses!`, 'success');
    await fetchTodayData();
    
  } catch (error) {
    isScanning.value = false;
    const pesanError = error.response?.data?.message || error.message || 'Gagal verifikasi.';
    await Swal.fire('Gagal', pesanError, 'error');
    showCameraModal.value = true;
    await nextTick();
    await initKamera();
  }
};

// --- LOGIKA KAMERA ---
const initKameraReg = async () => {
  try {
    await navigator.mediaDevices.getUserMedia({ video: true })
    const devices = await navigator.mediaDevices.enumerateDevices()
    listKamera.value = devices.filter(device => device.kind === 'videoinput')
    if (listKamera.value.length > 0) {
      kameraTerpilih.value = listKamera.value[0].deviceId
      mulaiStreamReg()
    }
  } catch (error) { Swal.fire('Error', 'Kamera tidak dapat diakses.', 'error') }
}

const mulaiStreamReg = async () => {
  if (streamSaatIni) streamSaatIni.getTracks().forEach(track => track.stop())
  const constraints = { video: { deviceId: kameraTerpilih.value ? { exact: kameraTerpilih.value } : undefined } }
  try {
    streamSaatIni = await navigator.mediaDevices.getUserMedia(constraints)
    if (videoElementReg.value) videoElementReg.value.srcObject = streamSaatIni
  } catch (error) { console.error(error) }
}

const bukaKamera = async (jenis) => {
  jenisAbsen.value = jenis
  showCameraModal.value = true
  await nextTick(); await initKamera()
}

const initKamera = async () => {
  try {
    await navigator.mediaDevices.getUserMedia({ video: true })
    const devices = await navigator.mediaDevices.enumerateDevices()
    listKamera.value = devices.filter(device => device.kind === 'videoinput')
    if (listKamera.value.length > 0) {
      kameraTerpilih.value = listKamera.value[0].deviceId
      mulaiStream()
    }
  } catch (error) { 
    Swal.fire('Error', 'Kamera diblokir.', 'error')
    showCameraModal.value = false
  }
}

const mulaiStream = async () => {
  if (streamSaatIni) streamSaatIni.getTracks().forEach(track => track.stop())
  const constraints = { video: { deviceId: kameraTerpilih.value ? { exact: kameraTerpilih.value } : undefined } }
  try {
    streamSaatIni = await navigator.mediaDevices.getUserMedia(constraints)
    if (videoElement.value) videoElement.value.srcObject = streamSaatIni
  } catch (error) { console.error(error) }
}

const tutupKamera = () => {
  if (streamSaatIni) streamSaatIni.getTracks().forEach(track => track.stop())
  showCameraModal.value = false
}

// ==========================================
// FUNGSI CORE (ABSEN, IZIN, LOGBOOK)
// ==========================================
const submitIzinForm = async () => {
  if (!formIzin.value.alasan || !formIzin.value.tanggal) return Swal.fire('Peringatan', 'Data tidak lengkap!', 'warning')
  Swal.fire({ title: 'Mengirim...', allowOutsideClick: false, didOpen: () => Swal.showLoading() })
  try {
    await axios.post('/attendances/permit', {
      user_id: user.value.id,
      tanggal: formIzin.value.tanggal,
      alasan: formIzin.value.alasan,
      bukti: formIzin.value.bukti
    });
    Swal.fire('Terkirim', 'Izin diajukan!', 'success')
    showIzinModal.value = false; fetchTodayData()
  } catch (e) { Swal.fire('Gagal', 'Terjadi kesalahan.', 'error') }
}

const toggleStatus = async () => {
  try {
    const res = await axios.post('/attendances/toggle-status', { user_id: user.value.id });
    if (res.data.success) { await fetchTodayData(); Swal.fire('Berhasil', res.data.message, 'success'); }
  } catch (e) { Swal.fire('Gagal', 'Sistem sibuk.', 'error'); }
};

const fetchTodayData = async () => {
  if (!user.value?.id) return;
  try {
    const res = await axios.get(`/attendances/today/${user.value.id}`);
    todayAttendance.value = res.data.attendance || null;
    logbookText.value = res.data.attendance?.logbook || '';
    isWeekend.value = res.data.is_weekend;
    currentHoliday.value = res.data.holiday;
  } catch (e) { console.error(e); }
};

const simpanLogbook = async () => {
  if (!logbookText.value.trim()) return Swal.fire('Opps', 'Isi logbook dulu!', 'warning');
  try {
    const res = await axios.post('/attendances/logbook', { user_id: user.value.id, logbook: logbookText.value });
    if (res.data.success) { Swal.fire('Tersimpan!', 'Laporan aman.', 'success'); fetchTodayData(); }
  } catch (e) { Swal.fire('Gagal', 'Gagal simpan.', 'error'); }
};

const fetchHistory = async () => {
  if (!user.value?.id) return;
  try {
    const res = await axios.get(`/attendances/history/${user.value.id}`);
    historyAbsen.value = res.data.data; 
  } catch (e) { console.error(e); }
};

// ==========================================
// COMPUTED & HELPERS
// ==========================================
const attendanceStatus = computed(() => {
  if (!todayAttendance.value) return 'BELUM ABSEN';
  if (todayAttendance.value.status === 'permit') return 'IZIN TIDAK MASUK';
  if (todayAttendance.value.clock_out) return 'SUDAH PULANG';
  if (todayAttendance.value.office_status === 'keluar_sementara') return 'SEDANG KELUAR';
  if (todayAttendance.value.clock_in) return 'DI KANTOR';
  return 'BELUM ABSEN';
});

const statusBadgeStyle = computed(() => {
  switch(attendanceStatus.value) {
    case 'DI KANTOR': return 'background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); color: #065f46; border: 1px solid #34d399;';
    case 'SEDANG KELUAR': return 'background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); color: #92400e; border: 1px solid #fbbf24;';
    case 'SUDAH PULANG': return 'background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); color: #1e40af; border: 1px solid #93c5fd;';
    default: return 'background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1;';
  }
});

const greetingMessage = computed(() => {
  if (currentHoliday.value) return { title: 'Hari ini libur 🎉', subtitle: currentHoliday.value.description, type: 'holiday' };
  if (isWeekend.value && !todayAttendance.value) return { title: 'Akhir Pekan! 🏖️', subtitle: 'Selamat istirahat!', type: 'holiday' };
  if (todayAttendance.value?.status === 'permit') return { title: 'Status: Izin 📝', subtitle: 'Kamu tercatat izin hari ini.', type: 'izin' };
  if (todayAttendance.value?.clock_out) return { title: 'Sudah Check-out 🏡', subtitle: 'Hati-hati di jalan!', type: 'pulang' };
  return null;
});

const canClockIn = computed(() => attendanceStatus.value === 'BELUM ABSEN');
const canClockOut = computed(() => attendanceStatus.value === 'DI KANTOR');

const formatTgl = (tgl) => tgl ? new Date(tgl).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '-';

// ==========================================
// WATCHERS & LIFECYCLE
// ==========================================
watch(() => user.value.id, async (newId) => { 
  if (newId) { 
    const needsReg = !authStore.user.face_descriptor || authStore.user.is_active == 0;
    if (needsReg) {
      showRegistrationModal.value = true;
      await nextTick(); await initKameraReg();
    } else { fetchTodayData(); }
  } 
}, { immediate: true });

onMounted(() => {
  loadModels(); handleResize();
  window.addEventListener('resize', handleResize)
  timer = setInterval(updateTime, 1000)
})

onUnmounted(() => {
  window.removeEventListener('resize', handleResize)
  clearInterval(timer); if (streamSaatIni) streamSaatIni.getTracks().forEach(t => t.stop()) 
})

const handleResize = () => { isMobile.value = window.innerWidth <= 768; isSidebarOpen.value = !isMobile.value }
const switchMenu = (m) => { activeMenu.value = m; if (m.includes('history')) fetchHistory(); if (isMobile.value) isSidebarOpen.value = false }
const unduhLaporan = () => window.open(`${axios.defaults.baseURL}/attendances/download/${user.value.id}`, '_blank');
</script>

<template>
  <div class="corporate-layout">
    <div class="sidebar-overlay" v-if="isSidebarOpen && isMobile" @click="toggleSidebar"></div>
    
    <aside class="sidebar" :class="{ 'open': isSidebarOpen }">
      <div class="sidebar-header"><div class="logo-space"><span class="logo-text">BRI</span>JISENT</div></div>
      <div class="user-profile">
        <div class="avatar">{{ user.name ? user.name.charAt(0).toUpperCase() : 'U' }}</div>
        <div class="user-info"><p class="greeting">Halo,</p><p class="name">{{ user.name }}</p></div>
      </div>
      <nav class="nav-menu">
        <button :class="{ active: activeMenu === 'beranda' }" @click="switchMenu('beranda')">🏠 Beranda</button>
        <button :class="{ active: activeMenu === 'history_absen' }" @click="switchMenu('history_absen')">📅 Riwayat Absen</button>
      </nav>
      <div class="sidebar-footer"><button @click="authStore.logout()" class="btn-logout">Keluar</button></div>
    </aside>

    <main class="main-content">
      <header class="topbar">
        <button class="menu-toggle" @click="toggleSidebar">☰</button>
        <div class="datetime-display">
          <span class="date">{{ currentTime.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long' }) }}</span>
          <span class="time">{{ currentTime.toLocaleTimeString('id-ID') }} WIB</span>
        </div>
      </header>

      <div class="content-wrapper">
        <div v-if="activeMenu === 'beranda'" class="fade-in">
          <div class="grid-container">
            <div class="card">
              <h3 class="card-title">Aksi Kehadiran</h3>
              <div v-if="greetingMessage" class="greeting-banner" :class="greetingMessage.type">
                <div class="greeting-icon">✨</div>
                <div class="greeting-text"><h4>{{ greetingMessage.title }}</h4><p>{{ greetingMessage.subtitle }}</p></div>
              </div>
              <div v-else>
                <div class="status-banner mb-4" :style="statusBadgeStyle">
                  <span class="status-label">Status Saat Ini</span>
                  <strong class="status-value">{{ attendanceStatus }}</strong>
                </div>
                <div class="action-buttons-vertical">
                  <button v-if="canClockIn" @click="bukaKamera('masuk')" class="btn-action btn-masuk">📸 Absen Masuk</button>
                  <button v-if="!canClockIn && attendanceStatus !== 'IZIN TIDAK MASUK'" @click="bukaKamera('keluar')" class="btn-action" :disabled="!canClockOut" :class="canClockOut ? 'btn-pulang' : 'btn-disabled'">
                    🏠 {{ attendanceStatus === 'SUDAH PULANG' ? 'Sudah Pulang' : 'Absen Pulang' }}
                  </button>
                  <button v-if="todayAttendance?.id && !todayAttendance.clock_out" @click="toggleStatus" class="btn-action" :class="attendanceStatus === 'SEDANG KELUAR' ? 'btn-kembali' : 'btn-keluar-sementara'">
                    {{ attendanceStatus === 'SEDANG KELUAR' ? '🚶‍♂️ Kembali' : '🏃‍♂️ Izin Keluar' }}
                  </button>
                  <button v-if="!todayAttendance?.id" @click="showIzinModal = true" class="btn-action btn-izin">📝 Ajukan Izin</button>
                </div>
              </div>
            </div>

            <div class="card">
              <h3 class="card-title">📝 Logbook Kerja</h3>
              <textarea v-model="logbookText" class="logbook-textarea" rows="6" :disabled="!todayAttendance?.id || todayAttendance?.status === 'permit'"></textarea>
              <button @click="simpanLogbook" class="btn-save-logbook" :disabled="!todayAttendance?.id || !logbookText || todayAttendance?.status === 'permit'">💾 Simpan Logbook</button>
            </div>
          </div>
        </div>

        <div v-if="activeMenu === 'history_absen'" class="fade-in card">
          <div class="flex-between mb-4"><h2>Riwayat Kehadiran</h2><button @click="unduhLaporan" class="btn-download">📥 Download CSV</button></div>
          <div class="table-responsive">
            <table class="corporate-table-sm">
              <thead><tr><th>Tanggal</th><th>Status</th><th>Masuk</th><th>Pulang</th></tr></thead>
              <tbody>
                <tr v-for="absen in historyAbsen" :key="absen.id">
                  <td>{{ formatTgl(absen.date) }}</td>
                  <td><span class="badge-status">{{ absen.status }}</span></td>
                  <td class="time-cell">{{ absen.clock_in || '--:--' }}</td>
                  <td class="time-cell">{{ absen.clock_out || '--:--' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </main>

    <div v-if="showRegistrationModal" class="modal-backdrop z-high">
      <div class="modal-card">
        <h2 class="text-center">Daftarkan Wajah 🎉</h2>
        <div class="video-container"><video ref="videoElementReg" autoplay playsinline></video></div>
        <button @click="prosesRegistrasiWajah" class="btn-action btn-masuk mt-4">📸 Daftarkan Sekarang</button>
      </div>
    </div>

    <div v-if="showCameraModal" class="modal-backdrop z-high">
      <div class="modal-card">
        <h3>Verifikasi Biometrik</h3>
        <div class="video-container"><video ref="videoElement" autoplay playsinline></video></div>
        <div class="modal-actions mt-4">
          <button @click="tutupKamera" class="btn-batal">Batal</button>
          <button @click="prosesAbsenDariKamera" class="btn-masuk flex-2">📸 Ambil Foto</button>
        </div>
      </div>
    </div>

    <div v-if="showIzinModal" class="modal-backdrop z-high">
      <div class="modal-card">
        <h3>Form Izin</h3>
        <form @submit.prevent="submitIzinForm">
          <label>Tanggal:</label><input type="date" v-model="formIzin.tanggal" class="form-input mb-3" required>
          <label>Alasan:</label><textarea v-model="formIzin.alasan" class="form-input mb-3" required></textarea>
          <label>Link Bukti:</label><input type="url" v-model="formIzin.bukti" class="form-input mb-4" placeholder="Link GDrive">
          <div class="modal-actions"><button type="button" @click="showIzinModal = false" class="btn-batal">Batal</button><button type="submit" class="btn-masuk flex-2">Kirim</button></div>
        </form>
      </div>
    </div>
  </div>
</template>

<style scoped>
* { box-sizing: border-box; }
.corporate-layout { display: flex; height: 100vh; width: 100vw; background: #F0F4F9; overflow: hidden; }
.sidebar { width: 260px; background: white; border-right: 1px solid #E2E8F0; display: flex; flex-direction: column; transition: 0.3s; z-index: 100; }
.main-content { flex: 1; display: flex; flex-direction: column; min-width: 0; overflow-x: hidden; overflow-y: auto; }
.content-wrapper { padding: 30px; width: 100%; max-width: 100%; }
.grid-container { display: grid; grid-template-columns: 1fr 1fr; gap: 25px; }
.card { background: white; border-radius: 16px; padding: 25px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
.status-banner { display: flex; flex-direction: column; align-items: center; padding: 20px; border-radius: 12px; }
.action-buttons-vertical { display: flex; flex-direction: column; gap: 15px; }
.btn-action { width: 100%; padding: 14px; border: none; border-radius: 12px; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 10px; }
.btn-masuk { background: #00529C; color: white; }
.btn-pulang { background: #F37021; color: white; }
.logbook-textarea { width: 100%; padding: 15px; border: 2px solid #E2E8F0; border-radius: 12px; resize: none; }
.table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
.corporate-table-sm { width: 100%; min-width: 600px; border-collapse: collapse; }
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.7); display: flex; align-items: center; justify-content: center; z-index: 1000; backdrop-filter: blur(4px); }
.modal-card { background: white; padding: 30px; border-radius: 16px; width: 95%; max-width: 450px; }
.video-container { width: 100%; aspect-ratio: 4/3; background: #000; border-radius: 12px; overflow: hidden; }
.video-container video { width: 100%; height: 100%; object-fit: cover; }
.form-input { width: 100%; padding: 12px; border: 1px solid #E2E8F0; border-radius: 8px; }
.z-high { z-index: 2000; }
.flex-between { display: flex; justify-content: space-between; align-items: center; }

@media (max-width: 768px) {
  .sidebar { position: fixed; transform: translateX(-100%); }
  .sidebar.open { transform: translateX(0); }
  .grid-container { grid-template-columns: 1fr; }
  .content-wrapper { padding: 15px; }
}
</style>
