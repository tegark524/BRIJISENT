import { defineStore } from 'pinia'
import axios from 'axios'

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: JSON.parse(localStorage.getItem('user')) || null,
    }),
    actions: {
        async login(email, password) {
            try {
                const response = await axios.post('/login', {
                    email: email,
                    password: password
                })

                // 1. Simpan data user ke state dan LocalStorage
                this.user = response.data.user
                localStorage.setItem('user', JSON.stringify(this.user))
                
                // 2. SIMPAN TOKENNYA JUGA
                const token = response.data.token
                localStorage.setItem('token', token)

                // 3. Pasang token ke Axios agar otomatis dipakai terus
                axios.defaults.headers.common['Authorization'] = `Bearer ${token}`

                return true
            } catch (error) {
                console.error("Gagal Login:", error.response?.data?.message)
                return false
            }
        },

        logout() {
            this.user = null
            localStorage.removeItem('user')
            localStorage.removeItem('token') // Hapus token saat logout
            delete axios.defaults.headers.common['Authorization'] // Cabut token dari axios
            window.location.reload()
        }
    }
})
