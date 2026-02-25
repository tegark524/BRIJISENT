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
const historyLogbook = ref([])
const editLogbookData = ref({ id: null, text: '' })
const showEditLogbookModal = ref(false)

// WAKTU REALTIME
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
// HELPER: VALIDASI FACE DESCRIPTOR
// ==========================================
/**
 * Mengecek apakah face_descriptor valid dan sudah terisi.
 * Menangani semua edge case: null, undefined, string "null", array kosong, dll.
 */
const hasFaceDescriptor = (userData) => {
  const fd = userData?.face_descriptor

  // Cek semua kemungkinan nilai "kosong"
  if (fd === null || fd === undefined) return false
  if (fd === 'null') return false       // String "null" dari JSON.stringify(null)
  if (fd === '') return false           // String kosong
  if (fd === '[]') return false         // Array kosong dalam bentuk string

  // Jika berupa string JSON, parse dan validasi isinya
  if (typeof fd === 'string') {
    try {
      const parsed = JSON.parse(fd)
      return Array.isArray(parsed) && parsed.length > 0
    } catch {
      return false
    }
  }

  // Jika sudah berupa array langsung (tanpa JSON.stringify)
  if (Array.isArray(fd)) return fd.length > 0

  return false
}

// ==========================================
// FUNGSI KAMERA & VERIFIKASI WAJAH
// ==========================================

// State untuk tracking apakah model sudah dimuat
const modelsLoaded = ref(false)
const isLoadingModels = ref(false)

/**
 * PERBAIKAN: loadModels dipanggil di onMounted agar model sudah siap
 * sebelum user klik tombol registrasi. Tidak perlu download ulang saat klik.
 */
const loadModels = async () => {
  if (modelsLoaded.value) return // Jangan load ulang kalau sudah ada
  
  isLoadingModels.value = true
  try {
    await faceapi.tf.setBackend('webgl')
    await faceapi.tf.ready()
    await Promise.all([
      faceapi.nets.tinyFaceDetector.loadFromUri('/models'),
      faceapi.nets.faceLandmark68Net.loadFromUri('/models'),
      faceapi.nets.faceRecognitionNet.loadFromUri('/models'),
    ])
    modelsLoaded.value = true
    console.log('✅ Model Face API berhasil dimuat!')
  } catch (error) {
    console.error('❌ Gagal memuat model Face API:', error)
  } finally {
    isLoadingModels.value = false
  }
}

/**
 * PERBAIKAN UTAMA REGISTRASI:
 * 1. Capture frame dari video SEBELUM tutup modal (pakai canvas)
 * 2. LANGSUNG tutup modal + stop kamera → user tidak bingung
 * 3. Tampilkan loading SweetAlert2
 * 4. Proses deteksi dari frame yang sudah di-capture (bukan dari video live)
 */
const prosesRegistrasiWajah = async () => {
  if (!videoElementReg.value) return

  // STEP 1: Capture frame dari video live ke canvas (sangat cepat, <10ms)
  const canvas = document.createElement('canvas')
  canvas.width = videoElementReg.value.videoWidth
  canvas.height = videoElementReg.value.videoHeight
  canvas.getContext('2d').drawImage(videoElementReg.value, 0, 0)

  // STEP 2: LANGSUNG tutup kamera dan modal → instant feedback ke user
  if (streamSaatIni) streamSaatIni.getTracks().forEach(track => track.stop())
  showRegistrationModal.value = false
  isScanning.value = true

  // STEP 3: Tampilkan loading overlay (kamera sudah ditutup, user lihat ini)
  Swal.fire({
    title: '🔍 Memindai Wajah...',
    html: `
      <div style="text-align:center; padding: 10px 0;">
        <p style="color:#64748b; font-size:0.95rem; margin-bottom: 16px;">
          Sistem sedang menganalisis biometrik Anda.<br>Mohon tunggu sebentar...
        </p>
        <div style="
          width: 60px; height: 60px; margin: 0 auto;
          border: 5px solid #e2e8f0;
          border-top-color: #00529C;
          border-radius: 50%;
          animation: spin 0.8s linear infinite;
        "></div>
      </div>
      <style>@keyframes spin { to { transform: rotate(360deg); } }</style>
    `,
    allowOutsideClick: false,
    showConfirmButton: false,
  })

  try {
    // Pastikan model sudah siap (fallback jika belum selesai load)
    if (!modelsLoaded.value) {
      Swal.update({ title: '⏳ Memuat Model AI...', html: '<p style="color:#64748b">Memuat model pengenalan wajah, ini hanya terjadi sekali...</p>' })
      await loadModels()
    }

    // STEP 4: Proses deteksi dari canvas (bukan video live yang sudah dimatikan)
    const options = new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.5 })
    const detection = await faceapi
      .detectSingleFace(canvas, options)
      .withFaceLandmarks()
      .withFaceDescriptor()

    if (!detection) {
      throw new Error('Wajah tidak terdeteksi. Pastikan wajah terlihat jelas dan pencahayaan cukup.')
    }

    Swal.update({ title: '💾 Menyimpan Data...' })

    const faceDescriptorArray = Array.from(detection.descriptor)
    await axios.post('/face-register', {
      user_id: user.value.id,
      face_descriptor: faceDescriptorArray
    })

    // Update store & localStorage agar tidak minta registrasi ulang setelah refresh
    authStore.user.face_descriptor = JSON.stringify(faceDescriptorArray)
    authStore.user.is_active = true
    localStorage.setItem('user', JSON.stringify(authStore.user))

    isScanning.value = false
    await Swal.fire({
      icon: 'success',
      title: 'Berhasil Terdaftar! 🎉',
      text: 'Wajah kamu berhasil didaftarkan. Selamat menggunakan BRIJISENT!',
      confirmButtonColor: '#00529C'
    })
    await fetchTodayData()

  } catch (error) {
    isScanning.value = false
    const pesan = error.response?.data?.message || error.message || 'Gagal menyimpan data ke server.'
    await Swal.fire({
      icon: 'error',
      title: 'Registrasi Gagal',
      text: pesan,
      confirmButtonColor: '#00529C'
    })

    // Buka kembali modal kamera jika gagal agar user bisa coba ulang
    showRegistrationModal.value = true
    await nextTick()
    await initKameraReg()
  }
}

