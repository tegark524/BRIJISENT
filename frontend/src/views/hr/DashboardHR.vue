<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useAuthStore } from '../../stores/authStore'
import axios from 'axios'
import Swal from 'sweetalert2'

const authStore = useAuthStore()
const activeTab = ref('dashboard')

// --- DATA STATES ---
const summary = ref({ total_interns: 0, present_today: 0 })
const internList = ref([]) 
const interns = ref([])    
const hrList = ref([])      

// Realtime & Holiday States
const realtimeInterns = ref([])
const isTodayHoliday = ref(false)
const holidayInfo = ref({ title: '', desc: '' })

// History & Settings States
const allHistoryData = ref([])
const attendanceSettings = ref({ start_time: '07:00', late_threshold: '07:30', end_time: '17:00' })
const holidays = ref([])
const newHoliday = ref({ date: '', desc: '' })

// Modal States
const showModal = ref(false)
const isEditMode = ref(false)
const form = ref({ id: null, name: '', email: '', phone: '', password: '' })
const showLogbookModal = ref(false)
const selectedLogbookText = ref('')

// Calendar States
const isCalendarEditMode = ref(false)
const currentDate = ref(new Date())
const monthNames = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"]

const currentMonthName = computed(() => monthNames[currentDate.value.getMonth()])
const currentYear = computed(() => currentDate.value.getFullYear())

const prevMonth = () => { currentDate.value = new Date(currentYear.value, currentDate.value.getMonth() - 1, 1) }
const nextMonth = () => { currentDate.value = new Date(currentYear.value, currentDate.value.getMonth() + 1, 1) }

// Real-time Clock
const currentClock = ref(new Date())
let timer = null
let refreshTimer = null
const updateTime = () => { currentClock.value = new Date() }

// --- FETCH DATA ---
const fetchData = async () => {
  try {
    const resSum = await axios.get('/hr/dashboard-summary')
    summary.value = resSum.data.summary || { total_interns: 0, present_today: 0 }
    
    // Tarik data Realtime & Cek Libur
    const resRealtime = await axios.get('/hr/realtime-status')
    realtimeInterns.value = resRealtime.data.data || []
    isTodayHoliday.value = resRealtime.data.is_holiday || false
    if (isTodayHoliday.value) {
      holidayInfo.value = { title: resRealtime.data.holiday_title, desc: resRealtime.data.holiday_desc }
    }

    const resIntern = await axios.get('/users/intern')
    interns.value = resIntern.data.data || []

    const resHR = await axios.get('/users/hr')
    hrList.value = resHR.data.data || []
    
    // Tarik Semua History
    const resHistory = await axios.get('/hr/all-history')
    allHistoryData.value = resHistory.data.data || []
    
    const resSettings = await axios.get('/hr/settings')
    if (resSettings.data.settings) attendanceSettings.value = resSettings.data.settings
    holidays.value = resSettings.data.holidays || []
  } catch (e) {
    console.error("Sinkronisasi gagal:", e)
  }
}

// --- HELPER FUNGSI ---
const limitText = (text, length) => {
  if (!text || text === '-') return '-';
  return text.length > length ? text.substring(0, length) + '...' : text;
}

const openLogbookDetail = (text) => {
  if (!text || text === '-') return;
  selectedLogbookText.value = text;
  showLogbookModal.value = true;
}

const formatTgl = (tgl) => {
  if (!tgl) return '-';
  return new Date(tgl).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
};

const formatHistoryStatus = (statusStr) => {
  const map = { 'present': 'Hadir', 'permit': 'Izin', 'absent': 'Tidak Hadir' };
  return map[statusStr] || statusStr;
}

const formatHistoryOfficeStatus = (statusStr) => {
  const map = { 'di_kantor': 'Di Kantor', 'keluar_sementara': 'Sedang Keluar' };
  return map[statusStr] || '-';
}

// --- LOGIKA SETTINGS & KALENDER ---
const saveSettings = async () => {
  try {
    await axios.post('/hr/settings', attendanceSettings.value)
    Swal.fire('Berhasil', 'Pengaturan jam kerja disimpan!', 'success')
  } catch (e) {
    Swal.fire('Gagal', 'Gagal menyimpan pengaturan', 'error')
  }
}

