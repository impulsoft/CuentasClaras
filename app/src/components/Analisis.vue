<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { IconChevronDown, IconChevronLeft, IconChevronRight, IconTrendingDown, IconTrendingUp } from '@tabler/icons-vue'
import GraficaBarras, { type Barra } from './GraficaBarras.vue'
import DesgloseMedios from './DesgloseMedios.vue'
import { fechaLarga, mesVecino, nombreMes, pesos } from '../calculos'
import { analisisAnual, analisisDiario, analisisMensual, compararAnios, diasConCierre, porcentaje, textoMeses, type CierreAnalisis, type PagoAnalisis } from '../analisis'

/*
 * RF-21 a RF-25: análisis por día, mes y año, con el desglose por medio de pago.
 * Lo usan el dueño (con los datos del celular) y el admin (con los del servidor, solo lectura).
 */
const props = defineProps<{ cierres: CierreAnalisis[]; pagos: PagoAnalisis[]; hoy: string }>()

const vista = ref<'dia' | 'mes' | 'anio'>('dia')
const anio = ref(Number(props.hoy.slice(0, 4)))
const mes = ref(Number(props.hoy.slice(5, 7)))
const seleccionada = ref<string | null>(null)
watch([vista, anio, mes], () => (seleccionada.value = null))

const dias = computed(() => diasConCierre(props.cierres, props.pagos, props.hoy))
const diario = computed(() => analisisDiario(dias.value, anio.value, mes.value, props.hoy))
const mensual = computed(() => analisisMensual(dias.value, anio.value, props.hoy))
const anual = computed(() => analisisAnual(dias.value, props.hoy))

const SEMANA = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado']
const diaSemana = (fecha: string) => SEMANA[new Date(`${fecha}T12:00:00Z`).getUTCDay()]!
const mayuscula = (t: string) => t.charAt(0).toUpperCase() + t.slice(1)
const nombreDelMes = (clave: string) => mayuscula(nombreMes(Number(clave.slice(5, 7))))

// Pasar de periodo con las flechas; no se va más allá de hoy
const etiquetaPeriodo = computed(() => (vista.value === 'dia' ? `${mayuscula(nombreMes(mes.value))} ${anio.value}` : String(anio.value)))
const esActual = computed(() => (vista.value === 'dia' ? `${anio.value}-${String(mes.value).padStart(2, '0')}` >= props.hoy.slice(0, 7) : anio.value >= Number(props.hoy.slice(0, 4))))
function mover(paso: 1 | -1) {
  if (vista.value === 'dia') {
    const v = mesVecino(anio.value, mes.value, paso)
    anio.value = v.anio
    mes.value = v.mes
  } else {
    anio.value += paso
  }
}

const resumen = computed(() => {
  if (vista.value === 'dia') {
    const d = diario.value
    return {
      total: d.desglose.total,
      detalle: `${d.diasConCierre} ${d.diasConCierre === 1 ? 'día cerrado' : 'días cerrados'}`,
      mejor: d.mejor ? { texto: `${mayuscula(diaSemana(d.mejor))} ${fechaLarga(d.mejor)}`, valor: d.dias.find((x) => x.fecha === d.mejor)!.total } : null,
      peor: d.peor ? { texto: `${mayuscula(diaSemana(d.peor))} ${fechaLarga(d.peor)}`, valor: d.dias.find((x) => x.fecha === d.peor)!.total } : null,
      desglose: d.desglose,
    }
  }
  if (vista.value === 'mes') {
    const m = mensual.value
    const valor = (clave: string) => m.meses.find((x) => x.clave === clave)!.desglose.total
    return {
      total: m.desglose.total,
      detalle: `Total del año ${anio.value}`,
      mejor: m.mejor ? { texto: nombreDelMes(m.mejor), valor: valor(m.mejor) } : null,
      peor: m.peor ? { texto: nombreDelMes(m.peor), valor: valor(m.peor) } : null,
      desglose: m.desglose,
    }
  }
  const total = anual.value.reduce((s, a) => s + a.desglose.total, 0)
  const desglose = anual.value.reduce(
    (s, a) => ({
      efectivo: s.efectivo + a.desglose.efectivo,
      nequi: s.nequi + a.desglose.nequi,
      otros: s.otros + a.desglose.otros,
      sinDetalle: s.sinDetalle + a.desglose.sinDetalle,
      total: s.total + a.desglose.total,
    }),
    { efectivo: 0, nequi: 0, otros: 0, sinDetalle: 0, total: 0 },
  )
  return { total, detalle: 'Todos los años', mejor: null, peor: null, desglose }
})

