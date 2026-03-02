<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useAuthStore } from '../../stores/authStore'
import axios from 'axios'
import Swal from 'sweetalert2'

const authStore = useAuthStore()
const activeMenu = ref('dashboard')
const isMobile = ref(false)
const isTablet = ref(false)
const isSidebarOpen = ref(true)
const toggleSidebar = () => { isSidebarOpen.value = !isSidebarOpen.value }

// ==========================================
// DATA STATES
// ==========================================
const summary = ref({ total_interns: 0, present_today: 0, late_today: 0, permit_today: 0, absent_today: 0 })
const interns = ref([])
const hrList = ref([])
const realtimeInterns = ref([])
const isTodayHoliday = ref(false)
const holidayInfo = ref({ title: '', desc: '' })
const allHistoryData = ref([])
const attendanceSettings = ref({ start_time: '07:00', late_threshold: '07:30', end_time: '17:00' })
const holidays = ref([])
const newHoliday = ref({ date: '', desc: '' })
const weeklyChartData = ref([])
const monthlyTrendData = ref([])

// ==========================================
// SEARCH & FILTER STATES
// ==========================================
const historySearch = ref('')
const historyFilterStatus = ref('')
const historyFilterDate = ref('')
const historySortField = ref('tanggal')
const historySortDir = ref('desc')
const historyPage = ref(1)
const HISTORY_PER_PAGE = 15
const realtimeSearch = ref('')

// ==========================================
// MODAL STATES
// ==========================================
const showModal = ref(false)
const isEditMode = ref(false)
const form = ref({ id: null, name: '', email: '', phone: '', password: '' })
const showLogbookModal = ref(false)
const selectedLogbookText = ref('')
const selectedLogbookName = ref('')
const showEditAttendanceModal = ref(false)
const formAttendance = ref({ id: null, date: '', nama: '', clock_in: '', clock_out: '', status: '' })

// ==========================================
// CALENDAR STATES
// ==========================================
const isCalendarEditMode = ref(false)
const currentDate = ref(new Date())
const monthNames = ["Januari","Februari","Maret","April","Mei","Juni","Juli","Agustus","September","Oktober","November","Desember"]
const currentMonthName = computed(() => monthNames[currentDate.value.getMonth()])
const currentYear = computed(() => currentDate.value.getFullYear())
const prevMonth = () => { currentDate.value = new Date(currentYear.value, currentDate.value.getMonth() - 1, 1) }
const nextMonth = () => { currentDate.value = new Date(currentYear.value, currentDate.value.getMonth() + 1, 1) }

// REALTIME CLOCK
const currentClock = ref(new Date())
let timer = null
let refreshTimer = null

// ==========================================
// FETCH DATA
// ==========================================
const fetchData = async () => {
  try {
    const [resSum, resRealtime, resIntern, resHR, resHistory, resSettings] = await Promise.all([
      axios.get('/hr/dashboard-summary'),
      axios.get('/hr/realtime-status'),
      axios.get('/users/intern'),
      axios.get('/users/hr'),
      axios.get('/hr/all-history'),
      axios.get('/hr/settings'),
    ])
    summary.value = resSum.data.summary || { total_interns: 0, present_today: 0, late_today: 0, permit_today: 0, absent_today: 0 }
    weeklyChartData.value = resSum.data.weekly || []
    monthlyTrendData.value = resSum.data.monthly || []
    realtimeInterns.value = resRealtime.data.data || []
    isTodayHoliday.value = resRealtime.data.is_holiday || false
    if (isTodayHoliday.value) holidayInfo.value = { title: resRealtime.data.holiday_title, desc: resRealtime.data.holiday_desc }
    interns.value = resIntern.data.data || []
    hrList.value = resHR.data.data || []
    allHistoryData.value = resHistory.data.data || []
    if (resSettings.data.settings) attendanceSettings.value = resSettings.data.settings
    holidays.value = resSettings.data.holidays || []
  } catch (e) { console.error('Sinkronisasi gagal:', e) }
}

// ==========================================
// COMPUTED: DONUT CHART DATA (JUMBO SIZE & FIX 360 BUG)
// ==========================================
const donutSegments = computed(() => {
  const p = summary.value.present_today || 0
  const l = summary.value.late_today || 0
  const iz = summary.value.permit_today || 0
  const a = summary.value.absent_today || 0
  const total = p + l + iz + a || 1
  
  const items = [
    { label: 'Hadir', value: p, pct: p/total*100, color: '#10b981' },
    { label: 'Terlambat', value: l, pct: l/total*100, color: '#f59e0b' },
    { label: 'Izin', value: iz, pct: iz/total*100, color: '#7c3aed' },
    { label: 'Tidak Hadir', value: a, pct: a/total*100, color: '#ef4444' },
  ]
  
  // Dibesarkan untuk viewbox 300x300
  const cx=150, cy=150, r=120, ri=70
  let cum = -90
  
  return items.filter(d => d.value > 0).map(d => {
    let sweep = d.pct/100*360;
    
    // BUG FIX: SVG Arc tidak bisa menggambar persis 360 derajat penuh, kurangi sedikit
    if (sweep >= 360) sweep = 359.999;
    
    const a1 = cum*Math.PI/180, a2 = (cum+sweep)*Math.PI/180
    const x1o=cx+r*Math.cos(a1), y1o=cy+r*Math.sin(a1)
    const x2o=cx+r*Math.cos(a2), y2o=cy+r*Math.sin(a2)
    const x1i=cx+ri*Math.cos(a2), y1i=cy+ri*Math.sin(a2)
    const x2i=cx+ri*Math.cos(a1), y2i=cy+ri*Math.sin(a1)
    const lg = sweep > 180 ? 1 : 0
    const path = `M${x1o},${y1o} A${r},${r} 0 ${lg},1 ${x2o},${y2o} L${x1i},${y1i} A${ri},${ri} 0 ${lg},0 ${x2i},${y2i} Z`
    cum += sweep
    
    return { ...d, path, pctLabel: Math.round(d.pct) }
  })
})

// ==========================================
// COMPUTED: FILTER & SEARCH HISTORY
// ==========================================
const filteredHistory = computed(() => {
  let data = [...allHistoryData.value]
  if (historySearch.value.trim()) {
    const q = historySearch.value.toLowerCase()
    data = data.filter(r => r.nama?.toLowerCase().includes(q))
  }
  if (historyFilterStatus.value) data = data.filter(r => r.status === historyFilterStatus.value)
  if (historyFilterDate.value) data = data.filter(r => r.tanggal?.startsWith(historyFilterDate.value))
  data.sort((a, b) => {
    let va = a[historySortField.value] || '', vb = b[historySortField.value] || ''
    return historySortDir.value === 'asc' ? (va > vb ? 1 : -1) : (va < vb ? 1 : -1)
  })
  return data
})
const historyTotal = computed(() => filteredHistory.value.length)
const historyPages = computed(() => Math.max(1, Math.ceil(historyTotal.value / HISTORY_PER_PAGE)))
const pagedHistory = computed(() => {
  const s = (historyPage.value - 1) * HISTORY_PER_PAGE
  return filteredHistory.value.slice(s, s + HISTORY_PER_PAGE)
})
const paginationRange = computed(() => {
  const pages = historyPages.value, cur = historyPage.value
  if (pages <= 7) return Array.from({length: pages}, (_, i) => i+1)
  const arr = []
  if (cur <= 4) { for (let i=1;i<=5;i++) arr.push(i); arr.push('...'); arr.push(pages) }
  else if (cur >= pages - 3) { arr.push(1); arr.push('...'); for (let i=pages-4;i<=pages;i++) arr.push(i) }
  else { arr.push(1); arr.push('...'); for (let i=cur-1;i<=cur+1;i++) arr.push(i); arr.push('...'); arr.push(pages) }
  return arr
})

