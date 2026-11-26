<script setup lang="ts">
import { computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSesionStore } from './stores/sesion'
import { useSincronizacionStore } from './stores/sincronizacion'
import VentanaDialogo from './components/VentanaDialogo.vue'
import ConfirmarComprobante from './components/ConfirmarComprobante.vue'
import { cancelarRecordatorios, programarRecordatorios } from './recordatorio'

const sesion = useSesionStore()
const sync = useSincronizacionStore()
const router = useRouter()
const route = useRoute()

// Al abrir, refresca los datos del usuario si hay internet (rol, términos, negocio)
onMounted(() => sesion.actualizar())

// Si la sesión se cierra (o el servidor la invalida), vuelve a la pantalla de entrada
watch(
  () => sesion.conSesion,
  (conSesion) => {
    if (!conSesion && !route.meta.publica) router.replace({ name: 'entrar' })
  },
)

// RF-46: si el servidor dice que la contraseña es temporal, se pide cambiarla
watch(
  () => sesion.usuario?.debe_cambiar_contrasena,
  (debe) => {
    if (debe && route.name !== 'contrasena') router.replace({ name: 'contrasena' })
  },
)

// RF-30: el dueño con los términos aceptados sincroniza en segundo plano
const sincroniza = computed(
  () => sesion.conSesion && !sesion.esAdmin && !sesion.usuario?.debe_aceptar_terminos && !sesion.usuario?.debe_cambiar_contrasena,
)
watch(
  sincroniza,
  (activo) => {
    if (activo) sync.iniciar()
    else sync.reiniciar()
    // Recordatorio diario del cierre (solo en la APK): se programa con la sesión del dueño y se quita al salir
    if (activo) programarRecordatorios()
    else cancelarRecordatorios()
  },
  { immediate: true },
)
</script>

<template>
  <RouterView />
  <!-- Revisar el pago Nequi leído de una foto: va aquí para que el botón de la cámara de la barra
       de arriba funcione desde cualquier pantalla del dueño (Caja, Deudores, Análisis) -->
  <ConfirmarComprobante v-if="sesion.conSesion && !sesion.esAdmin" />
  <VentanaDialogo />
</template>