const barras = computed<Barra[]>(() => {
  if (vista.value === 'dia') {
    const d = diario.value
    return d.dias.map((x, i) => ({
      clave: x.fecha,
      etiqueta: i === 0 || (i + 1) % 5 === 0 ? String(i + 1) : '',
      titulo: `${mayuscula(diaSemana(x.fecha))} ${fechaLarga(x.fecha)}`,
      valor: x.total,
      marca: x.fecha === d.mejor ? 'mejor' : x.fecha === d.peor ? 'peor' : null,
    }))
  }
  if (vista.value === 'mes') {
    const m = mensual.value
    return m.meses.map((x) => ({
      clave: x.clave,
      etiqueta: nombreDelMes(x.clave).slice(0, 1),
      titulo: `${nombreDelMes(x.clave)} ${anio.value}`,
      valor: x.desglose.total,
      marca: x.clave === m.mejor ? 'mejor' : x.clave === m.peor ? 'peor' : null,
    }))
  }
  return anual.value.map((a) => ({ clave: a.clave, etiqueta: a.clave, titulo: `Año ${a.clave}`, valor: a.desglose.total }))
})

/*
 * RF-21: la lista "tipo cuaderno". Por día (hasta 31 renglones) y por mes va plegada: la gráfica
 * de arriba ya muestra cada día, así que en su lugar sale un resumen con el promedio y un botón
 * para verla completa. Por año son pocos renglones y se muestra abierta.
 */
const listaAbierta = ref(false)
watch([vista, anio, mes], () => (listaAbierta.value = false))
const plegable = computed(() => vista.value !== 'anio')
const conCierre = computed(() => filas.value.filter((f) => !f.apagado).length)
const promedio = computed(() => (conCierre.value ? Math.round(resumen.value.total / conCierre.value) : 0))
const textoResumenLista = computed(() => {
  const unidad = vista.value === 'dia' ? ['día', 'días'] : ['mes', 'meses']
  if (!conCierre.value) return `Ningún ${unidad[0]} con cierre todavía`
  return `${conCierre.value} ${conCierre.value === 1 ? unidad[0] : unidad[1]} con cierre · promedio ${pesos(promedio.value)} por ${unidad[0]}`
})

// RF-21: la lista "tipo cuaderno", con el total de cada día, mes o año
const filas = computed(() => {
  if (vista.value === 'dia') {
    return diario.value.dias
      .filter((x) => x.estado !== 'futuro')
      .map((x) => ({
        clave: x.fecha,
        nombre: `${Number(x.fecha.slice(8))} · ${diaSemana(x.fecha)}`,
        valor: x.estado === 'cerrado' ? pesos(x.total) : x.estado === 'no_abrio' ? 'No abrió' : 'Sin cierre',
        apagado: x.estado !== 'cerrado',
        extra: '',
      }))
      .reverse()
  }
  if (vista.value === 'mes') {
    return mensual.value.meses
      .filter((x) => x.clave <= props.hoy.slice(0, 7))
      .map((x) => ({
        clave: x.clave,
        nombre: nombreDelMes(x.clave),
        valor: x.dias ? pesos(x.desglose.total) : 'Sin cierres',
        apagado: !x.dias,
        extra: x.dias ? `${x.dias} ${x.dias === 1 ? 'día' : 'días'}` : '',
      }))
      .reverse()
  }
  return [...anual.value].reverse().map((a) => ({
    clave: a.clave,
    nombre: a.clave,
    valor: pesos(a.desglose.total),
    apagado: false,
    // El cambio es con los mismos meses de los dos años (por ejemplo, "+12,3 % vs 2025, sep a nov")
    extra:
      a.cambio === null || !a.periodo
        ? ''
        : `${a.cambio >= 0 ? '+' : '−'}${porcentaje(Math.abs(a.cambio))} vs ${Number(a.clave) - 1}` +
          (a.periodo.meses.length === 12 && a.periodo.hastaDia === null ? '' : `, ${textoMeses(a.periodo.meses, true)}`),
  }))
})

// RF-24: comparar dos años cualesquiera (por defecto, los dos más recientes)
const anioA = ref('')
const anioB = ref('')
watch(
  anual,
  (lista) => {
    const claves = lista.map((a) => a.clave)
    if (!claves.includes(anioB.value)) anioB.value = claves[claves.length - 1] ?? ''
    if (!claves.includes(anioA.value)) anioA.value = claves[claves.length - 2] ?? ''
  },
  { immediate: true },
)
const comparacion = computed(() => compararAnios(dias.value, anioA.value, anioB.value, props.hoy))
// "Se comparan los mismos meses en los dos años: septiembre a noviembre (noviembre hasta el día 20)."
const textoPeriodo = computed(() => {
  const { meses, hastaDia } = comparacion.value.periodo
  const hasta = hastaDia === null ? '' : ` (${textoMeses([Number(props.hoy.slice(5, 7))])} hasta el día ${hastaDia})`
  return meses.length === 12 && hastaDia === null
    ? 'Se comparan los dos años completos.'
    : `Se comparan los mismos meses en los dos años: ${textoMeses(meses)}${hasta}.`
})
const cambio = (c: number | null) => (c === null ? '—' : `${c >= 0 ? '+' : '−'}${porcentaje(Math.abs(c))}`)

