<script setup lang="ts">
import { fechaCorta, pesos } from '../calculos'
import type { PagoSuscripcion } from '../admin'

// Un pago de la suscripción en una lista. El anulado sigue a la vista (no se borra), tachado y con su motivo.
defineProps<{ pago: PagoSuscripcion }>()
const emit = defineEmits<{ anular: [pago: PagoSuscripcion] }>()
const conAnio = (fecha: string) => `${fechaCorta(fecha)} ${fecha.slice(0, 4)}`
</script>

<template>
  <li class="fila" :class="{ anulado: pago.anulado_en }">
    <span class="datos">
      <span class="cuando">{{ conAnio(pago.fecha) }}<template v-if="pago.nota"> · {{ pago.nota }}</template></span>
      <span v-if="pago.anulado_en" class="motivo pequeno">Anulado: {{ pago.motivo_anulacion }}</span>
      <span v-else class="suave pequeno">Hasta el {{ conAnio(pago.vence_el) }}</span>
    </span>
    <span class="valor">
      <strong class="monto">{{ pesos(pago.valor) }}</strong>
      <button v-if="!pago.anulado_en" class="boton-texto anular" :aria-label="`Anular el pago del ${conAnio(pago.fecha)}`" @click="emit('anular', pago)">
        Anular
      </button>
    </span>
  </li>
</template>

<style scoped>
/* Sin "gap" en flex: el WebView de Android 7 no lo tiene */
.fila {
  display: flex;
  align-items: center;
  justify-content: space-between;
  min-height: 52px;
  padding: 8px 0;
  border-bottom: 1px solid var(--linea);
}

.datos {
  min-width: 0;
  display: grid;
  margin-right: 12px;
}

.valor {
  display: grid;
  justify-items: end;
  flex: none;
}

/* Un toque cómodo aunque el texto sea pequeño */
.anular {
  min-height: 40px;
  padding: 0 4px;
  color: var(--error);
  font-size: 0.95rem;
}

.anulado .monto,
.anulado .cuando {
  text-decoration: line-through;
  color: var(--texto-suave);
}

.motivo {
  color: var(--error);
  overflow-wrap: anywhere;
}
</style>
