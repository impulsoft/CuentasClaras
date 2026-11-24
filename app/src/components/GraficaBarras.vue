<script setup lang="ts">
import { computed } from 'vue'
import { pesos, pesosCortos } from '../calculos'

/*
 * Gráfica de barras de una sola serie (RF-22 a RF-24). Hecha con HTML y CSS, sin librerías,
 * para que cargue rápido en celulares de gama baja (RNF-11).
 * Al tocar o pasar el dedo por una barra, arriba se ve su nombre y su valor (en vez de un globo
 * que el dedo tapa). El mejor y el peor se marcan con ▲ y ▼, no solo con color.
 */
export interface Barra {
  clave: string
  etiqueta: string // debajo de la barra (puede ir vacía para no amontonar)
  titulo: string // nombre completo: "Martes 18 de noviembre"
  valor: number
  marca?: 'mejor' | 'peor' | null
}

const props = defineProps<{ barras: Barra[]; vacio?: string }>()
const seleccionada = defineModel<string | null>({ default: null })

const maximo = computed(() => Math.max(...props.barras.map((b) => b.valor), 0))
const actual = computed(() => props.barras.find((b) => b.clave === seleccionada.value) ?? null)
const hayDatos = computed(() => maximo.value > 0)

const alto = (valor: number) => (maximo.value > 0 ? Math.max((valor / maximo.value) * 100, valor > 0 ? 2 : 0) : 0)
</script>

<template>
  <div class="grafica">
    <p class="lectura" aria-live="polite">
      <template v-if="actual">
        <span class="suave">{{ actual.titulo }}</span>
        <strong class="monto">{{ pesos(actual.valor) }}</strong>
      </template>
      <span v-else class="suave">{{ hayDatos ? 'Toque una barra para ver su valor' : (vacio ?? 'Todavía no hay ventas en este periodo') }}</span>
    </p>

    <div class="area" :style="{ '--columnas': barras.length }">
      <span v-if="hayDatos" class="tope suave">{{ pesosCortos(maximo) }}</span>
      <div class="barras" role="list">
        <button
          v-for="b in barras"
          :key="b.clave"
          type="button"
          role="listitem"
          class="columna"
          :class="{ activa: b.clave === seleccionada }"
          :aria-label="`${b.titulo}: ${pesos(b.valor)}${b.marca === 'mejor' ? ', el de mayor venta' : b.marca === 'peor' ? ', el de menor venta' : ''}`"
          @click="seleccionada = b.clave"
          @pointerenter="(e) => e.pointerType === 'mouse' && (seleccionada = b.clave)"
        >
          <span class="relleno" :style="{ height: `${alto(b.valor)}%` }">
            <span v-if="b.marca" class="marca" :class="b.marca" aria-hidden="true">{{ b.marca === 'mejor' ? '▲' : '▼' }}</span>
          </span>
        </button>
      </div>
      <div class="etiquetas" aria-hidden="true">
        <span v-for="b in barras" :key="b.clave">{{ b.etiqueta }}</span>
      </div>
    </div>
  </div>
</template>

<style scoped>
.grafica {
  display: grid;
  gap: 8px;
}

.lectura {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 12px;
  min-height: 1.6em;
}

.lectura strong {
  font-size: 1.15rem;
}

.area {
  position: relative;
  display: grid;
  gap: 4px;
}

.tope {
  position: absolute;
  top: -2px;
  left: 0;
  font-size: 0.75rem;
  font-variant-numeric: tabular-nums;
}

/* La línea del máximo, muy suave, para leer la altura de las barras */
.barras {
  height: 160px;
  display: grid;
  grid-template-columns: repeat(var(--columnas), minmax(0, 1fr));
  align-items: end;
  gap: 2px;
  padding-top: 18px;
  border-bottom: 1px solid var(--borde-campo);
  background: linear-gradient(var(--linea), var(--linea)) 0 18px / 100% 1px no-repeat;
}

/* La barra se apoya abajo con posición absoluta y no con flex: en WebViews viejos (Android 7)
   un <button> no puede ser contenedor flex y la barra quedaba sin alto (RNF-03) */
.columna {
  position: relative;
  display: block;
  height: 100%;
  padding: 0;
  background: none;
  border: none;
  cursor: pointer;
}

.relleno {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  display: block;
  max-width: 40px;
  margin: 0 auto;
  background: var(--verde-600);
  border-radius: 4px 4px 0 0;
  transition-property: background-color;
  transition-duration: 150ms;
}

.columna.activa .relleno {
  background: var(--verde-900);
}

/* Centrada sobre la barra sin "translate" (RNF-03) */
.marca {
  position: absolute;
  bottom: calc(100% + 2px);
  left: -6px;
  right: -6px;
  text-align: center;
  font-size: 0.65rem;
  line-height: 1;
  color: var(--texto);
}

.etiquetas {
  display: grid;
  grid-template-columns: repeat(var(--columnas), minmax(0, 1fr));
  gap: 2px;
  font-size: 0.72rem;
  color: var(--texto-suave);
  text-align: center;
  white-space: nowrap;
}

.etiquetas span {
  overflow: visible;
}
</style>
