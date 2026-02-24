import { createApp } from 'vue'
import { createPinia } from 'pinia'
import axios from 'axios'

import App from './App.vue'
import router from './router'

axios.defaults.baseURL = 'https://api-brijisent.duckdns.org/api'
axios.defaults.headers.common['Accept'] = 'application/json'

// --- TAMBAHKAN KODE INI ---
// Cek apakah ada token di memori, kalau ada, pasang langsung ke Axios
const token = localStorage.getItem('token')
if (token) {
    axios.defaults.headers.common['Authorization'] = `Bearer ${token}`
}
// -------------------------

const app = createApp(App)

app.use(createPinia())
app.use(router)

app.mount('#app')
