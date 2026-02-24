import { defineStore } from 'pinia'
import axios from 'axios'

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: JSON.parse(localStorage.getItem('user')) || null,
    }),
    actions: {
        // 1. Fungsi Login sekarang menerima EMAIL dan PASSWORD
        async login(email, password) {
            try {
                const response = await axios.post('/login', {
                    email: email,
                    password: password
                })

                // Simpan data user ke state dan LocalStorage
                this.user = response.data.user
                localStorage.setItem('user', JSON.stringify(this.user))

                return true
            } catch (error) {
                console.error("Gagal Login:", error.response?.data?.message)
                return false
            }
        },

        // 2. Fungsi Logout
        logout() {
            this.user = null
            localStorage.removeItem('user')
            // Refresh halaman paksa agar router membuang user ke halaman login
            window.location.reload()
        }
    }
})
