<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/authStore'
import axios from 'axios'
import Swal from 'sweetalert2'

const router = useRouter()
const authStore = useAuthStore()

// STATE LOGIN
const email = ref('')
const password = ref('')
const showPassword = ref(false)
const isLoading = ref(false)

// STATE RESET PASSWORD
const showResetModal = ref(false)
const step = ref(1)
const resetForm = ref({ email: '', otp: '', new_password: '', confirm_password: '' })
const showNewPassword = ref(false)
const showConfirmPassword = ref(false)
const isResetLoading = ref(false)

const passwordsMatch = computed(() => {
  if (!resetForm.value.confirm_password) return true
  return resetForm.value.new_password === resetForm.value.confirm_password
})

const canSubmitReset = computed(() => {
  return resetForm.value.new_password &&
    resetForm.value.confirm_password &&
    passwordsMatch.value &&
    resetForm.value.otp.length >= 6
})

// ==========================================
// LOGIN
// ==========================================
const handleLogin = async () => {
  if (!email.value || !password.value) {
    return Swal.fire({ title: 'Peringatan', text: 'Email dan Password wajib diisi!', icon: 'warning', confirmButtonColor: '#00529C' })
  }
  isLoading.value = true
  try {
    const success = await authStore.login(email.value, password.value)
    if (success) {
      Swal.fire({ title: 'Berhasil!', text: 'Selamat datang kembali', icon: 'success', timer: 1500, showConfirmButton: false })
      setTimeout(() => {
        if (authStore.user.role === 'intern') router.push('/intern')
        else if (authStore.user.role === 'hr') router.push('/hr')
      }, 1500)
    } else {
      Swal.fire({ title: 'Gagal Masuk', text: 'Kredensial salah atau tidak ditemukan.', icon: 'error', confirmButtonColor: '#00529C' })
    }
  } catch {
    Swal.fire({ title: 'Error', text: 'Terjadi kesalahan pada sistem', icon: 'error', confirmButtonColor: '#00529C' })
  } finally {
    isLoading.value = false
  }
}

// ==========================================
// RESET PASSWORD
// ==========================================
const requestOTP = async () => {
  if (!resetForm.value.email) return Swal.fire({ title: 'Peringatan', text: 'Email wajib diisi!', icon: 'warning', confirmButtonColor: '#00529C' })
  isResetLoading.value = true
  try {
    Swal.fire({ title: 'Mengirim Email...', allowOutsideClick: false, didOpen: () => Swal.showLoading() })
    await axios.post('/send-otp-email', { email: resetForm.value.email })
    Swal.close()
    step.value = 2
    Swal.fire({ title: 'Berhasil!', text: 'Kode OTP telah dikirim ke email kamu.', icon: 'success', confirmButtonColor: '#00529C' })
  } catch (e) {
    Swal.fire({ title: 'Gagal', text: e.response?.data?.message || 'Email tidak terdaftar', icon: 'error', confirmButtonColor: '#00529C' })
  } finally {
    isResetLoading.value = false
  }
}

const submitReset = async () => {
  if (resetForm.value.new_password !== resetForm.value.confirm_password)
    return Swal.fire({ title: 'Peringatan', text: 'Password baru tidak cocok!', icon: 'warning', confirmButtonColor: '#00529C' })
  if (resetForm.value.new_password.length < 6)
    return Swal.fire({ title: 'Peringatan', text: 'Password minimal 6 karakter!', icon: 'warning', confirmButtonColor: '#00529C' })
  isResetLoading.value = true
  try {
    await axios.post('/reset-password', { email: resetForm.value.email, otp: resetForm.value.otp, new_password: resetForm.value.new_password })
    Swal.fire({ title: 'Sandi Diperbarui!', text: 'Silakan login dengan sandi baru kamu.', icon: 'success', confirmButtonColor: '#00529C' })
    closeModal()
  } catch (e) {
    Swal.fire({ title: 'Gagal', text: e.response?.data?.message || 'Kode OTP salah atau kedaluwarsa.', icon: 'error', confirmButtonColor: '#00529C' })
  } finally {
    isResetLoading.value = false
  }
}

