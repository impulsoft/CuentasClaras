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
  gap: 5px;
  margin-bottom: 5px;
}

.letras span {
  text-align: center;
  font-size: 0.72rem;
  font-weight: 600;
  color: var(--texto-tenue);
}

/*
 * Casilla limpia: blanca con borde fino, el número arriba y lo vendido (en miles) debajo.
 * El estado va en una rayita de color abajo: verde = cerrado, naranja = falta, gris = no abrió.
 */
.dia {
  position: relative;
  min-height: 60px;
  padding: 7px 2px 8px;
  font: inherit;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1px;
  overflow: hidden;
  color: var(--texto);
  background: var(--superficie);
  border: 1px solid var(--linea);
  border-radius: 10px;
  cursor: pointer;
  transition-property: transform, box-shadow, border-color;
  transition-duration: 150ms;
}

.dia::after {
  content: '';
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  height: 3px;
  background: transparent;
}

.dia:active:not(:disabled) {
  transform: scale(0.94);
}

.numero {
  font-size: 0.95rem;
  font-weight: 600;
  line-height: 1.2;
}

.monto {
  font-size: 0.72rem;
  font-weight: 600;
  white-space: nowrap;
  color: var(--texto-suave);
  font-variant-numeric: tabular-nums;
}

.unidad {
  margin-top: -2px;
  font-size: 0.58rem;
  font-weight: 500;
  line-height: 1;
  color: var(--texto-tenue);
}

.marca {
  font-size: 0.55rem;
  line-height: 1;
}

/* Celulares angostos (360 px o menos): menos espacio entre días y el valor un poco más pequeño */
@media (max-width: 380px) {
  .fila {
    gap: 3px;
    margin-bottom: 3px;
  }

  .dia {
    padding-inline: 1px;
    border-radius: 8px;
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

.cerrado::after {
  background: var(--verde-600);
}

.cerrado .monto {
  color: var(--texto);
}

.sin_cierre::after {
  background: #ef9f27;
}

.no_abrio {
  background: var(--superficie-suave);
  color: var(--texto-suave);
}

.no_abrio::after {
  background: var(--no-abrio-borde);
}

/* "No abrió" puede partirse en dos renglones si la columna es angosta */
.no_abrio .monto {
  font-size: 0.6rem;
  font-weight: 500;
  line-height: 1.1;
  text-align: center;
  white-space: normal;
}

.futuro {
  color: #c4cac7;
  background: transparent;
  border-color: transparent;
  cursor: default;
}

/* Hoy: borde verde; día escogido: aro oscuro */
.hoy:not(.seleccionado) {
  border-color: var(--verde-600);
  box-shadow: 0 0 0 1px var(--verde-600);
}

.hoy .monto {
  color: var(--verde-700);
}

.seleccionado {
  border-color: var(--texto);
  box-shadow: 0 0 0 1.5px var(--texto);
}

.leyenda {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 14px;
  margin-top: 10px;
  padding-top: 12px;
  border-top: 1px solid var(--linea);
  font-size: 0.76rem;
  font-weight: 500;
  color: var(--texto-suave);
}

/* Muestra de la leyenda: una rayita del color del estado */
.muestra {
  display: inline-block;
  width: 14px;
  height: 4px;
  margin-right: 6px;
  vertical-align: 2px;
  border-radius: 2px;
  background: var(--no-abrio-borde);
}

.muestra.cerrado {
  background: var(--verde-600);
}

.muestra.sin_cierre {
  background: #ef9f27;
}

.muestra.futuro {
  background: var(--linea);
}
</style>