const setSort = (field) => {
  if (historySortField.value === field) historySortDir.value = historySortDir.value === 'asc' ? 'desc' : 'asc'
  else { historySortField.value = field; historySortDir.value = 'asc' }
  historyPage.value = 1
}
const sortIcon = (f) => historySortField.value !== f ? '↕' : historySortDir.value === 'asc' ? '↑' : '↓'
watch([historySearch, historyFilterStatus, historyFilterDate], () => { historyPage.value = 1 })

const filteredRealtime = computed(() => {
  if (!realtimeSearch.value.trim()) return realtimeInterns.value
  const q = realtimeSearch.value.toLowerCase()
  return realtimeInterns.value.filter(r => r.nama?.toLowerCase().includes(q))
})

// ==========================================
// WEEKLY CHART (SVG bar)
// ==========================================
const weeklyBars = computed(() => {
  if (!weeklyChartData.value.length) return []
  const maxVal = Math.max(...weeklyChartData.value.map(d => d.total), 1)
  const chartH = 100, barW = 28, gap = 14
  return weeklyChartData.value.map((d, i) => ({
    ...d,
    x: i * (barW + gap),
    barH: (d.total / maxVal) * chartH,
    y: chartH - (d.total / maxVal) * chartH
  }))
})

// ==========================================
// HELPERS
// ==========================================
const limitText = (t, n) => (!t || t==='-') ? '-' : t.length > n ? t.slice(0,n)+'...' : t
const openLogbook = (text, name) => {
  if (!text || text==='-') return
  selectedLogbookText.value = text; selectedLogbookName.value = name || ''
  showLogbookModal.value = true
}
const formatTgl = (t) => t ? new Date(t).toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }) : '-'
const fmtStatus = (s) => ({ present:'Hadir', permit:'Izin', absent:'Tidak Hadir' }[s] || s)
const fmtOffice = (s) => ({ di_kantor:'Di Kantor', keluar_sementara:'Keluar Sebentar' }[s] || '-')
const statusCls = (s) => ({ present:'bs-ok', permit:'bs-warn', absent:'bs-danger' }[s] || 'bs-muted')
const kehadiranCls = (k) => ({ 'Hadir':'bs-ok', 'Terlambat':'bs-late', 'Izin':'bs-warn', 'Tidak Hadir':'bs-danger', 'Belum Absen':'bs-muted' }[k] || 'bs-muted')

// ==========================================
// SETTINGS & CALENDAR
// ==========================================
const saveSettings = async () => {
  try {
    await axios.post('/hr/settings', attendanceSettings.value)
    Swal.fire({ icon:'success', title:'Tersimpan!', text:'Pengaturan jam kerja diperbarui.', confirmButtonColor:'#00529C', timer:1800, showConfirmButton:false })
  } catch { Swal.fire('Gagal', 'Gagal menyimpan pengaturan', 'error') }
}

const addHoliday = async () => {
  if (!newHoliday.value.date || !newHoliday.value.desc) return Swal.fire('Perhatian', 'Tanggal dan keterangan wajib diisi!', 'warning')
  try {
    await axios.post('/hr/holidays', newHoliday.value)
    Swal.fire({ icon:'success', title:'Ditambahkan!', timer:1500, showConfirmButton:false })
    newHoliday.value = { date:'', desc:'' }; fetchData()
  } catch { Swal.fire('Gagal', 'Gagal menambah hari libur', 'error') }
}

const deleteHoliday = async (id) => {
  try { await axios.delete(`/hr/holidays/${id}`); fetchData() }
  catch { Swal.fire('Gagal', 'Gagal menghapus hari libur', 'error') }
}

const calendarDays = computed(() => {
  const year=currentYear.value, month=currentDate.value.getMonth()
  const firstDay=new Date(year,month,1), lastDay=new Date(year,month+1,0)
  let sp=firstDay.getDay()-1; if(sp===-1) sp=6
  const wd=attendanceSettings.value.work_days ? attendanceSettings.value.work_days.split(',') : ['1','2','3','4','5']
  const days=[]
  for(let i=0;i<sp;i++) days.push({empty:true})
  for(let i=1;i<=lastDay.getDate();i++){
    const ds=`${year}-${String(month+1).padStart(2,'0')}-${String(i).padStart(2,'0')}`
    const dow=new Date(year,month,i).getDay(); const iso=dow===0?7:dow
    const hol=holidays.value.find(h=>h.holiday_date===ds)
    days.push({empty:false,date:i,fullDate:ds,isWeekend:!wd.includes(iso.toString()),holiday:hol,isToday:ds===new Date().toISOString().split('T')[0]})
  }
  return days
})

const handleDateClick = async (day) => {
  if(day.empty) return
  if(!isCalendarEditMode.value){
    let txt='Hari Kerja Aktif',icon='info'
    if(day.holiday){txt=`Libur: ${day.holiday.description}`;icon='warning'} else if(day.isWeekend){txt='Libur Akhir Pekan';icon='warning'}
    Swal.fire({title:day.fullDate,text:txt,icon,confirmButtonColor:'#00529C'})
  } else {
    if(day.holiday){
      const r=await Swal.fire({title:'Hapus Hari Libur?',text:`Cabut libur ${day.fullDate}?`,icon:'warning',showCancelButton:true,confirmButtonColor:'#d33'})
      if(r.isConfirmed) await deleteHoliday(day.holiday.id)
    } else {
      const {value:desc}=await Swal.fire({title:'Tandai Libur',input:'text',inputLabel:`Tanggal: ${day.fullDate}`,showCancelButton:true,confirmButtonColor:'#00529C'})
      if(desc){newHoliday.value={date:day.fullDate,desc};await addHoliday()}
    }
  }
}

// ==========================================
// USER MODAL
// ==========================================
const openModal = (u=null) => {
  if(u){isEditMode.value=true;form.value={...u,password:''}}
  else{isEditMode.value=false;form.value={id:null,name:'',email:'',phone:'',password:''}}
  showModal.value=true
}
const handleSave = async () => {
  const role=activeMenu.value==='manage-intern'?'intern':'hr'
  try {
    if(isEditMode.value) await axios.put(`/users/${form.value.id}`,form.value)
    else await axios.post('/users',{...form.value,role})
    Swal.fire({icon:'success',title:'Tersimpan!',timer:1500,showConfirmButton:false})
    showModal.value=false;fetchData()
  } catch(e){Swal.fire('Gagal',e.response?.data?.message||'Periksa kembali data','error')}
}
const confirmDelete = (id) => {
  Swal.fire({title:'Hapus User?',icon:'warning',showCancelButton:true,confirmButtonColor:'#d33',cancelButtonText:'Batal'})
  .then(async r=>{if(r.isConfirmed){await axios.delete(`/users/${id}`);fetchData();Swal.fire({icon:'success',title:'Dihapus!',timer:1200,showConfirmButton:false})}})
}

// ==========================================
// EDIT ATTENDANCE
// ==========================================
const openEditAttendance = (att) => {
  const parse=(t)=>(!t||t==='--:--')?'':(t.length>8?t.substring(11,16):t.substring(0,5))
  formAttendance.value={id:att.id,date:att.tanggal,nama:att.nama,clock_in:parse(att.clock_in),clock_out:parse(att.clock_out),status:att.status}
  showEditAttendanceModal.value=true
}
const saveAttendance = async () => {
  try {
    await axios.put(`/hr/attendance/${formAttendance.value.id}`,{
      status:formAttendance.value.status,
      clock_in:formAttendance.value.clock_in?`${formAttendance.value.clock_in}:00`:null,
      clock_out:formAttendance.value.clock_out?`${formAttendance.value.clock_out}:00`:null,
    })
    Swal.fire({icon:'success',title:'Diperbarui!',timer:1500,showConfirmButton:false})
    showEditAttendanceModal.value=false;fetchData()
  } catch{Swal.fire('Gagal','Terjadi kesalahan sistem','error')}
}