const closeModal = () => {
  showResetModal.value = false
  step.value = 1
  resetForm.value = { email: '', otp: '', new_password: '', confirm_password: '' }
}
</script>

<template>
  <div class="page">

    <!-- ===== LEFT PANEL (branding) ===== -->
    <div class="left-panel">
      <!-- Geometric accent -->
      <div class="lp-grid"></div>
      <div class="lp-circle lp-circle-1"></div>
      <div class="lp-circle lp-circle-2"></div>

      <div class="lp-content">
        <div class="lp-logo-wrap">
          <img src="/LOGO.png" alt="BRI Logo" class="lp-logo" onerror="this.style.display='none'" />
        </div>
        <div class="lp-divider"></div>
        <h1 class="lp-title">BRIJISENT</h1>
        <p class="lp-subtitle">Portal Absensi<br>Industrial</p>

        <div class="lp-features">
          <div class="lp-feat">
            <div class="lp-feat-dot"></div>
            <span>Absensi biometrik berbasis wajah</span>
          </div>
          <div class="lp-feat">
            <div class="lp-feat-dot"></div>
            <span>Logbook harian terintegrasi</span>
          </div>
          <div class="lp-feat">
            <div class="lp-feat-dot"></div>
            <span>Manajemen kehadiran real-time</span>
          </div>
        </div>
      </div>

      <p class="lp-copy">© 2024 PT Bank Rakyat Indonesia</p>
    </div>

    <!-- ===== RIGHT PANEL (form) ===== -->
    <div class="right-panel">
      <div class="form-card">

        <!-- Mobile logo (hanya muncul di mobile) -->
        <div class="mobile-brand">
          <img src="/LOGO.png" alt="BRI" class="mb-logo" onerror="this.style.display='none'" />
          <span class="mb-title">BRI<span class="mb-orange">JISENT</span></span>
        </div>

        <!-- Heading -->
        <div class="form-head">
          <h2 class="form-title">Masuk ke Sistem</h2>
          <p class="form-sub">Silakan masukkan kredensial Anda untuk melanjutkan</p>
        </div>

        <!-- EMAIL -->
        <div class="field">
          <label class="flbl">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" stroke="currentColor" stroke-width="1.8"/><polyline points="22,6 12,13 2,6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            Alamat Email
          </label>
          <div class="inp-wrap">
            <input
              v-model="email"
              type="email"
              placeholder="nama@perusahaan.com"
              class="inp"
              @keyup.enter="handleLogin"
              :disabled="isLoading"
            />
          </div>
        </div>

        <!-- PASSWORD -->
        <div class="field">
          <label class="flbl">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="M7 11V7a5 5 0 0110 0v4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            Password
          </label>
          <div class="inp-wrap inp-wrap-pw">
            <input
              v-model="password"
              :type="showPassword ? 'text' : 'password'"
              placeholder="Masukkan password"
              class="inp"
              @keyup.enter="handleLogin"
              :disabled="isLoading"
            />
            <button type="button" class="pw-toggle" @click="showPassword = !showPassword" tabindex="-1">
              <svg v-if="!showPassword" width="18" height="18" fill="none" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.8"/></svg>
              <svg v-else width="18" height="18" fill="none" viewBox="0 0 24 24"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><line x1="1" y1="1" x2="23" y2="23" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            </button>
          </div>
        </div>

        <!-- SUBMIT -->
        <button @click="handleLogin" class="btn-login" :disabled="isLoading">
          <span v-if="!isLoading" class="btn-login-inner">
            <svg width="17" height="17" fill="none" viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4M10 17l5-5-5-5M15 12H3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Masuk Sistem
          </span>
          <span v-else class="btn-loading">
            <span class="spin-ring"></span>
            Memuat...
          </span>
        </button>

        <!-- DIVIDER -->
        <div class="divider"><span>atau</span></div>

        <!-- FORGOT -->
        <button @click="showResetModal = true" class="btn-forgot">
          <svg width="15" height="15" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.8"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><line x1="12" y1="17" x2="12.01" y2="17" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/></svg>
          Lupa Password?
        </button>

        <!-- Footer -->
        <p class="form-foot">© 2024 BRIJISENT · PT Bank Rakyat Indonesia</p>
      </div>
    </div>

    <!-- ============================================================
         MODAL RESET PASSWORD
    ============================================================ -->
    <Transition name="modal-fade">
      <div v-if="showResetModal" class="modal-bg" @click.self="closeModal">
        <div class="modal-box">

          <!-- Close -->
          <button class="modal-x" @click="closeModal">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
          </button>

          <!-- Steps indicator -->
          <div class="steps-bar">
            <div class="step-node" :class="{ 'step-active': step >= 1, 'step-done': step > 1 }">
              <div class="step-circle">{{ step > 1 ? '✓' : '1' }}</div>
              <span>Email</span>
            </div>
            <div class="step-track" :class="{ 'step-track-done': step > 1 }"></div>
            <div class="step-node" :class="{ 'step-active': step >= 2 }">
              <div class="step-circle">2</div>
              <span>Verifikasi</span>
            </div>
          </div>

          <!-- STEP 1 -->
          <div v-if="step === 1" class="modal-body">
            <div class="modal-icon-wrap">
              <svg width="28" height="28" fill="none" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" stroke="#00529C" stroke-width="1.8"/><polyline points="22,6 12,13 2,6" stroke="#00529C" stroke-width="1.8" stroke-linecap="round"/></svg>
            </div>
            <h3 class="modal-title">Pemulihan Akses</h3>
            <p class="modal-desc">Masukkan email terdaftar untuk menerima kode OTP.</p>

            <div class="field" style="text-align:left">
              <label class="flbl">Alamat Email</label>
              <div class="inp-wrap">
                <input v-model="resetForm.email" type="email" placeholder="email@perusahaan.com" class="inp" @keyup.enter="requestOTP" />
              </div>
            </div>

            <button @click="requestOTP" class="btn-primary" :disabled="isResetLoading || !resetForm.email">
              <span v-if="!isResetLoading">Kirim Kode OTP</span>
              <span v-else class="btn-loading"><span class="spin-ring spin-ring-sm"></span>Mengirim...</span>
            </button>
          </div>

          <!-- STEP 2 -->
          <div v-if="step === 2" class="modal-body">
            <div class="modal-icon-wrap modal-icon-ok">
              <svg width="28" height="28" fill="none" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 11-5.93-9.14" stroke="#059669" stroke-width="1.8" stroke-linecap="round"/><polyline points="22 4 12 14.01 9 11.01" stroke="#059669" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </div>
            <h3 class="modal-title">Verifikasi OTP</h3>
            <p class="modal-desc">Kode OTP telah dikirim ke <strong>{{ resetForm.email }}</strong></p>

            <!-- OTP -->
            <div class="field" style="text-align:left">
              <label class="flbl">Kode OTP</label>
              <div class="inp-wrap">
                <input v-model="resetForm.otp" type="text" placeholder="000000" maxlength="6" class="inp otp-inp" />
              </div>
            </div>

            <!-- New password -->
            <div class="field" style="text-align:left">
              <label class="flbl">Password Baru</label>
              <div class="inp-wrap inp-wrap-pw">
                <input v-model="resetForm.new_password" :type="showNewPassword ? 'text' : 'password'" placeholder="Minimal 6 karakter" class="inp" />
                <button type="button" class="pw-toggle" @click="showNewPassword = !showNewPassword" tabindex="-1">
                  <svg v-if="!showNewPassword" width="17" height="17" fill="none" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.8"/></svg>
                  <svg v-else width="17" height="17" fill="none" viewBox="0 0 24 24"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><line x1="1" y1="1" x2="23" y2="23" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                </button>
              </div>
            </div>

            <!-- Confirm password -->
            <div class="field" style="text-align:left">
              <label class="flbl">Konfirmasi Password</label>
              <div class="inp-wrap inp-wrap-pw" :class="{ 'inp-err': !passwordsMatch && resetForm.confirm_password }">
                <input v-model="resetForm.confirm_password" :type="showConfirmPassword ? 'text' : 'password'" placeholder="Ulangi password baru" class="inp" />
                <button type="button" class="pw-toggle" @click="showConfirmPassword = !showConfirmPassword" tabindex="-1">
                  <svg v-if="!showConfirmPassword" width="17" height="17" fill="none" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.8"/></svg>
                  <svg v-else width="17" height="17" fill="none" viewBox="0 0 24 24"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><line x1="1" y1="1" x2="23" y2="23" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                </button>
              </div>
              <span v-if="!passwordsMatch && resetForm.confirm_password" class="txt-err">Password tidak cocok!</span>
              <span v-else-if="passwordsMatch && resetForm.confirm_password" class="txt-ok">
                <svg width="12" height="12" fill="none" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                Password cocok
              </span>
            </div>

            <button @click="submitReset" class="btn-primary btn-green" :disabled="!canSubmitReset || isResetLoading">
              <span v-if="!isResetLoading">Simpan Password Baru</span>
              <span v-else class="btn-loading"><span class="spin-ring spin-ring-sm"></span>Memproses...</span>
            </button>

            <button @click="step = 1" class="btn-back">
              <svg width="14" height="14" fill="none" viewBox="0 0 24 24"><line x1="19" y1="12" x2="5" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><polyline points="12 19 5 12 12 5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
              Kembali
            </button>
          </div>

        </div>
      </div>
    </Transition>

  </div>
