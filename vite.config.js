import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
    build: {
        // After splitting vendors, ApexCharts alone can exceed Vite's default 500 kB hint.
        chunkSizeWarningLimit: 600,
        rollupOptions: {
            output: {
                manualChunks(id) {
                    if (!id.includes('node_modules')) {
                        return;
                    }
                    if (id.includes('apexcharts')) {
                        return 'vendor-apexcharts';
                    }
                    if (id.includes('@fullcalendar')) {
                        return 'vendor-fullcalendar';
                    }
                    if (id.includes('flatpickr')) {
                        return 'vendor-flatpickr';
                    }
                    if (id.includes('alpinejs')) {
                        return 'vendor-alpine';
                    }
                    if (id.includes('axios')) {
                        return 'vendor-axios';
                    }
                },
            },
        },
    },
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/css/admin.css',
                'resources/js/admin.js',
                'resources/css/wechirp-app.css',
                'resources/js/wechirp-app/main.jsx',
            ],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    server: {
        host: '127.0.0.1',
        port: 9002,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
