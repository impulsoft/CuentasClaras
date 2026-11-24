<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { IconAlertTriangle, IconDownload } from '@tabler/icons-vue'
import HojaInferior from './HojaInferior.vue'
import { hoy as fechaHoy } from '../calculos'
import { descargar, ErrorApi } from '../api'
import { useSesionStore } from '../stores/sesion'
import { useDialogoStore } from '../stores/dialogo'

// RF-26: descargar los cierres de un mes, un año o un rango de fechas. Necesita internet.
const props = defineProps<{ abierta: boolean; ruta: string; antes?: () => Promise<void>; inicial?: 'mes' | 'anio' | 'todo' }>()
const emit = defineEmits<{ cerrar: [] }>()
const sesion = useSesionStore()
const dialogo = useDialogoStore()

const hoy = fechaHoy()
const opcion = ref<'mes' | 'anio' | 'todo' | 'rango'>(props.inicial ?? 'mes')
const desde = ref(`${hoy.slice(0, 8)}01`)
const hasta = ref(hoy)
const error = ref('')
const descargando = ref(false)

watch(
  () => props.abierta,
  (abierta) => {
    if (abierta) {
      opcion.value = props.inicial ?? 'mes'
      error.value = ''
    }
  },
)

const rango = computed(() => {
  if (opcion.value === 'mes') return { desde: `${hoy.slice(0, 8)}01`, hasta: hoy }
  if (opcion.value === 'anio') return { desde: `${hoy.slice(0, 4)}-01-01`, hasta: hoy }
  if (opcion.value === 'todo') return { desde: '2000-01-01', hasta: hoy }
  return { desde: desde.value, hasta: hasta.value }
})

async function bajar() {
  error.value = ''
  const { desde: d, hasta: h } = rango.value
  if (!d || !h || d > h) return (error.value = 'Revise las fechas: la inicial no puede ser después de la final.')
  descargando.value = true
  try {
    await props.antes?.() // el dueño envía primero lo que tiene sin sincronizar
    // Si cerró "Compartir" sin escoger nada, la ventana queda abierta para intentar otra vez
    if (!(await descargar(`${props.ruta}?desde=${d}&hasta=${h}`, sesion.token, `cierres-${d}-a-${h}.xlsx`))) return
    dialogo.avisar('Excel descargado')
    emit('cerrar')
  } catch (e) {
    error.value = e instanceof ErrorApi && e.sinConexion ? 'Sin conexión. Para descargar el Excel necesita internet.' : 'No se pudo descargar. Intente de nuevo.'
  } finally {
    descargando.value = false
  }
}

const opciones = [
  { valor: 'mes' as const, texto: 'Este mes' },
  { valor: 'anio' as const, texto: 'Este año' },
  { valor: 'todo' as const, texto: 'Todo' },
  { valor: 'rango' as const, texto: 'Escoger fechas' },
]
</script>

<template>
  <HojaInferior :abierta="abierta" titulo="Descargar en Excel" @cerrar="emit('cerrar')" @enviar="bajar">
    <p class="suave">Un día por fila, con la base, el efectivo, la plata que sacó de la caja, el Nequi y los otros pagos.</p>
    <div class="opciones" role="radiogroup" aria-label="Qué días">
      <label v-for="o in opciones" :key="o.valor" class="opcion" :class="{ activa: opcion === o.valor }">
        <input v-model="opcion" type="radio" name="periodo" :value="o.valor" />
        {{ o.texto }}
      </label>
    </div>
    <div v-if="opcion === 'rango'" class="dos">
      <label class="campo">
        Desde
        <span class="control"><input v-model="desde" type="date" :max="hoy" /></span>
      </label>
      <label class="campo">
        Hasta
        <span class="control"><input v-model="hasta" type="date" :max="hoy" /></span>
      </label>
    </div>
    <p v-if="error" class="mensaje error" role="alert"><IconAlertTriangle :size="20" aria-hidden="true" />{{ error }}</p>
    <template #acciones>
      <button type="button" class="boton secundario" @click="emit('cerrar')">Cancelar</button>
      <button class="boton" :disabled="descargando"><IconDownload :size="22" aria-hidden="true" /> {{ descargando ? 'Bajando…' : 'Descargar' }}</button>
    </template>
  </HojaInferior>
</template>

<style scoped>
.opciones {
  display: grid;
  gap: 8px;
}

.opcion {
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: 52px;
  padding: 10px 14px;
  font-weight: 700;
  border: 1.5px solid var(--borde-campo);
  border-radius: var(--radio-control);
  cursor: pointer;
}

.opcion.activa {
  border-color: var(--verde-600);
  background: var(--verde-50);
}

.opcion input {
  width: 22px;
  height: 22px;
  margin: 0;
  accent-color: var(--verde-600);
}

.dos {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: 10px;
}

.dos > *,
.dos .control,
.dos input {
  min-width: 0;
}

.dos input {
  font-size: 1rem;
  padding-inline: 10px;
}
</style>
