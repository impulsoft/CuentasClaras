<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { IconAlertTriangle, IconRefresh } from '@tabler/icons-vue'
import HojaInferior from './HojaInferior.vue'
import { traerArchivo, ErrorApi } from '../api'
import { useSesionStore } from '../stores/sesion'

// RN-43: la foto del comprobante de un pago, mientras se guarda en el servidor (90 días). Necesita internet.
const props = defineProps<{ ruta: string | null; titulo: string }>()
const emit = defineEmits<{ cerrar: [] }>()
const sesion = useSesionStore()

const url = ref<string | null>(null)
const error = ref('')
const cargando = ref(false)

function soltar() {
  if (url.value) URL.revokeObjectURL(url.value)
  url.value = null
}

watch(
  () => props.ruta,
  async (ruta) => {
    soltar()
    error.value = ''
    if (!ruta) return
    cargando.value = true
    try {
      const respuesta = await traerArchivo(`/comprobantes/foto?ruta=${encodeURIComponent(ruta)}`, sesion.token)
      url.value = URL.createObjectURL(await respuesta.blob())
    } catch (e) {
      error.value =
        e instanceof ErrorApi && e.sinConexion
          ? 'Sin conexión: para ver la foto necesita internet.'
          : e instanceof ErrorApi && e.estado === 404
            ? 'La foto ya no está. Se borra a los 90 días; los datos del pago se conservan.'
            : 'No se pudo traer la foto.'
    } finally {
      cargando.value = false
    }
  },
)
onBeforeUnmount(soltar)
</script>

<template>
  <HojaInferior :abierta="!!ruta" :titulo="titulo" @cerrar="emit('cerrar')">
    <p v-if="cargando" class="cargando suave" role="status"><IconRefresh class="girando" :size="22" aria-hidden="true" /> Trayendo la foto…</p>
    <p v-else-if="error" class="mensaje aviso" role="alert"><IconAlertTriangle :size="20" aria-hidden="true" />{{ error }}</p>
    <img v-else-if="url" :src="url" class="foto" alt="Foto del comprobante" />
  </HojaInferior>
</template>

<style scoped>
.cargando {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 120px;
}

.cargando svg {
  margin-right: 8px;
}

.girando {
  animation: girar 1s linear infinite;
}

@keyframes girar {
  to {
    transform: rotate(360deg);
  }
}

.foto {
  width: 100%;
  max-height: 70vh;
  object-fit: contain;
  border-radius: 14px;
  background: var(--superficie-suave);
  outline: 1px solid rgb(0 0 0 / 0.06);
  outline-offset: -1px;
}
</style>
