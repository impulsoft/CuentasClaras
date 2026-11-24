<script setup lang="ts">
import { computed } from 'vue'
import { pesos, semanasDelMes, valorEnMiles, type EstadoDia } from '../calculos'

// RF-14 / RNF-06: calendario del mes con el total y el estado de cada día
const props = defineProps<{
  anio: number
  mes: number
  hoy: string
  seleccionado: string
  dias: Map<string, { estado: EstadoDia; total: number }>
  mejor: string | null
  peor: string | null
}>()
const emit = defineEmits<{ seleccionar: [fecha: string] }>()

const semanas = computed(() => semanasDelMes(props.anio, props.mes))
const LETRAS = ['L', 'M', 'M', 'J', 'V', 'S', 'D']

// "431" y debajo "mil": así lo dice la gente ("vendí 431 mil") y cabe en la casilla
function etiqueta(fecha: string): { texto: string; unidad: string } {
  const dia = props.dias.get(fecha)
  if (!dia) return { texto: '', unidad: '' }
  if (dia.estado === 'cerrado') return valorEnMiles(dia.total)
  if (dia.estado === 'no_abrio') return { texto: 'No abrió', unidad: '' }
  if (fecha === props.hoy) return { texto: 'Hoy', unidad: '' }
  return { texto: '', unidad: '' }
}

function descripcion(fecha: string): string {
  const dia = props.dias.get(fecha)
  const numero = Number(fecha.slice(8))
  const estados: Record<EstadoDia, string> = {
    cerrado: `cerrado, ${pesos(dia?.total ?? 0)}`,
    sin_cierre: 'sin cierre',
    no_abrio: 'no abrió',
    futuro: 'día futuro',
  }
  const extra = fecha === props.mejor ? ', mejor día' : fecha === props.peor ? ', peor día' : ''
  return `${numero}: ${estados[dia?.estado ?? 'futuro']}${extra}`
}
</script>

<template>
  <div class="calendario">
    <div class="fila letras" aria-hidden="true">
      <span v-for="(letra, i) in LETRAS" :key="i">{{ letra }}</span>
    </div>
    <div v-for="(semana, s) in semanas" :key="s" class="fila">
      <template v-for="(fecha, d) in semana" :key="fecha ?? `vacio-${s}-${d}`">
        <span v-if="!fecha" class="vacio"></span>
        <button
          v-else
          class="dia"
          :class="[dias.get(fecha)?.estado, { hoy: fecha === hoy, seleccionado: fecha === seleccionado }]"
          :disabled="dias.get(fecha)?.estado === 'futuro'"
          :aria-pressed="fecha === seleccionado"
          :aria-label="descripcion(fecha)"
          @click="emit('seleccionar', fecha)"
        >
          <span class="numero">{{ Number(fecha.slice(8)) }}</span>
          <span class="monto">{{ etiqueta(fecha).texto }}</span>
          <span v-if="etiqueta(fecha).unidad" class="unidad">{{ etiqueta(fecha).unidad }}</span>
          <span v-if="fecha === mejor" class="marca mejor" aria-hidden="true">▲</span>
          <span v-else-if="fecha === peor" class="marca peor" aria-hidden="true">▼</span>
        </button>
      </template>
    </div>

    <div class="leyenda" aria-hidden="true">
      <span><i class="muestra cerrado"></i>Cerrado</span>
      <span><i class="muestra sin_cierre"></i>Sin cierre</span>
      <span><i class="muestra no_abrio"></i>No abrió</span>
      <span><i class="muestra futuro"></i>Futuro</span>
      <span><b class="mejor">▲</b> Mejor día</span>
      <span><b class="peor">▼</b> Peor día</span>
    </div>
  </div>
</template>

<style scoped>
/* minmax(0, 1fr): las 7 columnas se reparten el ancho por igual; con 1fr solo, un valor como
   "$468k" no deja encoger su columna y en celulares de 360 px el domingo quedaba por fuera */
.fila {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  gap: 6px;
  margin-bottom: 6px;
}

.letras span {
  text-align: center;
  font-size: 0.75rem;
  font-weight: 800;
  color: var(--texto-tenue);
}

.dia {
  position: relative;
  min-height: 64px;
  padding: 6px 2px 4px;
  font: inherit;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1px;
  color: var(--texto);
  background: var(--superficie);
  border: 1.5px solid var(--linea);
  border-radius: 14px;
  cursor: pointer;
  transition-property: transform, box-shadow;
  transition-duration: 150ms;
}

.dia:active:not(:disabled) {
  transform: scale(0.94);
}

.numero {
  font-size: 1rem;
  font-weight: 800;
  line-height: 1.2;
}

.monto {
  font-size: 0.72rem;
  font-weight: 700;
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}

.unidad {
  margin-top: -2px;
  font-size: 0.58rem;
  font-weight: 700;
  line-height: 1;
  opacity: 0.8;
}

.marca {
  font-size: 0.6rem;
  line-height: 1;
}

/* Celulares angostos (360 px o menos): menos espacio entre días y el valor un poco más pequeño */
@media (max-width: 380px) {
  .fila {
    gap: 3px;
    margin-bottom: 4px;
  }

  .dia {
    padding-inline: 1px;
    border-radius: 11px;
  }

  .monto {
    font-size: 0.64rem;
    letter-spacing: -0.02em;
  }
}

.mejor {
  color: var(--verde-700);
}

.peor {
  color: #b42318;
}

.cerrado {
  background: var(--cerrado);
  border-color: var(--cerrado-borde);
  color: var(--cerrado-texto);
}

.sin_cierre {
  background: var(--sin-cierre);
  border: 1.5px dashed var(--sin-cierre-borde);
  color: var(--sin-cierre-texto);
}

.no_abrio {
  background: var(--no-abrio);
  border-color: var(--no-abrio-borde);
  color: var(--texto-suave);
}

/* "No abrió" puede partirse en dos renglones si la columna es angosta */
.no_abrio .monto {
  font-size: 0.6rem;
  line-height: 1.1;
  text-align: center;
  white-space: normal;
}

.futuro {
  color: #c2c8c4;
  background: transparent;
  border-color: #eef0ee;
  cursor: default;
}

/* Hoy: aro verde; día escogido: aro oscuro y un poco más grande */
.hoy:not(.seleccionado) {
  box-shadow: 0 0 0 2.5px var(--verde-600);
}

.seleccionado {
  box-shadow: 0 0 0 3px var(--texto), var(--sombra-2);
}

.leyenda {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 14px;
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px solid var(--linea);
  font-size: 0.78rem;
  font-weight: 600;
  color: var(--texto-suave);
}

.muestra {
  display: inline-block;
  width: 14px;
  height: 14px;
  margin-right: 6px;
  vertical-align: -2px;
  border-radius: 4px;
  border: 1.5px solid var(--no-abrio-borde);
}

.muestra.cerrado {
  border-color: var(--cerrado-borde);
}

.muestra.sin_cierre {
  border: 1.5px dashed var(--sin-cierre-borde);
}

.muestra.futuro {
  background: var(--superficie);
  border-color: var(--linea);
}
</style>