// ABSENSI
const prosesAbsenDariKamera = async () => {
  if (!videoElement.value) return

  isScanning.value = true

  Swal.fire({ 
    title: 'Memindai Biometrik...', 
    html: 'Tahan posisi, sedang memverifikasi identitas...', 
    allowOutsideClick: false, 
    didOpen: () => Swal.showLoading() 
  })

  try {
    const options = new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.5 })
    const detection = await faceapi
      .detectSingleFace(videoElement.value, options)
      .withFaceLandmarks()
      .withFaceDescriptor()

    tutupKamera()
    isScanning.value = false

    if (!detection) {
      throw new Error('Wajah tidak terdeteksi. Pastikan pencahayaan cukup.')
    }

    const endpoint = jenisAbsen.value === 'masuk' ? '/attendances/clock-in' : '/attendances/clock-out'
    const faceDescriptorArray = Array.from(detection.descriptor)
    
    await axios.post(endpoint, { 
      user_id: user.value.id, 
      face_descriptor: faceDescriptorArray 
    })
    
    Swal.fire('Berhasil', `Absen ${jenisAbsen.value} sukses!`, 'success')
    await fetchTodayData()
    
  } catch (error) {
    isScanning.value = false
    const pesanError = error.response?.data?.message || error.message || 'Terjadi kesalahan sistem.'
    await Swal.fire('Gagal Verifikasi', pesanError, 'error')
    
    // Kembali ke kamera jika gagal
    showCameraModal.value = true
    await nextTick()
    await initKamera()
  }
}

// --- LOGIKA KAMERA REGISTRASI ---
const initKameraReg = async () => {
  try {
    await navigator.mediaDevices.getUserMedia({ video: true })
    const devices = await navigator.mediaDevices.enumerateDevices()
    listKamera.value = devices.filter(device => device.kind === 'videoinput')
    if (listKamera.value.length > 0) {
      kameraTerpilih.value = listKamera.value[0].deviceId
      mulaiStreamReg()
    }
  } catch (error) {
    Swal.fire('Error', 'Kamera tidak dapat diakses.', 'error')
  }
}

const mulaiStreamReg = async () => {
  if (streamSaatIni) streamSaatIni.getTracks().forEach(track => track.stop())
  const constraints = { video: { deviceId: kameraTerpilih.value ? { exact: kameraTerpilih.value } : undefined } }
  try {
    streamSaatIni = await navigator.mediaDevices.getUserMedia(constraints)
    if (videoElementReg.value) videoElementReg.value.srcObject = streamSaatIni
  } catch (error) { console.error(error) }
}

// --- LOGIKA KAMERA ABSENSI ---
const bukaKamera = async (jenis) => {
  jenisAbsen.value = jenis
  showCameraModal.value = true
  await nextTick()
  await initKamera()
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
    Swal.fire('Error', 'Kamera tidak dapat diakses. Pastikan izin kamera sudah diberikan.', 'error')
    showCameraModal.value = false
  }
}

const mulaiStream = async () => {
  if (streamSaatIni) streamSaatIni.getTracks().forEach(track => track.stop())
  const constraints = { video: { deviceId: kameraTerpilih.value ? { exact: kameraTerpilih.value } : undefined } }
  try {
    streamSaatIni = await navigator.mediaDevices.getUserMedia(constraints)
    if (videoElement.value) videoElement.value.srcObject = streamSaatIni
  } catch (error) { console.error("Error saat memulai stream:", error) }
}

const gantiKamera = () => { mulaiStream() }

const tutupKamera = () => {
  if (streamSaatIni) streamSaatIni.getTracks().forEach(track => track.stop())
  showCameraModal.value = false
}

// ==========================================
// FUNGSI IZIN & STATUS
// ==========================================
const submitIzinForm = async () => {
  if (!formIzin.value.alasan || !formIzin.value.tanggal) {
    return Swal.fire('Peringatan', 'Tanggal dan alasan izin wajib diisi!', 'warning')
  }
  
  Swal.fire({ title: 'Mengirim...', allowOutsideClick: false, didOpen: () => Swal.showLoading() })
  try {
    const payload = {
      user_id: user.value.id,
      tanggal: formIzin.value.tanggal,
      alasan: formIzin.value.alasan,
      bukti: formIzin.value.bukti
    }

    await axios.post('/attendances/permit', payload)
    
    Swal.fire('Terkirim', 'Izin berhasil diajukan ke HR', 'success')
    showIzinModal.value = false
    formIzin.value = { tanggal: '', alasan: '', bukti: '' }
    fetchTodayData()
  } catch (e) {
    Swal.fire('Gagal', e.response?.data?.message || 'Terjadi kesalahan sistem', 'error')
  }
}

const toggleStatus = async () => {
  try {
    const res = await axios.post('/attendances/toggle-status', { user_id: user.value.id })
    if (res.data.success) {
      await fetchTodayData()
      Swal.fire('Berhasil', res.data.message, 'success')
    }
  } catch (e) {
    Swal.fire('Gagal', 'Gagal mengubah status', 'error')
  }
}