</template>

<style scoped>
/* ============================================================
   RESET
============================================================ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
button, input { font-family: 'Inter', 'Helvetica Neue', Arial, sans-serif; }

/* ============================================================
   PAGE SHELL — split layout
============================================================ */
.page {
  display: flex;
  min-height: 100dvh;
  font-family: 'Inter', 'Helvetica Neue', Arial, sans-serif;
  background: #f0f4f9;
}

/* ============================================================
   LEFT PANEL — branding
============================================================ */
.left-panel {
  width: 420px;
  min-width: 420px;
  background: #00529C;
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  flex-shrink: 0;
}

/* Geometric grid overlay */
.lp-grid {
  position: absolute;
  inset: 0;
  background-image:
    linear-gradient(rgba(255,255,255,.04) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,255,255,.04) 1px, transparent 1px);
  background-size: 40px 40px;
}

/* Subtle circle accents */
.lp-circle {
  position: absolute;
  border-radius: 50%;
  border: 1px solid rgba(255,255,255,.1);
}
.lp-circle-1 {
  width: 380px; height: 380px;
  top: -120px; right: -140px;
}
.lp-circle-2 {
  width: 260px; height: 260px;
  bottom: -80px; left: -80px;
  border-color: rgba(243,112,33,.25);
  background: rgba(243,112,33,.06);
}

