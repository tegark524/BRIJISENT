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
const resetForm = ref({ 
  email: '', 
  otp: '', 
  new_password: '', 
  confirm_password: '' 
})
const showNewPassword = ref(false)
const showConfirmPassword = ref(false)
const isResetLoading = ref(false)

// Computed untuk cek kecocokan password
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
// LOGIKA LOGIN
// ==========================================
const handleLogin = async () => {
  if (!email.value || !password.value) {
    return Swal.fire({
      title: 'Peringatan',
      text: 'Email dan Password wajib diisi!',
      icon: 'warning',
      confirmButtonColor: '#00529C'
    })
  }

  isLoading.value = true
  
  try {
    const success = await authStore.login(email.value, password.value)
    
    if (success) {
      Swal.fire({
        title: 'Berhasil!',
        text: 'Selamat datang kembali',
        icon: 'success',
        timer: 1500,
        showConfirmButton: false
      })
      
      setTimeout(() => {
        if (authStore.user.role === 'intern') router.push('/intern')
        else if (authStore.user.role === 'hr') router.push('/hr')
      }, 1500)
    } else {
      Swal.fire({
        title: 'Gagal Masuk',
        text: 'Kredensial salah atau tidak ditemukan.',
        icon: 'error',
        confirmButtonColor: '#00529C'
      })
    }
  } catch (error) {
    Swal.fire({
      title: 'Error',
      text: 'Terjadi kesalahan pada sistem',
      icon: 'error',
      confirmButtonColor: '#00529C'
    })
  } finally {
    isLoading.value = false
  }
}

// ==========================================
// LOGIKA LUPA PASSWORD
// ==========================================
const requestOTP = async () => {
  if (!resetForm.value.email) {
    return Swal.fire({
      title: 'Peringatan',
      text: 'Email wajib diisi!',
      icon: 'warning',
      confirmButtonColor: '#00529C'
    })
  }

  isResetLoading.value = true
  
  try {
    Swal.fire({ 
      title: 'Mengirim Email...', 
      allowOutsideClick: false, 
      didOpen: () => Swal.showLoading() 
    })
    
    await axios.post('/send-otp-email', { 
      email: resetForm.value.email 
    })
    
    Swal.close()
    step.value = 2
    Swal.fire({
      title: 'Berhasil!',
      text: 'Kode OTP telah dikirim ke email kamu.',
      icon: 'success',
      confirmButtonColor: '#00529C'
    })
  } catch (e) {
    Swal.fire({
      title: 'Gagal',
      text: e.response?.data?.message || 'Email tidak terdaftar',
      icon: 'error',
      confirmButtonColor: '#00529C'
    })
  } finally {
    isResetLoading.value = false
  }
}

