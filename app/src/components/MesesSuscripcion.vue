<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { IconChevronLeft, IconChevronRight } from '@tabler/icons-vue'
import FilaPagoSuscripcion from './FilaPagoSuscripcion.vue'
import { nombreMes, pesos, pesosCortos } from '../calculos'
import { aniosDePagos, mesesDePagos, type EstadoMesPago, type MesPagos, type PagoSuscripcion } from '../admin'

/*
 * Los meses del año que el negocio ha pagado, como el calendario del dueño pero por meses.
 * Al tocar un mes se ven sus pagos (con "Anular"). Cada pago cuenta en el mes en que se pagó.
 */
const props = defineProps<{ pagos: PagoSuscripcion[]; hoy: string; clienteDesde: string | null }>()
const emit = defineEmits<{ anular: [pago: PagoSuscripcion] }>()

const anios = computed(() => aniosDePagos(props.pagos, props.hoy, props.clienteDesde))
const anioActual = Number(props.hoy.slice(0, 4))
const anio = ref(anioActual)
const meses = computed(() => mesesDePagos(props.pagos, anio.value, props.hoy, props.clienteDesde))

// Mes escogido: el actual en el año actual; en un año pasado, diciembre
const seleccionado = ref(props.hoy.slice(0, 7))
watch(anio, (a) => (seleccionado.value = a === anioActual ? props.hoy.slice(0, 7) : `${a}-12`))
const elegido = computed(() => meses.value.find((m) => m.mes === seleccionado.value) ?? null)

// Los meses gratis (pagos de $0) se cuentan aparte
const pagados = computed(() => meses.value.filter((m) => m.estado === 'pagado' && m.total > 0))
const gratis = computed(() => meses.value.filter((m) => m.estado === 'pagado' && m.total === 0).length)
const sinPagar = computed(() => meses.value.filter((m) => m.estado === 'sin_pagar').length)
const totalAnio = computed(() => pagados.value.reduce((suma, m) => suma + m.total, 0))

const corto = (mes: string) => {
  const nombre = nombreMes(Number(mes.slice(5)))
  return `${nombre[0]!.toUpperCase()}${nombre.slice(1, 3)}`
}
const largo = (mes: string) => {
  const nombre = nombreMes(Number(mes.slice(5)))
  return `${nombre[0]!.toUpperCase()}${nombre.slice(1)} ${mes.slice(0, 4)}`
}

const TEXTO: Record<EstadoMesPago, string> = { pagado: '', al_dia: 'Al día', sin_pagar: 'Sin pagar', antes: '—', futuro: '' }
const DETALLE: Record<EstadoMesPago, string> = {
  pagado: '',
  al_dia: 'No pagó en este mes, pero estaba al día por un pago anterior.',
  sin_pagar: 'Este mes no tuvo pagos y ningún pago anterior lo cubría.',
  antes: 'Todavía no era cliente.',
  futuro: '',
}
const textoDe = (m: MesPagos) => (m.estado === 'pagado' ? (m.total ? pesosCortos(m.total) : 'Gratis') : TEXTO[m.estado])
const descripcion = (m: MesPagos) => `${largo(m.mes)}: ${m.estado === 'pagado' ? (m.total ? `pagó ${pesos(m.total)}` : 'mes gratis') : TEXTO[m.estado] === '—' ? 'todavía no era cliente' : TEXTO[m.estado].toLowerCase() || 'no ha llegado'}`

function cambiarAnio(paso: 1 | -1) {
  const siguiente = anio.value + paso
  if (anios.value.includes(siguiente)) anio.value = siguiente
}
</script>

<template>
  <div class="meses-pagos">
    <div class="encabezado">
      <button v-if="anios.length > 1" type="button" class="flecha" :disabled="anio === anios[0]" aria-label="Año anterior" @click="cambiarAnio(-1)">
        <IconChevronLeft :size="22" aria-hidden="true" />
      </button>
      <strong class="anio">{{ anio }}</strong>
      <button v-if="anios.length > 1" type="button" class="flecha" :disabled="anio === anioActual" aria-label="Año siguiente" @click="cambiarAnio(1)">
        <IconChevronRight :size="22" aria-hidden="true" />
      </button>
    </div>

    <div class="cuadricula">
      <button
        v-for="m in meses"
        :key="m.mes"
        type="button"
        class="mes"
        :class="[m.estado, { actual: m.mes === hoy.slice(0, 7), seleccionado: m.mes === seleccionado }]"
        :disabled="m.estado === 'futuro'"
        :aria-pressed="m.mes === seleccionado"
        :aria-label="descripcion(m)"
        @click="seleccionado = m.mes"
      >
        <span class="nombre">{{ corto(m.mes) }}</span>
        <span class="texto">{{ textoDe(m) }}</span>
      </button>
    </div>

    <!-- "En 2026: pagó 6 meses · $180.000 · 1 mes gratis · 1 mes sin pagar" (solo las partes que tienen algo) -->
    <p class="resumen">
      En {{ anio }}:
      <template v-if="pagados.length">
        <strong>{{ pagados.length === 1 ? 'pagó 1 mes' : `pagó ${pagados.length} meses` }}</strong> · {{ pesos(totalAnio) }}
      </template>
      <template v-if="gratis">{{ pagados.length ? ' · ' : '' }}<strong>{{ gratis === 1 ? '1 mes gratis' : `${gratis} meses gratis` }}</strong></template>
      <template v-if="sinPagar">{{ pagados.length || gratis ? ' · ' : '' }}<span class="falta">{{ sinPagar === 1 ? '1 mes sin pagar' : `${sinPagar} meses sin pagar` }}</span></template>
      <template v-if="!pagados.length && !gratis && !sinPagar">sin pagos</template>
    </p>

    <div class="leyenda" aria-hidden="true">
      <span><i class="muestra pagado"></i>Pagó</span>
      <span><i class="muestra al_dia"></i>Al día</span>
      <span><i class="muestra sin_pagar"></i>Sin pagar</span>
      <span><i class="muestra antes"></i>No era cliente</span>
    </div>

    <!-- Lo del mes escogido -->
    <div v-if="elegido" class="detalle">
      <h4>{{ largo(elegido.mes) }}</h4>
      <ul v-if="elegido.pagos.length" class="lista">
        <FilaPagoSuscripcion v-for="p in elegido.pagos" :key="p.id" :pago="p" @anular="emit('anular', $event)" />
      </ul>
      <p v-if="DETALLE[elegido.estado]" class="suave">{{ DETALLE[elegido.estado] }}</p>
    </div>
  </div>
