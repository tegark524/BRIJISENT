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
const isTablet = ref(false)
const isSidebarOpen = ref(true)
const toggleSidebar = () => isSidebarOpen.value = !isSidebarOpen.value

// STATE ATTENDANCE & LOGBOOK
const todayAttendance = ref(null)
const logbookText = ref('')
const isWeekend = ref(false)
const currentHoliday = ref(null)

// DATA HISTORY
const historyAbsen = ref([])
const editLogbookData = ref({ id: null, text: '' })
const showEditLogbookModal = ref(false)

// WAKTU REALTIME
const currentTime = ref(new Date())
let timer = null

// ==========================================
// STATE KAMERA & IZIN
// ==========================================
const showCameraModal = ref(false)
const showRegistrationModal = ref(false)
const jenisAbsen = ref('')
const listKamera = ref([])
const kameraTerpilih = ref(null)
const videoElement = ref(null)
const videoElementReg = ref(null)
let streamSaatIni = null

const showIzinModal = ref(false)
const formIzin = ref({ tanggal: '', alasan: '', bukti: '' })

// MODEL STATE
const modelsLoaded = ref(false)
const isLoadingModels = ref(false)

// ==========================================
// HELPER: VALIDASI FACE DESCRIPTOR
// ==========================================
const hasFaceDescriptor = (userData) => {
  const fd = userData?.face_descriptor
  if (fd === null || fd === undefined || fd === 'null' || fd === '' || fd === '[]') return false
  if (typeof fd === 'string') {
    try {
      const parsed = JSON.parse(fd)
      return Array.isArray(parsed) && parsed.length > 0
    } catch { return false }
  }
  if (Array.isArray(fd)) return fd.length > 0
  return false
}

// ==========================================
// LOAD MODEL FACE-API
// ==========================================
const loadModels = async () => {
  if (modelsLoaded.value) return
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
  } catch (error) {
    console.error('Gagal memuat model:', error)
  } finally {
    isLoadingModels.value = false
  }
}

// ==========================================
// KAMERA UTILS — robust cross-device
// ==========================================
const stopStream = () => {
  if (streamSaatIni) {
    streamSaatIni.getTracks().forEach(t => t.stop())
    streamSaatIni = null
  }
}

const startStream = async (videoRef, deviceId) => {
  stopStream()
  const constraints = {
    video: {
      deviceId: deviceId ? { exact: deviceId } : undefined,
      width: { ideal: 640 },
      height: { ideal: 480 },
      facingMode: deviceId ? undefined : 'user'
    }
  }
  try {
    streamSaatIni = await navigator.mediaDevices.getUserMedia(constraints)
    await nextTick()
    if (videoRef.value) {
      videoRef.value.srcObject = streamSaatIni
      await videoRef.value.play().catch(() => {})
    }
    return true
  } catch (e) {
    console.error('Stream error:', e)
    return false
  }
}

const getKameraList = async () => {
  try {
    // Request permission first then stop immediately
    const tempStream = await navigator.mediaDevices.getUserMedia({ video: true })
    tempStream.getTracks().forEach(t => t.stop())
    const devices = await navigator.mediaDevices.enumerateDevices()
    listKamera.value = devices.filter(d => d.kind === 'videoinput')
    if (listKamera.value.length > 0 && !kameraTerpilih.value) {
      kameraTerpilih.value = listKamera.value[0].deviceId
    }
    return true
  } catch {
    return false
  }
}

// ==========================================
// REGISTRASI WAJAH
// ==========================================
const initKameraReg = async () => {
  const ok = await getKameraList()
  if (!ok) {
    return Swal.fire('Kamera Error', 'Tidak dapat mengakses kamera. Pastikan izin sudah diberikan.', 'error')
  }
  await startStream(videoElementReg, kameraTerpilih.value)
}

const gantiKameraReg = async () => {
  await startStream(videoElementReg, kameraTerpilih.value)
}

const captureFrame = (videoRef) => {
  if (!videoRef.value || videoRef.value.videoWidth === 0) return null
  const canvas = document.createElement('canvas')
  canvas.width = videoRef.value.videoWidth
  canvas.height = videoRef.value.videoHeight
  canvas.getContext('2d').drawImage(videoRef.value, 0, 0)
  return canvas
}

const prosesRegistrasiWajah = async () => {
  if (!videoElementReg.value) return

  // 1. Capture frame SEBELUM tutup kamera
  const canvas = captureFrame(videoElementReg)
  if (!canvas) {
    return Swal.fire('Error', 'Kamera belum siap. Tunggu sebentar lalu coba lagi.', 'warning')
  }

  // 2. Tutup kamera & modal SEGERA → instant feedback
  stopStream()
  showRegistrationModal.value = false

  // 3. Tampilkan loading
  Swal.fire({
    title: 'Memindai Biometrik',
    html: `
      <div style="padding:20px 0 10px">
        <div style="position:relative;width:72px;height:72px;margin:0 auto 16px">
          <svg width="72" height="72" viewBox="0 0 72 72" fill="none" style="display:block">
            <circle cx="36" cy="36" r="34" stroke="#e4e9f0" stroke-width="2"/>
            <circle cx="36" cy="36" r="34" stroke="#00529C" stroke-width="2.5" stroke-dasharray="53 160" stroke-linecap="round" style="animation:rotSpin 1.2s linear infinite;transform-origin:center">
              <animateTransform attributeName="transform" type="rotate" from="0 36 36" to="360 36 36" dur="1.2s" repeatCount="indefinite"/>
            </circle>
          </svg>
          <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center">
            <svg width="28" height="28" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4" stroke="#00529C" stroke-width="1.8"/><path d="M4 20c0-4.418 3.582-8 8-8s8 3.582 8 8" stroke="#00529C" stroke-width="1.8" stroke-linecap="round"/></svg>
          </div>
        </div>
        <p style="color:#1a2332;font-weight:700;font-size:1rem;margin:0 0 4px">Menganalisis Wajah</p>
        <p style="color:#64748b;font-size:0.82rem;margin:0">Mohon tunggu sebentar...</p>
      </div>
    `,
    allowOutsideClick: false,
    showConfirmButton: false,
  })

  try {
    // 4. Pastikan model siap
    if (!modelsLoaded.value) {
      Swal.update({ title: 'Memuat Model AI...', html: '<p style="color:#64748b;padding:20px 0">Memuat model pengenalan wajah...<br><small style="color:#94a3b8">Hanya terjadi sekali</small></p>' })
      await loadModels()
    }

    // 5. Deteksi dari canvas
    const options = new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.5 })
    const detection = await faceapi.detectSingleFace(canvas, options).withFaceLandmarks().withFaceDescriptor()

    if (!detection) {
      throw new Error('Wajah tidak terdeteksi. Pastikan wajah menghadap kamera dengan pencahayaan yang cukup.')
    }

    Swal.update({ title: 'Menyimpan Data...', html: '<p style="color:#64748b;padding:20px 0">Menyimpan data biometrik ke server...</p>' })

    const faceDescriptorArray = Array.from(detection.descriptor)
    await axios.post('/face-register', { user_id: user.value.id, face_descriptor: faceDescriptorArray })

    authStore.user.face_descriptor = JSON.stringify(faceDescriptorArray)
    authStore.user.is_active = true
    localStorage.setItem('user', JSON.stringify(authStore.user))

    await Swal.fire({
      icon: 'success',
      title: 'Registrasi Berhasil!',
      text: 'Wajah Anda telah terdaftar. Selamat menggunakan BRIJISENT.',
      confirmButtonColor: '#00529C',
      confirmButtonText: 'Mulai Sekarang'
    })
    await fetchTodayData()

  } catch (error) {
    const pesan = error.response?.data?.message || error.message || 'Terjadi kesalahan.'
    await Swal.fire({ icon: 'error', title: 'Registrasi Gagal', text: pesan, confirmButtonColor: '#00529C' })
    showRegistrationModal.value = true
    await nextTick()
    await initKameraReg()
  }
}

