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
const toggleSidebar = () => { isSidebarOpen.value = !isSidebarOpen.value }

// STATE ATTENDANCE & LOGBOOK
const todayAttendance = ref(null)
const logbookText = ref('')
const isWeekend = ref(false)
const currentHoliday = ref(null)

// DATA HISTORY
const historyAbsen = ref([])

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
    try { const p = JSON.parse(fd); return Array.isArray(p) && p.length > 0 } catch { return false }
  }
  if (Array.isArray(fd)) return fd.length > 0
  return false
}

// ==========================================
// LOAD MODEL
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
  } catch (e) { console.error('Model gagal:', e) }
  finally { isLoadingModels.value = false }
}

// ==========================================
// KAMERA UTILS
// ==========================================
const stopStream = () => {
  if (streamSaatIni) { streamSaatIni.getTracks().forEach(t => t.stop()); streamSaatIni = null }
}

const startStream = async (videoRef, deviceId) => {
  stopStream()
  try {
    streamSaatIni = await navigator.mediaDevices.getUserMedia({
      video: { deviceId: deviceId ? { exact: deviceId } : undefined, width: { ideal: 640 }, height: { ideal: 480 }, facingMode: deviceId ? undefined : 'user' }
    })
    await nextTick()
    if (videoRef.value) { videoRef.value.srcObject = streamSaatIni; await videoRef.value.play().catch(() => {}) }
    return true
  } catch (e) { console.error('Stream error:', e); return false }
}

const getKameraList = async () => {
  try {
    const tmp = await navigator.mediaDevices.getUserMedia({ video: true })
    tmp.getTracks().forEach(t => t.stop())
    const devs = await navigator.mediaDevices.enumerateDevices()
    listKamera.value = devs.filter(d => d.kind === 'videoinput')
    if (listKamera.value.length > 0 && !kameraTerpilih.value) kameraTerpilih.value = listKamera.value[0].deviceId
    return true
  } catch { return false }
}

const captureFrame = (videoRef) => {
  if (!videoRef.value || videoRef.value.videoWidth === 0) return null
  const c = document.createElement('canvas')
  c.width = videoRef.value.videoWidth; c.height = videoRef.value.videoHeight
  c.getContext('2d').drawImage(videoRef.value, 0, 0)
  return c
}

// ==========================================
// REGISTRASI WAJAH
// ==========================================
const initKameraReg = async () => {
  const ok = await getKameraList()
  if (!ok) { Swal.fire('Error', 'Kamera tidak dapat diakses.', 'error'); return }
  await startStream(videoElementReg, kameraTerpilih.value)
}

const prosesRegistrasiWajah = async () => {
  if (!videoElementReg.value) return
  const canvas = captureFrame(videoElementReg)
  if (!canvas) { Swal.fire('Error', 'Kamera belum siap, coba lagi.', 'warning'); return }

  stopStream()
  showRegistrationModal.value = false

  Swal.fire({
    title: 'Memindai Biometrik',
    html: `<div style="padding:20px 0 8px;text-align:center">
      <div style="width:64px;height:64px;margin:0 auto 14px;border-radius:50%;border:3px solid #e2e8f2;border-top-color:#00529C;animation:sw_spin .9s linear infinite"></div>
      <p style="color:#475569;font-size:0.85rem;margin:0">Menganalisis data biometrik...</p>
    </div>
    <style>@keyframes sw_spin{to{transform:rotate(360deg)}}</style>`,
    allowOutsideClick: false, showConfirmButton: false
  })

  try {
    if (!modelsLoaded.value) {
      Swal.update({ title: 'Memuat AI Model...', html: '<p style="color:#64748b;padding:20px 0 8px">Memuat model AI pengenalan wajah...<br><small style="color:#94a3b8">Hanya terjadi sekali</small></p>' })
      await loadModels()
    }
    const opts = new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.5 })
    const det = await faceapi.detectSingleFace(canvas, opts).withFaceLandmarks().withFaceDescriptor()
    if (!det) throw new Error('Wajah tidak terdeteksi. Pastikan wajah menghadap kamera dengan pencahayaan cukup.')

    Swal.update({ title: 'Menyimpan Data...', html: '<p style="color:#64748b;padding:20px 0 8px">Menyimpan data ke server...</p>' })
    const desc = Array.from(det.descriptor)
    await axios.post('/face-register', { user_id: user.value.id, face_descriptor: desc })
    authStore.user.face_descriptor = JSON.stringify(desc)
    authStore.user.is_active = true
    localStorage.setItem('user', JSON.stringify(authStore.user))

    await Swal.fire({ icon: 'success', title: 'Registrasi Berhasil!', text: 'Wajah Anda telah terdaftar. Selamat menggunakan BRIJISENT.', confirmButtonColor: '#00529C', confirmButtonText: 'Mulai' })
    await fetchTodayData()
  } catch (e) {
    const msg = e.response?.data?.message || e.message || 'Terjadi kesalahan.'
    await Swal.fire({ icon: 'error', title: 'Registrasi Gagal', text: msg, confirmButtonColor: '#00529C' })
    showRegistrationModal.value = true
    await nextTick(); await initKameraReg()
  }
}

// ==========================================
// ABSENSI WAJAH
// ==========================================
const initKamera = async () => {
  const ok = await getKameraList()
  if (!ok) { Swal.fire('Error', 'Kamera tidak dapat diakses.', 'error'); showCameraModal.value = false; return }
  await startStream(videoElement, kameraTerpilih.value)
}

const bukaKamera = async (jenis) => {
  jenisAbsen.value = jenis; showCameraModal.value = true
  await nextTick(); await initKamera()
}

const tutupKamera = () => { stopStream(); showCameraModal.value = false }

