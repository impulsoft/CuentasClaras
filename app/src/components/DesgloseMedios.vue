<script setup lang="ts">
import { computed } from 'vue'
import { pesos } from '../calculos'
import { porMedio, porcentaje, type Desglose } from '../analisis'

/*
 * RF-25: cuánto se vendió en efectivo, por Nequi, por otros pagos y "sin detalle" (RN-35).
 * Una barra partida y, debajo, cada medio con su nombre, valor y porcentaje (el color nunca va solo).
 * Colores validados para daltonismo (skill de visualización): efectivo, Nequi y otros; "sin detalle" en gris.
 */
const props = defineProps<{ desglose: Desglose }>()
const medios = computed(() => porMedio(props.desglose))
</script>

<template>
  <div v-if="desglose.total > 0" class="desglose">
    <div class="barra" aria-hidden="true">
      <span v-for="m in medios" :key="m.clave" class="parte" :class="m.clave" :style="{ flexGrow: m.valor }" />
    </div>
    <ul class="medios">
      <li v-for="m in medios" :key="m.clave">
        <span class="muestra" :class="m.clave" aria-hidden="true" />
        <span class="nombre">{{ m.nombre }}</span>
        <span class="monto">{{ pesos(m.valor) }}</span>
        <span class="por suave">{{ porcentaje(m.porcentaje) }}</span>
      </li>
    </ul>
    <p v-if="desglose.sinDetalle > 0" class="suave pequeno">"Sin detalle" es lo que se cargó del cuaderno o de Excel solo con el total del día.</p>
  </div>
</template>

<style scoped>
.desglose {
  display: grid;
  gap: 12px;
}

.barra {
  display: flex;
  gap: 2px;
  height: 14px;
}

.parte {
  min-width: 4px;
  flex-basis: 0;
}

.parte:first-child {
  border-radius: 4px 0 0 4px;
}

.parte:last-child {
  border-radius: 0 4px 4px 0;
}

.parte:only-child {
  border-radius: 4px;
}

.efectivo {
  background: #1baf7a;
}

.nequi {
  background: #6250d6;
}

.otros {
  background: #eda100;
}

.sinDetalle {
  background: #b9c0bc;
}

.medios {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 8px;
}

.medios li {
  display: grid;
  grid-template-columns: 14px minmax(0, 1fr) auto 64px;
  align-items: center;
  gap: 10px;
}

.muestra {
  width: 14px;
  height: 14px;
  border-radius: 4px;
}

.monto {
  font-weight: 700;
}

.por {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
</style>
