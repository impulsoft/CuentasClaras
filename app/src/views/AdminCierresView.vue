<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { IconAlertTriangle, IconChevronLeft, IconChevronRight, IconEye } from '@tabler/icons-vue'
import BarraVolver from '../components/BarraVolver.vue'
import CalendarioMes from '../components/CalendarioMes.vue'
import {
  estadoDelDia,
  fechaDe,
  fechaLarga,
  hoy as fechaHoy,
  mejorYPeor,
  mesVecino,
  nombreMes,
  pesos,
  totalDelMes,
  totalesDelDia,
  type DatosPago,
  type ResumenDia,
} from '../calculos'
import { useAdminApi, type MesNegocio } from '../admin'
import { ErrorApi } from '../api'

// RF-07: el admin ve los cierres de un negocio en solo lectura. De los abonos, solo el total del día (RN-26).
const props = defineProps<{ id: string }>()
const api = useAdminApi()

const hoy = fechaHoy()
const anio = ref(Number(hoy.slice(0, 4)))
const mes = ref(Number(hoy.slice(5, 7)))
const seleccionado = ref(hoy)
const datos = ref<MesNegocio | null>(null)
const nombreNegocio = ref('')
const error = ref('')

async function cargar() {
  error.value = ''
  datos.value = null
  try {
    const [delMes, negocio] = await Promise.all([api.mes(props.id, anio.value, mes.value), nombreNegocio.value ? null : api.negocio(props.id)])
    datos.value = delMes
    if (negocio) nombreNegocio.value = negocio.nombre
  } catch (e) {
    error.value = e instanceof ErrorApi && e.sinConexion ? 'Sin conexión: esta consulta necesita internet.' : 'No se pudieron cargar los cierres.'
  }
}
watch([anio, mes], cargar, { immediate: true })

const ultimoDia = computed(() => new Date(Date.UTC(anio.value, mes.value, 0)).getUTCDate())
const pagosDe = (fecha: string): DatosPago[] =>
  (datos.value?.pagos ?? []).filter((p) => p.fecha === fecha).map((p) => ({ ...p, estado: 'confirmado' as const }))
const cierreDe = (fecha: string) => datos.value?.cierres.find((c) => c.fecha === fecha)

const resumen = computed<ResumenDia[]>(() => {
  const dias: ResumenDia[] = []
  for (let dia = 1; dia <= ultimoDia.value; dia++) {
    const fecha = fechaDe(anio.value, mes.value, dia)
    const cierre = cierreDe(fecha)
    dias.push({ fecha, estado: estadoDelDia(fecha, hoy, cierre), total: totalesDelDia(cierre, pagosDe(fecha)).total })
  }
  return dias
})
const porFecha = computed(() => new Map(resumen.value.map((d) => [d.fecha, d])))
const mejorPeor = computed(() => mejorYPeor(resumen.value))

function cambiarMes(paso: 1 | -1) {
  const vecino = mesVecino(anio.value, mes.value, paso)
  anio.value = vecino.anio
  mes.value = vecino.mes
  // En el mes actual queda hoy; en otro mes, el día 1
  const primero = fechaDe(vecino.anio, vecino.mes, 1)
  seleccionado.value = hoy.slice(0, 7) === primero.slice(0, 7) ? hoy : primero
}

// Detalle del día seleccionado
const dia = computed(() => {
  const cierre = cierreDe(seleccionado.value)
  const totales = totalesDelDia(cierre, pagosDe(seleccionado.value))
  return { cierre, totales, abonos: datos.value?.abonos_por_dia[seleccionado.value] ?? 0 }
})
const filas = computed(() => {
  const { cierre, totales } = dia.value
  if (!cierre || cierre.no_abrio) return []
  if (cierre.tipo === 'historico') return [{ etiqueta: 'Total del día (cargado del cuaderno o Excel)', valor: totales.total }]
  return [
    { etiqueta: 'Base inicial', valor: cierre.base ?? 0 },
    { etiqueta: 'Efectivo contado', valor: cierre.efectivo ?? 0 },
    { etiqueta: 'Plata que sacó de la caja', valor: cierre.salida_caja },
    { etiqueta: 'Vendido en efectivo', valor: totales.efectivo },
    { etiqueta: 'Nequi', valor: totales.nequi },
    { etiqueta: 'Otros pagos', valor: totales.otros },
  ]
})
</script>

<template>
  <div>
    <BarraVolver :titulo="nombreNegocio || 'Cierres'" :volver-a="{ name: 'admin-negocio', params: { id } }" />

    <main class="pantalla">
      <p class="mensaje exito"><IconEye :size="20" aria-hidden="true" /> Solo consulta: el admin no puede cambiar cierres.</p>
      <p v-if="error" class="mensaje error" role="alert">
        <IconAlertTriangle :size="20" aria-hidden="true" />{{ error }}
        <button class="boton-texto" @click="cargar">Reintentar</button>
      </p>

      <section class="tarjeta">
        <nav class="mes" aria-label="Cambiar de mes">
          <button class="flecha" aria-label="Mes anterior" @click="cambiarMes(-1)"><IconChevronLeft :size="26" /></button>
          <div class="mes-textos">
            <h2>{{ nombreMes(mes) }} {{ anio }}</h2>
            <p>
              <span class="suave">Total del mes </span>
              <strong class="monto">{{ datos ? pesos(totalDelMes(resumen)) : '–' }}</strong>
            </p>
          </div>
          <button class="flecha" aria-label="Mes siguiente" @click="cambiarMes(1)"><IconChevronRight :size="26" /></button>
        </nav>
        <CalendarioMes
          :anio="anio"
          :mes="mes"
          :hoy="hoy"
          :seleccionado="seleccionado"
          :dias="porFecha"
          :mejor="mejorPeor.mejor"
          :peor="mejorPeor.peor"
          @seleccionar="seleccionado = $event"
        />
      </section>

      <section v-if="datos && seleccionado <= hoy" class="tarjeta">
        <h2>{{ fechaLarga(seleccionado) }}</h2>
        <template v-if="dia.cierre && !dia.cierre.no_abrio">
          <ul class="detalle">
            <li v-for="f in filas" :key="f.etiqueta">
              <span class="suave">{{ f.etiqueta }}</span>
              <span class="monto">{{ pesos(f.valor) }}</span>
            </li>
          </ul>
          <div class="total">
            <span>Total vendido</span>
            <strong class="monto">{{ pesos(dia.totales.total) }}</strong>
          </div>
        </template>
        <p v-else-if="dia.cierre?.no_abrio" class="suave">Este día el negocio no abrió.</p>
        <p v-else class="suave">Este día no tiene cierre.</p>
        <!-- RN-26: solo el total, sin nombres -->
        <p v-if="dia.abonos" class="recuadro abonos">
          <span>Abonos de deudores</span>
          <strong class="monto">{{ pesos(dia.abonos) }}</strong>
        </p>
      </section>
    </main>
  </div>
</template>

<style scoped>
.mes {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
}

.mes-textos {
  text-align: center;
  display: grid;
  gap: 2px;
}

.mes h2 {
  text-transform: capitalize;
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

.detalle {
  list-style: none;
  margin: 0;
  padding: 0;
}

.detalle li {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  min-height: 40px;
  align-items: center;
  border-bottom: 1px solid var(--linea);
}

.total {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  font-weight: 800;
  font-size: 1.15rem;
}

.total strong {
  font-size: 1.4rem;
  color: var(--verde-700);
}

.abonos {
  display: flex;
  justify-content: space-between;
  gap: 12px;
}
</style>
