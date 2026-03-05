import { defineConfig } from 'vite'
import { resolve } from 'path'

export default defineConfig({
    base: '/People-Serve-India-LLP/',
    build: {
        rollupOptions: {
            input: {
                main: resolve(__dirname, 'index.html'),
                about: resolve(__dirname, 'about.html'),
                services: resolve(__dirname, 'services.html'),
                contact: resolve(__dirname, 'contact.html'),
            },
        },
    },
})