// ==========================================
// RESIZE & LIFECYCLE
// ==========================================
const handleResize = () => {
  const w=window.innerWidth
  isMobile.value=w<=640; isTablet.value=w>640&&w<=1024; isSidebarOpen.value=w>1024
}
const switchMenu = (menu) => {
  activeMenu.value=menu
  if(isMobile.value||isTablet.value) isSidebarOpen.value=false
}

onMounted(() => {
  handleResize(); window.addEventListener('resize',handleResize)
  fetchData()
  timer=setInterval(()=>{currentClock.value=new Date()},1000)
  refreshTimer=setInterval(fetchData,30000)
})
onUnmounted(() => {
  window.removeEventListener('resize',handleResize)
  clearInterval(timer);clearInterval(refreshTimer)
})
</script>

<template>
  <div class="shell">

    <transition name="fade-overlay">
      <div v-if="isSidebarOpen && (isMobile || isTablet)" class="overlay-dim" @click="toggleSidebar"></div>
    </transition>

    <aside class="sidebar" :class="{ 'sidebar-visible': isSidebarOpen }">
      <div class="sb-brand">
        <img src="/LOGO.png" alt="BRI" class="sb-logo" onerror="this.style.display='none'" />
        <span class="sb-title">BRI<span class="sb-orange">JISENT</span></span>
      </div>
      <div class="sb-user">
        <div class="sb-avatar">{{ authStore.user.name?.charAt(0).toUpperCase() || 'A' }}</div>
        <div class="sb-userinfo">
          <span class="sb-name">{{ authStore.user.name }}</span>
          <span class="sb-role">Administrator HR</span>
        </div>
      </div>
      <nav class="sb-nav">
        <button class="nav-btn" :class="{ 'nav-btn-active': activeMenu === 'dashboard' }" @click="switchMenu('dashboard')">
          <svg class="nav-ico" fill="none" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.8"/><rect x="3" y="14" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.8"/><rect x="14" y="3" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.8"/><rect x="14" y="14" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.8"/></svg>
          Monitoring
        </button>
        <button class="nav-btn" :class="{ 'nav-btn-active': activeMenu === 'history-intern' }" @click="switchMenu('history-intern')">
          <svg class="nav-ico" fill="none" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="M16 2v4M8 2v4M3 10h18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
          History Intern
        </button>
        <button class="nav-btn" :class="{ 'nav-btn-active': activeMenu === 'manage-intern' }" @click="switchMenu('manage-intern')">
          <svg class="nav-ico" fill="none" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8zM23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
          Manajemen Intern
        </button>
        <button class="nav-btn" :class="{ 'nav-btn-active': activeMenu === 'manage-hr' }" @click="switchMenu('manage-hr')">
          <svg class="nav-ico" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="1.8"/><path d="M6 20v-2a6 6 0 0112 0v2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M19 8l2 2-2 2M21 10h-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
          Manajemen HR
        </button>
        <button class="nav-btn" :class="{ 'nav-btn-active': activeMenu === 'settings' }" @click="switchMenu('settings')">
          <svg class="nav-ico" fill="none" viewBox="0 0 24 24"><path d="M12 15a3 3 0 100-6 3 3 0 000 6z" stroke="currentColor" stroke-width="1.8"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z" stroke="currentColor" stroke-width="1.8"/></svg>
          Pengaturan
        </button>
      </nav>
      <div class="sb-foot">
        <button @click="authStore.logout()" class="btn-logout">
          <svg width="15" height="15" fill="none" viewBox="0 0 24 24"><path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-6 0v-1m0-8V7a3 3 0 016 0v1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
          Keluar Sistem
        </button>
      </div>
    </aside>

    <main class="main-area">

      <header class="topbar">
        <button class="hamburger" @click="toggleSidebar">
          <svg width="20" height="20" fill="none" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        </button>
        <div class="topbar-center">
          <span class="tb-page">{{ { dashboard:'Monitoring Kehadiran', 'history-intern':'History Intern', 'manage-intern':'Manajemen Intern', 'manage-hr':'Manajemen HR', settings:'Pengaturan Hari Aktif' }[activeMenu] }}</span>
        </div>
        <div class="topbar-right">
          <span class="tb-date">{{ currentClock.toLocaleDateString('id-ID', { weekday:'long', day:'numeric', month:'long', year:'numeric' }) }}</span>
          <span class="tb-time">{{ currentClock.toLocaleTimeString('id-ID') }} <em>WIB</em></span>
        </div>
      </header>

      <div class="page-wrap">

        <div v-if="activeMenu === 'dashboard'" class="anim-in">

          <div v-if="isTodayHoliday" class="greeting-bar gb-holiday">
            <span class="gb-ico">🏖️</span>
            <div>
              <p class="gb-title">Hari Libur — {{ holidayInfo.title }}</p>
              <p class="gb-sub">{{ holidayInfo.desc }} · Tidak ada aktivitas absensi hari ini.</p>
            </div>
          </div>

          <div class="stat-grid">
            <div class="stat-card sc-blue">
              <div class="sc-icon-wrap"><svg fill="none" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="1.8"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></div>
              <div class="sc-body"><span class="sc-label">Total Intern</span><span class="sc-num">{{ summary.total_interns }}</span></div>
            </div>
            <div class="stat-card sc-green">
              <div class="sc-icon-wrap"><svg fill="none" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
              <div class="sc-body"><span class="sc-label">Hadir Hari Ini</span><span class="sc-num">{{ summary.present_today }}</span></div>
            </div>
            <div class="stat-card sc-amber">
              <div class="sc-icon-wrap"><svg fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.8"/><path d="M12 6v6l4 2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></div>
              <div class="sc-body"><span class="sc-label">Terlambat</span><span class="sc-num">{{ summary.late_today }}</span></div>
            </div>
            <div class="stat-card sc-purple">
              <div class="sc-icon-wrap"><svg fill="none" viewBox="0 0 24 24"><path d="M9 12h6m-3-3v6m9-3a9 9 0 11-18 0 9 9 0 0118 0z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></div>
              <div class="sc-body"><span class="sc-label">Izin</span><span class="sc-num">{{ summary.permit_today }}</span></div>
            </div>
            <div class="stat-card sc-red">
              <div class="sc-icon-wrap"><svg fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.8"/><path d="M15 9l-6 6M9 9l6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></div>
              <div class="sc-body"><span class="sc-label">Tidak Hadir</span><span class="sc-num">{{ summary.absent_today }}</span></div>
            </div>
          </div>

          <div class="dash-grid-2">

            <div class="card chart-card full-screen-chart">
              <div class="card-top"><span class="card-label">DISTRIBUSI KEHADIRAN HARI INI (VIEW FULL)</span></div>
              <div class="donut-wrap jumbo-donut">
                
                <svg viewBox="0 0 300 300" class="donut-svg">
                  <path v-for="(seg,i) in donutSegments" :key="i" :d="seg.path" :fill="seg.color" />
                  <text x="150" y="145" text-anchor="middle" class="donut-center-num jumbo-text">{{ summary.present_today }}</text>
                  <text x="150" y="180" text-anchor="middle" class="donut-center-lbl jumbo-label">Hadir</text>
                </svg>
                
                <div class="donut-legend jumbo-legend">
                  <div v-for="seg in donutSegments" :key="seg.label" class="dl-item jumbo-dl-item">
                    <span class="dl-dot" :style="`background:${seg.color}`"></span>
                    <span class="dl-label">{{ seg.label }}</span>
                    <span class="dl-val">{{ seg.value }} <small>({{ seg.pctLabel }}%)</small></span>
                  </div>
                  <div v-if="!donutSegments.length" class="dl-empty">Belum ada data hari ini</div>
                </div>
              </div>
            </div>

            <div class="card chart-card">
              <div class="card-top"><span class="card-label">KEHADIRAN 7 HARI TERAKHIR</span></div>
              <div class="bar-chart-wrap">
                <svg viewBox="0 0 240 120" class="bar-svg" preserveAspectRatio="xMidYMid meet">
                  <g v-for="(b,i) in weeklyBars" :key="i">
                    <rect :x="b.x" :y="b.y" :width="28" :height="b.barH" rx="4" fill="#00529C" opacity="0.85"/>
                    <text :x="b.x+14" :y="b.y-4" text-anchor="middle" class="bar-val-txt">{{ b.total }}</text>
                    <text :x="b.x+14" y="118" text-anchor="middle" class="bar-day-txt">{{ b.day }}</text>
                  </g>
                  <line x1="0" y1="100" x2="240" y2="100" stroke="#e2e8f0" stroke-width="1"/>
                  <text v-if="!weeklyBars.length" x="120" y="60" text-anchor="middle" class="bar-empty-txt">Belum ada data</text>
                </svg>
              </div>
            </div>
          </div>

          <div class="card">
            <div class="card-top">
              <span class="card-label">REAL-TIME TRACKING</span>
              <div class="live-badge"><span class="live-dot"></span>LIVE</div>
            </div>
            <div class="filter-bar" style="margin-bottom:14px">
              <div class="search-wrap">
                <svg class="search-ico" fill="none" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8" stroke="currentColor" stroke-width="1.8"/><path d="M21 21l-4.35-4.35" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                <input v-model="realtimeSearch" class="search-inp" placeholder="Cari nama intern..." />
              </div>
            </div>
            <div class="tbl-scroll">
              <table class="dtbl">
                <thead><tr>
                  <th>Nama Intern</th><th>Status Kehadiran</th><th>Status Lokasi</th><th>Jam Masuk</th><th>Jam Keluar</th><th>Logbook Hari Ini</th>
                </tr></thead>
                <tbody>
                  <tr v-for="u in filteredRealtime" :key="u.id">
                    <td class="td-name"><strong>{{ u.nama }}</strong></td>
                    <td><span class="badge" :class="kehadiranCls(u.kehadiran)">{{ u.kehadiran }}</span></td>
                    <td><span :class="u.status_lokasi==='Di Kantor'?'text-blue':u.status_lokasi==='Keluar Kantor'?'text-orange':'text-muted'">{{ u.status_lokasi || '—' }}</span></td>
                    <td class="td-tm">{{ u.jam_masuk || '—' }}</td>
                    <td class="td-tm">{{ u.jam_keluar || '—' }}</td>
                    <td class="td-log" :class="{'td-log-click': u.logbook && u.logbook!=='-'}" @click="openLogbook(u.logbook, u.nama)">{{ limitText(u.logbook, 38) }}</td>
                  </tr>
                  <tr v-if="filteredRealtime.length === 0"><td colspan="6" class="td-empty">Belum ada data absensi hari ini.</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div v-if="activeMenu === 'history-intern'" class="anim-in">
          <div class="card">
            <div class="card-top">
              <span class="card-label">REKAPITULASI HISTORI SELURUH INTERN</span>
              <span class="result-count">{{ historyTotal }} data</span>
            </div>

            <div class="filter-bar">
              <div class="search-wrap">
                <svg class="search-ico" fill="none" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8" stroke="currentColor" stroke-width="1.8"/><path d="M21 21l-4.35-4.35" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                <input v-model="historySearch" class="search-inp" placeholder="Cari nama intern..." />
              </div>
              <input type="date" v-model="historyFilterDate" class="fsel" title="Filter tanggal" />
              <select v-model="historyFilterStatus" class="fsel">
                <option value="">Semua Status</option>
                <option value="present">Hadir</option>
                <option value="permit">Izin</option>
                <option value="absent">Tidak Hadir</option>
              </select>
              <button v-if="historySearch || historyFilterStatus || historyFilterDate" @click="historySearch='';historyFilterStatus='';historyFilterDate=''" class="btn-reset">
                <svg width="13" height="13" fill="none" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                Reset
              </button>
            </div>

            <div class="tbl-scroll">
              <table class="dtbl">
                <thead><tr>
                  <th class="th-sort" @click="setSort('tanggal')">Tanggal {{ sortIcon('tanggal') }}</th>
                  <th class="th-sort" @click="setSort('nama')">Nama {{ sortIcon('nama') }}</th>
                  <th>Status</th>
                  <th>Lokasi</th>
                  <th class="th-sort" @click="setSort('clock_in')">Masuk {{ sortIcon('clock_in') }}</th>
                  <th class="th-sort" @click="setSort('clock_out')">Pulang {{ sortIcon('clock_out') }}</th>
                  <th>Logbook</th>
                  <th style="text-align:center">Aksi</th>
                </tr></thead>
                <tbody>
                  <tr v-for="att in pagedHistory" :key="att.id">
                    <td class="td-dt">{{ formatTgl(att.tanggal) }}</td>
                    <td class="td-name"><strong>{{ att.nama }}</strong></td>
                    <td><span class="badge" :class="statusCls(att.status)">{{ fmtStatus(att.status) }}</span></td>
                    <td class="text-muted">{{ fmtOffice(att.office_status) }}</td>
                    <td class="td-tm">{{ att.clock_in || '—' }}</td>
                    <td class="td-tm">{{ att.clock_out || '—' }}</td>
                    <td class="td-log" :class="{'td-log-click': att.logbook && att.logbook!=='-'}" @click="openLogbook(att.logbook, att.nama)">{{ limitText(att.logbook, 35) }}</td>
                    <td>
                      <div class="action-row">
                        <a v-if="att.status==='permit' && att.evidence_path" :href="att.evidence_path" target="_blank" class="btn-act btn-act-view">📄</a>
                        <button @click="openEditAttendance(att)" class="btn-act btn-act-edit">✏️ Edit</button>
                      </div>
                    </td>
                  </tr>
                  <tr v-if="pagedHistory.length === 0"><td colspan="8" class="td-empty">Tidak ada data yang sesuai filter.</td></tr>
                </tbody>
              </table>
            </div>

            <div class="pagination" v-if="historyPages > 1">
              <button class="pg-btn" :disabled="historyPage===1" @click="historyPage=1">«</button>
              <button class="pg-btn" :disabled="historyPage===1" @click="historyPage--">‹</button>
              <template v-for="pg in paginationRange" :key="pg">
                <button v-if="pg==='...'" class="pg-btn pg-ellipsis" disabled>…</button>
                <button v-else class="pg-btn" :class="{'pg-active': historyPage===pg}" @click="historyPage=pg">{{ pg }}</button>
              </template>
              <button class="pg-btn" :disabled="historyPage===historyPages" @click="historyPage++">›</button>
              <button class="pg-btn" :disabled="historyPage===historyPages" @click="historyPage=historyPages">»</button>
              <span class="pg-info">{{ (historyPage-1)*15+1 }}–{{ Math.min(historyPage*15, historyTotal) }} dari {{ historyTotal }}</span>
            </div>
          </div>
        </div>

        <div v-if="activeMenu === 'manage-intern' || activeMenu === 'manage-hr'" class="anim-in">
          <div class="card">
            <div class="card-top">
              <span class="card-label">DAFTAR {{ activeMenu === 'manage-intern' ? 'INTERN' : 'ADMIN HR' }}</span>
              <button @click="openModal()" class="btn-add">
                <svg width="14" height="14" fill="none" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
                Tambah {{ activeMenu === 'manage-intern' ? 'Intern' : 'HR' }}
              </button>
            </div>
            <div class="tbl-scroll">
              <table class="dtbl">
                <thead><tr><th>Nama Lengkap</th><th>Email</th><th>No. HP</th><th style="text-align:center">Aksi</th></tr></thead>
                <tbody>
                  <tr v-for="u in (activeMenu === 'manage-intern' ? interns : hrList)" :key="u.id">
                    <td class="td-name">
                      <strong>{{ u.name }}</strong>
                      <span v-if="u.id === authStore.user.id" class="me-tag">Anda</span>
                    </td>
                    <td class="text-muted">{{ u.email }}</td>
                    <td class="text-muted">{{ u.phone || '—' }}</td>
                    <td>
                      <div class="action-row" style="justify-content:center">
                        <button @click="openModal(u)" class="btn-act btn-act-edit">✏️ Edit</button>
                        <button v-if="u.id !== authStore.user.id" @click="confirmDelete(u.id)" class="btn-act btn-act-del">🗑️ Hapus</button>
                      </div>
                    </td>
                  </tr>
                  <tr v-if="(activeMenu==='manage-intern'?interns:hrList).length===0">
                    <td colspan="4" class="td-empty">Belum ada data.</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div v-if="activeMenu === 'settings'" class="anim-in">
          <div class="card" style="margin-bottom:18px">
            <div class="card-top">
              <span class="card-label">ATURAN JAM KERJA</span>
              <button @click="saveSettings" class="btn-add">
                <svg width="14" height="14" fill="none" viewBox="0 0 24 24"><path d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                Simpan Pengaturan
              </button>
            </div>
            <div class="settings-grid">
              <div class="fgrp">
                <label class="flbl">Buka Akses Absen</label>
                <input type="time" v-model="attendanceSettings.start_time" class="finp" />
              </div>
              <div class="fgrp">
                <label class="flbl">Batas Terlambat</label>
                <input type="time" v-model="attendanceSettings.late_threshold" class="finp" />
              </div>
              <div class="fgrp">
                <label class="flbl">Auto-Alpa (Batas Pulang)</label>
                <input type="time" v-model="attendanceSettings.end_time" class="finp" />
              </div>
            </div>
          </div>

          <div class="card">
            <div class="card-top">
              <span class="card-label">KALENDER HARI AKTIF</span>
              <div class="cal-mode-toggle">
                <span class="cal-mode-lbl">Mode Edit</span>
                <label class="toggle-switch">
                  <input type="checkbox" v-model="isCalendarEditMode" />
                  <span class="toggle-track"></span>
                </label>
              </div>
            </div>
            <div class="cal-nav">
              <button @click="prevMonth" class="btn-nav">◀</button>
              <span class="cal-month">{{ currentMonthName }} {{ currentYear }}</span>
              <button @click="nextMonth" class="btn-nav">▶</button>
            </div>
            <div class="cal-legend">
              <span class="cl-item"><span class="cl-box cl-work"></span>Hari Kerja</span>
              <span class="cl-item"><span class="cl-box cl-weekend"></span>Akhir Pekan</span>
              <span class="cl-item"><span class="cl-box cl-holiday"></span>Libur Spesial</span>
              <span class="cl-item"><span class="cl-box cl-today"></span>Hari Ini</span>
            </div>
            <div class="cal-grid" :class="{'cal-edit': isCalendarEditMode}">
              <div class="cal-dow" v-for="d in ['Sen','Sel','Rab','Kam','Jum','Sab','Min']" :key="d">{{ d }}</div>
              <div v-for="(day,i) in calendarDays" :key="i" class="cal-cell"
                :class="{'cal-empty':day.empty,'cal-weekend':!day.empty&&day.isWeekend&&!day.holiday,'cal-holiday':!day.empty&&day.holiday,'cal-today':!day.empty&&day.isToday}"
                @click="handleDateClick(day)">
                <span v-if="!day.empty" class="cal-num">{{ day.date }}</span>
                <span v-if="!day.empty&&day.holiday" class="cal-hol-desc">{{ day.holiday.description }}</span>
                <div v-if="isCalendarEditMode&&!day.empty" class="cal-edit-overlay">
                  <span>{{ day.holiday ? 'Hapus' : '+ Libur' }}</span>
                </div>
              </div>
            </div>
          </div>
        </div>

      </div></main>

    <transition name="modal-pop">
      <div v-if="showLogbookModal" class="modal-bg">
        <div class="modal-box">
          <div class="modal-hdr">
            <h3 class="modal-ttl">📝 Logbook — {{ selectedLogbookName }}</h3>
            <button class="btn-x" @click="showLogbookModal = false">
              <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </button>
          </div>
          <div class="modal-body-txt">
            <p class="logbook-full">{{ selectedLogbookText }}</p>
          </div>
          <div class="modal-foot">
            <button @click="showLogbookModal = false" class="btn-cancel" style="width:auto">Tutup</button>
          </div>
        </div>
      </div>
    </transition>

    <transition name="modal-pop">
      <div v-if="showModal" class="modal-bg">
        <div class="modal-box">
          <div class="modal-hdr">
            <h3 class="modal-ttl">{{ isEditMode ? 'Edit' : 'Tambah' }} {{ activeMenu==='manage-intern'?'Intern':'HR' }}</h3>
            <button class="btn-x" @click="showModal = false">
              <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </button>
          </div>
          <div class="modal-body-form">
            <div class="fgrp"><label class="flbl">Nama Lengkap</label><input v-model="form.name" type="text" class="finp" placeholder="Nama lengkap..." /></div>
            <div class="fgrp"><label class="flbl">Email</label><input v-model="form.email" type="email" class="finp" placeholder="email@example.com" /></div>
            <div class="fgrp"><label class="flbl">No. WhatsApp</label><input v-model="form.phone" type="text" class="finp" placeholder="08xxxxxxxxxx" /></div>
            <div class="fgrp" v-if="!isEditMode"><label class="flbl">Password Awal</label><input v-model="form.password" type="password" class="finp" placeholder="Minimal 8 karakter" /></div>
          </div>
          <div class="modal-foot">
            <button @click="showModal = false" class="btn-cancel">Batal</button>
            <button @click="handleSave" class="btn-capture" style="flex:2">Simpan Data</button>
          </div>
        </div>
      </div>
    </transition>

    <transition name="modal-pop">
      <div v-if="showEditAttendanceModal" class="modal-bg">
        <div class="modal-box">
          <div class="modal-hdr">
            <h3 class="modal-ttl">✏️ Edit Absensi</h3>
            <button class="btn-x" @click="showEditAttendanceModal = false">
              <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </button>
          </div>
          <div class="modal-body-form">
            <div class="att-info-box">
              <strong>{{ formAttendance.nama }}</strong>
              <small>{{ formatTgl(formAttendance.date) }}</small>
            </div>
            <div class="fgrp">
              <label class="flbl">Status Kehadiran</label>
              <select v-model="formAttendance.status" class="finp">
                <option value="present">Hadir (Present)</option>
                <option value="permit">Izin (Permit)</option>
                <option value="absent">Tidak Hadir (Absent)</option>
              </select>
            </div>
            <div v-if="formAttendance.status==='present'" class="time-row">
              <div class="fgrp">
                <label class="flbl">Jam Masuk</label>
                <input type="time" v-model="formAttendance.clock_in" class="finp" />
              </div>
              <div class="fgrp">
                <label class="flbl">Jam Keluar</label>
                <input type="time" v-model="formAttendance.clock_out" class="finp" />
              </div>
            </div>
            <p v-if="formAttendance.status==='present'" class="hint-sm">* Kosongkan jam keluar jika belum pulang.</p>
          </div>
          <div class="modal-foot">
            <button @click="showEditAttendanceModal = false" class="btn-cancel">Batal</button>
            <button @click="saveAttendance" class="btn-capture" style="flex:2">Simpan Data</button>
          </div>
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