// ==========================================
// ABSENSI WAJAH
// ==========================================
const initKamera = async () => {
  const ok = await getKameraList()
  if (!ok) {
    Swal.fire('Kamera Error', 'Tidak dapat mengakses kamera.', 'error')
    showCameraModal.value = false
    return
  }
  await startStream(videoElement, kameraTerpilih.value)
}

const bukaKamera = async (jenis) => {
  jenisAbsen.value = jenis
  showCameraModal.value = true
  await nextTick()
  await initKamera()
}

const gantiKamera = async () => {
  await startStream(videoElement, kameraTerpilih.value)
}

const tutupKamera = () => {
  stopStream()
  showCameraModal.value = false
}

const prosesAbsenDariKamera = async () => {
  if (!videoElement.value) return

  const canvas = captureFrame(videoElement)
  if (!canvas) return Swal.fire('Error', 'Kamera belum siap.', 'warning')

  // Tutup kamera SEGERA
  stopStream()
  showCameraModal.value = false

  Swal.fire({
    title: 'Memverifikasi Identitas',
    html: `
      <div style="padding:20px 0 10px">
        <div style="position:relative;width:72px;height:72px;margin:0 auto 16px">
          <svg width="72" height="72" viewBox="0 0 72 72" fill="none" style="display:block">
            <circle cx="36" cy="36" r="34" stroke="#e4e9f0" stroke-width="2"/>
            <circle cx="36" cy="36" r="34" stroke="#00529C" stroke-width="2.5" stroke-dasharray="53 160" stroke-linecap="round">
              <animateTransform attributeName="transform" type="rotate" from="0 36 36" to="360 36 36" dur="1.2s" repeatCount="indefinite"/>
            </circle>
          </svg>
          <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center">
            <svg width="28" height="28" fill="none" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" stroke="#00529C" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </div>
        </div>
        <p style="color:#1a2332;font-weight:700;font-size:1rem;margin:0 0 4px">Verifikasi Biometrik</p>
        <p style="color:#64748b;font-size:0.82rem;margin:0">Sedang mencocokkan data wajah...</p>
      </div>
    `,
    allowOutsideClick: false,
    showConfirmButton: false
  })

  try {
    if (!modelsLoaded.value) await loadModels()

    const options = new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.5 })
    const detection = await faceapi.detectSingleFace(canvas, options).withFaceLandmarks().withFaceDescriptor()

    if (!detection) throw new Error('Wajah tidak terdeteksi. Pastikan pencahayaan cukup.')

    const endpoint = jenisAbsen.value === 'masuk' ? '/attendances/clock-in' : '/attendances/clock-out'
    await axios.post(endpoint, { user_id: user.value.id, face_descriptor: Array.from(detection.descriptor) })

    await Swal.fire({
      icon: 'success',
      title: jenisAbsen.value === 'masuk' ? 'Selamat Datang! 👋' : 'Sampai Jumpa! 🏡',
      text: `Absen ${jenisAbsen.value} berhasil dicatat.`,
      confirmButtonColor: '#00529C', timer: 2500, timerProgressBar: true
    })
    await fetchTodayData()

  } catch (error) {
    const pesanError = error.response?.data?.message || error.message || 'Terjadi kesalahan sistem.'
    await Swal.fire({ icon: 'error', title: 'Verifikasi Gagal', text: pesanError, confirmButtonColor: '#00529C' })
    showCameraModal.value = true
    await nextTick()
    await initKamera()
  }
}

// ==========================================
// IZIN & STATUS
// ==========================================
const submitIzinForm = async () => {
  if (!formIzin.value.alasan || !formIzin.value.tanggal) {
    return Swal.fire('Peringatan', 'Tanggal dan alasan izin wajib diisi!', 'warning')
  }
  Swal.fire({ title: 'Mengirim...', allowOutsideClick: false, didOpen: () => Swal.showLoading() })
  try {
    await axios.post('/attendances/permit', { user_id: user.value.id, ...formIzin.value })
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
    if (res.data.success) { await fetchTodayData(); Swal.fire('Berhasil', res.data.message, 'success') }
  } catch (e) { Swal.fire('Gagal', 'Gagal mengubah status', 'error') }
}

// ==========================================
// DATA & COMPUTED
// ==========================================
const fetchTodayData = async () => {
  if (!user.value?.id) return
  try {
    const res = await axios.get(`/attendances/today/${user.value.id}`)
    todayAttendance.value = res.data.attendance || null
    logbookText.value = res.data.attendance?.logbook || ''
    isWeekend.value = res.data.is_weekend
    currentHoliday.value = res.data.holiday
  } catch (e) { console.error('Gagal refresh data:', e) }
}

const attendanceStatus = computed(() => {
  if (!todayAttendance.value) return 'BELUM ABSEN'
  if (todayAttendance.value.status === 'permit') return 'IZIN TIDAK MASUK'
  if (todayAttendance.value.clock_out) return 'SUDAH PULANG'
  if (todayAttendance.value.office_status === 'keluar_sementara') return 'SEDANG KELUAR'
  if (todayAttendance.value.clock_in) return 'DI KANTOR'
  return 'BELUM ABSEN'
})

