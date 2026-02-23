import { createRouter, createWebHistory } from 'vue-router'
import LoginView from '../views/LoginView.vue'
import DashboardHR from '../views/hr/DashboardHR.vue'
import DashboardIntern from '../views/intern/DashboardIntern.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'login',
      component: LoginView
    },
    {
      path: '/hr',
      name: 'hr-dashboard',
      component: DashboardHR,
      meta: { requiresAuth: true, role: 'hr' } // Tandai butuh akses HR
    },
    {
      path: '/intern',
      name: 'intern-dashboard',
      component: DashboardIntern,
      meta: { requiresAuth: true, role: 'intern' } // Tandai butuh akses Intern
    }
  ]
})

// === SATPAM (ROUTER GUARD) ===
router.beforeEach((to, from, next) => {
  // Ambil data user dari LocalStorage (karena Pinia mungkin belum ready)
  const user = JSON.parse(localStorage.getItem('user'))

  // 1. Kalau mau ke halaman rahasia (hr/intern) tapi belum login
  if (to.meta.requiresAuth && !user) {
    next('/') // Tendang ke Login
  }
  // 2. Kalau sudah login tapi mau balik ke halaman login
  else if (to.path === '/' && user) {
    // Balikin ke dashboard masing-masing
    if (user.role === 'hr') next('/hr')
    else next('/intern')
  }
  // 3. Kalau login sebagai Intern tapi coba masuk kandang HR
  else if (to.meta.role && user && user.role !== to.meta.role) {
    alert("Eits, Anda salah kamar! 🚫")
    if (user.role === 'hr') next('/hr')
    else next('/intern')
  }
  // 4. Lolos seleksi, silakan masuk
  else {
    next()
  }
})

export default router