const addHoliday = async () => {
  if (!newHoliday.value.date || !newHoliday.value.desc) return Swal.fire('Perhatian', 'Tanggal dan keterangan libur wajib diisi!', 'warning')
  try {
    await axios.post('/hr/holidays', newHoliday.value)
    Swal.fire('Berhasil', 'Hari libur ditambahkan!', 'success')
    newHoliday.value = { date: '', desc: '' }
    fetchData()
  } catch (e) {
    Swal.fire('Gagal', 'Gagal menambah hari libur', 'error')
  }
}

const deleteHoliday = async (id) => {
  try {
    await axios.delete(`/hr/holidays/${id}`)
    fetchData()
  } catch (e) {
    Swal.fire('Gagal', 'Gagal menghapus hari libur', 'error')
  }
}

const calendarDays = computed(() => {
  const year = currentYear.value; const month = currentDate.value.getMonth();
  const firstDay = new Date(year, month, 1); const lastDay = new Date(year, month + 1, 0);
  let startPadding = firstDay.getDay() - 1; if (startPadding === -1) startPadding = 6; 
  
  const days = [];
  const workDaysArray = attendanceSettings.value.work_days ? attendanceSettings.value.work_days.split(',') : ['1','2','3','4','5'];

  for (let i = 0; i < startPadding; i++) days.push({ empty: true });

  for (let i = 1; i <= lastDay.getDate(); i++) {
    const dateObj = new Date(year, month, i);
    const dateString = `${year}-${String(month + 1).padStart(2, '0')}-${String(i).padStart(2, '0')}`;
    let jsDay = dateObj.getDay(); let isoDay = jsDay === 0 ? 7 : jsDay;
    let isWeekend = !workDaysArray.includes(isoDay.toString());
    let holidayData = holidays.value.find(h => h.holiday_date === dateString);
    
    days.push({
      empty: false, date: i, fullDate: dateString, isWeekend: isWeekend,
      holiday: holidayData, isToday: dateString === new Date().toISOString().split('T')[0]
    });
  }
  return days;
});