const statusConfig = computed(() => {
  const map = {
    'DI KANTOR':        { color: '#059669', bg: '#ecfdf5', border: '#6ee7b7', dot: '#10b981' },
    'SEDANG KELUAR':    { color: '#d97706', bg: '#fffbeb', border: '#fcd34d', dot: '#f59e0b' },
    'SUDAH PULANG':     { color: '#1d4ed8', bg: '#eff6ff', border: '#93c5fd', dot: '#3b82f6' },
    'IZIN TIDAK MASUK': { color: '#7c3aed', bg: '#f5f3ff', border: '#c4b5fd', dot: '#8b5cf6' },
    'BELUM ABSEN':      { color: '#475569', bg: '#f8fafc', border: '#cbd5e1', dot: '#94a3b8' },
  }
  return map[attendanceStatus.value] || map['BELUM ABSEN']
})

const greetingMessage = computed(() => {
  if (currentHoliday.value) return { title: 'Hari Libur 🎉', subtitle: `Selamat berlibur — ${currentHoliday.value.description}`, type: 'holiday' }
  if (isWeekend.value && !todayAttendance.value) return { title: 'Selamat Weekend! 🏖️', subtitle: 'Istirahat yang cukup, sampai Senin!', type: 'holiday' }
  if (todayAttendance.value?.status === 'permit') {
    const alasan = todayAttendance.value.permit_reason || todayAttendance.value.logbook || 'Keperluan tertentu'
    return { title: 'Anda Sedang Izin 📋', subtitle: `Alasan: "${alasan}". Semoga urusanmu lancar!`, type: 'izin' }
  }
  if (todayAttendance.value?.clock_out) return { title: 'Selamat Pulang 🏡', subtitle: 'Hati-hati di jalan dan selamat beristirahat!', type: 'pulang' }
  return null
})

const canClockIn = computed(() => attendanceStatus.value === 'BELUM ABSEN')
const canClockOut = computed(() => attendanceStatus.value === 'DI KANTOR')

// ==========================================
// LOGBOOK & HISTORY
// ==========================================
const simpanLogbook = async () => {
  if (todayAttendance.value?.status === 'permit') return Swal.fire('Info', 'Kamu sedang izin, tidak perlu mengisi logbook!', 'info')
  if (!logbookText.value.trim()) return Swal.fire('Oops', 'Logbook tidak boleh kosong.', 'warning')
  try {
    const res = await axios.post('/attendances/logbook', { user_id: user.value.id, logbook: logbookText.value })
    if (res.data.success) {
      Swal.fire({ icon: 'success', title: 'Tersimpan!', timer: 1800, showConfirmButton: false })
      await fetchTodayData()
    }
  } catch (e) { Swal.fire('Gagal', e.response?.data?.message || 'Gagal menyimpan logbook.', 'error') }
}

const fetchHistory = async () => {
  if (!user.value?.id) return
  try {
    const res = await axios.get(`/attendances/history/${user.value.id}`)
    historyAbsen.value = res.data.data
  } catch (e) { console.error('Gagal memuat riwayat:', e) }
}

const formatTgl = (tgl) => {
  if (!tgl) return '-'
  return new Date(tgl).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })
}
const formatJam = (jam) => jam || '—'
const labelStatus = (s) => ({ 'present': 'Hadir', 'permit': 'Izin', 'absent': 'Alpa' }[s] || 'Hadir')
const statusClass = (s) => ({ 'permit': 'badge-warning', 'absent': 'badge-danger' }[s] || 'badge-success')

const bukaEditLogbook = async (data) => {
  const { value: text } = await Swal.fire({
    title: 'Edit Logbook', input: 'textarea',
    inputLabel: `Tanggal: ${formatTgl(data.date)}`,
    inputValue: data.logbook || '',
    showCancelButton: true, confirmButtonColor: '#00529C',
    confirmButtonText: 'Simpan', cancelButtonText: 'Batal'
  })
  if (text !== undefined) {
    try {
      await axios.post('/attendances/logbook', { user_id: user.value.id, logbook: text, date: data.date })
      Swal.fire({ icon: 'success', title: 'Diperbarui!', timer: 1500, showConfirmButton: false })
      fetchHistory()
    } catch { Swal.fire('Gagal', 'Gagal memperbarui logbook', 'error') }
  }
}

const switchMenu = (menu) => {
  activeMenu.value = menu
  if (menu === 'history_absen' || menu === 'history_logbook') fetchHistory()
  if (isMobile.value || isTablet.value) isSidebarOpen.value = false
}

const handleResize = () => {
  const w = window.innerWidth
  isMobile.value = w <= 640
  isTablet.value = w > 640 && w <= 1024
  // Mobile & Tablet (termasuk iPad portrait ≤1024px): sidebar default tertutup
  isSidebarOpen.value = w > 1024
}

const unduhLaporan = () => window.open(`/attendances/download/${user.value.id}`, '_blank')

// ==========================================
// WATCHER & LIFECYCLE
// ==========================================
watch(() => user.value.id, async (newId) => {
  if (!newId) return
  try {
    const res = await axios.get(`/user/${newId}`)
    authStore.user = { ...authStore.user, ...res.data.user }
    localStorage.setItem('user', JSON.stringify(authStore.user))
  } catch (e) { console.warn('Sinkronisasi user gagal, pakai data lokal:', e.message) }

  if (!hasFaceDescriptor(authStore.user)) {
    showRegistrationModal.value = true
    await nextTick()
    await initKameraReg()
  } else {
    await fetchTodayData()
  }
}, { immediate: true })

onMounted(() => {
  handleResize()
  window.addEventListener('resize', handleResize)
  timer = setInterval(() => { currentTime.value = new Date() }, 1000)
  loadModels()
})

onUnmounted(() => {
  window.removeEventListener('resize', handleResize)
  clearInterval(timer)
  stopStream()
})
</script>

