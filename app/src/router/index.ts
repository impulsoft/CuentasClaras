import { createRouter, createWebHashHistory } from 'vue-router'
import { useSesionStore } from '../stores/sesion'
import EntrarView from '../views/EntrarView.vue'
import TerminosView from '../views/TerminosView.vue'
import InicioView from '../views/InicioView.vue'
import AdminView from '../views/AdminView.vue'

// Módulo de deudores (fase 3): se carga aparte para que la app abra rápido
const DeudoresView = () => import('../views/DeudoresView.vue')
const DeudorView = () => import('../views/DeudorView.vue')
const FiadoView = () => import('../views/FiadoView.vue')
const AbonoView = () => import('../views/AbonoView.vue')
const ContrasenaView = () => import('../views/ContrasenaView.vue')

// Análisis, Excel y cuaderno (fase 5)
const AnalisisView = () => import('../views/AnalisisView.vue')
const CargarView = () => import('../views/CargarView.vue')
const AdminAnalisisView = () => import('../views/AdminAnalisisView.vue')

// Panel del admin (fase 4)
const AdminNegocioView = () => import('../views/AdminNegocioView.vue')
const AdminCierresView = () => import('../views/AdminCierresView.vue')

declare module 'vue-router' {
  interface RouteMeta {
    publica?: boolean
    rol?: 'admin' | 'dueno'
  }
}

// Hash para que las rutas funcionen igual en la PWA sin internet y dentro de la APK
const router = createRouter({
  history: createWebHashHistory(),
  // Cada pantalla nueva abre arriba; al volver atrás, queda donde estaba. Abrir o cerrar el panel
  // del día (?dia=...) no mueve el calendario de atrás.
  scrollBehavior: (destino, origen, guardada) => guardada ?? (destino.path === origen.path ? false : { top: 0 }),
  routes: [
    { path: '/entrar', name: 'entrar', component: EntrarView, meta: { publica: true } },
    { path: '/terminos', name: 'terminos', component: TerminosView, meta: { rol: 'dueno' } },
    { path: '/', name: 'inicio', component: InicioView, meta: { rol: 'dueno' } },
    { path: '/deudores', name: 'deudores', component: DeudoresView, meta: { rol: 'dueno' } },
    { path: '/deudores/:id', name: 'deudor', component: DeudorView, props: true, meta: { rol: 'dueno' } },
    { path: '/deudores/:id/abono', name: 'abono', component: AbonoView, props: true, meta: { rol: 'dueno' } },
    { path: '/fiado/nuevo', name: 'nuevo-fiado', component: FiadoView, meta: { rol: 'dueno' } },
    { path: '/analisis', name: 'analisis', component: AnalisisView, meta: { rol: 'dueno' } },
    { path: '/cargar/:tipo(excel|cuaderno)', name: 'cargar', component: CargarView, props: true, meta: { rol: 'dueno' } },
    { path: '/contrasena', name: 'contrasena', component: ContrasenaView },
    { path: '/admin', name: 'admin', component: AdminView, meta: { rol: 'admin' } },
    { path: '/admin/negocios/:id', name: 'admin-negocio', component: AdminNegocioView, props: true, meta: { rol: 'admin' } },
    { path: '/admin/negocios/:id/cierres', name: 'admin-cierres', component: AdminCierresView, props: true, meta: { rol: 'admin' } },
    { path: '/admin/negocios/:id/analisis', name: 'admin-analisis', component: AdminAnalisisView, props: true, meta: { rol: 'admin' } },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

router.beforeEach((destino) => {
  const sesion = useSesionStore()

  if (!sesion.conSesion) {
    return destino.meta.publica ? true : { name: 'entrar' }
  }

  const inicio = sesion.esAdmin ? 'admin' : 'inicio'
  if (destino.meta.publica) return { name: inicio }
  if (destino.meta.rol && destino.meta.rol !== sesion.usuario!.rol) return { name: inicio }

  // RF-46: con una contraseña temporal, primero se cambia
  const debeCambiar = sesion.usuario!.debe_cambiar_contrasena
  if (debeCambiar && destino.name !== 'contrasena') return { name: 'contrasena' }
  if (debeCambiar) return true

  // RN-15: el dueño no usa nada hasta aceptar los términos
  const debeAceptar = sesion.usuario!.debe_aceptar_terminos
  if (debeAceptar && destino.name !== 'terminos') return { name: 'terminos' }
  if (!debeAceptar && destino.name === 'terminos') return { name: inicio }

  return true
})

export default router
