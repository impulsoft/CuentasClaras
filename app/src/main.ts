import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { Capacitor } from '@capacitor/core'
import '@fontsource-variable/nunito' // va dentro de la app: funciona sin internet
import './style.css'
import App from './App.vue'
import router from './router'

// RNF-03: si el navegador no entiende "gap" en flexbox (WebViews viejos de Android 7), se activan
// los márgenes de respaldo que agrega scripts/respaldo-gap.mjs
function soportaGapEnFlex(): boolean {
  const prueba = document.createElement('div')
  prueba.style.display = 'flex'
  prueba.style.flexDirection = 'column'
  prueba.style.setProperty('row-gap', '1px')
  prueba.appendChild(document.createElement('div'))
  prueba.appendChild(document.createElement('div'))
  document.body.appendChild(prueba)
  const soporta = prueba.scrollHeight === 1
  document.body.removeChild(prueba)
  return soporta
}
if (!soportaGapEnFlex()) document.documentElement.classList.add('sin-gap')

// PWA: el service worker guarda la app para abrir sin internet en el navegador (RNF-01).
// En la APK no hace falta (los archivos vienen dentro) y podría mostrar una versión vieja después
// de actualizar la app, así que no se registra y, si quedó uno de una versión anterior, se quita.
if (Capacitor.isNativePlatform()) {
  navigator.serviceWorker?.getRegistrations().then((registros) => registros.forEach((r) => r.unregister()))

  /*
   * Botón "atrás" del celular: vuelve a la pantalla anterior (y cierra el panel del día). En las
   * pantallas principales minimiza la app, como cualquier app de Android: no cierra la sesión ni
   * pierde lo pendiente. Sin esto, "atrás" cerraba la app desde cualquier pantalla, y desde el
   * inicio quedaba dando vueltas (volvía a "entrar", que lo devuelve al inicio).
   */
  import('@capacitor/app').then(({ App }) =>
    App.addListener('backButton', ({ canGoBack }) => {
      const ruta = router.currentRoute.value
      const principal = ['admin', 'entrar', 'terminos', 'contrasena'].includes(String(ruta.name)) || (ruta.name === 'inicio' && !ruta.query.dia)
      if (principal || !canGoBack) App.minimizeApp()
      else window.history.back()
    }),
  )

  // Al tocar el aviso "¿Ya hizo el cierre de hoy?" se abre el cierre de ese día
  import('@capacitor/local-notifications').then(({ LocalNotifications }) =>
    LocalNotifications.addListener('localNotificationActionPerformed', ({ notification }) => {
      const dia = typeof notification.extra?.dia === 'string' ? notification.extra.dia : undefined
      router.push({ name: 'inicio', query: dia ? { dia } : {} })
    }),
  )
} else {
  import('virtual:pwa-register').then(({ registerSW }) => registerSW({ immediate: true }))
}

createApp(App).use(createPinia()).use(router).mount('#app')