<template>
  <div class="app-shell">

    <!-- Overlay mobile/tablet -->
    <div class="sidebar-overlay" v-if="isSidebarOpen && (isMobile || isTablet)" @click="toggleSidebar"></div>

    <!-- ===== SIDEBAR ===== -->
    <aside class="sidebar" :class="{ 'sidebar--open': isSidebarOpen }">
      <div class="sidebar-brand">
        <img src="/LOGO.png" alt="Logo" class="brand-logo" onerror="this.style.display='none'" />
        <span class="brand-name">BRI<span class="brand-accent">JISENT</span></span>
      </div>

      <div class="sidebar-user">
        <div class="user-avatar">{{ user.name ? user.name.charAt(0).toUpperCase() : 'U' }}</div>
        <div class="user-meta">
          <span class="user-greeting">Selamat datang,</span>
          <span class="user-name">{{ user.name || 'Intern' }}</span>
        </div>
      </div>

      <nav class="sidebar-nav">
        <button class="nav-item" :class="{ 'nav-item--active': activeMenu === 'beranda' }" @click="switchMenu('beranda')">
          <span class="nav-icon">
            <svg width="17" height="17" fill="none" viewBox="0 0 24 24"><path d="M3 12L12 3l9 9M5 10v9a1 1 0 001 1h4v-5h4v5h4a1 1 0 001-1v-9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </span>
          <span>Beranda</span>
        </button>
        <button class="nav-item" :class="{ 'nav-item--active': activeMenu === 'history_absen' }" @click="switchMenu('history_absen')">
          <span class="nav-icon">
            <svg width="17" height="17" fill="none" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="M16 2v4M8 2v4M3 10h18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
          </span>
          <span>Riwayat Kehadiran</span>
        </button>
        <button class="nav-item" :class="{ 'nav-item--active': activeMenu === 'history_logbook' }" @click="switchMenu('history_logbook')">
          <span class="nav-icon">
            <svg width="17" height="17" fill="none" viewBox="0 0 24 24"><path d="M9 12h6M9 8h6M9 16h4M5 3h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
          </span>
          <span>Riwayat Logbook</span>
        </button>
      </nav>

      <div class="sidebar-footer">
        <button @click="authStore.logout()" class="btn-logout">
          <svg width="15" height="15" fill="none" viewBox="0 0 24 24"><path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-6 0v-1m0-8V7a3 3 0 016 0v1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
          Keluar Sistem
        </button>
      </div>
    </aside>

    <!-- ===== MAIN ===== -->
    <main class="main">

      <!-- TOPBAR -->
      <header class="topbar">
        <button class="topbar-toggle" @click="toggleSidebar" aria-label="Toggle menu">
          <svg width="19" height="19" fill="none" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        </button>
        <div class="topbar-datetime">
          <span class="topbar-date">{{ currentTime.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) }}</span>
          <span class="topbar-time">{{ currentTime.toLocaleTimeString('id-ID') }} <em>WIB</em></span>
        </div>
      </header>

      <!-- CONTENT -->
      <div class="content">

        <!-- ===== BERANDA ===== -->
        <div v-if="activeMenu === 'beranda'" class="page-fade">

          <!-- GREETING BANNER -->
          <div v-if="greetingMessage" class="greeting-card" :class="`greeting--${greetingMessage.type}`">
            <div class="greeting-icon-wrap">
              <span v-if="greetingMessage.type==='holiday'">🌴</span>
              <span v-else-if="greetingMessage.type==='izin'">📋</span>
              <span v-else>🏡</span>
            </div>
            <div>
              <p class="greeting-title">{{ greetingMessage.title }}</p>
              <p class="greeting-sub">{{ greetingMessage.subtitle }}</p>
            </div>
          </div>

          <div class="dashboard-grid">

            <!-- PANEL KEHADIRAN -->
            <div class="panel">
              <div class="panel-header">
                <span class="panel-label">Status Kehadiran</span>
                <div class="status-chip" :style="`color:${statusConfig.color};background:${statusConfig.bg};border-color:${statusConfig.border}`">
                  <span class="status-dot" :style="`background:${statusConfig.dot}`"></span>
                  {{ attendanceStatus }}
                </div>
              </div>

              <!-- Timeline -->
              <div class="timeline" v-if="todayAttendance && todayAttendance.status !== 'permit'">
                <div class="tl-item">
                  <div class="tl-dot" :class="{ 'tl-dot--on': todayAttendance?.clock_in }"></div>
                  <div class="tl-text">
                    <span class="tl-lbl">Jam Masuk</span>
                    <span class="tl-val">{{ formatJam(todayAttendance?.clock_in) }}</span>
                  </div>
                </div>
                <div class="tl-rule"></div>
                <div class="tl-item">
                  <div class="tl-dot" :class="{ 'tl-dot--on': todayAttendance?.clock_out }"></div>
                  <div class="tl-text">
                    <span class="tl-lbl">Jam Pulang</span>
                    <span class="tl-val">{{ formatJam(todayAttendance?.clock_out) }}</span>
                  </div>
                </div>
              </div>

              <!-- BUTTONS -->
              <div class="action-stack" v-if="!greetingMessage">
                <button v-if="canClockIn" @click="bukaKamera('masuk')" class="btn-act btn-act--primary">
                  <svg width="17" height="17" fill="none" viewBox="0 0 24 24"><path d="M15 10l4.553-2.069A1 1 0 0121 8.87v6.26a1 1 0 01-1.447.9L15 14M3 8a2 2 0 012-2h10a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z" stroke="currentColor" stroke-width="1.8"/></svg>
                  Absen Masuk
                </button>
                <button v-if="!canClockIn && attendanceStatus !== 'IZIN TIDAK MASUK'" @click="bukaKamera('keluar')" class="btn-act btn-act--orange" :disabled="!canClockOut">
                  <svg width="17" height="17" fill="none" viewBox="0 0 24 24"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                  {{ attendanceStatus === 'SUDAH PULANG' ? 'Sudah Pulang' : 'Absen Pulang' }}
                </button>
                <button v-if="todayAttendance?.id && !todayAttendance.clock_out" @click="toggleStatus" class="btn-act btn-act--green">
                  <svg width="17" height="17" fill="none" viewBox="0 0 24 24"><path d="M8 9l4-4 4 4M16 15l-4 4-4-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  {{ attendanceStatus === 'SEDANG KELUAR' ? 'Kembali ke Kantor' : 'Izin Keluar Sebentar' }}
                </button>
                <button v-if="!todayAttendance?.id" @click="showIzinModal = true" class="btn-act btn-act--ghost">
                  <svg width="17" height="17" fill="none" viewBox="0 0 24 24"><path d="M9 12h6m-3-3v6m9-3a9 9 0 11-18 0 9 9 0 0118 0z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                  Ajukan Izin Tidak Masuk
                </button>
              </div>

              <div class="action-stack" v-if="greetingMessage && !todayAttendance?.id">
                <button @click="showIzinModal = true" class="btn-act btn-act--ghost">
                  <svg width="17" height="17" fill="none" viewBox="0 0 24 24"><path d="M9 12h6m-3-3v6m9-3a9 9 0 11-18 0 9 9 0 0118 0z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                  Ajukan Izin Tidak Masuk
                </button>
              </div>
            </div>

            <!-- PANEL LOGBOOK -->
            <div class="panel">
              <div class="panel-header">
                <span class="panel-label">Logbook Harian</span>
                <span class="lb-chip" :class="logbookText ? 'lb-chip--filled' : 'lb-chip--empty'">
                  {{ logbookText ? '✓ Terisi' : '○ Kosong' }}
                </span>
              </div>
              <p class="lb-hint">Catat progress kerja, pencapaian, atau kendala hari ini.</p>
              <textarea
                v-model="logbookText"
                class="lb-area"
                rows="6"
                :placeholder="todayAttendance?.status === 'permit' ? 'Sedang izin — tidak perlu mengisi logbook hari ini.' : 'Contoh: Meeting tim, debugging API payment, review kode modul absensi...'"
                :disabled="!todayAttendance?.id || todayAttendance?.status === 'permit'"
              ></textarea>
              <button @click="simpanLogbook" class="btn-save" :disabled="!todayAttendance?.id || !logbookText.trim() || todayAttendance?.status === 'permit'">
                <svg width="15" height="15" fill="none" viewBox="0 0 24 24"><path d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Simpan Laporan
              </button>
              <p v-if="!todayAttendance?.id" class="hint-muted">Absen masuk terlebih dahulu untuk mengisi logbook.</p>
            </div>

          </div><!-- /grid -->
        </div>

        <!-- ===== RIWAYAT KEHADIRAN ===== -->
        <div v-if="activeMenu === 'history_absen'" class="page-fade panel">
          <div class="panel-header" style="margin-bottom:18px">
            <span class="panel-label">Riwayat Kehadiran</span>
            <button @click="unduhLaporan" class="btn-dl">
              <svg width="14" height="14" fill="none" viewBox="0 0 24 24"><path d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
              Unduh CSV
            </button>
          </div>
          <div class="tbl-wrap">
            <table class="dtbl">
              <thead><tr><th>Tanggal</th><th>Status</th><th>Masuk</th><th>Pulang</th></tr></thead>
              <tbody>
                <tr v-for="absen in historyAbsen" :key="absen.id">
                  <td class="td-date">{{ formatTgl(absen.date) }}</td>
                  <td><span class="badge" :class="statusClass(absen.status)">{{ labelStatus(absen.status) }}</span></td>
                  <td class="td-time">{{ formatJam(absen.clock_in) }}</td>
                  <td class="td-time">{{ formatJam(absen.clock_out) }}</td>
                </tr>
                <tr v-if="historyAbsen.length === 0">
                  <td colspan="4" class="td-empty">Belum ada riwayat absen.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- ===== RIWAYAT LOGBOOK ===== -->
        <div v-if="activeMenu === 'history_logbook'" class="page-fade panel">
          <div class="panel-header" style="margin-bottom:18px">
            <span class="panel-label">Riwayat Logbook</span>
          </div>
          <div class="tbl-wrap">
            <table class="dtbl">
              <thead><tr><th style="width:130px">Tanggal</th><th>Catatan</th><th style="width:70px;text-align:center">Aksi</th></tr></thead>
              <tbody>
                <tr v-for="log in historyAbsen" :key="'log-'+log.id">
                  <td class="td-date">{{ formatTgl(log.date) }}</td>
                  <td class="td-log">{{ log.logbook || '—' }}</td>
                  <td style="text-align:center"><button class="btn-edit" @click="bukaEditLogbook(log)">Edit</button></td>
                </tr>
                <tr v-if="historyAbsen.length === 0">
                  <td colspan="3" class="td-empty">Belum ada data logbook.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

      </div><!-- /content -->
    </main>

    <!-- ============================================================
         MODAL REGISTRASI WAJAH
    ============================================================ -->
    <div v-if="showRegistrationModal" class="overlay overlay--dark">
      <div class="modal-box modal-box--reg">
        <div class="reg-head">
          <span class="reg-badge">BRIJISENT</span>
          <h2 class="reg-title">Registrasi Biometrik</h2>
          <p class="reg-sub">Daftarkan wajah Anda untuk mengaktifkan akses sistem kehadiran digital.</p>
        </div>

        <div class="cam-select-wrap">
          <label class="field-lbl">Pilih Kamera</label>
          <select v-model="kameraTerpilih" @change="gantiKameraReg" class="field-sel">
            <option v-for="(cam,i) in listKamera" :key="cam.deviceId" :value="cam.deviceId">{{ cam.label || `Kamera ${i+1}` }}</option>
          </select>
        </div>

        <div class="cam-frame">
          <video ref="videoElementReg" autoplay playsinline muted class="cam-vid"></video>
          <div class="cam-ovl"><div class="cam-guide"></div></div>
        </div>

        <div class="reg-foot">
          <button @click="prosesRegistrasiWajah" class="btn-capture" :disabled="isLoadingModels">
            <span v-if="isLoadingModels" class="spin"></span>
            <svg v-else width="17" height="17" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="2"/><path d="M3 9a2 2 0 012-2h.5L7 5h10l1.5 2H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" stroke="currentColor" stroke-width="1.8"/></svg>
            {{ isLoadingModels ? 'Memuat Model AI...' : 'Ambil & Daftarkan Wajah' }}
          </button>
          <p v-if="isLoadingModels" class="note-muted">Model AI dimuat di latar belakang — hanya terjadi sekali.</p>
        </div>
      </div>
    </div>

    <!-- ============================================================
         MODAL KAMERA ABSENSI
    ============================================================ -->
    <div v-if="showCameraModal" class="overlay">
      <div class="modal-box">
        <div class="modal-hdr">
          <h3 class="modal-ttl">{{ jenisAbsen === 'masuk' ? 'Absen Masuk' : 'Absen Pulang' }}</h3>
          <button class="modal-x" @click="tutupKamera" aria-label="Tutup">
            <svg width="17" height="17" fill="none" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
          </button>
        </div>
        <div class="cam-select-wrap" style="margin-top:14px">
          <label class="field-lbl">Pilih Kamera</label>
          <select v-model="kameraTerpilih" @change="gantiKamera" class="field-sel">
            <option v-for="(cam,i) in listKamera" :key="cam.deviceId" :value="cam.deviceId">{{ cam.label || `Kamera ${i+1}` }}</option>
          </select>
        </div>
        <div class="cam-frame">
          <video ref="videoElement" autoplay playsinline muted class="cam-vid"></video>
          <div class="cam-ovl"><div class="cam-guide"></div></div>
        </div>
        <div class="modal-foot">
          <button @click="tutupKamera" class="btn-cancel">Batal</button>
          <button @click="prosesAbsenDariKamera" class="btn-capture" style="flex:2">
            <svg width="17" height="17" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="2"/><path d="M3 9a2 2 0 012-2h.5L7 5h10l1.5 2H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" stroke="currentColor" stroke-width="1.8"/></svg>
            Verifikasi Wajah
          </button>
        </div>
      </div>
    </div>

    <!-- ============================================================
         MODAL FORM IZIN
    ============================================================ -->
    <div v-if="showIzinModal" class="overlay">
      <div class="modal-box">
        <div class="modal-hdr">
          <h3 class="modal-ttl">Pengajuan Izin</h3>
          <button class="modal-x" @click="showIzinModal = false" aria-label="Tutup">
            <svg width="17" height="17" fill="none" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
          </button>
        </div>
        <form @submit.prevent="submitIzinForm" class="form-body">
          <div class="field-grp">
            <label class="field-lbl">Tanggal Izin <span class="req">*</span></label>
            <input type="date" v-model="formIzin.tanggal" required class="field-inp" />
          </div>
          <div class="field-grp">
            <label class="field-lbl">Alasan Izin <span class="req">*</span></label>
            <textarea v-model="formIzin.alasan" rows="3" placeholder="Contoh: Sakit, keperluan keluarga, dll." required class="field-inp"></textarea>
          </div>
          <div class="field-grp">
            <label class="field-lbl">Link Bukti (Google Drive)</label>
            <input type="url" v-model="formIzin.bukti" placeholder="https://drive.google.com/..." class="field-inp" />
            <span class="field-note">Pastikan akses link diatur "Anyone with the link"</span>
          </div>
          <div class="modal-foot">
            <button type="button" @click="showIzinModal = false" class="btn-cancel">Batal</button>
            <button type="submit" class="btn-capture" style="flex:2">Kirim Pengajuan</button>
          </div>
        </form>
      </div>
    </div>

  </div>
