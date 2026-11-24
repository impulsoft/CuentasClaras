<script setup lang="ts">
import { computed, ref } from 'vue'
import { IconAlertTriangle, IconClock, IconDownload } from '@tabler/icons-vue'
import DescargarExcel from './DescargarExcel.vue'
import { fechaLarga, hoy } from '../calculos'
import { useSesionStore } from '../stores/sesion'
import { useSincronizacionStore } from '../stores/sincronizacion'

// RF-10: avisa al dueño cuando la suscripción está por vencer o venció. RN-34: cuándo se borrarían sus datos.
// Si el negocio está bloqueado, el aviso de bloqueo de la pantalla ya lo dice todo.
const sesion = useSesionStore()
const sync = useSincronizacionStore()
const descargando = ref(false) // RN-34: se le ofrece exportar su información antes del borrado
const negocio = computed(() => sesion.usuario?.negocio)

const dias = computed(() => {
  const vence = negocio.value?.suscripcion_vence_el
  if (!vence) return null
  return Math.round((Date.parse(`${vence}T12:00:00Z`) - Date.parse(`${hoy()}T12:00:00Z`)) / 86_400_000)
})

const aviso = computed<{ tipo: 'aviso' | 'error'; texto: string } | null>(() => {
  const n = negocio.value
  if (!n || n.estado === 'bloqueado') return null
  const vence = n.suscripcion_vence_el
  if (n.suscripcion_estado === 'por_vencer' && vence && dias.value !== null) {
    const cuando = dias.value === 0 ? 'hoy' : dias.value === 1 ? 'mañana' : `el ${fechaLarga(vence)} (en ${dias.value} días)`
    return { tipo: 'aviso', texto: `Su suscripción vence ${cuando}. Comuníquese con el administrador para renovarla.` }
  }
  if (n.suscripcion_estado === 'vencida' && vence) {
    return { tipo: 'error', texto: `Su suscripción venció el ${fechaLarga(vence)}. Comuníquese con el administrador para renovarla y seguir registrando.` }
  }
  return null
})
</script>

<template>
  <div v-if="negocio?.datos_se_borran_el" class="mensaje error borrado" role="alert">
    <IconAlertTriangle :size="22" aria-hidden="true" />
    <div class="texto">
      <p>Si no renueva su suscripción, sus datos se borrarán el {{ fechaLarga(negocio.datos_se_borran_el) }}. Comuníquese con el administrador.</p>
      <p>Antes de esa fecha puede descargar toda su información en Excel.</p>
      <button type="button" class="boton secundario" @click="descargando = true"><IconDownload :size="22" aria-hidden="true" /> Descargar mis datos</button>
    </div>
  </div>
  <p v-else-if="aviso" class="mensaje" :class="aviso.tipo" role="status">
    <IconClock :size="22" aria-hidden="true" />
    {{ aviso.texto }}
  </p>
  <DescargarExcel :abierta="descargando" ruta="/excel/cierres" inicial="todo" :antes="() => sync.sincronizar()" @cerrar="descargando = false" />
</template>

<style scoped>
.borrado .texto {
  display: grid;
  gap: 8px;
}

.borrado .boton {
  justify-self: start;
  min-height: 48px;
}
</style>