.lp-content {
  position: relative;
  z-index: 1;
  text-align: center;
  padding: 40px;
}

.lp-logo-wrap {
  display: flex;
  justify-content: center;
  margin-bottom: 20px;
}
.lp-logo {
  width: 72px;
  height: 72px;
  object-fit: contain;
  filter: brightness(0) invert(1);
  drop-shadow: 0 4px 12px rgba(0,0,0,.2);
}

.lp-divider {
  width: 40px;
  height: 3px;
  background: #F37021;
  border-radius: 2px;
  margin: 0 auto 18px;
}

.lp-title {
  font-size: 2rem;
  font-weight: 900;
  color: #ffffff;
  letter-spacing: 3px;
  line-height: 1;
  margin-bottom: 8px;
}

.lp-subtitle {
  font-size: .9rem;
  color: rgba(255,255,255,.65);
  font-weight: 500;
  line-height: 1.6;
  margin-bottom: 40px;
}

.lp-features {
  display: flex;
  flex-direction: column;
  gap: 12px;
  text-align: left;
  max-width: 260px;
  margin: 0 auto;
}
.lp-feat {
  display: flex;
  align-items: center;
  gap: 12px;
}
.lp-feat-dot {
  width: 7px; height: 7px;
  border-radius: 50%;
  background: #F37021;
  flex-shrink: 0;
}
.lp-feat span {
  font-size: .82rem;
  color: rgba(255,255,255,.75);
  font-weight: 500;
  line-height: 1.4;
}