const handleDateClick = async (day) => {
  if (day.empty) return;
  if (!isCalendarEditMode.value) {
    let statusText = 'Hari Kerja Aktif'; let icon = 'info';
    if (day.holiday) { statusText = `Libur Nasional: ${day.holiday.description}`; icon = 'warning'; } 
    else if (day.isWeekend) { statusText = 'Libur Akhir Pekan'; icon = 'warning'; }
    Swal.fire({ title: day.fullDate, text: statusText, icon: icon, confirmButtonColor: '#00529C' });
  } else {
    if (day.holiday) {
      Swal.fire({ title: 'Hapus Hari Libur?', text: `Cabut libur pada ${day.fullDate}?`, icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33' })
      .then(async (result) => { if (result.isConfirmed) await deleteHoliday(day.holiday.id); });
    } else {
      const { value: desc } = await Swal.fire({ title: 'Tandai Libur', input: 'text', inputLabel: `Tanggal: ${day.fullDate}`, showCancelButton: true, confirmButtonColor: '#00529C' });
      if (desc) { newHoliday.value = { date: day.fullDate, desc: desc }; await addHoliday(); }
    }
  }
}

// --- LOGIKA MODAL USER ---
const openModal = (user = null) => {
  if (user) { isEditMode.value = true; form.value = { ...user, password: '' }; } 
  else { isEditMode.value = false; form.value = { id: null, name: '', email: '', phone: '', password: '' }; }
  showModal.value = true;
}

const handleSave = async () => {
  const currentRole = activeTab.value === 'manage-intern' ? 'intern' : 'hr';
  try {
    if (isEditMode.value) await axios.put(`/users/${form.value.id}`, form.value);
    else await axios.post(`/users`, { ...form.value, role: currentRole });
    Swal.fire('Berhasil', 'Data disimpan!', 'success');
    showModal.value = false; fetchData();
  } catch (e) { Swal.fire('Gagal', e.response?.data?.message || 'Periksa kembali data Anda', 'error'); }
}

const confirmDelete = (id) => {
  Swal.fire({ title: 'Hapus User?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33' })
  .then(async (result) => {
    if (result.isConfirmed) { await axios.delete(`/users/${id}`); fetchData(); Swal.fire('Deleted', 'Data dihapus', 'success'); }
  })
}

onMounted(() => { 
  fetchData(); 
  timer = setInterval(updateTime, 1000);
  refreshTimer = setInterval(fetchData, 30000); 
})

onUnmounted(() => { clearInterval(timer); clearInterval(refreshTimer); })
</script>

<template>
  <div class="app-layout">
    <aside class="sidebar">
      <div class="brand">
        <div class="logo-space"><span class="logo-text">BRI</span>JISENT</div>
      </div>
      <nav class="side-nav">
        <button :class="{ active: activeTab === 'dashboard' }" @click="activeTab = 'dashboard'">📊 Monitoring</button>
        <button :class="{ active: activeTab === 'history-intern' }" @click="activeTab = 'history-intern'">📋 History Intern</button>
        <button :class="{ active: activeTab === 'manage-intern' }" @click="activeTab = 'manage-intern'">👥 Manajemen Intern</button>
        <button :class="{ active: activeTab === 'manage-hr' }" @click="activeTab = 'manage-hr'">🛠️ Manajemen HR</button>
        <button :class="{ active: activeTab === 'settings' }" @click="activeTab = 'settings'">⚙️ Pengaturan Hari Aktif</button>
      </nav>
      <div class="sidebar-footer">
        <div class="admin-profile">
          <div class="avatar">{{ authStore.user.name?.charAt(0).toUpperCase() || 'A' }}</div>
          <div class="info"><strong>{{ authStore.user.name }}</strong><small>Administrator HR</small></div>
        </div>
        <button @click="authStore.logout()" class="btn-logout">Sign Out</button>
      </div>
    </aside>

    <main class="main-content">
      <header class="content-header">
        <div class="title-area">
          <h2 class="page-title">{{ activeTab.replace('-', ' ').toUpperCase() }}</h2>
          <p class="date-text">{{ currentClock.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) }}</p>
        </div>
        <div class="digital-clock">{{ currentClock.toLocaleTimeString('id-ID') }} WIB</div>
      </header>

      <div v-if="activeTab === 'dashboard'" class="fade-in">
        <div class="stats-grid">
          <div class="stat-card"><div class="label">Total Intern Terdaftar</div><div class="number">{{ summary.total_interns }}</div></div>
          <div class="stat-card blue"><div class="label">Hadir Hari Ini</div><div class="number">{{ summary.present_today }}</div></div>
        </div>

        <div class="table-container shadow-sm">
          <div class="table-header flex-between">
            <h3>Real-time Attendance Status</h3>
            <span class="pulse-icon">● LIVE TRACKING</span>
          </div>
          
          <div v-if="isTodayHoliday" class="holiday-banner-hr">
            <div class="holiday-icon">🏖️</div>
            <div>
              <h4 style="margin: 0; font-size: 1.2rem; color: #1e293b;">Hari ini adalah hari libur.</h4>
              <p style="margin: 5px 0 0 0; color: #64748b;">{{ holidayInfo.title }} - {{ holidayInfo.desc }}</p>
              <p style="margin: 5px 0 0 0; font-style: italic; color: #94a3b8;">Tidak ada aktivitas absensi intern untuk hari ini.</p>
            </div>
          </div>

          <table v-else class="enterprise-table">
            <thead>
              <tr>
                <th>Nama Intern</th>
                <th>Status Kehadiran</th>
                <th>Status Lokasi</th>
                <th>Jam Masuk</th>
                <th>Jam Keluar</th>
                <th>Logbook Hari Ini</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="user in realtimeInterns" :key="user.id">
                <td><strong>{{ user.nama }}</strong></td>
                <td>
                  <span :class="['badge-pill', 
                    user.kehadiran === 'Hadir' ? 'status-in' : 
                    user.kehadiran === 'Terlambat' ? 'status-late' : 
                    user.kehadiran === 'Izin' ? 'status-permit' : 'status-out']">
                    {{ user.kehadiran }}
                  </span>
                </td>
                <td><span :class="user.status_lokasi === 'Di Kantor' ? 'text-blue' : (user.status_lokasi === 'Keluar Kantor' ? 'text-orange' : 'text-muted')">{{ user.status_lokasi }}</span></td>
                <td class="time-cell">{{ user.jam_masuk }}</td>
                <td class="time-cell">--:--</td> 
                <td class="log-cell" @click="openLogbookDetail(user.logbook)" :class="{'clickable-log': user.logbook && user.logbook !== '-'}">
                  {{ limitText(user.logbook, 35) }}
                </td>
              </tr>
              <tr v-if="realtimeInterns.length === 0">
                <td colspan="6" style="text-align: center; color: #95a5a6; padding: 20px;">Belum ada data absensi hari ini.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-if="activeTab === 'history-intern'" class="fade-in">
        <div class="table-container shadow-sm">
          <div class="table-header">
            <h3>Rekapitulasi Histori Seluruh Intern</h3>
          </div>
          <div style="overflow-x: auto;">
            <table class="enterprise-table">
              <thead>
                <tr>
                  <th>Tanggal</th>
                  <th>Nama Intern</th>
                  <th>Status Kehadiran</th>
                  <th>Status Lokasi</th>
                  <th>Jam Masuk</th>
                  <th>Jam Keluar</th>
                  <th>Logbook Harian</th>
                  <th style="text-align: center;">Bukti Surat</th> </tr>
              </thead>
              <tbody>
                <tr v-for="att in allHistoryData" :key="att.id">
                  <td style="white-space: nowrap; color: #64748b;">{{ formatTgl(att.tanggal) }}</td>
                  <td><strong>{{ att.nama }}</strong></td>
                  <td>
                    <span :class="['badge-pill', att.status === 'present' ? 'status-in' : (att.status === 'permit' ? 'status-permit' : 'status-out')]">
                      {{ formatHistoryStatus(att.status) }}
                    </span>
                  </td>
                  <td>{{ formatHistoryOfficeStatus(att.office_status) }}</td>
                  <td class="time-cell">{{ att.clock_in }}</td>
                  <td class="time-cell">{{ att.clock_out }}</td>
                  <td class="log-cell" @click="openLogbookDetail(att.logbook)" :class="{'clickable-log': att.logbook && att.logbook !== '-'}">
                    {{ limitText(att.logbook, 40) }}
                  </td>
                  <td style="text-align: center;">
                    <a v-if="att.status === 'permit' && att.evidence_path" 
                       :href="att.evidence_path" 
                       target="_blank" 
                       class="btn-sm-edit" 
                       style="text-decoration: none; display: inline-block;">
                       📄 Buka
                    </a>
                    <span v-else class="text-muted">-</span>
                  </td>
                </tr>
                <tr v-if="allHistoryData.length === 0">
                  <td colspan="8" style="text-align: center; color: #95a5a6; padding: 20px;">Belum ada riwayat absensi tersimpan.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div v-if="activeTab === 'manage-intern' || activeTab === 'manage-hr'" class="fade-in">
        <div class="table-container shadow-sm">
          <div class="table-header flex-between">
            <h3>Daftar Seluruh {{ activeTab === 'manage-intern' ? 'Intern' : 'Admin HR' }}</h3>
            <button @click="openModal()" class="btn-add">+ Tambah {{ activeTab === 'manage-intern' ? 'Intern' : 'HR' }} Baru</button>
          </div>
          <table class="enterprise-table">
            <thead>
              <tr><th>Nama Lengkap</th><th>Email Akses</th><th>No. HP</th><th>Aksi</th></tr>
            </thead>
            <tbody>
              <tr v-for="user in (activeTab === 'manage-intern' ? interns : hrList)" :key="user.id">
                <td><strong>{{ user.name }}</strong> <span v-if="user.id === authStore.user.id" class="me-tag">(Anda)</span></td>
                <td>{{ user.email }}</td>
                <td>{{ user.phone || '-' }}</td>
                <td class="action-buttons">
                  <button @click="openModal(user)" class="btn-sm-edit">Edit</button>
                  <button v-if="user.id !== authStore.user.id" @click="confirmDelete(user.id)" class="btn-sm-delete">Hapus</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-if="activeTab === 'settings'" class="fade-in">
        <div class="card p-4 shadow-sm mb-4" style="background: white; border-radius: 12px;">
          <div class="flex-between mb-4">
            <h3 style="color: #00529C; margin: 0;">Aturan Jam Kerja (Default)</h3>
            <button @click="saveSettings" class="btn-add">💾 Simpan Aturan Jam</button>
          </div>
          <div class="grid-container" style="grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
            <div class="form-group"><label>Buka Akses Absen</label><input type="time" v-model="attendanceSettings.start_time" class="form-input"></div>
            <div class="form-group"><label>Batas Terlambat</label><input type="time" v-model="attendanceSettings.late_threshold" class="form-input"></div>
            <div class="form-group"><label>Auto-Alpa (Batas Pulang)</label><input type="time" v-model="attendanceSettings.end_time" class="form-input"></div>
          </div>
        </div>

        <div class="calendar-container card shadow-sm">
          <div class="calendar-header">
            <div class="month-nav">
              <button @click="prevMonth" class="btn-nav">◀</button>
              <h2 class="month-title">{{ currentMonthName }} {{ currentYear }}</h2>
              <button @click="nextMonth" class="btn-nav">▶</button>
            </div>
            <div class="mode-toggle">
              <span style="font-weight: 600; margin-right: 10px; color: #475569;">Mode Edit:</span>
              <label class="switch"><input type="checkbox" v-model="isCalendarEditMode"><span class="slider round"></span></label>
            </div>
          </div>
          <div class="calendar-legend">
            <span class="legend-item"><div class="box work"></div> Hari Kerja</span>
            <span class="legend-item"><div class="box weekend"></div> Akhir Pekan</span>
            <span class="legend-item"><div class="box holiday"></div> Libur Spesial</span>
          </div>
          <div class="calendar-grid mt-3" :class="{'edit-mode-active': isCalendarEditMode}">
            <div class="day-name">Sen</div><div class="day-name">Sel</div><div class="day-name">Rab</div>
            <div class="day-name">Kam</div><div class="day-name">Jum</div><div class="day-name">Sab</div><div class="day-name">Min</div>
            <div v-for="(day, index) in calendarDays" :key="index" class="calendar-cell"
              :class="{ 'empty': day.empty, 'is-weekend': !day.empty && day.isWeekend && !day.holiday, 'is-holiday': !day.empty && day.holiday, 'is-today': !day.empty && day.isToday }"
              @click="handleDateClick(day)" :title="day.holiday ? day.holiday.description : (day.isWeekend ? 'Akhir Pekan' : 'Hari Kerja')">
              <span v-if="!day.empty" class="date-num">{{ day.date }}</span>
              <span v-if="!day.empty && day.holiday" class="holiday-desc">{{ day.holiday.description }}</span>
              <div v-if="isCalendarEditMode && !day.empty" class="edit-overlay">
                <span v-if="day.holiday">❌ Hapus</span><span v-else>➕ Libur</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </main>

    <div v-if="showLogbookModal" class="modal-overlay">
      <div class="modal-card fade-in" style="max-width: 500px;">
        <div class="modal-header">
          <h3 style="color: #00529C; margin: 0;">Isi Logbook</h3>
          <button @click="showLogbookModal = false" class="close-x">×</button>
        </div>
        <div class="modal-body">
          <p style="white-space: pre-wrap; color: #334155; line-height: 1.6;">{{ selectedLogbookText }}</p>
        </div>
        <div class="modal-footer" style="justify-content: flex-end;">
          <button @click="showLogbookModal = false" class="btn-cancel" style="width: auto;">Tutup</button>
        </div>
      </div>
    </div>

    <div v-if="showModal" class="modal-overlay">
      <div class="modal-card fade-in">
        <div class="modal-header">
          <h3>{{ isEditMode ? 'Edit' : 'Tambah' }} User</h3>
          <button @click="showModal = false" class="close-x">×</button>
        </div>
        <div class="modal-body">
          <div class="form-group"><label>Nama Lengkap</label><input v-model="form.name" type="text" class="form-input"></div>
          <div class="form-group"><label>Email</label><input v-model="form.email" type="email" class="form-input"></div>
          <div class="form-group"><label>No. WhatsApp</label><input v-model="form.phone" type="text" class="form-input"></div>
          <div class="form-group" v-if="!isEditMode">
            <label>Password Awal</label><input v-model="form.password" type="password" class="form-input">
          </div>
        </div>
        <div class="modal-footer">
          <button @click="handleSave" class="btn-confirm">SIMPAN</button>
          <button @click="showModal = false" class="btn-cancel">BATAL</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* --- BASE & SIDEBAR (Gunakan Base putih bersih) --- */