// ==========================================
// COMPUTED LOGIC (UI STATE)
// ==========================================
const fetchTodayData = async () => {
  if (!user.value?.id) return
  try {
    const res = await axios.get(`/attendances/today/${user.value.id}`)
    todayAttendance.value = res.data.attendance || null
    logbookText.value = res.data.attendance?.logbook || ''
    isWeekend.value = res.data.is_weekend
    currentHoliday.value = res.data.holiday
  } catch (e) {
    console.error("Gagal refresh data:", e)
  }
}

const attendanceStatus = computed(() => {
  if (!todayAttendance.value) return 'BELUM ABSEN'
  if (todayAttendance.value.status === 'permit') return 'IZIN TIDAK MASUK'
  if (todayAttendance.value.clock_out) return 'SUDAH PULANG'
  if (todayAttendance.value.office_status === 'keluar_sementara') return 'SEDANG KELUAR'
  if (todayAttendance.value.clock_in) return 'DI KANTOR'
  return 'BELUM ABSEN'
})

const statusBadgeStyle = computed(() => {
  switch(attendanceStatus.value) {
    case 'DI KANTOR': return 'background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); color: #065f46; border: 1px solid #34d399; box-shadow: 0 4px 10px rgba(52, 211, 153, 0.2);'
    case 'SEDANG KELUAR': return 'background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); color: #92400e; border: 1px solid #fbbf24; box-shadow: 0 4px 10px rgba(251, 191, 36, 0.2);'
    case 'SUDAH PULANG': return 'background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); color: #1e40af; border: 1px solid #93c5fd; box-shadow: 0 4px 10px rgba(147, 197, 253, 0.2);'
    default: return 'background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%); color: #475569; border: 1px solid #cbd5e1;'
  }
})

const greetingMessage = computed(() => {
  if (currentHoliday.value) {
    return { title: 'Hari ini libur 🎉', subtitle: `Selamat berlibur dalam rangka ${currentHoliday.value.description}, nikmati waktu istirahatmu!`, type: 'holiday' }
  }
  
  if (isWeekend.value && !todayAttendance.value) {
    return { title: 'Akhir Pekan Telah Tiba! 🏖️', subtitle: 'Saatnya recharge energi. Sampai jumpa di hari kerja berikutnya!', type: 'holiday' }
  }

  if (todayAttendance.value?.status === 'permit') {
    const alasan = todayAttendance.value.permit_reason || todayAttendance.value.logbook || 'Keperluan tertentu'
    return { 
      title: 'Status: Sedang Izin 📝', 
      subtitle: `Kamu tercatat izin hari ini karena: "${alasan}". Semoga urusanmu lancar!`, 
      type: 'izin' 
    }
  }

  if (todayAttendance.value?.clock_out) {
    return { title: 'Sudah Check-out 🏡', subtitle: 'Selamat pulang, hati-hati di jalan dan selamat beristirahat!', type: 'pulang' }
  }
  
  return null
})

const canClockIn = computed(() => attendanceStatus.value === 'BELUM ABSEN')
const canClockOut = computed(() => attendanceStatus.value === 'DI KANTOR')
const canToggleKeluar = computed(() => attendanceStatus.value === 'DI KANTOR' || attendanceStatus.value === 'SEDANG KELUAR')

// ==========================================
// FUNGSI LOGBOOK & RIWAYAT
// ==========================================
const simpanLogbook = async () => {
  if (todayAttendance.value?.status === 'permit') {
    return Swal.fire('Info', 'Kamu sedang izin hari ini, tidak perlu mengisi logbook!', 'info')
  }

  if (!logbookText.value.trim()) return Swal.fire('Opps', 'Isi dulu kegiatannya!', 'warning')
  
  try {
    const res = await axios.post('/attendances/logbook', { user_id: user.value.id, logbook: logbookText.value })
    if (res.data.success) {
      Swal.fire({ icon: 'success', title: 'Tersimpan!', text: 'Laporan kerja BRIJISENT kamu sudah aman.', timer: 2000 })
      await fetchTodayData()
    }
  } catch (e) {
    Swal.fire('Gagal', e.response?.data?.message || 'Gagal menyimpan logbook.', 'error')
  }
}

const fetchHistory = async () => {
  if (!user.value?.id) return
  try {
    const res = await axios.get(`/attendances/history/${user.value.id}`)
    historyAbsen.value = res.data.data
  } catch (e) { console.error("Gagal memuat riwayat:", e) }
}

const formatTgl = (tgl) => {
  if (!tgl) return '-'
  return new Date(tgl).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })
}

const labelStatus = (s) => {
  const map = { 'present': 'Masuk', 'permit': 'Izin', 'absent': 'Alpa' }
  return map[s] || 'Masuk'
}

const statusClass = (s) => {
  if (s === 'permit') return 'bg-warning-light'
  if (s === 'absent') return 'bg-danger-light'
  return 'bg-success-light'
}

const bukaEditLogbook = async (data) => {
  const { value: text } = await Swal.fire({
    title: 'Edit Logbook', input: 'textarea', inputLabel: `Tanggal: ${formatTgl(data.date)}`,
    inputValue: data.logbook || '', showCancelButton: true, confirmButtonColor: '#00529C', confirmButtonText: 'Simpan Perubahan'
  })

  if (text !== undefined) {
    try {
      await axios.post('/attendances/logbook', { user_id: user.value.id, logbook: text, date: data.date })
      Swal.fire('Tersimpan', 'Logbook berhasil diperbarui', 'success')
      fetchHistory()
    } catch (e) { Swal.fire('Gagal', 'Gagal memperbarui logbook', 'error') }
  }
}

const switchMenu = (menu) => {
  activeMenu.value = menu
  if (menu === 'history_absen' || menu === 'history_logbook') fetchHistory()
  if (isMobile.value) isSidebarOpen.value = false 
}

const handleResize = () => {
  isMobile.value = window.innerWidth <= 768
  isSidebarOpen.value = !isMobile.value
}