</template>

<style scoped>
/* ============================================================
   DESIGN TOKENS
============================================================ */
:root {
  --brand: #003f8a;
  --brand-mid: #00529C;
  --brand-light: #e8f1fb;
  --accent: #F37021;
  --surface: #ffffff;
  --bg: #f0f4f9;
  --border: #e2e8f2;
  --text: #111827;
  --text-2: #64748b;
  --text-3: #9ca3af;
  --r: 12px;
  --r-sm: 8px;
  --sh: 0 1px 2px rgba(0,0,0,.05), 0 4px 16px rgba(0,0,0,.04);
  --sh-md: 0 8px 30px rgba(0,0,0,.1), 0 2px 6px rgba(0,0,0,.06);
  --fn: 'Inter','Helvetica Neue',sans-serif;
}

*,*::before,*::after { box-sizing: border-box; margin: 0; padding: 0; }
button { font-family: var(--fn); }
textarea, input, select { font-family: var(--fn); }

/* ============================================================
   APP SHELL
============================================================ */
.app-shell {
  display: flex;
  height: 100dvh;
  background: var(--bg);
  font-family: var(--fn);
  color: var(--text);
  overflow: hidden;
}

/* ============================================================
   SIDEBAR
============================================================ */
.sidebar {
  width: 252px;
  min-width: 252px;
  background: var(--surface);
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  z-index: 200;
  flex-shrink: 0;
  transition: transform .28s cubic-bezier(.4,0,.2,1);
}

