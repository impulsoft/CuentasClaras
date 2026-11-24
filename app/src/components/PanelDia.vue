<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import {
  IconAlertTriangle,
  IconArrowsExchange,
  IconCash,
  IconCreditCard,
  IconDeviceFloppy,
  IconDeviceMobile,
  IconDoorOff,
  IconPencil,
  IconReceiptOff,
  IconWallet,
} from '@tabler/icons-vue'
import CampoPesos from './CampoPesos.vue'
import PagosDelDia from './PagosDelDia.vue'
import AbonosDelDia from './AbonosDelDia.vue'
import { avisoBase, fechaLarga, pesos, totalesDelDia, vendidoEnEfectivo } from '../calculos'
import { cierreDelDia, guardarCierre, marcarNoAbrio, pagosDelDia, resolverConflicto, ultimaBase, useConsulta } from '../datos'
import { db, type Cierre } from '../db'
import { useSincronizacionStore } from '../stores/sincronizacion'
import { useDialogoStore } from '../stores/dialogo'
import { programarRecordatorios } from '../recordatorio'

// RF-15: panel del día seleccionado en el calendario. enPanel: va dentro del panel lateral, que ya
// muestra el título del día en su barra verde
const props = defineProps<{ fecha: string; hoy: string; negocioId: string; soloConsulta: boolean; enPanel?: boolean }>()

const sync = useSincronizacionStore()
const dialogo = useDialogoStore()

// null = todavía cargando; undefined = el día no tiene cierre
const cierre = useConsulta<Cierre | undefined | null>(() => cierreDelDia(props.fecha), null, [() => props.fecha])
const pagos = useConsulta(() => pagosDelDia(props.fecha), [], [() => props.fecha])
const conflicto = useConsulta(() => db.conflictos.where('fecha').equals(props.fecha).first(), undefined, [() => props.fecha])

const editando = ref(false)
const aviso = ref('')
watch(
  () => props.fecha,
  () => {
    editando.value = false
    aviso.value = ''
  },
)

const cargando = computed(() => cierre.value === null)
const tieneCierre = computed(() => !!cierre.value && !cierre.value.no_abrio)
const noAbrio = computed(() => cierre.value?.no_abrio === true)
const totales = computed(() => totalesDelDia(cierre.value ?? undefined, pagos.value))
const titulo = computed(() => (props.fecha === props.hoy ? 'Cierre de hoy' : fechaLarga(props.fecha)))
const mostrarFormulario = computed(() => !cargando.value && !props.soloConsulta && (editando.value || (!tieneCierre.value && !noAbrio.value)))

// ---------- Formulario del cierre (RF-16) ----------
const base = ref<number | null>(null)
const efectivo = ref<number | null>(null)
const salida = ref<number | null>(0)
const error = ref('')

watch(
  mostrarFormulario,
  async (abierto) => {
    if (!abierto) return
    error.value = ''
    const actual = tieneCierre.value ? cierre.value! : undefined
    base.value = actual?.base ?? (await ultimaBase()) // RF-49
    efectivo.value = actual?.efectivo ?? null
    salida.value = actual?.salida_caja ?? 0
  },
  { immediate: true },
)

// RF-49: si la base sugerida llega después (p. ej. la trae la sincronización al entrar),
// se llena mientras el dueño no haya escrito una
const baseSugerida = useConsulta(() => ultimaBase(), null)
watch(baseSugerida, (sugerida) => {
  if (sugerida !== null && mostrarFormulario.value && !tieneCierre.value && base.value === null) base.value = sugerida
})

const datosFormulario = computed(() => ({ base: base.value, efectivo: efectivo.value, salida_caja: salida.value ?? 0 }))
const efectivoEnVivo = computed(() => vendidoEnEfectivo({ ...datosFormulario.value, tipo: 'normal', no_abrio: false, total_historico: null }))
const totalEnVivo = computed(() => efectivoEnVivo.value + totales.value.nequi + totales.value.otros)

async function guardar() {
  error.value = ''
  if (base.value === null || efectivo.value === null) {
    error.value = 'Escriba la base inicial y el efectivo contado.'
    return
  }
  // RN-06
  if (
    avisoBase(datosFormulario.value) &&
    !(await dialogo.confirmar({
      titulo: '¿Guardar igual?',
      mensaje: 'El efectivo contado más la plata que sacó es menor que la base. Revise los valores.',
      aceptar: 'Guardar igual',
      cancelar: 'Revisar',
      peligro: true,
    }))
  ) {
    return
  }
  await guardarCierre(props.negocioId, props.fecha, { base: base.value, efectivo: efectivo.value, salida_caja: salida.value ?? 0 })
  editando.value = false
  aviso.value = ''
  dialogo.avisar('Cierre guardado')
  sync.programar()
  programarRecordatorios() // ese día ya no necesita el aviso "¿Ya hizo el cierre?"
}