const unduhLaporan = () => {
  window.open(`/attendances/download/${user.value.id}`, '_blank')
}

// ==========================================
// LIFECYCLE HOOKS & WATCHERS
// ==========================================

/**
 * PERBAIKAN UTAMA:
 * Hanya ada SATU watcher tunggal di sini.
 * Watcher duplikat yang lama menjadi sumber bug dan sudah dihapus.
 * 
 * Alur:
 * 1. Coba fetch data user terbaru dari server untuk sinkronisasi.
 * 2. Gunakan hasFaceDescriptor() untuk validasi yang robust.
 * 3. Jika belum punya wajah → tampilkan modal registrasi.
 * 4. Jika sudah punya wajah → langsung fetch data absensi hari ini.
 */
watch(() => user.value.id, async (newId) => {
  if (!newId) return

  try {
    // Fetch data user terbaru dari server agar face_descriptor selalu sinkron
    const res = await axios.get(`/user/${newId}`) // Sesuaikan endpoint dengan backend Anda
    const freshUser = res.data.user

    // Merge data terbaru ke store & localStorage
    authStore.user = { ...authStore.user, ...freshUser }
    localStorage.setItem('user', JSON.stringify(authStore.user))
  } catch (e) {
    // Jika gagal fetch (misal offline), lanjut pakai data lokal yang ada
    console.warn('Gagal sinkronisasi data user dari server, menggunakan data lokal:', e.message)
  }

  // Validasi face_descriptor menggunakan helper yang robust
  if (!hasFaceDescriptor(authStore.user)) {
    // Belum ada data wajah → paksa registrasi
    showRegistrationModal.value = true
    await nextTick()
    await initKameraReg()
  } else {
    // Sudah ada data wajah → langsung ke dashboard
    await fetchTodayData()
  }
}, { immediate: true })

onMounted(() => {
  handleResize()
  window.addEventListener('resize', handleResize)
  timer = setInterval(updateTime, 1000)
  // Preload model di background saat halaman dibuka
  // Sehingga saat user klik tombol registrasi, model sudah siap → tidak ada delay
  loadModels()
})

onUnmounted(() => {
  window.removeEventListener('resize', handleResize)
  clearInterval(timer)
  if (streamSaatIni) streamSaatIni.getTracks().forEach(track => track.stop())
})
</script>

<template>
  <div class="corporate-layout">
    <div class="sidebar-overlay" v-if="isSidebarOpen && isMobile" @click="toggleSidebar"></div>
    
    <aside class="sidebar" :class="{ 'open': isSidebarOpen }">
      <div class="sidebar-header" style="display: flex; align-items: center; gap: 10px; padding: 20px;">
  <img src="/LOGO.png" alt="Logo" style="width: 40px; height: 40px; object-fit: contain;">
  <div class="logo-space" style="border: none; padding: 0;">BRIJISENT</div>
