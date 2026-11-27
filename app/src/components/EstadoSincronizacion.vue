<script setup lang="ts">
import { computed } from 'vue'
import { IconCloudCheck, IconCloudOff, IconRefresh } from '@tabler/icons-vue'
import { useSincronizacionStore } from '../stores/sincronizacion'

// Va dentro de la barra verde: dice si lo guardado ya llegó al servidor (RF-30)
const sync = useSincronizacionStore()

const texto = computed(() => {
  if (sync.estado === 'sincronizando') return 'Guardando en la nube…'
  if (sync.estado === 'sin_conexion') {
    return sync.pendientes > 0 ? `Sin internet · ${sync.pendientes} por enviar` : 'Sin internet'
  }
  if (sync.estado === 'error') return 'No se pudo enviar'
  if (sync.pendientes > 0) return `${sync.pendientes} por enviar`
  return sync.ultima ? 'Todo guardado' : 'Sin sincronizar todavía'
})

const desconectado = computed(() => sync.estado === 'sin_conexion' || sync.estado === 'error')
const puedeReintentar = computed(() => sync.estado !== 'sincronizando' && (sync.pendientes > 0 || sync.estado !== 'en_espera'))
</script>

<template>
  <p class="estado" role="status">
    <IconRefresh v-if="sync.estado === 'sincronizando'" class="girando" :size="16" aria-hidden="true" />
    <IconCloudOff v-else-if="desconectado" :size="16" aria-hidden="true" />
    <IconCloudCheck v-else :size="16" aria-hidden="true" />
    <span>{{ texto }}</span>
    <button v-if="puedeReintentar" class="reintentar" @click="sync.sincronizar()">Enviar ahora</button>
  </p>
</template>

<style scoped>
.estado {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 2px;
  font-size: 0.8rem;
  font-weight: 500;
  color: var(--texto-suave);
}

.estado svg {
  color: var(--verde-600);
}

.reintentar {
  font: inherit;
  font-weight: 600;
  color: var(--verde-700);
  padding: 2px 10px;
  border: 1px solid var(--verde-200);
  border-radius: var(--radio-pildora);
  background: none;
  cursor: pointer;
}

.girando {
  animation: girar 1s linear infinite;
}

@keyframes girar {
  to {
    transform: rotate(360deg);
  }
}
</style>
