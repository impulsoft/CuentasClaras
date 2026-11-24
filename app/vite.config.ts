import vue from '@vitejs/plugin-vue'
import legacy from '@vitejs/plugin-legacy'
import { defineConfig } from 'vite'
import { VitePWA } from 'vite-plugin-pwa'
import respaldoGap from './scripts/respaldo-gap.mjs'

// https://vite.dev/config/
export default defineConfig({
  // RNF-03: el CSS se convierte para WebViews viejos (por ejemplo, "inset" pasa a top/right/bottom/left
  // y los colores "rgb(0 0 0 / 0.06)" a rgba). Lo que no se puede convertir se evita en el código.
  build: {
    cssTarget: 'chrome61',
  },
  css: {
    postcss: { plugins: [respaldoGap()] },
  },
  plugins: [
    vue(),
    // Celulares con Android 7 cuyo WebView no se ha actualizado (RNF-03).
    // Chrome 64 a 91 corre el código moderno, así que también lleva las funciones que le faltan
    // (por ejemplo, Array.prototype.at o Array.prototype.flat).
    legacy({
      targets: ['chrome >= 60', 'android >= 7'],
      modernPolyfills: true,
    }),
    // App instalable que abre sin internet (RNF-01, RNF-02)
    VitePWA({
      registerType: 'autoUpdate',
      // Se registra en src/main.ts, solo en el navegador: en la APK los archivos ya vienen en el celular
      injectRegister: false,
      includeAssets: ['favicon.svg'],
      workbox: {
        // La letra Nunito también queda guardada para verse igual sin internet (solo el alfabeto latino: tildes y ñ incluidas)
        globPatterns: ['**/*.{js,css,html,svg,png}', 'assets/nunito-latin-wght-normal-*.woff2'],
      },
      manifest: {
        name: 'Cierre de Caja',
        short_name: 'Cierre de Caja',
        description: 'Cierre de caja diario, pagos Nequi y fiados, con o sin internet.',
        lang: 'es-CO',
        start_url: '/',
        display: 'standalone',
        orientation: 'portrait',
        theme_color: '#0f6e56', // el verde de la barra superior
        background_color: '#ffffff',
        icons: [
          { src: 'icons/icon-192.png', sizes: '192x192', type: 'image/png' },
          { src: 'icons/icon-512.png', sizes: '512x512', type: 'image/png' },
          { src: 'icons/icon-512-maskable.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
      },
    }),
  ],
})