</div>
      
      <div class="user-profile">
        <div class="avatar">{{ user.name ? user.name.charAt(0).toUpperCase() : 'U' }}</div>
        <div class="user-info">
          <p class="greeting">Halo,</p>
          <p class="name">{{ user.name || 'Intern' }}</p>
        </div>
      </div>

      <nav class="nav-menu">
        <button :class="{ active: activeMenu === 'beranda' }" @click="switchMenu('beranda')"><i class="icon">🏠</i> Beranda</button>
        <button :class="{ active: activeMenu === 'history_absen' }" @click="switchMenu('history_absen')"><i class="icon">📅</i> Riwayat Kehadiran</button>
        <button :class="{ active: activeMenu === 'history_logbook' }" @click="switchMenu('history_logbook')"><i class="icon">📝</i> Riwayat Logbook</button>
      </nav>

      <div class="sidebar-footer">
        <button @click="authStore.logout()" class="btn-logout">Keluar Sistem</button>
      </div>
    </aside>

    <main class="main-content">
      <header class="topbar">
        <button class="menu-toggle" @click="toggleSidebar">☰</button>
        <div class="datetime-display">
          <span class="date">{{ currentTime.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) }}</span>
          <span class="time">{{ currentTime.toLocaleTimeString('id-ID') }} WIB</span>
        </div>
      </header>

      <div class="content-wrapper">
        <div v-if="activeMenu === 'beranda'" class="fade-in">
          
          <div class="grid-container">
            <div class="card actions-card">
              <h3 class="card-title">Aksi Kehadiran</h3>
              
              <div v-if="greetingMessage" class="greeting-banner" :class="greetingMessage.type">
                <div class="greeting-icon">{{ greetingMessage.type === 'holiday' ? '🌴' : (greetingMessage.type === 'izin' ? '📝' : '🎒') }}</div>
                <div class="greeting-text">
                  <h4>{{ greetingMessage.title }}</h4>
                  <p>{{ greetingMessage.subtitle }}</p>
                </div>
              </div>
              
              <div v-else>
                <div class="status-banner mb-4" :style="statusBadgeStyle">
                  <span class="status-label">Status Anda Saat Ini</span>
                  <strong class="status-value">{{ attendanceStatus }}</strong>
                </div>

                <div class="action-buttons-vertical">
                  <button v-if="canClockIn" @click="bukaKamera('masuk')" class="btn-action btn-masuk">
                    <span class="icon-btn">📸</span> <span class="btn-text">Absen Masuk</span>
                  </button>

                  <button v-if="!canClockIn && attendanceStatus !== 'IZIN TIDAK MASUK'" @click="bukaKamera('keluar')" class="btn-action" :disabled="!canClockOut" :class="canClockOut ? 'btn-pulang' : 'btn-disabled'">
                    <span class="icon-btn">🏠</span> <span class="btn-text">{{ attendanceStatus === 'SUDAH PULANG' ? 'Sudah Pulang' : 'Absen Pulang' }}</span>
                  </button>
                  
                  <button v-if="todayAttendance?.id && !todayAttendance.clock_out" @click="toggleStatus" class="btn-action" :class="attendanceStatus === 'SEDANG KELUAR' ? 'btn-kembali' : 'btn-keluar-sementara'">
                    <span class="icon-btn">{{ attendanceStatus === 'SEDANG KELUAR' ? '🚶‍♂️' : '🏃‍♂️' }}</span> 
                    <span class="btn-text">{{ attendanceStatus === 'SEDANG KELUAR' ? 'Kembali ke Kantor' : 'Izin Keluar Sebentar' }}</span>
                  </button>

                  <button v-if="!todayAttendance?.id" @click="showIzinModal = true" class="btn-action btn-izin">
                    <span class="icon-btn">📝</span> <span class="btn-text">Ajukan Izin Tidak Masuk</span>
                  </button>
                </div>
              </div>
              
            </div>

            <div class="right-column">
              <div class="card logbook-card">
                <div class="card-header-custom">
                  <h3 class="card-title" style="margin-bottom:0; border-bottom:none;">📝 Logbook Kerja</h3>
                  <span class="status-indicator" :class="logbookText ? 'status-filled' : 'status-empty'">{{ logbookText ? 'Terisi' : 'Kosong' }}</span>
                </div>
                <div class="logbook-body">
                  <p class="logbook-hint">Ceritakan progres pekerjaan atau kendala yang kamu hadapi hari ini.</p>
                  
                  <textarea 
                    v-model="logbookText" 
                    :placeholder="todayAttendance?.status === 'permit' ? 'Istirahat yang cukup ya, tidak perlu mengisi logbook hari ini...' : 'Contoh: Menyelesaikan modul absensi wajah, debugging API...'" 
                    class="logbook-textarea" 
                    rows="6" 
                    :disabled="!todayAttendance?.id || todayAttendance?.status === 'permit'">
                  </textarea>
                  
                  <div class="logbook-footer">
                    <button 
                      @click="simpanLogbook" 
                      class="btn-save-logbook" 
                      :disabled="!todayAttendance?.id || !logbookText || todayAttendance?.status === 'permit'">
                      <span class="icon">💾</span> <span class="text">Simpan Laporan Hari Ini</span>
                    </button>
                    
                    <p v-if="!todayAttendance?.id" class="text-warning-sm">*Silakan absen masuk terlebih dahulu untuk mengisi logbook.</p>
                    <p v-if="todayAttendance?.status === 'permit'" class="text-warning-sm" style="color: #d97706;">*Kamu sedang izin. Fitur logbook dinonaktifkan untuk hari ini.</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div v-if="activeMenu === 'history_absen'" class="fade-in card">
          <div class="d-flex" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h2 class="section-title-sm" style="font-size:1.2rem;">Riwayat Kehadiran</h2>
            <button @click="unduhLaporan" class="btn-download">📥 Download Laporan (CSV)</button>
          </div>
          <div class="table-responsive">
            <table class="corporate-table-sm">
              <thead>
                <tr><th>Tanggal</th><th>Status</th><th>Masuk</th><th>Pulang</th></tr>
              </thead>
              <tbody>
                <tr v-for="absen in historyAbsen" :key="absen.id">
                  <td class="text-nowrap">{{ formatTgl(absen.date) }}</td>
                  <td><span class="badge-status" :class="statusClass(absen.status)">{{ labelStatus(absen.status) }}</span></td>
                  <td class="time-cell">{{ absen.clock_in || '--:--' }}</td>
                  <td class="time-cell">{{ absen.clock_out || '--:--' }}</td>
                </tr>
                <tr v-if="historyAbsen.length === 0">
                  <td colspan="4" class="text-center py-4 text-muted">Belum ada riwayat absen.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div v-if="activeMenu === 'history_logbook'" class="fade-in card">
          <h2 class="section-title-sm" style="font-size:1.2rem; margin-bottom:20px;">📝 Riwayat Logbook</h2>
          <div class="table-responsive">
            <table class="corporate-table-sm">
              <thead>
                <tr><th style="width: 120px;">Tanggal</th><th>Isi Logbook</th><th style="width: 80px;" class="text-center">Aksi</th></tr>
              </thead>
              <tbody>
                <tr v-for="log in historyAbsen" :key="'log-'+log.id">
                  <td class="text-nowrap">{{ formatTgl(log.date) }}</td>
                  <td class="log-text-cell">{{ log.logbook || 'Belum mengisi logbook' }}</td>
                  <td class="text-center"><button class="btn-edit-sm" @click="bukaEditLogbook(log)">Edit</button></td>
                </tr>
                <tr v-if="historyAbsen.length === 0">
                  <td colspan="3" class="text-center py-4 text-muted">Belum ada data logbook.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </main>

    <!-- MODAL: REGISTRASI WAJAH (INTERN BARU) -->
    <div v-if="showRegistrationModal" class="modal-backdrop z-high" style="background: rgba(0,0,0,0.9);">
      <div class="modal-card camera-modal" style="margin-top: -50px;">
        <div style="text-align: center; margin-bottom: 20px;">
          <h2 style="color: #00529C; margin: 0; font-weight: 900;">Selamat Datang! 🎉</h2>
          <p style="color: #64748b; font-size: 0.95rem; margin-top: 5px;">
            Sebagai intern baru, silakan daftarkan wajah Anda ke sistem keamanan <strong>BRIJISENT</strong>.
          </p>
        </div>
        
        <div class="camera-select-wrapper">
          <label class="text-sm" style="font-weight: 600;">Pilih Perangkat Kamera:</label>
          <select v-model="kameraTerpilih" @change="mulaiStreamReg" class="form-input mt-1">
            <option v-for="cam in listKamera" :key="cam.deviceId" :value="cam.deviceId">{{ cam.label || 'Kamera ' + (listKamera.indexOf(cam) + 1) }}</option>
          </select>
        </div>
        
        <div class="video-container" style="border: 4px dashed #00529C; box-shadow: 0 0 20px rgba(0,82,156,0.2);">
          <video ref="videoElementReg" autoplay playsinline></video>
        </div>
        
        <div class="modal-actions mt-4" style="flex-direction: column; gap: 8px;">
          <button 
            @click="prosesRegistrasiWajah" 
            class="btn-action btn-masuk" 
            style="flex:1; padding: 15px; width:100%;"
            :disabled="isLoadingModels"
          >
            <span v-if="isLoadingModels" class="spinner-btn"></span>
            <span v-else style="font-size: 1.2rem;">📸</span>
            <span class="btn-text">
              {{ isLoadingModels ? 'Memuat AI, harap tunggu...' : 'Daftarkan Wajah Sekarang' }}
            </span>
          </button>
          <p v-if="isLoadingModels" style="text-align:center; font-size:0.78rem; color:#64748b; margin-top:4px;">
            ⚙️ Model AI sedang dimuat, ini hanya terjadi sekali saat pertama kali...
          </p>
        </div>
      </div>
    </div>

    <!-- MODAL: EDIT LOGBOOK -->
    <div v-if="showEditLogbookModal" class="modal-backdrop">
      <div class="modal-card">
        <h3>Edit Logbook</h3>
        <p class="text-muted mb-3">Tanggal: {{ editLogbookData.created_at ? editLogbookData.created_at.substring(0, 10) : '-' }}</p>
        <textarea v-model="editLogbookData.logbook" class="logbook-textarea mb-3" rows="5"></textarea>
        <div class="modal-actions">
          <button @click="showEditLogbookModal = false" class="btn-batal">Batal</button>
          <button @click="showEditLogbookModal = false" class="btn-save">Simpan Perubahan</button>
        </div>
      </div>
    </div>

    <!-- MODAL: KAMERA ABSENSI -->
    <div v-if="showCameraModal" class="modal-backdrop z-high">
      <div class="modal-card camera-modal">
        <h3>{{ jenisAbsen === 'masuk' ? 'Verifikasi Wajah (Masuk)' : 'Verifikasi Wajah (Keluar)' }}</h3>
        <div class="camera-select-wrapper mt-3">
          <label class="text-sm" style="font-weight: 600;">Pilih Perangkat Kamera:</label>
          <select v-model="kameraTerpilih" @change="gantiKamera" class="form-input mt-1">
            <option v-for="cam in listKamera" :key="cam.deviceId" :value="cam.deviceId">{{ cam.label || 'Kamera ' + (listKamera.indexOf(cam) + 1) }}</option>
          </select>
        </div>
        <div class="video-container"><video ref="videoElement" autoplay playsinline></video></div>
        <div class="modal-actions mt-4">
          <button @click="tutupKamera" class="btn-batal" style="flex:1">Batal</button>
          <button @click="prosesAbsenDariKamera" class="btn-masuk" style="flex:2; border-radius: 12px; border: none; font-weight: bold; cursor: pointer; color: white;"><span style="font-size: 1.2rem; margin-right: 5px;">📸</span> Ambil Foto</button>
        </div>
      </div>
    </div>

    <!-- MODAL: FORM IZIN -->
    <div v-if="showIzinModal" class="modal-backdrop z-high">
      <div class="modal-card">
        <h3>Form Izin Tidak Masuk</h3>
        <form @submit.prevent="submitIzinForm" class="mt-3">
          <div class="form-group mb-3">
            <label class="text-sm">Tanggal Izin:</label>
            <input type="date" v-model="formIzin.tanggal" required class="form-input mt-1" />
          </div>
          <div class="form-group mb-3">
            <label class="text-sm">Alasan Izin:</label>
            <textarea v-model="formIzin.alasan" rows="3" placeholder="Contoh: Sakit, dll" required class="form-input mt-1"></textarea>
          </div>
          <div class="form-group mb-4">
            <label class="text-sm">Link Bukti / Surat (Google Drive):</label>
            <input type="url" v-model="formIzin.bukti" placeholder="Paste link Google Drive yang sudah di-share..." class="form-input mt-1" />
            <small class="text-muted" style="display: block; margin-top: 5px; font-size: 0.75rem;">
              *Pastikan akses link GDrive diatur ke "Anyone with the link"
            </small>
          </div>
          <div class="modal-actions">
            <button type="button" @click="showIzinModal = false" class="btn-batal flex-1">Batal</button>
            <button type="submit" class="btn-masuk flex-2" style="border-radius: 8px; border: none; font-weight: bold; cursor: pointer; color: white;">Kirim Pengajuan</button>
          </div>
        </form>
      </div>
    </div>

  </div>
