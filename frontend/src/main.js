import { createApp } from 'vue'
import { createPinia } from 'pinia'
import axios from 'axios' // <-- Tambahkan ini

import App from './App.vue'
import router from './router'

// Atur Base URL mengarah ke server aaPanel kamu
axios.defaults.baseURL = 'https://api-brijisent.duckdns.org/api'
// Wajibkan balasan berupa JSON
axios.defaults.headers.common['Accept'] = 'application/json'

const app = createApp(App)

app.use(createPinia())
app.use(router)

app.mount('#app')