.sidebar-brand {
  display: flex; align-items: center; gap: 10px;
  padding: 20px 18px 16px;
  border-bottom: 1px solid var(--border);
}
.brand-logo { width: 32px; height: 32px; object-fit: contain; }
.brand-name { font-size: 1.1rem; font-weight: 800; color: var(--brand-mid); letter-spacing: .3px; }
.brand-accent { color: var(--accent); }

.sidebar-user {
  display: flex; align-items: center; gap: 11px;
  padding: 14px 18px;
  border-bottom: 1px solid var(--border);
  background: var(--brand-light);
}
.user-avatar {
  width: 38px; height: 38px; border-radius: 50%;
  background: var(--brand-mid); color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: .95rem; font-weight: 800; flex-shrink: 0;
}
.user-meta { display: flex; flex-direction: column; min-width: 0; }
.user-greeting { font-size: .68rem; color: var(--text-2); font-weight: 500; }
.user-name { font-size: .875rem; font-weight: 700; color: var(--brand); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.sidebar-nav { flex: 1; padding: 12px 10px; display: flex; flex-direction: column; gap: 2px; overflow-y: auto; }
.nav-item {
  display: flex; align-items: center; gap: 9px;
  padding: 10px 12px; width: 100%;
  border: none; background: transparent; border-radius: var(--r-sm);
  color: var(--text-2); font-size: .85rem; font-weight: 600;
  cursor: pointer; text-align: left;
  transition: background .15s, color .15s;
}
.nav-item:hover { background: var(--bg); color: var(--brand-mid); }
.nav-item--active { background: var(--brand-light); color: var(--brand-mid); }
.nav-icon { display: flex; align-items: center; color: inherit; flex-shrink: 0; }

.sidebar-footer { padding: 14px; border-top: 1px solid var(--border); }
.btn-logout {
  width: 100%; padding: 9px 13px;
  display: flex; align-items: center; justify-content: center; gap: 7px;
  background: #fff5f5; color: #dc2626;
  border: 1px solid #fecaca; border-radius: var(--r-sm);
  font-size: .82rem; font-weight: 600; cursor: pointer;
  transition: background .15s;
}
.btn-logout:hover { background: #fee2e2; }

/* Mobile / Tablet */
@media (max-width: 1024px) {
  .sidebar {
    position: fixed; top: 0; left: 0; bottom: 0;
    transform: translateX(-100%);
    box-shadow: var(--sh-md);
  }
  .sidebar--open { transform: translateX(0); }
}
.sidebar-overlay {
  position: fixed; inset: 0;
  background: rgba(0,0,0,.42);
  z-index: 199;
  backdrop-filter: blur(3px);
  display: none;
}
@media (max-width: 1024px) { .sidebar-overlay { display: block; } }

/* ============================================================
   MAIN
============================================================ */
.main { flex: 1; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }

/* TOPBAR */
.topbar {
  display: flex; align-items: center; gap: 14px;
  padding: 0 22px; height: 58px;
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  flex-shrink: 0;
}
.topbar-toggle {
  display: flex; align-items: center; justify-content: center;
  width: 34px; height: 34px;
  border: none; background: var(--bg); border-radius: var(--r-sm);
  color: var(--text-2); cursor: pointer; flex-shrink: 0;
  transition: background .15s;
}
.topbar-toggle:hover { background: var(--border); }
.topbar-datetime { margin-left: auto; text-align: right; }
.topbar-date { display: block; font-size: .72rem; color: var(--text-2); }
.topbar-time { font-size: .98rem; font-weight: 800; color: var(--brand-mid); line-height: 1.2; }
.topbar-time em { font-style: normal; font-size: .7rem; font-weight: 500; color: var(--text-3); }

/* CONTENT */
.content { flex: 1; overflow-y: auto; padding: 22px; }
.page-fade { animation: pFade .3s ease both; }
@keyframes pFade { from { opacity:0; transform:translateY(6px) } to { opacity:1; transform:none } }

/* ============================================================
   PANELS
============================================================ */
.panel {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r);
  padding: 22px;
  box-shadow: var(--sh);
}

.panel-header {
  display: flex; align-items: center; justify-content: space-between;
  gap: 10px; margin-bottom: 18px; flex-wrap: wrap;
}
.panel-label {
  font-size: .68rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: .08em; color: var(--text-3);
}

/* STATUS CHIP */
.status-chip {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 4px 11px; border: 1px solid; border-radius: 20px;
  font-size: .75rem; font-weight: 700;
}
.status-dot {
  width: 6px; height: 6px; border-radius: 50%;
  animation: blink 2.4s ease infinite;
}
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.4} }