</template>

<style scoped>
/* --- VARIABEL WARNA --- */
:root {
  --bri-blue: #00529C;
  --bri-orange: #F37021;
  --bg-color: #F0F4F9; 
  --text-main: #1E293B;
  --text-muted: #64748B;
  --border-color: #E2E8F0;
}
* {
  box-sizing: border-box;
}

.corporate-layout { display: flex; height: 100vh; background-color: var(--bg-color); font-family: 'Inter', sans-serif; overflow: hidden; }

/* SIDEBAR */
.sidebar { width: 260px; background: white; border-right: 1px solid var(--border-color); display: flex; flex-direction: column; transition: transform 0.3s ease; z-index: 100; }
.sidebar-header { padding: 25px 20px; border-bottom: 1px solid var(--border-color); }
.logo-space { font-size: 1.4rem; font-weight: 900; color: #00529C; letter-spacing: 1px; }
.logo-text { background-color: #00529C; color: #ffffff; padding: 4px 8px; border-radius: 6px; margin-right: 4px; }
.user-profile { padding: 25px 20px; display: flex; align-items: center; gap: 15px; border-bottom: 1px solid var(--border-color); }
.avatar { width: 45px; height: 45px; border-radius: 50%; background: var(--bri-blue); color: white; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; font-weight: bold; }
.greeting { font-size: 0.8rem; color: var(--text-muted); margin: 0; }
.name { font-size: 1rem; font-weight: 700; color: var(--text-main); margin: 0; }
.nav-menu { flex: 1; padding: 20px 0; }
.nav-menu button { width: 100%; text-align: left; padding: 15px 25px; background: none; border: none; border-left: 4px solid transparent; color: var(--text-muted); font-size: 0.95rem; font-weight: 600; cursor: pointer; transition: 0.2s; display: flex; gap: 10px; align-items: center; }
.nav-menu button:hover { background: #f8fafc; color: var(--bri-blue); }
.nav-menu button.active { border-left-color: var(--bri-orange); color: var(--bri-blue); background: #f0f7ff; }
.sidebar-footer { padding: 20px; border-top: 1px solid var(--border-color); }
.btn-logout { width: 100%; padding: 12px; background: #fff1f0; color: #e74c3c; border: 1px solid #ffccc7; border-radius: 8px; font-weight: 600; cursor: pointer; transition: 0.3s; }
.btn-logout:hover { background: #e74c3c; color: white; }

/* HEADER & MAIN CONTENT */
.main-content { flex: 1; display: flex; flex-direction: column; overflow-x: hidden; overflow-y: auto; }
.topbar { background: white; padding: 20px 30px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); }
.menu-toggle { display: none; background: none; border: none; font-size: 1.5rem; color: var(--bri-blue); cursor: pointer; }
.datetime-display { text-align: right; }
.date { display: block; font-size: 0.85rem; color: var(--text-muted); }
.time { font-size: 1.2rem; font-weight: 800; color: var(--bri-blue); }
.content-wrapper { padding: 30px; margin: 0 auto; max-width: 100%; width: 100%; }
.grid-container { display: grid; grid-template-columns: 1fr 1fr; gap: 25px; } 
.right-column { display: flex; flex-direction: column; gap: 25px; }

/* CARD & BANNER STYLES */
.card { background: white; border-radius: 16px; padding: 25px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); border: 1px solid rgba(226, 232, 240, 0.8); transition: 0.2s ease; }
.card-title { font-size: 1.1rem; font-weight: 700; color: var(--text-main); margin-bottom: 20px; border-bottom: 2px solid #f0f2f5; padding-bottom: 10px; }

/* STATUS BANNER */
.status-banner { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 20px; border-radius: 12px; text-align: center; transition: all 0.3s ease; }
.status-label { font-size: 0.85rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; opacity: 0.85; margin-bottom: 5px; }
.status-value { font-size: 1.5rem; font-weight: 900; letter-spacing: 0.5px; }

/* BANNER HUMAN-FRIENDLY */
.greeting-banner { display: flex; align-items: center; gap: 20px; padding: 25px; border-radius: 16px; margin-bottom: 20px; animation: fadeIn 0.5s ease; }
.greeting-banner.holiday { background: linear-gradient(135deg, #fdfbfb 0%, #ebedee 100%); border: 1px solid #e2e8f0; }
.greeting-banner.pulang { background: linear-gradient(135deg, #f0f7ff 0%, #e0efff 100%); border: 1px solid #bae6fd; }
.greeting-banner.izin { background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); border: 1px solid #fde68a; box-shadow: 0 4px 15px rgba(245, 158, 11, 0.1); }
.greeting-icon { font-size: 2.5rem; background: white; padding: 15px; border-radius: 50%; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
.greeting-banner.izin .greeting-icon { background: #fef3c7; }
.greeting-text h4 { margin: 0 0 5px 0; font-size: 1.2rem; color: #1e293b; font-weight: 800; }
.greeting-banner.izin h4 { color: #92400e; }
.greeting-text p { margin: 0; font-size: 0.9rem; color: #64748b; line-height: 1.5; }
.greeting-banner.izin p { color: #b45309; font-weight: 500; }

/* ACTION BUTTONS */
.action-buttons-vertical { display: flex !important; flex-direction: column !important; gap: 16px !important; }
.btn-action { width: 100% !important; padding: 14px 20px !important; border: none !important; border-radius: 12px !important; font-weight: 700 !important; font-size: 1rem !important; cursor: pointer !important; display: flex !important; align-items: center !important; justify-content: center !important; gap: 10px !important; box-shadow: 0 4px 10px rgba(0,0,0,0.1) !important; transition: all 0.3s ease !important; }
.btn-action:active:not(:disabled) { transform: scale(0.97) !important; }
.btn-masuk { background: linear-gradient(135deg, #00529C, #0076E3) !important; color: #ffffff !important; }
.btn-pulang { background: linear-gradient(135deg, #F37021, #FF8C42) !important; color: #ffffff !important; }
.btn-keluar-sementara { background: linear-gradient(135deg, #F59E0B, #FBBF24) !important; color: #ffffff !important; }
.btn-kembali { background: linear-gradient(135deg, #10B981, #34D399) !important; color: #ffffff !important; }
.btn-izin { background: #ffffff !important; color: #00529C !important; border: 2px solid #00529C !important; }
.btn-disabled, .btn-action:disabled { background: #E2E8F0 !important; color: #94A3B8 !important; cursor: not-allowed !important; box-shadow: none !important; border: 1px solid #CBD5E1 !important; }
.btn-text { color: inherit !important; }

/* LOGBOOK */
.logbook-card { padding: 0; overflow: hidden; }
.card-header-custom { padding: 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
.logbook-body { padding: 20px; }
.logbook-hint { font-size: 0.85rem; color: #64748b; margin-bottom: 12px; }
.logbook-textarea { width: 100%; padding: 15px; border: 2px solid #e2e8f0; border-radius: 12px; font-size: 0.95rem; transition: all 0.3s ease; resize: vertical; background-color: #fdfdfd; }
.logbook-textarea:focus { outline: none; border-color: #00529C; background-color: #fff; box-shadow: 0 0 0 4px rgba(0, 82, 156, 0.1); }
.btn-save-logbook { margin-top: 15px; width: 100%; padding: 14px; background: linear-gradient(135deg, #00529C 0%, #003a6e 100%); color: white; border: none; border-radius: 10px; font-weight: 600; display: flex; justify-content: center; align-items: center; gap: 10px; transition: transform 0.2s; }
.btn-save-logbook:hover:not(:disabled) { transform: translateY(-2px); filter: brightness(1.1); }
.btn-save-logbook:disabled { background: #cbd5e1; cursor: not-allowed; }
.status-indicator { font-size: 0.75rem; padding: 4px 10px; border-radius: 20px; font-weight: 600; }
.status-filled { background: #dcfce7; color: #166534; }
.status-empty { background: #fee2e2; color: #991b1b; }
.text-warning-sm { font-size: 0.75rem; color: #ef4444; text-align: center; margin-top: 10px; }

/* TABLE & HISTORY */
.table-responsive { width: 100%; overflow-x: auto; }
.corporate-table-sm { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.corporate-table-sm th { background: #f1f5f9; padding: 12px 16px; text-align: left; color: #64748b; font-weight: 600; border-bottom: 2px solid #e2e8f0; }
.corporate-table-sm td { padding: 12px 16px; border-bottom: 1px solid #f1f5f9; }
.time-cell { font-family: 'Monaco', monospace; font-weight: 600; color: #00529C; }
.badge-status { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; }
.bg-success-light { background: #dcfce7; color: #166534; }
.bg-warning-light { background: #fef3c7; color: #92400e; }
.bg-danger-light { background: #fee2e2; color: #991b1b; }
.log-text-cell { max-width: 250px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #475569; }
.btn-edit-sm { background: transparent; border: 1px solid #00529C; color: #00529C; padding: 4px 12px; border-radius: 6px; font-size: 0.75rem; cursor: pointer; transition: 0.2s; font-weight: 600; }
.btn-edit-sm:hover { background: #00529C; color: white; }
.btn-download { background-color: #00529C; color: white; border: none; padding: 8px 16px; border-radius: 8px; font-weight: 600; cursor: pointer; transition: 0.3s; }
.btn-download:hover { background-color: #003a6e; transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.1); }

/* MODALS & FORMS */
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.6); display: flex; align-items: center; justify-content: center; z-index: 1000; backdrop-filter: blur(4px); }
.z-high { z-index: 2000; }
.modal-card { background: white; padding: 30px; border-radius: 16px; width: 90%; max-width: 450px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); }
.video-container { width: 100%; aspect-ratio: 4/3; background: #000; border-radius: 12px; overflow: hidden; margin-top: 15px; }
.video-container video { width: 100%; height: 100%; object-fit: cover; }
.form-input { width: 100%; padding: 12px; border: 1px solid var(--border-color); border-radius: 8px; font-family: inherit; margin-top: 5px; box-sizing: border-box; }
.modal-actions { display: flex; gap: 10px; margin-top: 20px; }
.btn-batal { padding: 12px; background: #F1F5F9; border: 1px solid #CBD5E1; color: #475569; border-radius: 10px; font-weight: bold; cursor: pointer; transition: 0.2s; flex: 1; }
.btn-batal:hover { background: #E2E8F0; }

/* SPINNER BUTTON */
.spinner-btn {
  display: inline-block;
  width: 18px; height: 18px;
  border: 3px solid rgba(255,255,255,0.4);
  border-top-color: #ffffff;
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
  flex-shrink: 0;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* UTILITIES */
.flex-1 { flex: 1; } .flex-2 { flex: 2; }
.text-muted { color: var(--text-muted); }
.fade-in { animation: fadeIn 0.4s ease; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

/* --- RESPONSIVE MOBILE --- */
@media (max-width: 768px) {
  .sidebar { position: fixed; height: 100vh; transform: translateX(-100%); width: 250px; }
  .sidebar.open { transform: translateX(0); }
  .sidebar-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 99; backdrop-filter: blur(2px); }
  .menu-toggle { display: block; }
  
  .topbar { padding: 15px 20px; }
  .time { font-size: 1rem; }
  .date { font-size: 0.75rem; }
  .content-wrapper { padding: 15px; }
  .grid-container { grid-template-columns: 1fr; gap: 15px; } 
  .card { padding: 18px; }
  
  .greeting-banner { flex-direction: column; text-align: center; gap: 10px; padding: 20px; }
  .greeting-icon { font-size: 2rem; padding: 12px; }
  
  .btn-action { padding: 12px 15px !important; font-size: 0.95rem !important; }
  
  .d-flex { flex-direction: column; align-items: flex-start !important; gap: 10px; }
  .btn-download { width: 100%; text-align: center; padding: 10px; }
  .corporate-table-sm th, .corporate-table-sm td { padding: 10px; font-size: 0.8rem; }
  .log-text-cell { max-width: 150px; } 
  
  .modal-card { padding: 20px; width: 95%; }
  .modal-actions { flex-direction: column; gap: 8px; }
  .btn-batal, .btn-masuk, .btn-save { width: 100%; margin: 0; }
  
  .video-container { margin-top: 10px; }
}
</style>