const prosesAbsenDariKamera = async () => {
  if (!videoElement.value) return
  const canvas = captureFrame(videoElement)
  if (!canvas) { Swal.fire('Error', 'Kamera belum siap.', 'warning'); return }

  stopStream(); showCameraModal.value = false

  Swal.fire({
    title: 'Memverifikasi Identitas',
    html: `<div style="padding:20px 0 8px;text-align:center">
      <div style="width:64px;height:64px;margin:0 auto 14px;border-radius:50%;border:3px solid #e2e8f2;border-top-color:#00529C;animation:sw_spin .9s linear infinite"></div>
      <p style="color:#475569;font-size:0.85rem;margin:0">Memverifikasi data biometrik...</p>
    </div>
    <style>@keyframes sw_spin{to{transform:rotate(360deg)}}</style>`,
    allowOutsideClick: false, showConfirmButton: false
  })

  try {
    if (!modelsLoaded.value) await loadModels()
    const opts = new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.5 })
    const det = await faceapi.detectSingleFace(canvas, opts).withFaceLandmarks().withFaceDescriptor()
    if (!det) throw new Error('Wajah tidak terdeteksi. Pastikan pencahayaan cukup.')

    const endpoint = jenisAbsen.value === 'masuk' ? '/attendances/clock-in' : '/attendances/clock-out'
    await axios.post(endpoint, { user_id: user.value.id, face_descriptor: Array.from(det.descriptor) })

    await Swal.fire({ icon: 'success', title: jenisAbsen.value === 'masuk' ? 'Selamat Datang! 👋' : 'Sampai Jumpa! 🏡', text: `Absen ${jenisAbsen.value} berhasil dicatat.`, confirmButtonColor: '#00529C', timer: 2500, timerProgressBar: true })
    await fetchTodayData()
  } catch (e) {
    await Swal.fire({ icon: 'error', title: 'Verifikasi Gagal', text: e.response?.data?.message || e.message || 'Terjadi kesalahan.', confirmButtonColor: '#00529C' })
    showCameraModal.value = true; await nextTick(); await initKamera()
  }
}

// ==========================================
// IZIN & TOGGLE STATUS
// ==========================================
const submitIzinForm = async () => {
  if (!formIzin.value.alasan || !formIzin.value.tanggal) return Swal.fire('Peringatan', 'Tanggal dan alasan wajib diisi!', 'warning')
  Swal.fire({ title: 'Mengirim...', allowOutsideClick: false, didOpen: () => Swal.showLoading() })
  try {
    await axios.post('/attendances/permit', { user_id: user.value.id, ...formIzin.value })
    Swal.fire('Terkirim!', 'Izin berhasil diajukan ke HR.', 'success')
    showIzinModal.value = false; formIzin.value = { tanggal: '', alasan: '', bukti: '' }; fetchTodayData()
  } catch (e) { Swal.fire('Gagal', e.response?.data?.message || 'Terjadi kesalahan', 'error') }
}

const toggleStatus = async () => {
  try {
    const r = await axios.post('/attendances/toggle-status', { user_id: user.value.id })
    if (r.data.success) { await fetchTodayData(); Swal.fire('Berhasil', r.data.message, 'success') }
  } catch { Swal.fire('Gagal', 'Gagal mengubah status', 'error') }
}

// ==========================================
// DATA & COMPUTED
// ==========================================
const fetchTodayData = async () => {
  if (!user.value?.id) return
  try {
    const r = await axios.get(`/attendances/today/${user.value.id}`)
    todayAttendance.value = r.data.attendance || null
    logbookText.value = r.data.attendance?.logbook || ''
    isWeekend.value = r.data.is_weekend
    currentHoliday.value = r.data.holiday
  } catch (e) { console.error('Gagal refresh:', e) }
}

const attendanceStatus = computed(() => {
  if (!todayAttendance.value) return 'BELUM ABSEN'
  if (todayAttendance.value.status === 'permit') return 'IZIN TIDAK MASUK'
  if (todayAttendance.value.clock_out) return 'SUDAH PULANG'
  if (todayAttendance.value.office_status === 'keluar_sementara') return 'SEDANG KELUAR'
  if (todayAttendance.value.clock_in) return 'DI KANTOR'
  return 'BELUM ABSEN'
})

const statusCfg = computed(() => {
  const map = {
    'DI KANTOR':        { color: '#065f46', bg: '#d1fae5', border: '#6ee7b7', dot: '#10b981', label: 'DI KANTOR' },
    'SEDANG KELUAR':    { color: '#92400e', bg: '#fef3c7', border: '#fcd34d', dot: '#f59e0b', label: 'SEDANG KELUAR' },
    'SUDAH PULANG':     { color: '#1e40af', bg: '#dbeafe', border: '#93c5fd', dot: '#3b82f6', label: 'SUDAH PULANG' },
    'IZIN TIDAK MASUK': { color: '#5b21b6', bg: '#ede9fe', border: '#c4b5fd', dot: '#7c3aed', label: 'IZIN' },
    'BELUM ABSEN':      { color: '#374151', bg: '#f3f4f6', border: '#d1d5db', dot: '#9ca3af', label: 'BELUM ABSEN' },
  }
  return map[attendanceStatus.value] || map['BELUM ABSEN']
})

const greetingMsg = computed(() => {
  if (currentHoliday.value) return { title: 'Hari Libur 🎉', sub: `Selamat berlibur — ${currentHoliday.value.description}`, type: 'holiday' }
  if (isWeekend.value && !todayAttendance.value) return { title: 'Selamat Weekend! 🏖️', sub: 'Istirahat yang cukup, sampai Senin!', type: 'holiday' }
  if (todayAttendance.value?.status === 'permit') {
    const alasan = todayAttendance.value.permit_reason || todayAttendance.value.logbook || 'Keperluan tertentu'
    return { title: 'Anda Sedang Izin 📋', sub: `Alasan: "${alasan}". Semoga lancar!`, type: 'izin' }
  }
  if (todayAttendance.value?.clock_out) return { title: 'Selamat Pulang 🏡', sub: 'Hati-hati di jalan!', type: 'pulang' }
  return null
})

const canClockIn = computed(() => attendanceStatus.value === 'BELUM ABSEN')
const canClockOut = computed(() => attendanceStatus.value === 'DI KANTOR')