/* DASHBOARD GRID */
.dashboard-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 18px;
  margin-top: 0;
}
/* iPad (≤1024px) dan bawah: susun ke bawah */
@media (max-width: 1024px) {
  .dashboard-grid { grid-template-columns: 1fr; }
}

/* GREETING CARD */
.greeting-card {
  display: flex; align-items: flex-start; gap: 14px;
  padding: 16px 18px;
  border-radius: var(--r); border-left: 4px solid;
  margin-bottom: 18px;
  animation: pFade .3s ease;
}
.greeting--holiday { background: #f0fdf4; border-color: #22c55e; }
.greeting--izin { background: #fffbeb; border-color: #f59e0b; }
.greeting--pulang { background: var(--brand-light); border-color: var(--brand-mid); }
.greeting-icon-wrap { font-size: 1.5rem; line-height: 1; flex-shrink: 0; margin-top: 2px; }
.greeting-title { font-size: .9rem; font-weight: 700; color: var(--text); }
.greeting-sub { font-size: .8rem; color: var(--text-2); margin-top: 3px; line-height: 1.5; }

/* TIMELINE */
.timeline {
  display: flex; align-items: center;
  padding: 12px 14px; margin-bottom: 18px;
  background: var(--bg); border: 1px solid var(--border);
  border-radius: var(--r-sm);
  gap: 0;
}
.tl-item { display: flex; align-items: center; gap: 9px; flex: 1; }
.tl-rule { width: 32px; height: 1px; background: var(--border); flex-shrink: 0; }
.tl-dot {
  width: 11px; height: 11px; border-radius: 50%;
  border: 2px solid var(--border); background: var(--surface);
  flex-shrink: 0; transition: border-color .3s, background .3s;
}
.tl-dot--on { border-color: var(--brand-mid); background: var(--brand-mid); }
.tl-text { display: flex; flex-direction: column; }
.tl-lbl { font-size: .66rem; color: var(--text-3); font-weight: 600; }
.tl-val { font-size: .9rem; font-weight: 800; color: var(--text); font-variant-numeric: tabular-nums; }

/* ============================================================
   ACTION BUTTONS
============================================================ */
.action-stack { display: flex; flex-direction: column; gap: 9px; }
.btn-act {
  display: flex; align-items: center; gap: 9px;
  width: 100%; padding: 12px 16px;
  border: none; border-radius: var(--r-sm);
  font-size: .875rem; font-weight: 700; cursor: pointer;
  transition: filter .15s, transform .1s;
}
.btn-act:active:not(:disabled) { transform: scale(.98); }
.btn-act:disabled { background: #f1f5f9 !important; color: #94a3b8 !important; border-color: transparent !important; cursor: not-allowed !important; filter: none !important; }

.btn-act--primary { background: var(--brand-mid); color: #fff; }
.btn-act--primary:hover:not(:disabled) { filter: brightness(1.08); }
.btn-act--orange { background: var(--accent); color: #fff; }
.btn-act--orange:hover:not(:disabled) { filter: brightness(1.08); }
.btn-act--green { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
.btn-act--green:hover:not(:disabled) { background: #dcfce7; }
.btn-act--ghost { background: var(--bg); color: var(--brand-mid); border: 1.5px solid var(--brand-mid); }
.btn-act--ghost:hover:not(:disabled) { background: var(--brand-light); }

/* SAVE */
.btn-save {
  width: 100%; margin-top: 11px;
  display: flex; align-items: center; justify-content: center; gap: 7px;
  padding: 11px;
  background: var(--brand-mid); color: #fff;
  border: none; border-radius: var(--r-sm);
  font-size: .85rem; font-weight: 700; cursor: pointer;
  transition: filter .15s;
}
.btn-save:hover:not(:disabled) { filter: brightness(1.08); }
.btn-save:disabled { background: #e2e8f0; color: #94a3b8; cursor: not-allowed; }

/* ============================================================
   LOGBOOK
============================================================ */
.lb-chip {
  font-size: .7rem; font-weight: 700; padding: 3px 10px; border-radius: 20px;
}
.lb-chip--filled { background: #dcfce7; color: #166534; }
.lb-chip--empty  { background: #fee2e2; color: #991b1b; }
.lb-hint { font-size: .8rem; color: var(--text-2); margin-bottom: 9px; line-height: 1.5; }
.lb-area {
  width: 100%; padding: 11px 13px;
  border: 1.5px solid var(--border); border-radius: var(--r-sm);
  font-size: .85rem; resize: vertical; background: var(--bg);
  color: var(--text); line-height: 1.6;
  transition: border-color .15s, box-shadow .15s;
}
.lb-area:focus { outline: none; border-color: var(--brand-mid); box-shadow: 0 0 0 3px rgba(0,82,156,.1); background: #fff; }
.lb-area:disabled { opacity: .55; cursor: not-allowed; }
.hint-muted { font-size: .72rem; color: var(--text-3); margin-top: 7px; text-align: center; }

/* ============================================================
   TABLE
============================================================ */
.tbl-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.dtbl { width: 100%; border-collapse: collapse; font-size: .84rem; }
.dtbl th {
  background: var(--bg); padding: 9px 13px;
  text-align: left; color: var(--text-2);
  font-size: .68rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .06em;
  border-bottom: 2px solid var(--border);
  white-space: nowrap;
}
.dtbl td { padding: 11px 13px; border-bottom: 1px solid var(--border); }
.dtbl tbody tr:last-child td { border-bottom: none; }
.dtbl tbody tr:hover { background: var(--bg); }
.td-date { font-size: .8rem; color: var(--text-2); white-space: nowrap; }
.td-time { font-family: 'SF Mono','Monaco',monospace; font-weight: 700; color: var(--brand-mid); white-space: nowrap; }
.td-log { max-width: 280px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--text-2); }
.td-empty { text-align: center; padding: 36px; color: var(--text-3); font-size: .85rem; }

.badge { display: inline-block; padding: 3px 9px; border-radius: 20px; font-size: .7rem; font-weight: 700; }
.badge-success { background: #dcfce7; color: #166534; }
.badge-warning { background: #fef3c7; color: #92400e; }
.badge-danger  { background: #fee2e2; color: #991b1b; }

.btn-dl {
  display: flex; align-items: center; gap: 6px;
  padding: 7px 13px;
  background: var(--brand-mid); color: #fff;
  border: none; border-radius: var(--r-sm);
  font-size: .8rem; font-weight: 600; cursor: pointer;
  transition: filter .15s;
}
.btn-dl:hover { filter: brightness(1.1); }
.btn-edit {
  padding: 4px 11px;
  border: 1px solid var(--brand-mid); color: var(--brand-mid);
  background: transparent; border-radius: 6px;
  font-size: .72rem; font-weight: 600; cursor: pointer;
  transition: background .15s, color .15s;
}
.btn-edit:hover { background: var(--brand-mid); color: #fff; }

/* ============================================================
   MODALS / OVERLAYS
============================================================ */
.overlay {
  position: fixed; inset: 0;
  background: rgba(15,23,42,.52);
  display: flex; align-items: center; justify-content: center;
  z-index: 500; backdrop-filter: blur(6px);
  padding: 16px;
}
.overlay--dark { background: rgba(5,10,20,.88); }

.modal-box {
  background: var(--surface);
  border-radius: 16px;
  width: 100%; max-width: 410px;
  box-shadow: var(--sh-md);
  overflow: hidden;
  animation: mIn .22s cubic-bezier(.34,1.56,.64,1);
}
@keyframes mIn { from { opacity:0; transform:scale(.93) translateY(10px) } to { opacity:1; transform:none } }
.modal-box--reg { max-width: 430px; }

.modal-hdr {
  display: flex; align-items: center; justify-content: space-between;
  padding: 18px 20px 0;
}
.modal-ttl { font-size: .95rem; font-weight: 700; }
.modal-x {
  width: 30px; height: 30px; border-radius: 50%;
  border: none; background: var(--bg);
  color: var(--text-2); cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  transition: background .15s;
}
.modal-x:hover { background: var(--border); }
.modal-foot { display: flex; gap: 9px; padding: 0 20px 20px; }

/* REGISTRATION HEADER */
.reg-head { text-align: center; padding: 26px 20px 14px; }
.reg-badge {
  display: inline-block; padding: 3px 11px;
  background: var(--brand-mid); color: #fff;
  border-radius: 5px; font-size: .66rem; font-weight: 800;
  letter-spacing: .1em; margin-bottom: 11px;
}
.reg-title { font-size: 1.15rem; font-weight: 800; }
.reg-sub { font-size: .8rem; color: var(--text-2); margin-top: 5px; line-height: 1.5; }
.reg-foot { padding: 0 20px 8px; }

/* CAMERA */
.cam-select-wrap { padding: 0 20px 10px; }
.field-lbl { display: block; font-size: .72rem; font-weight: 700; color: var(--text-2); margin-bottom: 5px; }
.req { color: #dc2626; }
.field-sel, .field-inp {
  width: 100%; padding: 8px 11px;
  border: 1.5px solid var(--border); border-radius: var(--r-sm);
  font-size: .84rem; color: var(--text); background: var(--bg);
  outline: none; transition: border-color .15s;
}
.field-sel:focus, .field-inp:focus { border-color: var(--brand-mid); box-shadow: 0 0 0 3px rgba(0,82,156,.1); }
.field-note { display: block; font-size: .7rem; color: var(--text-3); margin-top: 4px; }
.field-grp { margin-bottom: 12px; padding: 0 20px; }
textarea.field-inp { resize: vertical; line-height: 1.5; }
.form-body { margin-top: 14px; }

.cam-frame {
  position: relative;
  margin: 0 20px 14px;
  border-radius: 10px; overflow: hidden;
  aspect-ratio: 4/3;
  background: #0a0f1a;
  border: 1.5px solid var(--border);
}
.cam-vid { width: 100%; height: 100%; object-fit: cover; display: block; }
.cam-ovl {
  position: absolute; inset: 0;
  display: flex; align-items: center; justify-content: center;
  pointer-events: none;
}
.cam-guide {
  width: 52%; aspect-ratio: 3/4;
  border: 2px solid rgba(255,255,255,.55);
  border-radius: 50% 50% 50% 50% / 45% 45% 55% 55%;
  box-shadow: 0 0 0 9999px rgba(0,0,0,.32);
}

.btn-capture {
  display: flex; align-items: center; justify-content: center; gap: 9px;
  width: 100%;
  padding: 12px;
  background: var(--brand-mid); color: #fff;
  border: none; border-radius: var(--r-sm);
  font-size: .875rem; font-weight: 700; cursor: pointer;
  transition: filter .15s; margin-bottom: 4px;
}
.btn-capture:hover:not(:disabled) { filter: brightness(1.08); }
.btn-capture:disabled { background: #e2e8f0; color: #94a3b8; cursor: not-allowed; }
.btn-cancel {
  flex: 1; padding: 11px;
  background: var(--bg); color: var(--text-2);
  border: 1px solid var(--border); border-radius: var(--r-sm);
  font-size: .84rem; font-weight: 600; cursor: pointer;
  transition: background .15s;
}
.btn-cancel:hover { background: var(--border); }
.note-muted { text-align: center; font-size: .7rem; color: var(--text-3); padding-bottom: 10px; }

/* SPINNER */
.spin {
  display: inline-block; width: 15px; height: 15px;
  border: 2px solid rgba(255,255,255,.35);
  border-top-color: #fff; border-radius: 50%;
  animation: rot .7s linear infinite; flex-shrink: 0;
}
@keyframes rot { to { transform: rotate(360deg); } }

/* ============================================================
   RESPONSIVE BREAKPOINTS
============================================================ */
/* Tablet portrait (iPad 11" = ~820px) */
@media (max-width: 1024px) {
  .content { padding: 16px; }
  .panel { padding: 18px; }
  .topbar { padding: 0 16px; }
}

/* Mobile */
@media (max-width: 640px) {
  .content { padding: 12px; }
  .panel { padding: 14px; }
  .topbar { height: 50px; padding: 0 12px; }
  .topbar-date { display: none; }
  .topbar-time { font-size: .88rem; }
  .dashboard-grid { gap: 12px; }
  .cam-frame { margin: 0 14px 12px; }
  .cam-select-wrap { padding: 0 14px 8px; }
  .reg-foot { padding: 0 14px 8px; }
  .reg-head { padding: 18px 14px 10px; }
  .field-grp { padding: 0 14px; }
  .modal-hdr { padding: 14px 14px 0; }
  .modal-foot { padding: 0 14px 14px; }
  .timeline { gap: 4px; padding: 10px 11px; }
  .tl-rule { width: 16px; }
  .tl-val { font-size: .82rem; }
}

@media (max-width: 360px) {
  .status-chip { font-size: .68rem; padding: 3px 8px; }
  .btn-act { font-size: .8rem; padding: 10px 12px; }
}
</style>
