<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { IconAlertTriangle } from '@tabler/icons-vue'
import HojaInferior from './HojaInferior.vue'
import CampoPesos from './CampoPesos.vue'
import { fechaLarga, hoy } from '../calculos'
import { nuevoVencimiento, useAdminApi, type DetalleNegocio, type NegocioAdmin } from '../admin'
import { ErrorApi } from '../api'
import { useDialogoStore } from '../stores/dialogo'

// RF-09 / RF-11 "Ya pagó": registra el pago, extiende el vencimiento (RN-12) y desbloquea (RF-12)
const props = defineProps<{ negocio: NegocioAdmin | null }>()
const emit = defineEmits<{ cerrar: []; pagado: [negocio: DetalleNegocio] }>()
const api = useAdminApi()
const dialogo = useDialogoStore()

const fechaHoy = hoy()
const valor = ref<number | null>(null)
const fecha = ref(fechaHoy)
const nota = ref('')
const error = ref('')
const enviando = ref(false)

watch(
  () => props.negocio?.id,
  () => {
    valor.value = props.negocio?.ultimo_valor || null // el mismo valor del último pago
    fecha.value = fechaHoy
    nota.value = ''
    error.value = ''
  },
  { immediate: true },
)

const vence = computed(() => (props.negocio && fecha.value ? nuevoVencimiento(props.negocio.suscripcion.vence_el, fecha.value) : null))

async function guardar() {
  error.value = ''
  if (!valor.value) return (error.value = 'Escriba el valor que pagó.')
  if (!fecha.value || fecha.value > fechaHoy) return (error.value = 'La fecha del pago no puede ser un día que no ha llegado.')
  enviando.value = true
  try {
    const actualizado = await api.pagar(props.negocio!.id, { valor: valor.value, fecha: fecha.value, nota: nota.value.trim() || null })
    dialogo.avisar(`Pago registrado: al día hasta el ${fechaLarga(actualizado.suscripcion.vence_el!)}`)
    emit('pagado', actualizado)
  } catch (e) {
    error.value = e instanceof ErrorApi ? (e.sinConexion ? 'Sin conexión. Intente de nuevo cuando tenga internet.' : e.message) : 'No se pudo registrar.'
  } finally {
    enviando.value = false
  }
}
</script>

<template>
  <HojaInferior :abierta="!!negocio" :titulo="negocio ? `Pago de ${negocio.nombre}` : ''" @cerrar="emit('cerrar')" @enviar="guardar">
    <CampoPesos v-model="valor" etiqueta="Valor pagado" />
    <label class="campo">
      Fecha del pago
      <span class="control"><input v-model="fecha" type="date" :max="fechaHoy" /></span>
    </label>
    <label class="campo">
      <span>Nota <span class="ayuda">(opcional)</span></span>
      <span class="control"><input v-model="nota" autocomplete="off" maxlength="255" placeholder="Ej.: Pagó por Nequi" /></span>
    </label>
    <div v-if="vence" class="recuadro nuevo">
      <span class="suave">Queda al día hasta el</span>
      <strong>{{ fechaLarga(vence) }} de {{ vence.slice(0, 4) }}</strong>
      <span v-if="negocio?.estado === 'bloqueado'" class="suave pequeno">Al registrar el pago, el negocio se desbloquea.</span>
    </div>
    <p v-if="error" class="mensaje error" role="alert"><IconAlertTriangle :size="20" aria-hidden="true" />{{ error }}</p>
    <template #acciones>
      <button type="button" class="boton secundario" @click="emit('cerrar')">Cancelar</button>
      <button class="boton" :disabled="enviando">Registrar</button>
    </template>
  </HojaInferior>
</template>

<style scoped>
.nuevo {
  display: grid;
  gap: 2px;
}

.nuevo strong {
  font-size: 1.2rem;
  color: var(--verde-800);
}
</style>