const submitReset = async () => {
  if (resetForm.value.new_password !== resetForm.value.confirm_password) {
    return Swal.fire({
      title: 'Peringatan',
      text: 'Password baru dan konfirmasi password tidak cocok!',
      icon: 'warning',
      confirmButtonColor: '#00529C'
    })
  }

  if (resetForm.value.new_password.length < 6) {
    return Swal.fire({
      title: 'Peringatan',
      text: 'Password minimal 6 karakter!',
      icon: 'warning',
      confirmButtonColor: '#00529C'
    })
  }

  isResetLoading.value = true

  try {
    await axios.post('/reset-password', {
      email: resetForm.value.email,
      otp: resetForm.value.otp,
      new_password: resetForm.value.new_password
    })
    
    Swal.fire({
      title: 'Sandi Diperbarui!',
      text: 'Silakan login menggunakan sandi baru kamu.',
      icon: 'success',
      confirmButtonColor: '#00529C'
    })
    
    showResetModal.value = false
    step.value = 1
    resetForm.value = { email: '', otp: '', new_password: '', confirm_password: '' }
  } catch (e) {
    Swal.fire({
      title: 'Gagal',
      text: e.response?.data?.message || 'Kode OTP salah atau kedaluwarsa.',
      icon: 'error',
      confirmButtonColor: '#00529C'
    })
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
  <div class="login-container">
    <!-- Background Elements -->
    <div class="bg-shapes">
      <div class="shape shape-1"></div>
      <div class="shape shape-2"></div>
      <div class="shape shape-3"></div>
    </div>

    <!-- Login Card -->
    <div class="login-card">
      <!-- Header dengan Logo -->
      <div class="card-header">
        <div class="logo-container">
          <div class="logo-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
            </svg>
          </div>
        </div>
        <h1 class="brand-title">BRIJISENT</h1>
        <p class="brand-subtitle">Portal Absensi Industrial</p>
        <div class="header-line"></div>
      </div>

      <!-- Form Login -->
      <div class="form-container">
        <div class="input-wrapper">
          <label class="input-label">
            <svg class="label-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
              <polyline points="22,6 12,13 2,6"/>
            </svg>
            Alamat Email
          </label>
          <div class="input-field">
            <input 
              v-model="email" 
              type="email" 
              placeholder="nama@perusahaan.com"
              @keyup.enter="handleLogin"
              :disabled="isLoading"
            >
          </div>
        </div>
        
        <div class="input-wrapper">
          <label class="input-label">
            <svg class="label-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
              <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
            Password
          </label>
          <div class="input-field">
            <input 
              v-model="password" 
              :type="showPassword ? 'text' : 'password'" 
              placeholder="Masukkan password"
              @keyup.enter="handleLogin"
              :disabled="isLoading"
            >
            <button 
              type="button" 
              class="toggle-password"
              @click="showPassword = !showPassword"
            >
              <svg v-if="!showPassword" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                <circle cx="12" cy="12" r="3"/>
              </svg>
              <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                <line x1="1" y1="1" x2="23" y2="23"/>
              </svg>
            </button>
          </div>
        </div>

        <button 
          @click="handleLogin" 
          class="btn-login"
          :disabled="isLoading"
          :class="{ 'loading': isLoading }"
        >
          <span v-if="!isLoading">MASUK SISTEM</span>
          <span v-else class="spinner">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="10" stroke-dasharray="60" stroke-dashoffset="20"/>
            </svg>
            Memuat...
          </span>
        </button>

        <div class="divider">
          <span>atau</span>
        </div>

        <button @click="showResetModal = true" class="btn-forgot">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/>
            <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/>
            <line x1="12" y1="17" x2="12.01" y2="17"/>
          </svg>
          Lupa Password?
        </button>
      </div>

      <!-- Footer -->
      <div class="card-footer">
        <p>© 2024 BRIJISENT. All rights reserved.</p>
      </div>
    </div>

    <!-- Modal Reset Password -->
    <Transition name="modal">
      <div v-if="showResetModal" class="modal-overlay" @click.self="closeModal">
        <div class="modal-card">
          <!-- Progress Steps -->
          <div class="progress-steps">
            <div class="step" :class="{ active: step >= 1, completed: step > 1 }">
              <div class="step-number">1</div>
              <span>Email</span>
            </div>
            <div class="step-line" :class="{ completed: step > 1 }"></div>
            <div class="step" :class="{ active: step >= 2 }">
              <div class="step-number">2</div>
              <span>Verifikasi</span>
            </div>
          </div>

          <!-- Step 1: Email -->
          <div v-if="step === 1" class="modal-body">
            <div class="modal-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                <polyline points="22,6 12,13 2,6"/>
              </svg>
            </div>
            <h2 class="modal-title">Pemulihan Akses</h2>
            <p class="modal-desc">
              Masukkan email terdaftar untuk menerima kode verifikasi (OTP).
            </p>
            
            <div class="input-wrapper">
              <div class="input-field">
                <input 
                  v-model="resetForm.email" 
                  type="email" 
                  placeholder="email@perusahaan.com"
                  @keyup.enter="requestOTP"
                >
              </div>
            </div>

            <button 
              @click="requestOTP" 
              class="btn-primary"
              :disabled="isResetLoading || !resetForm.email"
            >
              <span v-if="!isResetLoading">Kirim Kode OTP</span>
              <span v-else class="spinner-small">Mengirim...</span>
            </button>
          </div>

          <!-- Step 2: OTP & Password -->
          <div v-if="step === 2" class="modal-body">
            <div class="modal-icon success">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                <polyline points="22 4 12 14.01 9 11.01"/>
              </svg>
            </div>
            <h2 class="modal-title">Verifikasi OTP</h2>
            <p class="modal-desc">
              Kode OTP telah dikirim ke <strong>{{ resetForm.email }}</strong>
            </p>

            <!-- OTP Input -->
            <div class="input-wrapper">
              <label class="input-label">Kode OTP</label>
              <div class="input-field">
                <input 
                  v-model="resetForm.otp" 
                  type="text" 
                  placeholder="000000"
                  maxlength="6"
                  class="otp-input"
                >
              </div>
            </div>

            <!-- New Password -->
            <div class="input-wrapper">
              <label class="input-label">Password Baru</label>
              <div class="input-field">
                <input 
                  v-model="resetForm.new_password" 
                  :type="showNewPassword ? 'text' : 'password'" 
                  placeholder="Minimal 6 karakter"
                >
                <button 
                  type="button" 
                  class="toggle-password"
                  @click="showNewPassword = !showNewPassword"
                >
                  <svg v-if="!showNewPassword" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                    <circle cx="12" cy="12" r="3"/>
                  </svg>
                  <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                    <line x1="1" y1="1" x2="23" y2="23"/>
                  </svg>
                </button>
              </div>
            </div>

            <!-- Confirm Password -->
            <div class="input-wrapper">
              <label class="input-label">Konfirmasi Password</label>
              <div class="input-field" :class="{ 'error': !passwordsMatch && resetForm.confirm_password }">
                <input 
                  v-model="resetForm.confirm_password" 
                  :type="showConfirmPassword ? 'text' : 'password'" 
                  placeholder="Ulangi password baru"
                >
                <button 
                  type="button" 
                  class="toggle-password"
                  @click="showConfirmPassword = !showConfirmPassword"
                >
                  <svg v-if="!showConfirmPassword" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                    <circle cx="12" cy="12" r="3"/>
                  </svg>
                  <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                    <line x1="1" y1="1" x2="23" y2="23"/>
                  </svg>
                </button>
              </div>
              <span v-if="!passwordsMatch && resetForm.confirm_password" class="error-text">
                Password tidak cocok!
              </span>
              <span v-else-if="passwordsMatch && resetForm.confirm_password" class="success-text">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                  <polyline points="20 6 9 17 4 12"/>
                </svg>
                Password cocok
              </span>
            </div>

            <button 
              @click="submitReset" 
              class="btn-success"
              :disabled="!canSubmitReset || isResetLoading"
            >
              <span v-if="!isResetLoading">Simpan Password Baru</span>
              <span v-else class="spinner-small">Memproses...</span>
            </button>

            <button @click="step = 1" class="btn-back">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="19" y1="12" x2="5" y2="12"/>
                <polyline points="12 19 5 12 12 5"/>
              </svg>
              Kembali
            </button>
          </div>

          <button @click="closeModal" class="btn-close">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <line x1="18" y1="6" x2="6" y2="18"/>
              <line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
          </button>
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
/* ===== VARIABLES & BASE ===== */
.login-container {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  position: relative;
  overflow: hidden;
  font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
  padding: 20px;
}

/* ===== BACKGROUND SHAPES ===== */
.bg-shapes {
  position: fixed;
  inset: 0;
  pointer-events: none;
  overflow: hidden;
}

.shape {
  position: absolute;
  border-radius: 50%;
  filter: blur(80px);
  opacity: 0.4;
}

.shape-1 {
  width: 500px;
  height: 500px;
  background: #f093fb;
  top: -200px;
  right: -100px;
  animation: float 8s ease-in-out infinite;
}

.shape-2 {
  width: 400px;
  height: 400px;
  background: #4facfe;
  bottom: -150px;
  left: -100px;
  animation: float 10s ease-in-out infinite reverse;
}

.shape-3 {
  width: 300px;
  height: 300px;
  background: #43e97b;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  animation: pulse 6s ease-in-out infinite;
}

@keyframes float {
  0%, 100% { transform: translateY(0) rotate(0deg); }
  50% { transform: translateY(-30px) rotate(10deg); }
}

@keyframes pulse {
  0%, 100% { transform: translate(-50%, -50%) scale(1); opacity: 0.3; }
  50% { transform: translate(-50%, -50%) scale(1.2); opacity: 0.5; }
}

/* ===== LOGIN CARD ===== */
.login-card {
  background: rgba(255, 255, 255, 0.95);
  backdrop-filter: blur(20px);
  width: 100%;
  max-width: 440px;
  border-radius: 24px;
  box-shadow: 
    0 25px 50px -12px rgba(0, 0, 0, 0.25),
    0 0 0 1px rgba(255, 255, 255, 0.5) inset;
  overflow: hidden;
  position: relative;
  z-index: 1;
  animation: slideUp 0.6s ease-out;
}

@keyframes slideUp {
  from {
    opacity: 0;
    transform: translateY(40px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

/* ===== CARD HEADER ===== */
.card-header {
  padding: 40px 40px 20px;
  text-align: center;
  background: linear-gradient(180deg, rgba(0,82,156,0.05) 0%, transparent 100%);
}

.logo-container {
  display: flex;
  justify-content: center;
  margin-bottom: 20px;
}

.logo-icon {
  width: 70px;
  height: 70px;
  background: linear-gradient(135deg, #00529C 0%, #003d73 100%);
  border-radius: 20px;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 10px 30px rgba(0, 82, 156, 0.3);
}

.logo-icon svg {
  width: 35px;
  height: 35px;
  color: white;
}

.brand-title {
  font-size: 1.8rem;
  font-weight: 800;
  color: #1a202c;
  margin: 0;
  letter-spacing: 2px;
  background: linear-gradient(135deg, #00529C 0%, #667eea 100%);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
}

.brand-subtitle {
  color: #718096;
  font-size: 0.95rem;
  margin-top: 8px;
  font-weight: 500;
}

.header-line {
  width: 60px;
  height: 4px;
  background: linear-gradient(90deg, #00529C, #667eea);
  border-radius: 2px;
  margin: 20px auto 0;
}

/* ===== FORM CONTAINER ===== */
.form-container {
  padding: 30px 40px;
}

/* ===== INPUT STYLES ===== */
.input-wrapper {
  margin-bottom: 24px;
}

.input-label {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 0.875rem;
  font-weight: 600;
  color: #4a5568;
  margin-bottom: 10px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.label-icon {
  width: 16px;
  height: 16px;
  color: #00529C;
}

.input-field {
  position: relative;
  display: flex;
  align-items: center;
}

.input-field input {
  width: 100%;
  padding: 14px 18px;
  padding-right: 50px;
  border: 2px solid #e2e8f0;
  border-radius: 12px;
  font-size: 1rem;
  transition: all 0.3s ease;
  background: #f7fafc;
  color: #2d3748;
}

.input-field input:focus {
  border-color: #00529C;
  background: white;
  outline: none;
  box-shadow: 0 0 0 4px rgba(0, 82, 156, 0.1);
}

.input-field input::placeholder {
  color: #a0aec0;
}

.input-field.error input {
  border-color: #e53e3e;
  background: #fff5f5;
}

.toggle-password {
  position: absolute;
  right: 14px;
  background: none;
  border: none;
  cursor: pointer;
  padding: 4px;
  color: #a0aec0;
  transition: color 0.3s;
  display: flex;
  align-items: center;
  justify-content: center;
}

.toggle-password:hover {
  color: #00529C;
}

.toggle-password svg {
  width: 20px;
  height: 20px;
}

.error-text {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 0.8rem;
  color: #e53e3e;
  margin-top: 6px;
}

.success-text {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 0.8rem;
  color: #38a169;
  margin-top: 6px;
}

.success-text svg {
  width: 14px;
  height: 14px;
}

/* ===== BUTTONS ===== */
.btn-login {
  width: 100%;
  padding: 16px;
  background: linear-gradient(135deg, #00529C 0%, #003d73 100%);
  color: white;
  border: none;
  border-radius: 12px;
  font-size: 1rem;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.3s ease;
  box-shadow: 0 4px 15px rgba(0, 82, 156, 0.3);
  position: relative;
  overflow: hidden;
}

.btn-login:hover:not(:disabled) {
  transform: translateY(-2px);
  box-shadow: 0 8px 25px rgba(0, 82, 156, 0.4);
}

.btn-login:active:not(:disabled) {
  transform: translateY(0);
}

.btn-login:disabled {
  opacity: 0.7;
  cursor: not-allowed;
}

.btn-login.loading {
  background: linear-gradient(135deg, #718096 0%, #4a5568 100%);
}

.spinner {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
}

.spinner svg {
  width: 20px;
  height: 20px;
  animation: spin 1s linear infinite;
}

@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

.spinner-small {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}

/* Divider */
.divider {
  display: flex;
  align-items: center;
  margin: 24px 0;
  color: #a0aec0;
  font-size: 0.875rem;
}

.divider::before,
.divider::after {
  content: '';
  flex: 1;
  height: 1px;
  background: linear-gradient(90deg, transparent, #e2e8f0, transparent);
}

.divider span {
  padding: 0 16px;
}

/* Forgot Password Button */
.btn-forgot {
  width: 100%;
  padding: 14px;
  background: transparent;
  color: #4a5568;
  border: 2px dashed #e2e8f0;
  border-radius: 12px;
  font-size: 0.95rem;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.3s ease;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}

.btn-forgot:hover {
  border-color: #00529C;
  color: #00529C;
  background: rgba(0, 82, 156, 0.05);
}

.btn-forgot svg {
  width: 18px;
  height: 18px;
}

/* Card Footer */
.card-footer {
  padding: 20px 40px;
  background: #f7fafc;
  text-align: center;
  border-top: 1px solid #e2e8f0;
}

.card-footer p {
  margin: 0;
  font-size: 0.8rem;
  color: #a0aec0;
}

/* ===== MODAL STYLES - FIXED RESPONSIVE ===== */
.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.6);
  backdrop-filter: blur(8px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 100;
  padding: 20px;
  overflow-y: auto;
}

.modal-card {
  background: white;
  width: 100%;
  max-width: 480px;
  max-height: 90vh;
  border-radius: 24px;
  box-shadow: 
    0 25px 50px -12px rgba(0, 0, 0, 0.5),
    0 0 0 1px rgba(255, 255, 255, 0.1) inset;
  position: relative;
  overflow: hidden;
  animation: modalSlideIn 0.4s ease-out;
  display: flex;
  flex-direction: column;
}

/* Scrollable content inside modal */
.modal-card > *:not(.progress-steps) {
  overflow-y: auto;
}

@keyframes modalSlideIn {
  from {
    opacity: 0;
    transform: scale(0.9) translateY(20px);
  }
  to {
    opacity: 1;
    transform: scale(1) translateY(0);
  }
}

/* Progress Steps */
.progress-steps {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px 40px 0;
  gap: 8px;
  flex-shrink: 0;
}

.step {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
}

.step-number {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: #e2e8f0;
  color: #718096;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 0.9rem;
  transition: all 0.3s ease;
}

.step.active .step-number {
  background: linear-gradient(135deg, #00529C 0%, #667eea 100%);
  color: white;
  box-shadow: 0 4px 12px rgba(0, 82, 156, 0.3);
}

.step.completed .step-number {
  background: linear-gradient(135deg, #38a169 0%, #2f855a 100%);
  color: white;
}

.step span {
  font-size: 0.75rem;
  color: #718096;
  font-weight: 600;
}

.step.active span {
  color: #00529C;
}

.step-line {
  flex: 1;
  height: 3px;
  background: #e2e8f0;
  border-radius: 2px;
  max-width: 60px;
  transition: all 0.3s ease;
}

.step-line.completed {
  background: linear-gradient(90deg, #38a169, #00529C);
}

/* Modal Body - Scrollable */
.modal-body {
  padding: 30px 40px 40px;
  text-align: center;
  overflow-y: auto;
  flex: 1;
}

.modal-icon {
  width: 70px;
  height: 70px;
  background: linear-gradient(135deg, #ebf8ff 0%, #bee3f8 100%);
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 20px;
  flex-shrink: 0;
}

.modal-icon svg {
  width: 32px;
  height: 32px;
  color: #00529C;
}

.modal-icon.success {
  background: linear-gradient(135deg, #f0fff4 0%, #c6f6d5 100%);
}

.modal-icon.success svg {
  color: #38a169;
}

.modal-title {
  font-size: 1.5rem;
  font-weight: 700;
  color: #2d3748;
  margin: 0 0 10px;
}

.modal-desc {
  color: #718096;
  font-size: 0.95rem;
  margin-bottom: 24px;
  line-height: 1.6;
}

.modal-desc strong {
  color: #00529C;
}

.otp-input {
  text-align: center;
  letter-spacing: 8px;
  font-size: 1.5rem !important;
  font-weight: 700;
  font-family: 'Courier New', monospace;
}

/* Modal Buttons */
.btn-primary {
  width: 100%;
  padding: 14px;
  background: linear-gradient(135deg, #00529C 0%, #003d73 100%);
  color: white;
  border: none;
  border-radius: 12px;
  font-size: 1rem;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.3s ease;
  margin-top: 10px;
}

.btn-primary:hover:not(:disabled) {
  transform: translateY(-2px);
  box-shadow: 0 8px 20px rgba(0, 82, 156, 0.3);
}

.btn-primary:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.btn-success {
  width: 100%;
  padding: 14px;
  background: linear-gradient(135deg, #38a169 0%, #2f855a 100%);
  color: white;
  border: none;
  border-radius: 12px;
  font-size: 1rem;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.3s ease;
  margin-top: 10px;
  box-shadow: 0 4px 15px rgba(56, 161, 105, 0.3);
}

.btn-success:hover:not(:disabled) {
  transform: translateY(-2px);
  box-shadow: 0 8px 25px rgba(56, 161, 105, 0.4);
}

.btn-success:disabled {
  opacity: 0.6;
  cursor: not-allowed;
  background: #a0aec0;
}

.btn-back {
  width: 100%;
  padding: 12px;
  background: transparent;
  color: #718096;
  border: none;
  border-radius: 12px;
  font-size: 0.9rem;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.3s ease;
  margin-top: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
}

.btn-back:hover {
  color: #00529C;
  background: rgba(0, 82, 156, 0.05);
}

.btn-back svg {
  width: 16px;
  height: 16px;
}

.btn-close {
  position: absolute;
  top: 16px;
  right: 16px;
  width: 36px;
  height: 36px;
  background: #f7fafc;
  border: none;
  border-radius: 10px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #718096;
  transition: all 0.3s ease;
  z-index: 10;
}

.btn-close:hover {
  background: #e2e8f0;
  color: #2d3748;
}

.btn-close svg {
  width: 18px;
  height: 18px;
}

/* Modal Transitions */
.modal-enter-active,
.modal-leave-active {
  transition: all 0.3s ease;
}

.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}

.modal-enter-from .modal-card,
.modal-leave-to .modal-card {
  transform: scale(0.9) translateY(20px);
}

/* ===== RESPONSIVE - LAPTOP & DESKTOP ===== */
@media (min-width: 1024px) {
  .modal-card {
    max-width: 550px;
  }
  
  .modal-body {
    padding: 40px 50px 50px;
  }
  
  .modal-title {
    font-size: 1.75rem;
  }
  
  .input-field input {
    padding: 16px 20px;
    padding-right: 55px;
    font-size: 1.1rem;
  }
  
  .otp-input {
    font-size: 1.75rem !important;
  }
}

@media (min-width: 1440px) {
  .modal-card {
    max-width: 600px;
  }
  
  .modal-body {
    padding: 50px 60px 60px;
  }
}

/* ===== RESPONSIVE - MOBILE ===== */
@media (max-width: 640px) {
  .login-container {
    padding: 10px;
    align-items: flex-start;
    padding-top: 20px;
  }
  
  .login-card {
    border-radius: 20px;
    max-height: calc(100vh - 40px);
    overflow-y: auto;
  }
  
  .card-header,
  .form-container {
    padding-left: 24px;
    padding-right: 24px;
  }
  
  .brand-title {
    font-size: 1.5rem;
  }
  
  .modal-overlay {
    padding: 10px;
    align-items: flex-start;
    padding-top: 20px;
  }
  
  .modal-card {
    max-height: calc(100vh - 40px);
    border-radius: 20px;
  }
  
  .modal-body {
    padding: 20px 24px 30px;
  }
  
  .progress-steps {
    padding: 20px 24px 0;
  }
  
  .modal-title {
    font-size: 1.25rem;
  }
  
  .otp-input {
    font-size: 1.25rem !important;
    letter-spacing: 6px;
  }
}

/* Small mobile */
@media (max-width: 380px) {
  .card-header {
    padding: 30px 20px 15px;
  }
  
  .form-container {
    padding: 20px;
  }
  
  .logo-icon {
    width: 60px;
    height: 60px;
  }
  
  .logo-icon svg {
    width: 28px;
    height: 28px;
  }
  
  .brand-title {
    font-size: 1.3rem;
  }
}
</style>