// ==========================================
// LOGBOOK & HISTORY
// ==========================================
const simpanLogbook = async () => {
  if (todayAttendance.value?.status === 'permit') return Swal.fire('Info', 'Sedang izin, tidak perlu logbook!', 'info')
  if (!logbookText.value.trim()) return Swal.fire('Oops', 'Logbook tidak boleh kosong.', 'warning')
  try {
    const r = await axios.post('/attendances/logbook', { user_id: user.value.id, logbook: logbookText.value })
    if (r.data.success) { Swal.fire({ icon: 'success', title: 'Tersimpan!', timer: 1500, showConfirmButton: false }); await fetchTodayData() }
  } catch (e) { Swal.fire('Gagal', e.response?.data?.message || 'Gagal menyimpan logbook.', 'error') }
}

const fetchHistory = async () => {
  if (!user.value?.id) return
  try { const r = await axios.get(`/attendances/history/${user.value.id}`); historyAbsen.value = r.data.data }
  catch (e) { console.error('Gagal riwayat:', e) }
}

const formatTgl = (t) => t ? new Date(t).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '-'
const formatJam = (j) => j || '—'
const labelStatus = (s) => ({ present: 'Hadir', permit: 'Izin', absent: 'Alpa' }[s] || 'Hadir')
const statusCls = (s) => ({ permit: 'badge-warn', absent: 'badge-danger' }[s] || 'badge-ok')