.lp-copy {
  position: absolute;
  bottom: 20px;
  left: 0; right: 0;
  text-align: center;
  font-size: .7rem;
  color: rgba(255,255,255,.35);
  z-index: 1;
}

/* ============================================================
   RIGHT PANEL — form
============================================================ */
.right-panel {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 32px 20px;
  background: #f0f4f9;
  min-height: 100dvh;
}

/* Mobile brand — hidden on desktop */
.mobile-brand {
  display: none;
  align-items: center;
  justify-content: center;
  gap: 10px;
  margin-bottom: 28px;
}
.mb-logo {
  width: 36px; height: 36px;
  object-fit: contain;
}
.mb-title {
  font-size: 1.3rem;
  font-weight: 900;
  color: #00529C;
  letter-spacing: 1px;
}
.mb-orange { color: #F37021; }

/* FORM CARD */
.form-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 16px;
  padding: 40px 40px 32px;
  width: 100%;
  max-width: 440px;
  box-shadow: 0 1px 3px rgba(0,0,0,.05), 0 8px 24px rgba(0,0,0,.06);
  animation: cardIn .4s cubic-bezier(.22,1,.36,1);
}
@keyframes cardIn {
  from { opacity: 0; transform: translateY(16px); }
  to { opacity: 1; transform: none; }
}

.form-head { margin-bottom: 28px; }
.form-title {
  font-size: 1.4rem;
  font-weight: 800;
  color: #111827;
  margin-bottom: 6px;
}
.form-sub {
  font-size: .82rem;
  color: #64748b;
  line-height: 1.5;
}

/* ============================================================
   FORM FIELDS
============================================================ */
.field { margin-bottom: 20px; }