const titulos = { dia: 'Día a día', mes: 'Mes a mes', anio: 'Año a año' }
const nombresMejor = { dia: ['Día de mayor venta', 'Día de menor venta'], mes: ['Mes de mayor venta', 'Mes de menor venta'], anio: ['', ''] }
</script>

<template>
  <div class="analisis">
    <div class="vistas" role="group" aria-label="Ver por">
      <button v-for="(t, v) in { dia: 'Por día', mes: 'Por mes', anio: 'Por año' }" :key="v" type="button" :class="{ activa: vista === v }" :aria-pressed="vista === v" @click="vista = v">
        {{ t }}
      </button>
    </div>

    <section class="tarjeta">
      <nav v-if="vista !== 'anio'" class="periodo" aria-label="Cambiar de periodo">
        <button class="flecha" :aria-label="vista === 'dia' ? 'Mes anterior' : 'Año anterior'" @click="mover(-1)"><IconChevronLeft :size="26" /></button>
        <h2>{{ etiquetaPeriodo }}</h2>
        <button class="flecha" :aria-label="vista === 'dia' ? 'Mes siguiente' : 'Año siguiente'" :disabled="esActual" @click="mover(1)"><IconChevronRight :size="26" /></button>
      </nav>

      <div class="total">
        <span class="suave">{{ resumen.detalle }}</span>
        <strong class="monto">{{ pesos(resumen.total) }}</strong>
      </div>

      <GraficaBarras v-model="seleccionada" :barras="barras" />

      <ul v-if="resumen.mejor || resumen.peor" class="extremos">
        <li v-if="resumen.mejor">
          <IconTrendingUp :size="22" aria-hidden="true" />
          <span><span class="suave pequeno">▲ {{ nombresMejor[vista][0] }}</span><strong>{{ resumen.mejor.texto }}</strong></span>
          <span class="monto">{{ pesos(resumen.mejor.valor) }}</span>
        </li>
        <li v-if="resumen.peor">
          <IconTrendingDown :size="22" aria-hidden="true" />
          <span><span class="suave pequeno">▼ {{ nombresMejor[vista][1] }}</span><strong>{{ resumen.peor.texto }}</strong></span>
          <span class="monto">{{ pesos(resumen.peor.valor) }}</span>
        </li>
      </ul>
      <p v-if="vista === 'mes' && resumen.mejor" class="suave pequeno">El mes en curso no cuenta para el de menor venta porque todavía no termina.</p>
    </section>

    <!-- RF-24 -->
    <section v-if="vista === 'anio' && anual.length >= 2" class="tarjeta">
      <h2>Comparar dos años</h2>
      <div class="dos-anios">
        <label class="campo">
          Año
          <span class="control">
            <select v-model="anioA"><option v-for="a in anual" :key="a.clave" :value="a.clave">{{ a.clave }}</option></select>
          </span>
        </label>
        <label class="campo">
          con
          <span class="control">
            <select v-model="anioB"><option v-for="a in anual" :key="a.clave" :value="a.clave">{{ a.clave }}</option></select>
          </span>
        </label>
      </div>
      <template v-if="comparacion.filas.length">
        <p class="suave pequeno">{{ textoPeriodo }}</p>
        <!-- En el celular no caben 4 columnas de cifras grandes: el medio va en su propio renglón -->
        <div class="desliza">
          <table class="comparacion">
            <thead>
              <tr>
                <th scope="col">{{ anioA }}</th>
                <th scope="col">{{ anioB }}</th>
                <th scope="col">Cambio</th>
              </tr>
            </thead>
            <tbody v-for="f in comparacion.filas" :key="f.nombre">
              <tr class="medio">
                <th colspan="3" scope="colgroup">{{ f.nombre }}</th>
              </tr>
              <tr class="cifras">
                <td class="monto">{{ pesos(f.antes) }}</td>
                <td class="monto">{{ pesos(f.despues) }}</td>
                <td class="monto" :class="{ sube: (f.cambio ?? 0) > 0, baja: (f.cambio ?? 0) < 0 }">{{ cambio(f.cambio) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="comparacion.soloTotal" class="suave pequeno">
          Solo se compara el total: en esos meses hay días cargados del cuaderno o de Excel, que no dicen cuánto fue en efectivo, Nequi u otros pagos.
        </p>
      </template>
      <p v-else class="suave">{{ anioA }} y {{ anioB }} no tienen meses con cierres en común, así que no se pueden comparar. Escoja otros años.</p>
    </section>

    <!-- RF-25 -->
    <section v-if="resumen.total > 0" class="tarjeta">
      <h2>Por medio de pago</h2>
      <DesgloseMedios :desglose="resumen.desglose" />
    </section>

    <!-- RF-21 -->
    <section v-if="filas.length" class="tarjeta">
      <h2>{{ titulos[vista] }}</h2>
      <template v-if="plegable">
        <p class="suave">{{ textoResumenLista }}</p>
        <button type="button" class="boton secundario ver-lista" :aria-expanded="listaAbierta" @click="listaAbierta = !listaAbierta">
          {{ listaAbierta ? 'Ocultar la lista' : `Ver los ${filas.length} ${vista === 'dia' ? 'días' : 'meses'}` }}
          <IconChevronDown class="flecha-lista" :class="{ abierta: listaAbierta }" :size="22" aria-hidden="true" />
        </button>
      </template>
      <ul v-if="!plegable || listaAbierta" class="filas">
        <li v-for="f in filas" :key="f.clave" :class="{ apagado: f.apagado }">
          <span class="nombre">{{ f.nombre }}</span>
          <span v-if="f.extra" class="suave pequeno">{{ f.extra }}</span>
          <span class="monto">{{ f.valor }}</span>
        </li>
      </ul>
    </section>
  </div>
</template>

<style scoped>
.analisis {
  display: grid;
  gap: 16px;
}

.vistas {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 4px;
  padding: 4px;
  background: var(--superficie-suave);
  border-radius: 16px;
}

.vistas button {
  min-height: 48px;
  font: inherit;
  font-weight: 800;
  color: var(--texto-suave);
  background: none;
  border: none;
  border-radius: 12px;
  cursor: pointer;
  transition-property: background-color, color;
  transition-duration: 150ms;
}

.vistas button.activa {
  color: var(--verde-800);
  background: var(--superficie);
  box-shadow: var(--sombra-1);
}

.periodo {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.flecha {
  width: 48px;
  height: 48px;
  flex: none;
  display: grid;
  place-items: center;
  color: var(--verde-800);
  background: var(--verde-50);
  border: none;
  border-radius: 14px;
  cursor: pointer;
}

.flecha:disabled {
  opacity: 0.35;
  cursor: default;
}

.total {
  display: grid;
  gap: 2px;
}

.total strong {
  font-size: 2rem;
  line-height: 1.1;
  color: var(--verde-800);
}

.extremos {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 8px;
}

.extremos li {
  display: grid;
  grid-template-columns: 22px minmax(0, 1fr) auto;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border-radius: var(--radio-control);
  background: var(--superficie-suave);
  color: var(--texto-suave);
}

.extremos li > span:nth-child(2) {
  display: grid;
  color: var(--texto);
}

.extremos .monto {
  font-weight: 800;
  color: var(--texto);
}

.dos-anios {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: 10px;
}

/* Si en un celular muy angosto la tabla no cabe, se desliza ella sola y no toda la pantalla */
.desliza {
  overflow-x: auto;
}

.comparacion {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.92rem;
  font-variant-numeric: tabular-nums;
}

.comparacion th,
.comparacion td {
  padding: 4px;
  text-align: right;
  white-space: nowrap;
}

.comparacion thead th {
  padding-bottom: 8px;
  font-size: 0.85rem;
  color: var(--texto-suave);
  border-bottom: 1px solid var(--linea);
}

.comparacion .medio th {
  padding-top: 12px;
  text-align: left;
  font-weight: 700;
}

.comparacion .cifras td {
  padding-bottom: 10px;
  border-bottom: 1px solid var(--linea);
}

/* Celulares muy pequeños (320 px): cifras un poco más pequeñas para que quepan sin deslizar */
@media (max-width: 340px) {
  .comparacion {
    font-size: 0.8rem;
  }
}

/* El total vendido va primero y resaltado */
.comparacion tbody:first-of-type {
  font-weight: 800;
}

.sube {
  color: var(--verde-700);
}

.baja {
  color: var(--error);
}

.filas {
  list-style: none;
  margin: 0;
  padding: 0;
}

.filas li {
  display: flex;
  align-items: baseline;
  gap: 10px;
  min-height: 40px;
  padding: 8px 0;
  border-bottom: 1px solid var(--linea);
}

/* Botón para abrir o cerrar la lista día a día (o mes a mes) */
.ver-lista {
  width: 100%;
  min-height: 48px;
}

.flecha-lista {
  transition-property: transform;
  transition-duration: 200ms;
}

.flecha-lista.abierta {
  transform: rotate(180deg);
}

.filas .nombre {
  flex: 1;
}

.filas .monto {
  font-weight: 700;
}

.filas li.apagado {
  color: var(--texto-tenue);
}

.filas li.apagado .monto {
  font-weight: 500;
}
</style>