.shell {
  display: flex; height: 100dvh;
  background: #f0f4f9;
  font-family: 'Inter', 'Helvetica Neue', Arial, sans-serif;
  color: #111827; overflow: hidden;
}

/* ============================================================
   OVERLAY DIM
============================================================ */
.overlay-dim { position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 199; backdrop-filter: blur(3px); }
.fade-overlay-enter-active, .fade-overlay-leave-active { transition: opacity .25s; }
.fade-overlay-enter-from, .fade-overlay-leave-to { opacity: 0; }

/* ============================================================
   SIDEBAR
============================================================ */
.sidebar {
  width: 250px; min-width: 250px; flex-shrink: 0;
  background: #ffffff; border-right: 1px solid #e2e8f0;
  display: flex; flex-direction: column; z-index: 200;
  transition: transform .28s cubic-bezier(.4,0,.2,1);
}
@media (max-width: 1024px) {
  .sidebar { position: fixed; top: 0; left: 0; bottom: 0; transform: translateX(-100%); box-shadow: 4px 0 20px rgba(0,0,0,.12); }
  .sidebar-visible { transform: translateX(0) !important; }
}
@media (min-width: 1025px) {
  .sidebar { transform: translateX(0) !important; }
  .sidebar:not(.sidebar-visible) { transform: translateX(-100%); }
  .sidebar-visible { transform: translateX(0); }
}
.sb-brand { display: flex; align-items: center; gap: 10px; padding: 20px 18px 16px; border-bottom: 1px solid #e2e8f0; }
.sb-logo { width: 32px; height: 32px; object-fit: contain; }
.sb-title { font-size: 1.1rem; font-weight: 800; color: #00529C; letter-spacing: .3px; }
.sb-orange { color: #F37021; }
.sb-user { display: flex; align-items: center; gap: 11px; padding: 14px 18px; border-bottom: 1px solid #e2e8f0; background: #e8f1fb; }
.sb-avatar { width: 38px; height: 38px; border-radius: 50%; background: #00529C; color: #fff; display: flex; align-items: center; justify-content: center; font-size: .95rem; font-weight: 800; flex-shrink: 0; }
.sb-userinfo { display: flex; flex-direction: column; min-width: 0; }
.sb-name { font-size: .88rem; font-weight: 700; color: #003f8a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sb-role { font-size: .68rem; color: #64748b; }
.sb-nav { flex: 1; padding: 12px 10px; display: flex; flex-direction: column; gap: 3px; overflow-y: auto; }
.nav-btn { display: flex; align-items: center; gap: 10px; padding: 11px 13px; width: 100%; border: none; background: transparent; border-radius: 8px; color: #64748b; font-size: .85rem; font-weight: 600; cursor: pointer; text-align: left; transition: background .15s, color .15s; }
.nav-btn:hover { background: #f0f4f9; color: #00529C; }
.nav-btn-active { background: #e8f1fb !important; color: #00529C !important; }
.nav-ico { width: 17px; height: 17px; flex-shrink: 0; }
.sb-foot { padding: 14px; border-top: 1px solid #e2e8f0; }
.btn-logout { width: 100%; padding: 10px 14px; display: flex; align-items: center; justify-content: center; gap: 7px; background: #fff5f5; color: #dc2626; border: 1px solid #fecaca; border-radius: 8px; font-size: .83rem; font-weight: 600; cursor: pointer; transition: background .15s; }
.btn-logout:hover { background: #fee2e2; }

/* ============================================================
   MAIN
============================================================ */
.main-area { flex: 1; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
.topbar { display: flex; align-items: center; gap: 12px; padding: 0 22px; height: 58px; background: #ffffff; border-bottom: 1px solid #e2e8f0; flex-shrink: 0; }
.hamburger { display: none; align-items: center; justify-content: center; width: 36px; height: 36px; border: none; background: #f0f4f9; border-radius: 8px; color: #64748b; cursor: pointer; flex-shrink: 0; transition: background .15s; }
.hamburger:hover { background: #e2e8f0; }
.topbar-center { flex: 1; padding-left: 4px; }
.tb-page { font-size: .95rem; font-weight: 700; color: #111827; }
.topbar-right { text-align: right; }
.tb-date { display: block; font-size: .69rem; color: #94a3b8; }
.tb-time { font-size: .95rem; font-weight: 800; color: #00529C; }
.tb-time em { font-style: normal; font-size: .68rem; font-weight: 500; color: #94a3b8; margin-left: 2px; }
.page-wrap { flex: 1; overflow-y: auto; padding: 22px; }
.anim-in { animation: animIn .3s ease both; }
@keyframes animIn { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:none; } }

/* ============================================================
   CARDS
============================================================ */
.card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 22px; box-shadow: 0 1px 3px rgba(0,0,0,.05), 0 4px 16px rgba(0,0,0,.04); margin-bottom: 18px; }
.card-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 18px; flex-wrap: wrap; }
.card-label { font-size: .67rem; font-weight: 700; text-transform: uppercase; letter-spacing: .09em; color: #9ca3af; }
.result-count { font-size: .75rem; color: #64748b; font-weight: 600; background: #f0f4f9; padding: 4px 10px; border-radius: 20px; }

/* GREETING BANNER */
.greeting-bar { display: flex; align-items: flex-start; gap: 14px; padding: 15px 18px; border-radius: 12px; border-left: 4px solid; margin-bottom: 18px; }
.gb-holiday { background: #f0fdf4; border-color: #22c55e; }
.gb-ico { font-size: 1.4rem; flex-shrink: 0; }
.gb-title { font-size: .9rem; font-weight: 700; color: #111827; }
.gb-sub { font-size: .8rem; color: #64748b; margin-top: 3px; }

/* ============================================================
   STAT CARDS (DIPERBESAR & LEBIH LEGA)
============================================================ */
.stat-grid { 
  display: grid; 
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); 
  gap: 20px; 
  margin-bottom: 25px; 
}

.stat-card { 
  background: #fff; 
  border: 1px solid #e2e8f0; 
  border-radius: 16px; 
  padding: 24px 20px; 
  display: flex; 
  align-items: center; 
  gap: 18px; 
  box-shadow: 0 4px 6px rgba(0,0,0,.03), 0 1px 3px rgba(0,0,0,.05); 
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.stat-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 10px 15px -3px rgba(0,0,0,.05), 0 4px 6px -2px rgba(0,0,0,.03);
}

.sc-icon-wrap { 
  width: 54px; 
  height: 54px; 
  border-radius: 14px; 
  display: flex; 
  align-items: center; 
  justify-content: center; 
  flex-shrink: 0; 
}

.sc-icon-wrap svg {
  width: 28px; 
  height: 28px;
}

.sc-iw-blue   { background: #e8f1fb; color: #00529C; }
.sc-iw-green  { background: #d1fae5; color: #059669; }
.sc-iw-amber  { background: #fef3c7; color: #d97706; }
.sc-iw-purple { background: #ede9fe; color: #7c3aed; }
.sc-iw-red    { background: #fee2e2; color: #dc2626; }

.sc-body { 
  display: flex; 
  flex-direction: column; 
  min-width: 0; 
}

.sc-label { 
  font-size: .8rem; 
  font-weight: 700; 
  color: #64748b; 
  text-transform: uppercase; 
  letter-spacing: .05em; 
  white-space: nowrap; 
  margin-bottom: 4px; 
}

.sc-num { 
  font-size: 2.2rem; 
  font-weight: 900; 
  color: #111827; 
  line-height: 1; 
}

.sc-blue  { border-bottom: 4px solid #00529C; }
.sc-green { border-bottom: 4px solid #10b981; }
.sc-amber { border-bottom: 4px solid #f59e0b; }
.sc-purple{ border-bottom: 4px solid #7c3aed; }
.sc-red   { border-bottom: 4px solid #ef4444; }

/* ============================================================
   CHART GRID
============================================================ */
.dash-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-bottom: 18px; }
@media (max-width: 900px) { .dash-grid-2 { grid-template-columns: 1fr; } }

.chart-card { padding: 20px; }
.bar-chart-wrap { width: 100%; overflow-x: auto; }
.bar-svg { width: 100%; min-width: 200px; height: 140px; }
.bar-val-txt { font-size: 7px; fill: #64748b; font-weight: 700; }
.bar-day-txt { font-size: 7px; fill: #9ca3af; }
.bar-empty-txt { font-size: 10px; fill: #9ca3af; font-style: italic; }

/* ============================================================
   JUMBO DONUT CHART (BIKIN PENUH LAYAR)
============================================================ */
@media (min-width: 901px) {
  .dash-grid-2 {
    grid-template-columns: 1fr; /* Jadi satu kolom jumbo */
  }
}

.full-screen-chart {
  padding: 40px; 
  min-height: 80dvh; 
  display: flex;
  flex-direction: column;
}

.jumbo-donut {
  flex: 1; 
  display: flex;
  flex-direction: column; 
  align-items: center;
  justify-content: center;
  gap: 40px; 
}

@media (min-width: 768px) {
  .jumbo-donut {
    flex-direction: row; 
    gap: 60px;
  }
}

.jumbo-donut .donut-svg {
  width: 100%; 
  max-width: 500px; 
  height: auto;
}

.jumbo-text {
  font-size: 48px; 
  font-weight: 900;
}

.jumbo-label {
  font-size: 18px; 
  font-weight: 700;
}

.jumbo-legend {
  width: 100%;
  max-width: 350px; 
  display: flex;
  flex-direction: column;
  gap: 15px;
}

.jumbo-dl-item {
  padding: 15px;
  background: #f8fafc;
  border-radius: 10px;
  border: 1px solid #e2e8f0;
  display: flex; 
  align-items: center; 
  gap: 8px;
}

.jumbo-dl-item .dl-label {
  font-size: 1rem; 
  font-weight: 700;
  color: #64748b; 
  flex: 1;
}

.jumbo-dl-item .dl-val {
  font-size: 1.1rem; 
  font-weight: 800;
  color: #111827; 
  white-space: nowrap;
}
.jumbo-dl-item .dl-val small { font-weight: 400; color: #9ca3af; font-size: .8rem; }

.jumbo-dl-item .dl-dot {
  width: 15px; 
  height: 15px;
  border-radius: 3px; 
  flex-shrink: 0;
}
.dl-empty { font-size: .78rem; color: #9ca3af; font-style: italic; }

/* ============================================================
   LIVE BADGE
============================================================ */
.live-badge { display: inline-flex; align-items: center; gap: 5px; background: #d1fae5; color: #065f46; border-radius: 20px; padding: 4px 10px; font-size: .68rem; font-weight: 800; letter-spacing: .05em; }
.live-dot { width: 7px; height: 7px; background: #10b981; border-radius: 50%; animation: sdot-pulse 1.5s ease infinite; }
@keyframes sdot-pulse { 0%,100%{opacity:1} 50%{opacity:.3} }

/* ============================================================
   FILTER BAR
============================================================ */
.filter-bar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
.search-wrap { position: relative; flex: 1; min-width: 180px; }
.search-ico { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 15px; height: 15px; color: #9ca3af; pointer-events: none; }
.search-inp { width: 100%; padding: 8px 12px 8px 34px; border: 1.5px solid #e2e8f0; border-radius: 8px; font-size: .84rem; color: #111827; background: #f8fafc; outline: none; transition: border-color .15s; }
.search-inp:focus { border-color: #00529C; box-shadow: 0 0 0 3px rgba(0,82,156,.08); }
.fsel { padding: 8px 10px; border: 1.5px solid #e2e8f0; border-radius: 8px; font-size: .82rem; color: #374151; background: #f8fafc; outline: none; cursor: pointer; transition: border-color .15s; }
.fsel:focus { border-color: #00529C; }
.btn-reset { display: flex; align-items: center; gap: 5px; padding: 8px 12px; background: #fff5f5; color: #dc2626; border: 1px solid #fecaca; border-radius: 8px; font-size: .78rem; font-weight: 600; cursor: pointer; transition: background .15s; white-space: nowrap; }
.btn-reset:hover { background: #fee2e2; }

/* ============================================================
   TABLES
============================================================ */
.tbl-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.dtbl { width: 100%; border-collapse: collapse; font-size: .84rem; }
.dtbl th { background: #f8fafc; padding: 9px 13px; text-align: left; color: #64748b; font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
.th-sort { cursor: pointer; user-select: none; transition: background .15s; }
.th-sort:hover { background: #f0f4f9; color: #00529C; }
.dtbl td { padding: 11px 13px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.dtbl tbody tr:last-child td { border-bottom: none; }
.dtbl tbody tr:hover { background: #f8fafc; }
.td-dt   { font-size: .8rem; color: #64748b; white-space: nowrap; }
.td-tm   { font-family: 'SF Mono','Monaco',monospace; font-weight: 700; color: #00529C; white-space: nowrap; }
.td-name strong { font-weight: 700; }
.td-log  { max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #64748b; font-style: italic; }
.td-log-click { cursor: pointer; color: #00529C; font-style: normal; font-weight: 500; transition: background .15s; }
.td-log-click:hover { background: #e8f1fb; border-radius: 4px; }
.td-empty { text-align: center; padding: 36px; color: #9ca3af; font-size: .85rem; }
.text-muted { color: #64748b; }
.text-blue   { color: #00529C; font-weight: 600; }
.text-orange { color: #F37021; font-weight: 600; }
.me-tag { display: inline-block; margin-left: 6px; padding: 2px 7px; background: #e8f1fb; color: #00529C; border-radius: 4px; font-size: .68rem; font-weight: 700; }

/* BADGES */
.badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: .71rem; font-weight: 700; }
.bs-ok     { background: #d1fae5; color: #065f46; }
.bs-late   { background: #fef3c7; color: #92400e; }
.bs-warn   { background: #ede9fe; color: #5b21b6; }
.bs-danger { background: #fee2e2; color: #991b1b; }
.bs-muted  { background: #f3f4f6; color: #374151; }

/* ACTION BUTTONS */
.action-row { display: flex; gap: 6px; align-items: center; }
.btn-act { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 6px; font-size: .73rem; font-weight: 600; cursor: pointer; transition: background .15s, color .15s; white-space: nowrap; border: 1px solid; }
.btn-act-edit { color: #00529C; border-color: #93c5fd; background: #eff6ff; }
.btn-act-edit:hover { background: #dbeafe; }
.btn-act-del  { color: #dc2626; border-color: #fca5a5; background: #fff5f5; }
.btn-act-del:hover  { background: #fee2e2; }
.btn-act-view { color: #059669; border-color: #6ee7b7; background: #d1fae5; text-decoration: none; }
.btn-act-view:hover { background: #a7f3d0; }

/* ADD BUTTON */
.btn-add { display: flex; align-items: center; gap: 6px; padding: 8px 14px; background: #00529C; color: #fff; border: none; border-radius: 8px; font-size: .82rem; font-weight: 700; cursor: pointer; transition: filter .15s; white-space: nowrap; }
.btn-add:hover { filter: brightness(1.1); }

/* ============================================================
   PAGINATION
============================================================ */
.pagination { display: flex; align-items: center; gap: 5px; padding-top: 16px; flex-wrap: wrap; }
.pg-btn { min-width: 32px; height: 32px; padding: 0 8px; border: 1.5px solid #e2e8f0; border-radius: 7px; background: #fff; color: #374151; font-size: .82rem; font-weight: 600; cursor: pointer; transition: all .15s; }
.pg-btn:hover:not(:disabled) { border-color: #00529C; color: #00529C; background: #e8f1fb; }
.pg-btn:disabled { opacity: .4; cursor: not-allowed; }
.pg-active { background: #00529C !important; color: #fff !important; border-color: #00529C !important; }
.pg-ellipsis { border: none; background: transparent; cursor: default; }
.pg-info { font-size: .72rem; color: #64748b; margin-left: 6px; white-space: nowrap; }

/* ============================================================
   SETTINGS
============================================================ */
.settings-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
@media (max-width: 768px) { .settings-grid { grid-template-columns: 1fr 1fr; } }
@media (max-width: 480px) { .settings-grid { grid-template-columns: 1fr; } }
.fgrp { margin-bottom: 14px; }
.flbl { display: block; font-size: .72rem; font-weight: 700; color: #64748b; margin-bottom: 5px; }
.finp { width: 100%; padding: 9px 12px; border: 1.5px solid #e2e8f0; border-radius: 8px; font-size: .84rem; color: #111827; background: #f8fafc; outline: none; transition: border-color .15s; }
.finp:focus { border-color: #00529C; box-shadow: 0 0 0 3px rgba(0,82,156,.1); }

/* ============================================================
   CALENDAR
============================================================ */
.cal-mode-toggle { display: flex; align-items: center; gap: 9px; }
.cal-mode-lbl { font-size: .78rem; font-weight: 600; color: #64748b; }
.toggle-switch { position: relative; display: inline-block; width: 44px; height: 24px; }
.toggle-switch input { opacity: 0; width: 0; height: 0; }
.toggle-track { position: absolute; inset: 0; background: #cbd5e1; border-radius: 34px; cursor: pointer; transition: .3s; }
.toggle-track::before { content: ''; position: absolute; width: 16px; height: 16px; left: 4px; bottom: 4px; background: #fff; border-radius: 50%; transition: .3s; }
input:checked + .toggle-track { background: #00529C; }
input:checked + .toggle-track::before { transform: translateX(20px); }
.cal-nav { display: flex; align-items: center; justify-content: center; gap: 16px; margin-bottom: 14px; }
.btn-nav { background: #f1f5f9; border: none; padding: 7px 14px; border-radius: 8px; cursor: pointer; color: #00529C; font-weight: 700; transition: background .15s; }
.btn-nav:hover { background: #e8f1fb; }
.cal-month { font-size: 1rem; font-weight: 700; color: #111827; min-width: 180px; text-align: center; }
.cal-legend { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 14px; padding-bottom: 12px; border-bottom: 1px solid #e2e8f0; }
.cl-item { display: flex; align-items: center; gap: 6px; font-size: .78rem; color: #64748b; font-weight: 600; }
.cl-box { width: 14px; height: 14px; border-radius: 3px; }
.cl-work    { background: #fff; border: 1px solid #cbd5e1; }
.cl-weekend { background: #f8fafc; border: 1px solid #e2e8f0; }
.cl-holiday { background: #fee2e2; border: 1px solid #fca5a5; }
.cl-today   { background: #e8f1fb; border: 2px solid #00529C; }
.cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; }
.cal-dow { text-align: center; font-size: .72rem; font-weight: 700; color: #64748b; padding: 6px 0; text-transform: uppercase; }
.cal-cell { min-height: 72px; border: 1px solid #e2e8f0; border-radius: 8px; padding: 6px 7px; display: flex; flex-direction: column; position: relative; cursor: pointer; overflow: hidden; background: #fff; transition: box-shadow .15s; }
.cal-cell:hover:not(.cal-empty) { box-shadow: 0 2px 8px rgba(0,0,0,.08); }
.cal-empty { background: transparent; border: none; cursor: default; }
.cal-weekend { background: #f8fafc; color: #94a3b8; }
.cal-holiday { background: #fee2e2; border-color: #fca5a5; color: #b91c1c; }
.cal-today { border: 2px solid #00529C; background: #e8f1fb; }
.cal-num { font-weight: 800; font-size: 1rem; }
.cal-hol-desc { font-size: .62rem; font-weight: 600; margin-top: 3px; line-height: 1.2; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.cal-edit-overlay { position: absolute; inset: 0; background: rgba(0,82,156,.88); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: .78rem; opacity: 0; transition: opacity .2s; }
.cal-edit .cal-cell:not(.cal-empty):hover .cal-edit-overlay { opacity: 1; }

/* ============================================================
   MODALS
============================================================ */
.modal-bg { position: fixed; inset: 0; background: rgba(15,23,42,.55); display: flex; align-items: center; justify-content: center; z-index: 500; backdrop-filter: blur(6px); padding: 16px; }
.modal-box { background: #ffffff; border-radius: 16px; width: 100%; max-width: 440px; box-shadow: 0 24px 48px rgba(0,0,0,.18); overflow: hidden; }
.modal-pop-enter-active { animation: modalIn .22s cubic-bezier(.34,1.56,.64,1); }
.modal-pop-leave-active { animation: modalIn .18s cubic-bezier(.4,0,1,1) reverse; }
@keyframes modalIn { from { opacity:0; transform:scale(.93) translateY(10px); } to { opacity:1; transform:none; } }
.modal-hdr { display: flex; align-items: center; justify-content: space-between; padding: 18px 20px; border-bottom: 1px solid #e2e8f0; background: #f8fafc; }
.modal-ttl { font-size: .95rem; font-weight: 700; color: #111827; }
.btn-x { width: 30px; height: 30px; border-radius: 50%; border: none; background: #f0f4f9; color: #64748b; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background .15s; }
.btn-x:hover { background: #e2e8f0; }
.modal-body-txt { padding: 20px; max-height: 60dvh; overflow-y: auto; }
.logbook-full { white-space: pre-wrap; color: #334155; line-height: 1.7; font-size: .9rem; }
.modal-body-form { padding: 20px; }
.att-info-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 9px; padding: 12px 14px; margin-bottom: 16px; display: flex; flex-direction: column; gap: 2px; }
.att-info-box strong { font-size: .9rem; color: #111827; }
.att-info-box small { font-size: .75rem; color: #64748b; }
.time-row { display: flex; gap: 14px; }
.time-row .fgrp { flex: 1; }
.hint-sm { font-size: .72rem; color: #9ca3af; margin-top: -6px; margin-bottom: 8px; }
.modal-foot { display: flex; gap: 9px; padding: 14px 20px; border-top: 1px solid #e2e8f0; }
.btn-cancel { flex: 1; padding: 12px; background: #f0f4f9; color: #64748b; border: 1px solid #e2e8f0; border-radius: 9px; font-size: .875rem; font-weight: 600; cursor: pointer; transition: background .15s; }
.btn-cancel:hover { background: #e2e8f0; }
.btn-capture { display: flex; align-items: center; justify-content: center; gap: 9px; padding: 12px; background: #00529C; color: #fff; border: none; border-radius: 9px; font-size: .875rem; font-weight: 700; cursor: pointer; transition: filter .15s; }
.btn-capture:hover { filter: brightness(1.1); }

/* ============================================================
   RESPONSIVE
============================================================ */
@media (max-width: 1024px) { .page-wrap { padding: 16px; } .card { padding: 18px; } .topbar { padding: 0 16px; } }
@media (max-width: 640px) {
  .page-wrap { padding: 12px; }
  .card { padding: 14px; }
  .topbar { height: 50px; padding: 0 12px; }
  .tb-date { display: none; }
  .tb-time { font-size: .85rem; }
  .filter-bar { gap: 8px; }
  .fsel { font-size: .76rem; }
  .cal-cell { min-height: 48px; padding: 4px; }
  .cal-num { font-size: .82rem; }
  .cal-hol-desc { display: none; }
  .donut-wrap { flex-direction: column; align-items: flex-start; gap: 12px; }
  .donut-svg { width: 100px; height: 100px; }
  .hamburger {
    display: flex; /* Munculkan burger menu di layar <= 1024px */
  }
}
</style>