.flbl {
  display: flex;
  align-items: center;
  gap: 7px;
  font-size: .72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .07em;
  color: #64748b;
  margin-bottom: 7px;
}
.flbl svg { color: #00529C; flex-shrink: 0; }

.inp-wrap {
  position: relative;
}
.inp-wrap-pw { display: flex; align-items: center; }

.inp {
  width: 100%;
  padding: 12px 16px;
  border: 1.5px solid #e2e8f0;
  border-radius: 9px;
  font-size: .9rem;
  color: #111827;
  background: #f8fafc;
  outline: none;
  transition: border-color .15s, box-shadow .15s, background .15s;
}
.inp:focus {
  border-color: #00529C;
  background: #ffffff;
  box-shadow: 0 0 0 3px rgba(0,82,156,.1);
}
.inp::placeholder { color: #9ca3af; }
.inp:disabled { opacity: .6; cursor: not-allowed; }

/* OTP special */
.otp-inp {
  text-align: center;
  letter-spacing: 10px;
  font-size: 1.3rem !important;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
}

/* Password toggle */
.pw-toggle {
  position: absolute;
  right: 13px;
  background: none; border: none;
  cursor: pointer; color: #9ca3af;
  display: flex; align-items: center; justify-content: center;
  padding: 2px;
  transition: color .15s;
  z-index: 1;
}
.pw-toggle:hover { color: #00529C; }

/* Error border */
.inp-err .inp { border-color: #dc2626; background: #fff5f5; }
.inp-err .inp:focus { box-shadow: 0 0 0 3px rgba(220,38,38,.1); }

.txt-err { display: flex; align-items: center; gap: 4px; font-size: .72rem; color: #dc2626; margin-top: 5px; }
.txt-ok  { display: flex; align-items: center; gap: 4px; font-size: .72rem; color: #059669; margin-top: 5px; }

/* ============================================================
   BUTTONS
============================================================ */
.btn-login {
  width: 100%;
  padding: 13px;
  background: #00529C;
  color: #ffffff;
  border: none;
  border-radius: 9px;
  font-size: .9rem;
  font-weight: 700;
  cursor: pointer;
  transition: filter .15s, transform .1s;
  margin-top: 4px;
}
.btn-login:hover:not(:disabled) { filter: brightness(1.1); transform: translateY(-1px); }
.btn-login:active:not(:disabled) { transform: none; }
.btn-login:disabled { background: #e2e8f0; color: #9ca3af; cursor: not-allowed; }

.btn-login-inner {
  display: flex; align-items: center; justify-content: center; gap: 9px;
}
.btn-loading {
  display: flex; align-items: center; justify-content: center; gap: 9px;
}

.divider {
  display: flex; align-items: center; gap: 12px;
  margin: 20px 0; color: #9ca3af; font-size: .78rem;
}
.divider::before, .divider::after { content: ''; flex: 1; height: 1px; background: #e2e8f0; }

.btn-forgot {
  width: 100%; padding: 12px;
  background: #f8fafc; color: #475569;
  border: 1.5px dashed #cbd5e1; border-radius: 9px;
  font-size: .85rem; font-weight: 600; cursor: pointer;
  display: flex; align-items: center; justify-content: center; gap: 8px;
  transition: border-color .15s, color .15s, background .15s;
}
.btn-forgot:hover { border-color: #00529C; color: #00529C; background: #e8f1fb; border-style: solid; }

.form-foot {
  text-align: center;
  font-size: .7rem;
  color: #9ca3af;
  margin-top: 24px;
}

/* ============================================================
   SPINNER
============================================================ */
.spin-ring {
  display: inline-block;
  width: 16px; height: 16px;
  border: 2.5px solid rgba(255,255,255,.3);
  border-top-color: #fff;
  border-radius: 50%;
  animation: spin .7s linear infinite;
  flex-shrink: 0;
}
.spin-ring-sm { width: 13px; height: 13px; border-width: 2px; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ============================================================
   MODAL
============================================================ */
.modal-bg {
  position: fixed; inset: 0;
  background: rgba(15,23,42,.52);
  display: flex; align-items: center; justify-content: center;
  z-index: 500; backdrop-filter: blur(6px); padding: 16px;
}

.modal-box {
  background: #ffffff;
  border-radius: 16px;
  width: 100%; max-width: 420px;
  max-height: 92dvh; overflow-y: auto;
  box-shadow: 0 24px 48px rgba(0,0,0,.18);
  position: relative;
}

.modal-fade-enter-active { animation: mIn .22s cubic-bezier(.34,1.56,.64,1); }
.modal-fade-leave-active { animation: mIn .18s ease reverse; }
@keyframes mIn { from { opacity:0; transform:scale(.94) translateY(10px); } to { opacity:1; transform:none; } }

.modal-x {
  position: absolute; top: 14px; right: 14px;
  width: 30px; height: 30px; border-radius: 50%;
  border: none; background: #f0f4f9; color: #64748b;
  cursor: pointer; display: flex; align-items: center; justify-content: center;
  z-index: 10; transition: background .15s;
}
.modal-x:hover { background: #e2e8f0; }

/* STEPS BAR */
.steps-bar {
  display: flex; align-items: center; justify-content: center;
  gap: 6px; padding: 24px 24px 0;
}
.step-node { display: flex; flex-direction: column; align-items: center; gap: 5px; }
.step-circle {
  width: 32px; height: 32px; border-radius: 50%;
  background: #f0f4f9; color: #9ca3af;
  display: flex; align-items: center; justify-content: center;
  font-size: .8rem; font-weight: 800;
  border: 2px solid #e2e8f0;
  transition: all .25s;
}
.step-node span { font-size: .68rem; color: #9ca3af; font-weight: 600; }
.step-active .step-circle { background: #00529C; color: #fff; border-color: #00529C; }
.step-active span { color: #00529C; }
.step-done .step-circle { background: #059669; color: #fff; border-color: #059669; }

.step-track {
  width: 48px; height: 2px;
  background: #e2e8f0; border-radius: 2px;
  margin-bottom: 16px;
  transition: background .3s;
}
.step-track-done { background: #059669; }

/* MODAL BODY */
.modal-body {
  padding: 20px 28px 28px;
  text-align: center;
}

.modal-icon-wrap {
  width: 60px; height: 60px; border-radius: 50%;
  background: #e8f1fb;
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto 16px;
}
.modal-icon-ok { background: #d1fae5; }

.modal-title { font-size: 1.1rem; font-weight: 800; color: #111827; margin-bottom: 6px; }
.modal-desc { font-size: .82rem; color: #64748b; line-height: 1.55; margin-bottom: 18px; }
.modal-desc strong { color: #00529C; }

/* Modal action buttons */
.btn-primary {
  width: 100%; padding: 12px;
  background: #00529C; color: #fff;
  border: none; border-radius: 9px;
  font-size: .875rem; font-weight: 700; cursor: pointer;
  transition: filter .15s; margin-top: 6px;
  display: flex; align-items: center; justify-content: center; gap: 8px;
}
.btn-primary:hover:not(:disabled) { filter: brightness(1.1); }
.btn-primary:disabled { background: #e2e8f0; color: #9ca3af; cursor: not-allowed; }
.btn-green { background: #059669; }
.btn-green:hover:not(:disabled) { filter: brightness(1.08); }
.btn-green:disabled { background: #e2e8f0; }

.btn-back {
  width: 100%; padding: 11px; margin-top: 10px;
  background: transparent; color: #64748b;
  border: none; border-radius: 9px;
  font-size: .82rem; font-weight: 600; cursor: pointer;
  display: flex; align-items: center; justify-content: center; gap: 6px;
  transition: background .15s, color .15s;
}
.btn-back:hover { background: #f0f4f9; color: #00529C; }

/* ============================================================
   RESPONSIVE
============================================================ */

/* Tablet: left panel lebih kecil */
@media (max-width: 1024px) {
  .left-panel { width: 320px; min-width: 320px; }
  .lp-title { font-size: 1.6rem; }
  .lp-features { display: none; } /* Sembunyikan fitur di tablet kecil */
}

/* Mobile: sembunyikan left panel, tampilkan mobile brand */
@media (max-width: 768px) {
  .page { display: block; }
  .left-panel { display: none; }
  .right-panel {
    min-height: 100dvh;
    padding: 24px 16px;
    align-items: flex-start;
    padding-top: 32px;
  }
  .mobile-brand { display: flex; }
  .form-card {
    padding: 28px 24px 24px;
    max-width: 100%;
    border-radius: 14px;
  }
  .form-title { font-size: 1.2rem; }

  /* Modal mobile */
  .modal-box { max-height: 95dvh; border-radius: 14px; }
  .modal-body { padding: 16px 20px 24px; }
  .steps-bar { padding: 20px 20px 0; }
}

@media (max-width: 380px) {
  .right-panel { padding: 16px 12px; padding-top: 24px; }
  .form-card { padding: 22px 18px 20px; }
  .otp-inp { letter-spacing: 6px; font-size: 1.1rem !important; }
}
</style>
