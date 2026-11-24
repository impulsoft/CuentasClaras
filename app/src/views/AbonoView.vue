<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { IconAlertTriangle, IconDeviceFloppy } from '@tabler/icons-vue'
import BarraVolver from '../components/BarraVolver.vue'
import CampoPesos from '../components/CampoPesos.vue'
import { hoy, pesos } from '../calculos'
import { registrarAbono, resumenDe } from '../datosDeudores'
import { useConsulta } from '../datos'
import { useSesionStore } from '../stores/sesion'
import { useSincronizacionStore } from '../stores/sincronizacion'
import { useDialogoStore } from '../stores/dialogo'

// RF-38: el abono baja el saldo; se aplica primero al fiado más antiguo (RN-28)
const props = defineProps<{ id: string }>()
const router = useRouter()
const sesion = useSesionStore()
const sync = useSincronizacionStore()
const dialogo = useDialogoStore()

const resumen = useConsulta(() => resumenDe(props.id), undefined, [() => props.id])
const saldo = computed(() => resumen.value?.saldo ?? 0)

const fechaHoy = hoy()
const valor = ref<number | null>(null)
const fecha = ref(fechaHoy)
const nota = ref('')
const error = ref('')

const nuevoSaldo = computed(() => Math.max(0, saldo.value - (valor.value ?? 0)))
const pasaDelSaldo = computed(() => (valor.value ?? 0) > saldo.value) // RN-19

async function guardar() {
  error.value = ''
  if (!valor.value) return (error.value = 'Escriba el valor del abono.')
  if (pasaDelSaldo.value) return (error.value = `El abono no puede ser mayor que lo que debe (${pesos(saldo.value)}).`)
  if (!fecha.value || fecha.value > fechaHoy) return (error.value = 'La fecha del abono no puede ser un día que no ha llegado.')

  await registrarAbono(sesion.usuario!.negocio!.id, props.id, { valor: valor.value, fecha: fecha.value, nota: nota.value })
  sync.programar()
  dialogo.avisar(nuevoSaldo.value === 0 ? `¡${resumen.value!.nombre} quedó a paz y salvo!` : 'Abono guardado')
  router.replace({ name: 'deudor', params: { id: props.id } })
}
</script>

<template>
  <div>
    <BarraVolver titulo="Registrar abono" :volver-a="{ name: 'deudor', params: { id } }" />

    <main class="pantalla">
      <form v-if="resumen" class="tarjeta formulario" novalidate @submit.prevent="guardar">
        <p class="suave">{{ resumen.nombre }} · debe {{ pesos(saldo) }}</p>

        <CampoPesos v-model="valor" etiqueta="Valor del abono" />
        <button v-if="saldo > 0 && valor !== saldo" type="button" class="boton-texto todo" @click="valor = saldo">Pagó todo: {{ pesos(saldo) }}</button>

        <label class="campo">
          Fecha del abono
          <span class="control"><input v-model="fecha" type="date" :max="fechaHoy" /></span>
        </label>
        <label class="campo">
          <span>Nota <span class="ayuda">(opcional)</span></span>
          <span class="control"><input v-model="nota" autocomplete="off" maxlength="255" placeholder="Ej.: Pagó en efectivo" /></span>
        </label>

        <section class="nuevo-saldo" :class="{ error: pasaDelSaldo }">
          <span>Nuevo saldo</span>
          <strong class="monto">{{ pasaDelSaldo ? '—' : pesos(nuevoSaldo) }}</strong>
          <span v-if="pasaDelSaldo">Es más de lo que debe ({{ pesos(saldo) }}).</span>
          <span v-else-if="nuevoSaldo === 0 && valor">Queda a paz y salvo.</span>
          <span v-else>Si el abono cubre todo, la deuda queda como pagada.</span>
        </section>

        <p v-if="error" class="mensaje error" role="alert">
          <IconAlertTriangle :size="20" aria-hidden="true" />
          {{ error }}
        </p>

        <button class="boton">
          <IconDeviceFloppy :size="22" aria-hidden="true" />
          Guardar abono
        </button>

        <!-- RN-18 / RN-39: evitar que el mismo abono por Nequi se registre dos veces -->
        <p class="suave pequeno">
          Si le pagó por Nequi, mejor cargue el pago en la caja (botón «Pago Nequi») y escoja «Abono de fiado»: así queda en el Nequi del día y
          en la deuda al mismo tiempo.
        </p>
      </form>
    </main>
  </div>
</template>

<style scoped>
.todo {
  justify-self: start;
  margin-top: -8px;
}

.nuevo-saldo {
  display: grid;
  gap: 2px;
  padding: 16px 18px;
  border-radius: var(--radio-control);
  color: var(--verde-900);
  background: var(--verde-50);
}

.nuevo-saldo strong {
  font-size: 1.8rem;
  line-height: 1.15;
}

.nuevo-saldo.error {
  color: var(--error);
  background: var(--error-fondo);
}
</style>
