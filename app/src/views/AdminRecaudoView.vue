<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { IconAlertTriangle, IconDownload } from '@tabler/icons-vue'
import BarraVolver from '../components/BarraVolver.vue'
import DescargarExcel from '../components/DescargarExcel.vue'
import { nombreMes, pesos } from '../calculos'
import { useAdminApi, type RecaudoAdmin } from '../admin'
import { ErrorApi } from '../api'

// Lo que ha entrado por las suscripciones en los últimos 12 meses, y el Excel de los pagos
const api = useAdminApi()
const recaudo = ref<RecaudoAdmin | null>(null)
const error = ref('')
const descargando = ref(false)

async function cargar() {
  error.value = ''
  try {
    recaudo.value = await api.recaudo(12)
  } catch (e) {
    error.value = e instanceof ErrorApi && e.sinConexion ? 'Sin conexión: el panel del admin necesita internet.' : 'No se pudo cargar lo recaudado.'
  }
}
onMounted(cargar)

// El mes actual arriba; los meses de antes del primer pago no se muestran
const meses = computed(() => {
  const lista = recaudo.value?.meses ?? []
  const primero = lista.findIndex((m) => m.total > 0)
  return primero === -1 ? lista.slice(-1) : lista.slice(primero).reverse()
})
// Sin .at(-1): el WebView de Android 7 no lo tiene
const esteMes = computed(() => {
  const lista = recaudo.value?.meses ?? []
  return lista[lista.length - 1]?.total ?? 0
})
const maximo = computed(() => Math.max(1, ...meses.value.map((m) => m.total)))
// Promedio desde el primer mes con pagos (sin contar el mes actual, que todavía no termina)
const promedio = computed(() => {
  const cerrados = meses.value.slice(1)
  return cerrados.length ? Math.round(cerrados.reduce((s, m) => s + m.total, 0) / cerrados.length) : null
})

const nombreDe = (mes: string, i: number) => {
  if (i === 0) return 'Este mes'
  const [anio, numero] = mes.split('-').map(Number) as [number, number]
  const nombre = nombreMes(numero)
  return `${nombre[0]!.toUpperCase()}${nombre.slice(1)} ${anio}`
}
</script>

<template>
  <div>
    <BarraVolver titulo="Lo recaudado" :volver-a="{ name: 'admin' }" />
    <main class="pantalla">
      <p v-if="error" class="mensaje error" role="alert">
        <IconAlertTriangle :size="20" aria-hidden="true" />{{ error }}
        <button class="boton-texto" @click="cargar">Reintentar</button>
      </p>
      <p v-else-if="!recaudo" class="suave">Cargando…</p>

      <template v-if="recaudo">
        <section class="tarjeta cifras" aria-label="Resumen">
          <div>
            <span class="suave">Este mes</span>
            <strong class="monto">{{ pesos(esteMes) }}</strong>
          </div>
          <div>
            <span class="suave">Últimos 12 meses</span>
            <strong class="monto">{{ pesos(recaudo.total) }}</strong>
          </div>
          <p v-if="promedio !== null" class="suave pequeno promedio">En promedio entran {{ pesos(promedio) }} al mes.</p>
        </section>

        <section class="tarjeta">
          <h2>Mes a mes</h2>
          <p class="suave pequeno">Sin los pagos anulados.</p>
          <ul class="meses">
            <li v-for="(m, i) in meses" :key="m.mes">
              <div class="renglon">
                <span>{{ nombreDe(m.mes, i) }}</span>
                <strong class="monto">{{ pesos(m.total) }}</strong>
              </div>
              <div class="progreso" aria-hidden="true"><span :style="{ width: `${(m.total / maximo) * 100}%` }" /></div>
              <span class="suave pequeno">{{ m.pagos === 0 ? 'Sin pagos' : m.pagos === 1 ? '1 pago' : `${m.pagos} pagos` }}</span>
            </li>
          </ul>
        </section>

        <button class="boton secundario" @click="descargando = true"><IconDownload :size="22" aria-hidden="true" /> Descargar los pagos en Excel</button>
      </template>
    </main>

    <DescargarExcel
      :abierta="descargando"
      ruta="/admin/recaudo/excel"
      nombre="pagos-suscripcion"
      inicial="anio"
      descripcion="Un pago por fila: la fecha, el negocio, el valor y hasta cuándo le cubre. Los pagos anulados aparecen con su motivo y sin valor."
      @cerrar="descargando = false"
    />
  </div>
</template>

<style scoped>
.cifras {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
  background: var(--verde-50);
  box-shadow: none;
}

.cifras div {
  display: grid;
  gap: 2px;
}

.cifras strong {
  font-size: 1.5rem;
  color: var(--verde-800);
  white-space: nowrap;
}

/* Celulares angostos: una cifra debajo de la otra para que "$1.015.000" no se parta */
@media (max-width: 380px) {
  .cifras {
    grid-template-columns: minmax(0, 1fr);
  }
}

.promedio {
  grid-column: 1 / -1;
  margin: 0;
}

.meses {
  list-style: none;
  margin: 0;
  padding: 0;
}

.meses li {
  display: grid;
  gap: 6px;
  padding: 12px 0;
  border-top: 1px solid var(--linea);
}

.meses li:first-child {
  border-top: none;
}

.renglon {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 12px;
}

.progreso {
  height: 10px;
  border-radius: 5px;
  background: var(--verde-50);
  overflow: hidden;
}

.progreso span {
  display: block;
  height: 100%;
  border-radius: 5px;
  background: var(--verde-600);
}
</style>