// RN-31
async function noAbrioEsteDia() {
  const confirmo = await dialogo.confirmar({
    titulo: '¿El negocio no abrió?',
    mensaje: `El ${fechaLarga(props.fecha)} quedará marcado como "No abrió" y no contará para el peor día.`,
    aceptar: 'Sí, no abrió',
    cancelar: 'Cancelar',
  })
  if (!confirmo) return
  await marcarNoAbrio(props.negocioId, props.fecha)
  sync.programar()
  programarRecordatorios()
}

// RNF-12
async function resolver(quedarseConEste: boolean) {
  await resolverConflicto(conflicto.value!.id, quedarseConEste)
  dialogo.avisar('Listo, se guardó su elección')
  sync.programar()
}

const totalDe = (c: Cierre) => totalesDelDia(c, pagos.value).total

const filasDetalle = computed(() => {
  const c = cierre.value
  if (!c) return []
  // RN-35: un día del cuaderno o de Excel solo trae el total y, si se conoce, el Nequi
  if (c.tipo === 'historico') {
    return [
      ...(totales.value.nequi ? [{ etiqueta: 'Nequi', valor: totales.value.nequi, icono: IconDeviceMobile }] : []),
      { etiqueta: 'Sin detalle (del cuaderno o Excel)', valor: totales.value.sinDetalle, icono: IconReceiptOff },
    ]
  }
  return [
    { etiqueta: 'Base inicial', valor: c.base ?? 0, icono: IconWallet },
    { etiqueta: 'Efectivo contado', valor: c.efectivo ?? 0, icono: IconCash },
    { etiqueta: 'Plata que sacó de la caja', valor: c.salida_caja, icono: IconReceiptOff },
    { etiqueta: 'Vendido en efectivo', valor: totales.value.efectivo, icono: IconCash },
    { etiqueta: 'Nequi', valor: totales.value.nequi, icono: IconDeviceMobile },
    { etiqueta: 'Otros pagos', valor: totales.value.otros, icono: IconCreditCard },
  ]
})
</script>