.app-layout { display: flex; height: 100vh; background: #f4f6f8; font-family: 'Inter', sans-serif; color: #333; }
.sidebar { width: 280px; background: #1a1c23; color: #ecf0f1; display: flex; flex-direction: column; }
.brand { padding: 40px 30px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid #2d3436; }
.logo-space { font-size: 1.4rem; font-weight: 900; color: #00529C; letter-spacing: 1px; }
.logo-text { background-color: #00529C; color: #ffffff; padding: 4px 8px; border-radius: 6px; margin-right: 4px; }
.side-nav { flex: 1; padding: 30px 0; }
.side-nav button { width: 100%; padding: 18px 30px; background: none; border: none; color: #95a5a6; text-align: left; cursor: pointer; border-left: 5px solid transparent; transition: 0.3s; }
.side-nav button:hover { background: #2d3436; color: white; }
.side-nav button.active { background: #2d3436; color: white; border-left-color: #00529C; font-weight: bold; }
.sidebar-footer { padding: 25px; border-top: 1px solid #2d3436; background: #15171c; }
.admin-profile { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; }
.avatar { width: 35px; height: 35px; background: #00529C; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; color: white; }
.info strong { display: block; font-size: 0.9rem; }
.info small { color: #7f8c8d; font-size: 0.75rem; }
.btn-logout { width: 100%; padding: 10px; background: #c0392b; border: none; color: white; border-radius: 6px; cursor: pointer; font-weight: bold; }

/* --- MAIN & DASHBOARD --- */
.main-content { flex: 1; padding: 40px 50px; overflow-y: auto; }
.content-header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 40px; border-bottom: 1px solid #ddd; padding-bottom: 25px; }
.page-title { font-size: 1.8rem; font-weight: 800; color: #1a1c23; margin: 0; }
.date-text { color: #7f8c8d; margin-top: 5px; }
.digital-clock { font-family: 'Courier New', monospace; font-size: 2rem; font-weight: bold; color: #00529C; background: white; padding: 10px 25px; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }

.stats-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 40px; }
.stat-card { background: white; padding: 30px; border-radius: 15px; border: 1px solid #eee; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
.stat-card .label { color: #7f8c8d; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 1px; }
.stat-card .number { font-size: 3.5rem; font-weight: 900; color: #1a1c23; }
.stat-card.blue { border-bottom: 6px solid #00529C; }

/* --- TABLES --- */
.table-container { background: white; border-radius: 15px; border: 1px solid #eee; overflow: hidden; margin-bottom: 30px; }
.table-header { padding: 25px; border-bottom: 1px solid #eee; display: flex; align-items: center; }
.flex-between { justify-content: space-between; }
.enterprise-table { width: 100%; border-collapse: collapse; }
.enterprise-table th { background: #fcfcfc; padding: 18px; text-align: left; font-size: 0.75rem; color: #95a5a6; text-transform: uppercase; border-bottom: 2px solid #f1f1f1; white-space: nowrap; }
.enterprise-table td { padding: 20px 18px; border-bottom: 1px solid #f1f1f1; font-size: 0.95rem; }

/* --- BADGES & LOGBOOK --- */
.badge-pill { padding: 6px 14px; border-radius: 6px; font-size: 0.75rem; font-weight: 800; }
.status-in { background: #e8f5e9; color: #2e7d32; }
.status-out { background: #ffebee; color: #c62828; }
.status-late { background: #fff3e0; color: #ef6c00; }
.status-permit { background: #fef3c7; color: #92400e; }
.text-blue { color: #00529C; font-weight: bold; }
.text-orange { color: #F37021; font-weight: bold; }
.text-muted { color: #95a5a6; }
.time-cell { font-family: monospace; font-size: 1rem; color: #333; white-space: nowrap; }

.log-cell { font-style: italic; color: #666; max-width: 250px; }
.clickable-log { cursor: pointer; color: #00529C; font-weight: 500; transition: 0.2s; }
.clickable-log:hover { text-decoration: underline; background-color: #f0f7ff; border-radius: 4px; }

/* --- BANNER LIBUR HR --- */
.holiday-banner-hr { display: flex; align-items: center; gap: 20px; padding: 30px; background: linear-gradient(135deg, #fdfbfb 0%, #ebedee 100%); border-radius: 12px; margin: 20px; border: 1px dashed #cbd5e1; }
.holiday-icon { font-size: 3rem; background: white; padding: 15px; border-radius: 50%; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }

/* --- CALENDAR --- */
.calendar-container { background: white; padding: 25px; border-radius: 12px; }
.calendar-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.month-nav { display: flex; align-items: center; justify-content: center; gap: 15px; }
.month-title { margin: 0; font-size: 1.5rem; color: #1e293b; min-width: 200px; text-align: center; }
.btn-nav { background: #f1f5f9; border: none; padding: 8px 15px; border-radius: 8px; cursor: pointer; color: #00529C; font-weight: bold; transition: 0.2s; }
.mode-toggle { display: flex; align-items: center; }

.calendar-legend { display: flex; gap: 15px; margin-top: 15px; padding-bottom: 15px; border-bottom: 1px solid #e2e8f0; }
.legend-item { display: flex; align-items: center; gap: 8px; font-size: 0.85rem; color: #64748b; font-weight: 600; }
.box { width: 16px; height: 16px; border-radius: 4px; }
.box.work { background: #ffffff; border: 1px solid #cbd5e1; }
.box.weekend { background: #f8fafc; border: 1px solid #e2e8f0; }
.box.holiday { background: #fee2e2; border: 1px solid #fca5a5; }

.calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 8px; }
.day-name { text-align: center; font-weight: 700; color: #64748b; padding: 10px 0; font-size: 0.9rem; text-transform: uppercase; }
.calendar-cell { min-height: 90px; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px; display: flex; flex-direction: column; position: relative; transition: 0.2s; background: white; cursor: pointer; overflow: hidden; }
.calendar-cell.empty { background: transparent; border: none; cursor: default; }
.calendar-cell.is-weekend { background: #f8fafc; color: #94a3b8; }
.calendar-cell.is-holiday { background: #fee2e2; border-color: #fca5a5; color: #b91c1c; }
.calendar-cell.is-today { border: 2px solid #00529C; }
.date-num { font-weight: 800; font-size: 1.1rem; }
.holiday-desc { font-size: 0.7rem; font-weight: 600; margin-top: auto; line-height: 1.2; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }

.edit-overlay { position: absolute; inset: 0; background: rgba(0, 82, 156, 0.9); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.85rem; opacity: 0; transition: opacity 0.2s; }
.calendar-grid.edit-mode-active .calendar-cell:not(.empty):hover .edit-overlay { opacity: 1; }

.switch { position: relative; display: inline-block; width: 50px; height: 26px; }
.switch input { opacity: 0; width: 0; height: 0; }
.slider { position: absolute; cursor: pointer; inset: 0; background-color: #cbd5e1; transition: .4s; border-radius: 34px; }
.slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 4px; bottom: 4px; background-color: white; transition: .4s; border-radius: 50%; }
input:checked + .slider { background-color: #00529C; }
input:checked + .slider:before { transform: translateX(24px); }

/* --- MODALS & BUTTONS --- */
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(5px); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal-card { background: white; width: 450px; border-radius: 20px; overflow: hidden; box-shadow: 0 25px 50px rgba(0,0,0,0.2); }
.modal-header { padding: 25px; background: #f8f9fa; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; }
.close-x { background: none; border: none; font-size: 1.5rem; cursor: pointer; }
.modal-body { padding: 30px; }
.form-group { margin-bottom: 20px; }
.form-group label { display: block; margin-bottom: 8px; font-weight: bold; font-size: 0.9rem; }
.form-input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 1rem; }
.modal-footer { padding: 25px; display: flex; gap: 10px; }
.btn-confirm, .btn-add { background: #00529C; color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; transition: 0.3s; padding: 12px 25px; }
.btn-confirm { flex: 1; padding: 14px; }
.btn-cancel { padding: 14px 20px; background: #eee; border: none; border-radius: 8px; cursor: pointer; }
.btn-sm-edit { color: #00529C; border: 1px solid #00529C; background: white; padding: 5px 12px; border-radius: 4px; cursor: pointer; margin-right: 8px; }
.btn-sm-delete { color: #c0392b; border: 1px solid #c0392b; background: white; padding: 5px 12px; border-radius: 4px; cursor: pointer; }
.pulse-icon { color: #2ecc71; font-weight: bold; font-size: 0.75rem; animation: pulse 2s infinite; }
.fade-in { animation: fadeIn 0.4s ease-out; }

@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
@keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.4; } 100% { opacity: 1; } }
</style>