</template>

<style scoped>
.meses-pagos {
  display: grid;
  gap: 12px;
}

.encabezado {
  display: flex;
  align-items: center;
  justify-content: center;
}

.anio {
  min-width: 72px;
  text-align: center;
  font-size: 1.15rem;
  font-variant-numeric: tabular-nums;
}

.flecha {
  width: 44px;
  height: 44px;
  padding: 0;
  font: inherit;
  color: var(--texto);
  background: var(--superficie-suave);
  border: none;
  border-radius: 50%;
  cursor: pointer;
}

.flecha:disabled {
  opacity: 0.35;
  cursor: default;
}

/* 3 columnas: los nombres y "30 mil" caben bien aun en celulares de 320 px */
.cuadricula {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
}

/* El botón no es contenedor flex (Android 7): cada renglón es un span en bloque */
/* Casilla como la del calendario del dueño: blanca, con una rayita de color abajo por estado */
.mes {
  position: relative;
  min-height: 62px;
  padding: 8px 4px 9px;
  overflow: hidden;
  font: inherit;
  text-align: center;
  color: var(--texto);
  background: var(--superficie);
  border: 1px solid var(--linea);
  border-radius: 10px;
  cursor: pointer;
  transition-property: transform, box-shadow, border-color;
  transition-duration: 150ms;
}

.mes::after {
  content: '';
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  height: 3px;
  background: transparent;
}

.mes:active:not(:disabled) {
  transform: scale(0.95);
}

.nombre,
.texto {
  display: block;
}

.nombre {
  font-weight: 600;
  line-height: 1.3;
}

.texto {
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--texto-suave);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  font-variant-numeric: tabular-nums;
}

.mes.pagado::after,
.muestra.pagado {
  background: var(--verde-600);
}

.mes.pagado .texto {
  color: var(--texto);
}

.mes.al_dia::after,
.muestra.al_dia {
  background: var(--verde-200);
}

.mes.al_dia .texto {
  color: var(--verde-700);
}

.mes.sin_pagar::after,
.muestra.sin_pagar {
  background: var(--error);
}

.mes.sin_pagar .texto {
  color: var(--error);
}

.mes.antes {
  background: var(--superficie-suave);
  color: var(--texto-tenue);
}

.muestra.antes {
  background: var(--no-abrio-borde);
}

.mes.futuro {
  color: #c4cac7;
  background: transparent;
  border-color: transparent;
  cursor: default;
}

/* Mes actual: borde verde; mes escogido: aro oscuro (como el calendario del dueño) */
.actual:not(.seleccionado) {
  border-color: var(--verde-600);
  box-shadow: 0 0 0 1px var(--verde-600);
}

.seleccionado {
  border-color: var(--texto);
  box-shadow: 0 0 0 1.5px var(--texto);
}

.resumen {
  margin: 0;
  text-align: center;
}

.falta {
  color: var(--error);
  font-weight: 600;
}

.leyenda {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  font-size: 0.8rem;
  color: var(--texto-suave);
}

/* Sin "gap" en flex (Android 7): separación con márgenes */
.leyenda span {
  display: inline-flex;
  align-items: center;
  margin: 0 6px 4px;
}

/* Muestra de la leyenda: una rayita del color del estado */
.muestra {
  display: inline-block;
  width: 14px;
  height: 4px;
  margin-right: 6px;
  border-radius: 2px;
}

.detalle {
  padding-top: 4px;
  border-top: 1px solid var(--linea);
}

.detalle h4 {
  margin: 8px 0 0;
}

.detalle p {
  margin: 8px 0 0;
}

.lista {
  list-style: none;
  margin: 0;
  padding: 0;
}
</style>