const bukaEditLogbook = async (data) => {
  const { value: text } = await Swal.fire({
    title: 'Edit Logbook', input: 'textarea', inputLabel: `Tanggal: ${formatTgl(data.date)}`,
    inputValue: data.logbook || '', showCancelButton: true,
    confirmButtonColor: '#00529C', confirmButtonText: 'Simpan', cancelButtonText: 'Batal'
  })
  if (text !== undefined) {
    try {
      await axios.post('/attendances/logbook', { user_id: user.value.id, logbook: text, date: data.date })
      Swal.fire({ icon: 'success', title: 'Diperbarui!', timer: 1500, showConfirmButton: false }); fetchHistory()
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
  isSidebarOpen.value = w > 1024
}

const unduhLaporan = () => window.open(`/attendances/download/${user.value.id}`, '_blank')

// ==========================================
// WATCHER & LIFECYCLE
// ==========================================
watch(() => user.value.id, async (id) => {
  if (!id) return
  try {
    const r = await axios.get(`/user/${id}`)
    authStore.user = { ...authStore.user, ...r.data.user }
    localStorage.setItem('user', JSON.stringify(authStore.user))
  } catch (e) { console.warn('Sinkronisasi gagal, pakai data lokal:', e.message) }

  if (!hasFaceDescriptor(authStore.user)) {
    showRegistrationModal.value = true; await nextTick(); await initKameraReg()
  } else { await fetchTodayData() }
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
  <div class="shell">

    <!-- Overlay mobile/tablet -->
    <transition name="fade-overlay">
      <div v-if="isSidebarOpen && (isMobile || isTablet)" class="overlay-dim" @click="toggleSidebar"></div>
    </transition>

    <!-- ===== SIDEBAR ===== -->
    <aside class="sidebar" :class="{ 'sidebar-visible': isSidebarOpen }">
      <!-- Brand -->
      <div class="sb-brand">
        <img src="/LOGO.png" alt="BRI" class="sb-logo" onerror="this.style.display='none'" />
        <span class="sb-title">BRI<span class="sb-orange">JISENT</span></span>
      </div>

      <!-- User -->
      <div class="sb-user">
        <div class="sb-avatar">{{ user.name ? user.name.charAt(0).toUpperCase() : 'U' }}</div>
        <div class="sb-userinfo">
          <span class="sb-hi">Selamat datang,</span>
          <span class="sb-name">{{ user.name || 'Intern' }}</span>
        </div>
      </div>

      <!-- Nav -->
      <nav class="sb-nav">
        <button class="nav-btn" :class="{ 'nav-btn-active': activeMenu === 'beranda' }" @click="switchMenu('beranda')">
          <svg class="nav-ico" fill="none" viewBox="0 0 24 24"><path d="M3 12L12 3l9 9M5 10v9a1 1 0 001 1h4v-5h4v5h4a1 1 0 001-1v-9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
          Beranda
        </button>
        <button class="nav-btn" :class="{ 'nav-btn-active': activeMenu === 'history_absen' }" @click="switchMenu('history_absen')">
          <svg class="nav-ico" fill="none" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="M16 2v4M8 2v4M3 10h18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
          Riwayat Kehadiran
        </button>
        <button class="nav-btn" :class="{ 'nav-btn-active': activeMenu === 'history_logbook' }" @click="switchMenu('history_logbook')">
          <svg class="nav-ico" fill="none" viewBox="0 0 24 24"><path d="M9 12h6M9 8h6M9 16h4M5 3h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
          Riwayat Logbook
        </button>
      </nav>

      <!-- Logout -->
      <div class="sb-foot">
        <button @click="authStore.logout()" class="btn-logout">
          <svg width="15" height="15" fill="none" viewBox="0 0 24 24"><path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-6 0v-1m0-8V7a3 3 0 016 0v1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
          Keluar Sistem
        </button>
      </div>
    </aside>

    <!-- ===== MAIN ===== -->
    <main class="main-area">

      <!-- TOPBAR -->
      <header class="topbar">
        <button class="hamburger" @click="toggleSidebar">
          <svg width="20" height="20" fill="none" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        </button>
        <div class="topbar-right">
          <span class="tb-date">{{ currentTime.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) }}</span>
          <span class="tb-time">{{ currentTime.toLocaleTimeString('id-ID') }} <em>WIB</em></span>
        </div>
      </header>

      <!-- PAGE CONTENT -->
      <div class="page-wrap">

        <!-- ===== BERANDA ===== -->
        <div v-if="activeMenu === 'beranda'" class="anim-in">

          <!-- GREETING BANNER -->
          <div v-if="greetingMsg" class="greeting-bar" :class="`gb-${greetingMsg.type}`">
            <span class="gb-ico">{{ greetingMsg.type === 'holiday' ? '🌴' : greetingMsg.type === 'izin' ? '📋' : '🏡' }}</span>
            <div>
              <p class="gb-title">{{ greetingMsg.title }}</p>
              <p class="gb-sub">{{ greetingMsg.sub }}</p>
            </div>
          </div>

          <!-- DASHBOARD 2-COL GRID -->
          <div class="dash-grid">

            <!-- PANEL KEHADIRAN -->
            <div class="card">
              <div class="card-top">
                <span class="card-label">STATUS KEHADIRAN</span>
                <!-- Status Chip -->
                <div class="status-chip" :style="`color:${statusCfg.color};background:${statusCfg.bg};border:1.5px solid ${statusCfg.border}`">
                  <span class="sdot" :style="`background:${statusCfg.dot}`"></span>
                  {{ statusCfg.label }}
                </div>
              </div>

              <!-- Timeline jam -->
              <div class="timeline" v-if="todayAttendance && todayAttendance.status !== 'permit'">
                <div class="tl-block">
                  <div class="tl-dot" :class="{ 'tl-dot-on': todayAttendance?.clock_in }"></div>
                  <div class="tl-info">
                    <span class="tl-lbl">Jam Masuk</span>
                    <span class="tl-val">{{ formatJam(todayAttendance?.clock_in) }}</span>
                  </div>
                </div>
                <div class="tl-line"></div>
                <div class="tl-block">
                  <div class="tl-dot" :class="{ 'tl-dot-on': todayAttendance?.clock_out }"></div>
                  <div class="tl-info">
                    <span class="tl-lbl">Jam Pulang</span>
                    <span class="tl-val">{{ formatJam(todayAttendance?.clock_out) }}</span>
                  </div>
                </div>
              </div>

              <!-- ACTION BUTTONS -->
              <div class="action-col">
                <!-- Absen Masuk -->
                <button v-if="canClockIn" @click="bukaKamera('masuk')" class="abtn abtn-blue">
                  <svg width="17" height="17" fill="none" viewBox="0 0 24 24"><path d="M15 10l4.553-2.069A1 1 0 0121 8.87v6.26a1 1 0 01-1.447.9L15 14M3 8a2 2 0 012-2h10a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z" stroke="currentColor" stroke-width="1.8"/></svg>
                  Absen Masuk
                </button>

                <!-- Absen Pulang -->
                <button v-if="!canClockIn && attendanceStatus !== 'IZIN TIDAK MASUK'" @click="bukaKamera('keluar')" :disabled="!canClockOut" class="abtn" :class="canClockOut ? 'abtn-orange' : 'abtn-disabled'">
                  <svg width="17" height="17" fill="none" viewBox="0 0 24 24"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                  {{ attendanceStatus === 'SUDAH PULANG' ? 'Sudah Pulang' : 'Absen Pulang' }}
                </button>

                <!-- Toggle keluar/kembali -->
                <button v-if="todayAttendance?.id && !todayAttendance.clock_out" @click="toggleStatus" class="abtn" :class="attendanceStatus === 'SEDANG KELUAR' ? 'abtn-green' : 'abtn-green'">
                  <svg width="17" height="17" fill="none" viewBox="0 0 24 24"><path d="M8 9l4-4 4 4M16 15l-4 4-4-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                  {{ attendanceStatus === 'SEDANG KELUAR' ? 'Kembali ke Kantor' : 'Izin Keluar Sebentar' }}
                </button>

                <!-- Ajukan Izin -->
                <button v-if="!todayAttendance?.id" @click="showIzinModal = true" class="abtn abtn-ghost">
                  <svg width="17" height="17" fill="none" viewBox="0 0 24 24"><path d="M9 12h6m-3-3v6m9-3a9 9 0 11-18 0 9 9 0 0118 0z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                  Ajukan Izin Tidak Masuk
                </button>
              </div>
            </div>

            <!-- PANEL LOGBOOK -->
            <div class="card">
              <div class="card-top">
                <span class="card-label">LOGBOOK HARIAN</span>
                <span class="lb-chip" :class="logbookText.trim() ? 'lbc-ok' : 'lbc-no'">
                  {{ logbookText.trim() ? '✓ Terisi' : '○ Kosong' }}
                </span>
              </div>
              <p class="lb-hint">Catat progress kerja, pencapaian, atau kendala hari ini.</p>
              <textarea
                v-model="logbookText"
                class="lb-area"
                rows="7"
                :placeholder="todayAttendance?.status === 'permit' ? 'Sedang izin — tidak perlu mengisi logbook.' : 'Contoh: Meeting tim, debugging API, review kode...'"
                :disabled="!todayAttendance?.id || todayAttendance?.status === 'permit'"
              ></textarea>
              <button @click="simpanLogbook" class="btn-save-lb" :disabled="!todayAttendance?.id || !logbookText.trim() || todayAttendance?.status === 'permit'">
                <svg width="15" height="15" fill="none" viewBox="0 0 24 24"><path d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                Simpan Laporan
              </button>
              <p v-if="!todayAttendance?.id" class="hint-sm">Absen masuk terlebih dahulu untuk mengisi logbook.</p>
            </div>

          </div>
        </div>

        <!-- ===== RIWAYAT KEHADIRAN ===== -->
        <div v-if="activeMenu === 'history_absen'" class="anim-in card">
          <div class="card-top" style="margin-bottom:16px">
            <span class="card-label">RIWAYAT KEHADIRAN</span>
            <button @click="unduhLaporan" class="btn-dl">
              <svg width="14" height="14" fill="none" viewBox="0 0 24 24"><path d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
              Unduh CSV
            </button>
          </div>
          <div class="tbl-scroll">
            <table class="dtbl">
              <thead><tr><th>Tanggal</th><th>Status</th><th>Masuk</th><th>Pulang</th></tr></thead>
              <tbody>
                <tr v-for="a in historyAbsen" :key="a.id">
                  <td class="td-dt">{{ formatTgl(a.date) }}</td>
                  <td><span class="badge" :class="statusCls(a.status)">{{ labelStatus(a.status) }}</span></td>
                  <td class="td-tm">{{ formatJam(a.clock_in) }}</td>
                  <td class="td-tm">{{ formatJam(a.clock_out) }}</td>
                </tr>
                <tr v-if="historyAbsen.length === 0"><td colspan="4" class="td-empty">Belum ada riwayat.</td></tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- ===== RIWAYAT LOGBOOK ===== -->
        <div v-if="activeMenu === 'history_logbook'" class="anim-in card">
          <div class="card-top" style="margin-bottom:16px">
            <span class="card-label">RIWAYAT LOGBOOK</span>
          </div>
          <div class="tbl-scroll">
            <table class="dtbl">
              <thead><tr><th style="width:130px">Tanggal</th><th>Catatan</th><th style="width:70px;text-align:center">Aksi</th></tr></thead>
              <tbody>
                <tr v-for="l in historyAbsen" :key="'lb'+l.id">
                  <td class="td-dt">{{ formatTgl(l.date) }}</td>
                  <td class="td-log">{{ l.logbook || '—' }}</td>
                  <td style="text-align:center"><button class="btn-edit" @click="bukaEditLogbook(l)">Edit</button></td>
                </tr>
                <tr v-if="historyAbsen.length === 0"><td colspan="3" class="td-empty">Belum ada data logbook.</td></tr>
              </tbody>
            </table>
          </div>
        </div>

      </div><!-- /page-wrap -->
    </main>

    <!-- ============================================================
         MODAL: REGISTRASI WAJAH
    ============================================================ -->
    <transition name="modal-pop">
      <div v-if="showRegistrationModal" class="modal-bg modal-bg-dark">
        <div class="modal-box">
          <!-- Header -->
          <div class="reg-head">
            <div class="reg-badge-pill">BRIJISENT</div>
            <h2 class="reg-h">Registrasi Biometrik</h2>
            <p class="reg-sub">Daftarkan wajah Anda untuk mengaktifkan akses sistem kehadiran.</p>
          </div>
          <!-- Kamera select -->
          <div class="modal-pad">
            <label class="flbl">Pilih Kamera</label>
            <select v-model="kameraTerpilih" @change="startStream(videoElementReg, kameraTerpilih)" class="fsel">
              <option v-for="(c,i) in listKamera" :key="c.deviceId" :value="c.deviceId">{{ c.label || `Kamera ${i+1}` }}</option>
            </select>
          </div>
          <!-- Video -->
          <div class="cam-wrap">
            <video ref="videoElementReg" autoplay playsinline muted class="cam-vid"></video>
            <div class="cam-guide-ring"></div>
          </div>
          <!-- CTA -->
          <div class="modal-pad" style="padding-bottom:20px">
            <button @click="prosesRegistrasiWajah" class="btn-capture" :disabled="isLoadingModels">
              <span v-if="isLoadingModels" class="mini-spin"></span>
              <svg v-else width="16" height="16" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="2"/><path d="M3 9a2 2 0 012-2h.5L7 5h10l1.5 2H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" stroke="currentColor" stroke-width="1.8"/></svg>
              {{ isLoadingModels ? 'Memuat Model AI...' : 'Ambil & Daftarkan Wajah' }}
            </button>
            <p v-if="isLoadingModels" class="note-gray">Model AI dimuat di latar belakang — hanya terjadi sekali.</p>
          </div>
        </div>
      </div>
    </transition>

    <!-- ============================================================
         MODAL: KAMERA ABSENSI
    ============================================================ -->
    <transition name="modal-pop">
      <div v-if="showCameraModal" class="modal-bg">
        <div class="modal-box">
          <div class="modal-hdr">
            <h3 class="modal-ttl">{{ jenisAbsen === 'masuk' ? '📸 Absen Masuk' : '🏠 Absen Pulang' }}</h3>
            <button class="btn-x" @click="tutupKamera">
              <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </button>
          </div>
          <div class="modal-pad">
            <label class="flbl">Pilih Kamera</label>
            <select v-model="kameraTerpilih" @change="startStream(videoElement, kameraTerpilih)" class="fsel">
              <option v-for="(c,i) in listKamera" :key="c.deviceId" :value="c.deviceId">{{ c.label || `Kamera ${i+1}` }}</option>
            </select>
          </div>
          <div class="cam-wrap">
            <video ref="videoElement" autoplay playsinline muted class="cam-vid"></video>
            <div class="cam-guide-ring"></div>
          </div>
          <div class="modal-foot">
            <button @click="tutupKamera" class="btn-cancel">Batal</button>
            <button @click="prosesAbsenDariKamera" class="btn-capture" style="flex:2">
              <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="2"/><path d="M3 9a2 2 0 012-2h.5L7 5h10l1.5 2H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" stroke="currentColor" stroke-width="1.8"/></svg>
              Verifikasi Wajah
            </button>
          </div>
        </div>
      </div>
    </transition>

    <!-- ============================================================
         MODAL: FORM IZIN
    ============================================================ -->
    <transition name="modal-pop">
      <div v-if="showIzinModal" class="modal-bg">
        <div class="modal-box">
          <div class="modal-hdr">
            <h3 class="modal-ttl">📋 Pengajuan Izin</h3>
            <button class="btn-x" @click="showIzinModal = false">
              <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </button>
          </div>
          <form @submit.prevent="submitIzinForm" class="form-body">
            <div class="fgrp">
              <label class="flbl">Tanggal Izin <span class="req">*</span></label>
              <input type="date" v-model="formIzin.tanggal" required class="finp" />
            </div>
            <div class="fgrp">
              <label class="flbl">Alasan Izin <span class="req">*</span></label>
              <textarea v-model="formIzin.alasan" rows="3" placeholder="Contoh: Sakit, keperluan keluarga..." required class="finp"></textarea>
            </div>
            <div class="fgrp">
              <label class="flbl">Link Bukti (Google Drive)</label>
              <input type="url" v-model="formIzin.bukti" placeholder="https://drive.google.com/..." class="finp" />
              <span class="fnote">Atur akses ke "Anyone with the link"</span>
            </div>
            <div class="modal-foot">
              <button type="button" @click="showIzinModal = false" class="btn-cancel">Batal</button>
              <button type="submit" class="btn-capture" style="flex:2">Kirim Pengajuan</button>
            </div>
          </form>
        </div>
      </div>
    </transition>

  </div>
</template>

<style scoped>
/* ============================================================
   RESET & BASE
============================================================ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
button, input, textarea, select { font-family: 'Inter', 'Helvetica Neue', Arial, sans-serif; }

/* ============================================================
   SHELL — main layout
============================================================ */
.shell {
  display: flex;
  height: 100dvh;
  background: #f0f4f9;
  font-family: 'Inter', 'Helvetica Neue', Arial, sans-serif;
  color: #111827;
  overflow: hidden;
}

/* ============================================================
   OVERLAY DIM (mobile sidebar backdrop)
============================================================ */
.overlay-dim {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.45);
  z-index: 199;
  backdrop-filter: blur(3px);
}
.fade-overlay-enter-active, .fade-overlay-leave-active { transition: opacity .25s; }
.fade-overlay-enter-from, .fade-overlay-leave-to { opacity: 0; }

/* ============================================================
   SIDEBAR
============================================================ */
.sidebar {
  width: 250px;
  min-width: 250px;
  flex-shrink: 0;
  background: #ffffff;
  border-right: 1px solid #e2e8f0;
  display: flex;
  flex-direction: column;
  z-index: 200;
  transition: transform .28s cubic-bezier(.4,0,.2,1);
}

/* On ≤1024px: sidebar slides in from left */
@media (max-width: 1024px) {
  .sidebar {
    position: fixed;
    top: 0; left: 0; bottom: 0;
    transform: translateX(-100%);
    box-shadow: 4px 0 20px rgba(0,0,0,.12);
  }
  .sidebar-visible { transform: translateX(0) !important; }
}

/* On >1024px: sidebar always visible */
@media (min-width: 1025px) {
  .sidebar { transform: translateX(0) !important; }
  .sidebar:not(.sidebar-visible) { transform: translateX(-100%); }
  .sidebar-visible { transform: translateX(0); }
}

/* BRAND */
.sb-brand {
  display: flex; align-items: center; gap: 10px;
  padding: 20px 18px 16px;
  border-bottom: 1px solid #e2e8f0;
}
.sb-logo { width: 32px; height: 32px; object-fit: contain; }
.sb-title { font-size: 1.1rem; font-weight: 800; color: #00529C; letter-spacing: .3px; }
.sb-orange { color: #F37021; }

/* USER */
.sb-user {
  display: flex; align-items: center; gap: 11px;
  padding: 14px 18px;
  border-bottom: 1px solid #e2e8f0;
  background: #e8f1fb;
}
.sb-avatar {
  width: 38px; height: 38px; border-radius: 50%;
  background: #00529C; color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: .95rem; font-weight: 800; flex-shrink: 0;
}
.sb-userinfo { display: flex; flex-direction: column; min-width: 0; }
.sb-hi { font-size: .68rem; color: #64748b; }
.sb-name { font-size: .88rem; font-weight: 700; color: #003f8a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* NAV */
.sb-nav { flex: 1; padding: 12px 10px; display: flex; flex-direction: column; gap: 3px; overflow-y: auto; }
.nav-btn {
  display: flex; align-items: center; gap: 10px;
  padding: 11px 13px; width: 100%;
  border: none; background: transparent; border-radius: 8px;
  color: #64748b; font-size: .85rem; font-weight: 600;
  cursor: pointer; text-align: left;
  transition: background .15s, color .15s;
}
.nav-btn:hover { background: #f0f4f9; color: #00529C; }
.nav-btn-active { background: #e8f1fb !important; color: #00529C !important; }
.nav-ico { width: 17px; height: 17px; flex-shrink: 0; }

/* LOGOUT */
.sb-foot { padding: 14px; border-top: 1px solid #e2e8f0; }
.btn-logout {
  width: 100%; padding: 10px 14px;
  display: flex; align-items: center; justify-content: center; gap: 7px;
  background: #fff5f5; color: #dc2626;
  border: 1px solid #fecaca; border-radius: 8px;
  font-size: .83rem; font-weight: 600; cursor: pointer;
  transition: background .15s;
}
.btn-logout:hover { background: #fee2e2; }

/* ============================================================
   MAIN AREA
============================================================ */
.main-area {
  flex: 1;
  display: flex; flex-direction: column;
  overflow: hidden; min-width: 0;
}

/* TOPBAR */
.topbar {
  display: flex; align-items: center; gap: 14px;
  padding: 0 22px; height: 58px;
  background: #ffffff;
  border-bottom: 1px solid #e2e8f0;
  flex-shrink: 0;
}
.hamburger {
  display: flex; align-items: center; justify-content: center;
  width: 36px; height: 36px;
  border: none; background: #f0f4f9; border-radius: 8px;
  color: #64748b; cursor: pointer; flex-shrink: 0;
  transition: background .15s;
}
.hamburger:hover { background: #e2e8f0; }
.topbar-right { margin-left: auto; text-align: right; }
.tb-date { display: block; font-size: .72rem; color: #94a3b8; }
.tb-time { font-size: 1rem; font-weight: 800; color: #00529C; }
.tb-time em { font-style: normal; font-size: .7rem; font-weight: 500; color: #94a3b8; margin-left: 2px; }

/* PAGE WRAP */
.page-wrap { flex: 1; overflow-y: auto; padding: 22px; }

.anim-in { animation: animIn .3s ease both; }
@keyframes animIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }

/* ============================================================
   CARDS / PANELS
============================================================ */
.card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  padding: 22px;
  box-shadow: 0 1px 3px rgba(0,0,0,.05), 0 4px 16px rgba(0,0,0,.04);
}

.card-top {
  display: flex; align-items: center; justify-content: space-between;
  gap: 10px; margin-bottom: 18px; flex-wrap: wrap;
}
.card-label {
  font-size: .67rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .09em;
  color: #9ca3af;
}

/* STATUS CHIP */
.status-chip {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 5px 12px; border-radius: 20px;
  font-size: .76rem; font-weight: 700;
}
.sdot {
  width: 7px; height: 7px; border-radius: 50%;
  animation: sdot-pulse 2s ease infinite;
}
@keyframes sdot-pulse { 0%,100%{opacity:1} 50%{opacity:.4} }

/* GREETING BANNER */
.greeting-bar {
  display: flex; align-items: flex-start; gap: 14px;
  padding: 15px 18px; border-radius: 12px; border-left: 4px solid;
  margin-bottom: 18px; animation: animIn .3s ease;
}
.gb-holiday { background: #f0fdf4; border-color: #22c55e; }
.gb-izin    { background: #fffbeb; border-color: #f59e0b; }
.gb-pulang  { background: #e8f1fb; border-color: #00529C; }
.gb-ico { font-size: 1.4rem; flex-shrink: 0; margin-top: 1px; }
.gb-title { font-size: .9rem; font-weight: 700; color: #111827; }
.gb-sub   { font-size: .8rem; color: #64748b; margin-top: 3px; line-height: 1.5; }

/* DASHBOARD 2-COL GRID */
.dash-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 18px;
}
/* iPad & below: stack columns */
@media (max-width: 1024px) {
  .dash-grid { grid-template-columns: 1fr; }
}

/* TIMELINE */
.timeline {
  display: flex; align-items: center;
  background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px;
  padding: 12px 16px; margin-bottom: 18px; gap: 0;
}
.tl-block { display: flex; align-items: center; gap: 9px; flex: 1; }
.tl-line  { width: 28px; height: 1px; background: #e2e8f0; flex-shrink: 0; }
.tl-dot {
  width: 11px; height: 11px; border-radius: 50%;
  border: 2px solid #e2e8f0; background: #fff;
  flex-shrink: 0; transition: all .25s;
}
.tl-dot-on { border-color: #00529C; background: #00529C; }
.tl-info { display: flex; flex-direction: column; }
.tl-lbl { font-size: .66rem; color: #9ca3af; font-weight: 600; }
.tl-val { font-size: .9rem; font-weight: 800; color: #111827; font-variant-numeric: tabular-nums; }

/* ============================================================
   ACTION BUTTONS
============================================================ */
.action-col { display: flex; flex-direction: column; gap: 10px; }
.abtn {
  display: flex; align-items: center; gap: 10px;
  width: 100%; padding: 13px 16px;
  border: none; border-radius: 9px;
  font-size: .875rem; font-weight: 700; cursor: pointer;
  transition: filter .15s, transform .1s;
}
.abtn:active:not(:disabled) { transform: scale(.98); }
.abtn:disabled, .abtn-disabled { background: #f1f5f9 !important; color: #9ca3af !important; cursor: not-allowed !important; }

.abtn-blue   { background: #00529C; color: #ffffff; }
.abtn-blue:hover   { filter: brightness(1.1); }
.abtn-orange { background: #F37021; color: #ffffff; }
.abtn-orange:hover { filter: brightness(1.1); }
.abtn-green  { background: #f0fdf4; color: #16a34a; border: 1.5px solid #bbf7d0; }
.abtn-green:hover  { background: #dcfce7; }
.abtn-ghost  { background: #f0f4f9; color: #00529C; border: 1.5px solid #00529C; }
.abtn-ghost:hover  { background: #e8f1fb; }

/* LOGBOOK */
.lb-chip { font-size: .71rem; font-weight: 700; padding: 3px 10px; border-radius: 20px; }
.lbc-ok  { background: #dcfce7; color: #166534; }
.lbc-no  { background: #fee2e2; color: #991b1b; }
.lb-hint { font-size: .8rem; color: #64748b; margin-bottom: 10px; line-height: 1.5; }
.lb-area {
  width: 100%; padding: 11px 13px;
  border: 1.5px solid #e2e8f0; border-radius: 9px;
  font-size: .85rem; resize: vertical; background: #f8fafc;
  color: #111827; line-height: 1.6;
  transition: border-color .15s, box-shadow .15s;
}
.lb-area:focus { outline: none; border-color: #00529C; box-shadow: 0 0 0 3px rgba(0,82,156,.1); background: #fff; }
.lb-area:disabled { opacity: .55; cursor: not-allowed; }

.btn-save-lb {
  width: 100%; margin-top: 11px; padding: 12px;
  display: flex; align-items: center; justify-content: center; gap: 8px;
  background: #00529C; color: #fff;
  border: none; border-radius: 9px;
  font-size: .875rem; font-weight: 700; cursor: pointer;
  transition: filter .15s;
}
.btn-save-lb:hover:not(:disabled) { filter: brightness(1.1); }
.btn-save-lb:disabled { background: #e2e8f0; color: #9ca3af; cursor: not-allowed; }
.hint-sm { font-size: .72rem; color: #9ca3af; margin-top: 8px; text-align: center; }

/* ============================================================
   TABLE
============================================================ */
.tbl-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.dtbl { width: 100%; border-collapse: collapse; font-size: .84rem; }
.dtbl th {
  background: #f8fafc; padding: 9px 13px;
  text-align: left; color: #64748b;
  font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em;
  border-bottom: 2px solid #e2e8f0; white-space: nowrap;
}
.dtbl td { padding: 11px 13px; border-bottom: 1px solid #f1f5f9; }
.dtbl tbody tr:last-child td { border-bottom: none; }
.dtbl tbody tr:hover { background: #f8fafc; }
.td-dt   { font-size: .8rem; color: #64748b; white-space: nowrap; }
.td-tm   { font-family: 'SF Mono','Monaco',monospace; font-weight: 700; color: #00529C; white-space: nowrap; }
.td-log  { max-width: 280px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #64748b; }
.td-empty { text-align: center; padding: 36px; color: #9ca3af; font-size: .85rem; }

.badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: .71rem; font-weight: 700; }
.badge-ok     { background: #dcfce7; color: #166534; }
.badge-warn   { background: #fef3c7; color: #92400e; }
.badge-danger { background: #fee2e2; color: #991b1b; }

.btn-dl {
  display: flex; align-items: center; gap: 6px;
  padding: 7px 13px; background: #00529C; color: #fff;
  border: none; border-radius: 8px; font-size: .8rem; font-weight: 600;
  cursor: pointer; transition: filter .15s;
}
.btn-dl:hover { filter: brightness(1.1); }

.btn-edit {
  padding: 4px 11px;
  border: 1px solid #00529C; color: #00529C;
  background: transparent; border-radius: 6px;
  font-size: .72rem; font-weight: 600; cursor: pointer;
  transition: background .15s, color .15s;
}
.btn-edit:hover { background: #00529C; color: #fff; }

/* ============================================================
   MODALS
============================================================ */
.modal-bg {
  position: fixed; inset: 0;
  background: rgba(15,23,42,.55);
  display: flex; align-items: center; justify-content: center;
  z-index: 500; backdrop-filter: blur(6px); padding: 16px;
}
.modal-bg-dark { background: rgba(5,10,20,.88); }

.modal-box {
  background: #ffffff;
  border-radius: 16px;
  width: 100%; max-width: 420px;
  box-shadow: 0 24px 48px rgba(0,0,0,.18);
  overflow: hidden;
}

/* modal animation */
.modal-pop-enter-active { animation: modalIn .22s cubic-bezier(.34,1.56,.64,1); }
.modal-pop-leave-active { animation: modalIn .18s cubic-bezier(.4,0,1,1) reverse; }
@keyframes modalIn { from { opacity:0; transform:scale(.93) translateY(10px); } to { opacity:1; transform:none; } }

/* REG HEADER */
.reg-head { text-align: center; padding: 26px 20px 14px; }
.reg-badge-pill {
  display: inline-block; padding: 3px 12px;
  background: #00529C; color: #fff;
  border-radius: 5px; font-size: .66rem; font-weight: 800; letter-spacing: .1em;
  margin-bottom: 11px;
}
.reg-h   { font-size: 1.15rem; font-weight: 800; color: #111827; }
.reg-sub { font-size: .8rem; color: #64748b; margin-top: 5px; line-height: 1.5; }

/* MODAL HEADER */
.modal-hdr {
  display: flex; align-items: center; justify-content: space-between;
  padding: 18px 20px 0;
}
.modal-ttl { font-size: .95rem; font-weight: 700; }
.btn-x {
  width: 30px; height: 30px; border-radius: 50%;
  border: none; background: #f0f4f9; color: #64748b;
  cursor: pointer; display: flex; align-items: center; justify-content: center;
  transition: background .15s;
}
.btn-x:hover { background: #e2e8f0; }

.modal-pad { padding: 12px 20px; }
.modal-foot { display: flex; gap: 9px; padding: 12px 20px 20px; }

/* FORM */
.form-body { padding-top: 12px; }
.fgrp { padding: 0 20px; margin-bottom: 13px; }
.flbl { display: block; font-size: .72rem; font-weight: 700; color: #64748b; margin-bottom: 5px; }
.req  { color: #dc2626; }
.fsel, .finp {
  width: 100%; padding: 9px 12px;
  border: 1.5px solid #e2e8f0; border-radius: 8px;
  font-size: .84rem; color: #111827; background: #f8fafc;
  outline: none; transition: border-color .15s;
}
.fsel:focus, .finp:focus { border-color: #00529C; box-shadow: 0 0 0 3px rgba(0,82,156,.1); }
textarea.finp { resize: vertical; line-height: 1.5; }
.fnote { display: block; font-size: .7rem; color: #9ca3af; margin-top: 4px; }

/* CAMERA */
.cam-wrap {
  position: relative;
  margin: 0 20px 14px;
  border-radius: 12px; overflow: hidden;
  aspect-ratio: 4/3;
  background: #0a0f1a;
  border: 1.5px solid #e2e8f0;
}
.cam-vid { width: 100%; height: 100%; object-fit: cover; display: block; }
.cam-guide-ring {
  position: absolute; inset: 0;
  display: flex; align-items: center; justify-content: center;
  pointer-events: none;
}
.cam-guide-ring::after {
  content: '';
  width: 52%; aspect-ratio: 3/4;
  border: 2px solid rgba(255,255,255,.55);
  border-radius: 50% 50% 50% 50% / 45% 45% 55% 55%;
  box-shadow: 0 0 0 9999px rgba(0,0,0,.3);
}

/* CAPTURE BUTTON */
.btn-capture {
  display: flex; align-items: center; justify-content: center; gap: 9px;
  width: 100%; padding: 13px;
  background: #00529C; color: #fff;
  border: none; border-radius: 9px;
  font-size: .9rem; font-weight: 700; cursor: pointer;
  transition: filter .15s; margin-bottom: 6px;
}
.btn-capture:hover:not(:disabled) { filter: brightness(1.1); }
.btn-capture:disabled { background: #e2e8f0; color: #9ca3af; cursor: not-allowed; }

.btn-cancel {
  flex: 1; padding: 12px;
  background: #f0f4f9; color: #64748b;
  border: 1px solid #e2e8f0; border-radius: 9px;
  font-size: .875rem; font-weight: 600; cursor: pointer;
  transition: background .15s;
}
.btn-cancel:hover { background: #e2e8f0; }

.note-gray { text-align: center; font-size: .7rem; color: #9ca3af; padding-bottom: 6px; }

/* SPINNER */
.mini-spin {
  display: inline-block; width: 15px; height: 15px; flex-shrink: 0;
  border: 2.5px solid rgba(255,255,255,.3); border-top-color: #fff;
  border-radius: 50%; animation: mspin .7s linear infinite;
}
@keyframes mspin { to { transform: rotate(360deg); } }

/* ============================================================
   RESPONSIVE
============================================================ */
@media (max-width: 1024px) {
  .page-wrap { padding: 16px; }
  .card { padding: 18px; }
  .topbar { padding: 0 16px; }
}

@media (max-width: 640px) {
  .page-wrap { padding: 12px; }
  .card { padding: 14px; }
  .topbar { height: 50px; padding: 0 12px; }
  .tb-date { display: none; }
  .tb-time { font-size: .88rem; }
  .dash-grid { gap: 12px; }
  .cam-wrap { margin: 0 14px 12px; }
  .modal-pad { padding: 10px 14px; }
  .modal-foot { padding: 10px 14px 16px; }
  .reg-head { padding: 18px 14px 10px; }
  .fgrp { padding: 0 14px; }
  .modal-hdr { padding: 14px 14px 0; }
  .btn-capture { font-size: .82rem; }
}
</style>
