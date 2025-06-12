import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.js',
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    server: {
        host: '0.0.0.0',
        port: 5173,
        cors: {
            origin: [
                'http://localhost:8080',
                'https://transcriptions.ngrok.dev',
                /\.ngrok\.dev$/,
                /\.ngrok-free\.app$/,
                /\.ngrok\.io$/
            ],
            credentials: true
        },
        hmr: {
            host: process.env.VITE_HMR_HOST || 'localhost',
            port: 5173,
            clientPort: process.env.VITE_HMR_CLIENT_PORT || 5173
        },
        watch: {
            usePolling: true,
            interval: 1000, // Increased from 50ms to 1000ms for better performance
            ignored: ['**/node_modules/**', '**/vendor/**'], // Ignore unnecessary directories
        },
    },
    optimizeDeps: {
        include: ['vue', '@inertiajs/vue3'], // Pre-bundle common dependencies
    },
    build: {
        rollupOptions: {
            output: {
                manualChunks: {
                    vendor: ['vue', '@inertiajs/vue3'],
                },
            },
        },
    },
});