<template>
  <section class="panel" :class="enPanel ? 'en-panel' : 'tarjeta'">
    <!-- RNF-12: el mismo cierre cambió en otro dispositivo -->
    <div v-if="conflicto" class="conflicto" role="alert">
      <div class="conflicto-titulo">
        <IconArrowsExchange :size="22" aria-hidden="true" />
        <strong>Este cierre se cambió en otro dispositivo</strong>
      </div>
      <div class="versiones">
        <div>
          <span class="pequeno">En este celular</span>
          <strong class="monto">{{ pesos(totalDe(conflicto.local)) }}</strong>
        </div>
        <div>
          <span class="pequeno">En el otro dispositivo</span>
          <strong class="monto">{{ pesos(totalDe(conflicto.servidor)) }}</strong>
        </div>
      </div>
      <p class="pequeno">¿Cuál se queda? El otro no se borra hasta que usted escoja.</p>
      <div class="acciones">
        <button class="boton secundario" @click="resolver(false)">El del otro</button>
        <button class="boton" :disabled="soloConsulta" @click="resolver(true)">El de este celular</button>
      </div>
    </div>

    <div v-if="!enPanel || tieneCierre || noAbrio || editando" class="cabecera">
      <h2 v-if="!enPanel">{{ titulo }}</h2>
      <span v-if="tieneCierre && !editando" class="etiqueta cerrado">Cerrado</span>
      <span v-else-if="noAbrio" class="etiqueta no_abrio">No abrió</span>
      <span v-else-if="editando" class="etiqueta aviso">Corrigiendo</span>
    </div>

    <p v-if="aviso" class="mensaje aviso" role="status">
      <IconAlertTriangle :size="20" aria-hidden="true" />
      {{ aviso }}
    </p>

    <!-- Detalle del cierre (RF-15) -->
    <template v-if="tieneCierre && !mostrarFormulario">
      <ul class="detalle">
        <li v-for="fila in filasDetalle" :key="fila.etiqueta">
          <component :is="fila.icono" class="icono-fila" :size="20" aria-hidden="true" />
          <span class="suave">{{ fila.etiqueta }}</span>
          <span class="monto">{{ pesos(fila.valor) }}</span>
        </li>
      </ul>
      <div class="total">
        <span>Total vendido</span>
        <strong class="monto">{{ pesos(totales.total) }}</strong>
      </div>
      <AbonosDelDia :fecha="fecha" />
      <p v-if="cierre!.modificado_en" class="suave pequeno">
        Corregido el {{ new Date(cierre!.modificado_en).toLocaleString('es-CO', { timeZone: 'America/Bogota', dateStyle: 'long', timeStyle: 'short' }) }}
      </p>
      <button v-if="!soloConsulta" class="boton secundario" @click="editando = true">
        <IconPencil :size="22" aria-hidden="true" />
        Corregir cierre
      </button>
    </template>

    <!-- Día "No abrió" (RN-31): los pagos que lleguen cuentan en este día -->
    <template v-else-if="noAbrio && !mostrarFormulario">
      <p class="suave">Este día el negocio no abrió.</p>
      <PagosDelDia v-if="pagos.length" :fecha="fecha" :negocio-id="negocioId" :pagos="pagos" :solo-consulta="soloConsulta" />
      <AbonosDelDia :fecha="fecha" />
      <button v-if="!soloConsulta" class="boton secundario" @click="editando = true">
        <IconPencil :size="22" aria-hidden="true" />
        Registrar el cierre de este día
      </button>
    </template>

    <!-- Formulario del cierre (RF-16, RF-20) -->
    <form v-else-if="mostrarFormulario" class="formulario" @submit.prevent="guardar">
      <CampoPesos v-model="base" etiqueta="Base inicial" ayuda="(con la que abrió la caja)" />
      <CampoPesos v-model="efectivo" etiqueta="Efectivo contado al final del día" />
      <CampoPesos v-model="salida" etiqueta="Plata que sacó de la caja hoy" ayuda="(si sacó)" />

      <PagosDelDia
        :fecha="fecha"
        :negocio-id="negocioId"
        :pagos="pagos"
        :solo-consulta="soloConsulta"
        @cierre-modificado="aviso = 'El total de este día cambió.'"
      />
      <AbonosDelDia :fecha="fecha" />

      <div class="total">
        <span>
          Total vendido
          <span class="desglose">Efectivo {{ pesos(efectivoEnVivo) }} · Nequi {{ pesos(totales.nequi) }} · Otros {{ pesos(totales.otros) }}</span>
        </span>
        <strong class="monto">{{ pesos(totalEnVivo) }}</strong>
      </div>

      <p v-if="error" class="mensaje error" role="alert">
        <IconAlertTriangle :size="20" aria-hidden="true" />
        {{ error }}
      </p>
      <button class="boton guardar">
        <IconDeviceFloppy :size="22" aria-hidden="true" />
        Guardar cierre
      </button>
      <button v-if="editando" type="button" class="boton secundario" @click="editando = false">Cancelar</button>
      <button v-else-if="fecha < hoy" type="button" class="boton-texto" @click="noAbrioEsteDia">
        <IconDoorOff :size="20" aria-hidden="true" />
        Este día no abrió
      </button>
    </form>

    <!-- Solo consulta y el día no tiene cierre -->
    <p v-else-if="!cargando" class="suave">Este día no tiene cierre.</p>
  </section>
</template>

<style scoped>
.panel {
  gap: 16px;
}

/* Dentro del panel lateral: sin el marco de tarjeta (el panel ya es la hoja) */
.en-panel {
  display: grid;
}

.en-panel .cabecera {
  justify-content: flex-end;
}

/* "Guardar cierre" queda siempre a la vista, abajo, mientras se llena el formulario */
.en-panel .guardar {
  position: sticky;
  bottom: 0;
  z-index: 2;
  box-shadow: 0 0 0 8px var(--fondo), 0 10px 24px rgb(15 110 86 / 0.35);
}

.cabecera {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.detalle {
  list-style: none;
  margin: 0;
  padding: 0;
}

.detalle li {
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: 48px;
  border-bottom: 1px solid var(--linea);
}

.detalle li .suave {
  flex: 1;
}

.detalle li .monto {
  font-weight: 700;
}

.icono-fila {
  flex: none;
  color: var(--verde-600);
}

/* Franja del total: lo más importante del día */
.total {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 16px;
  border-radius: 16px;
  font-weight: 800;
  color: var(--verde-900);
  background: var(--verde-50);
}

.total strong {
  font-size: 1.6rem;
  white-space: nowrap;
}

.desglose {
  display: block;
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--verde-800);
}

.conflicto {
  display: grid;
  gap: 10px;
  padding: 16px;
  border-radius: 16px;
  color: var(--aviso-texto);
  background: var(--aviso-fondo);
}

.conflicto-titulo {
  display: flex;
  align-items: center;
  gap: 8px;
}

.versiones {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
}

.versiones div {
  display: grid;
  padding: 10px 12px;
  border-radius: 12px;
  background: rgb(255 255 255 / 0.6);
}

.versiones strong {
  font-size: 1.15rem;
}

.acciones {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
</style>
