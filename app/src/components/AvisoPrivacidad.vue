<script setup lang="ts">
import HojaInferior from './HojaInferior.vue'
import { useSesionStore } from '../stores/sesion'

// RN-41: aviso corto para mostrárselo al cliente la primera vez que se le fía (Ley 1581 de 2012).
// TEXTO PROVISIONAL: falta que lo revise un abogado (sección 7 del documento de requisitos).
defineProps<{ abierto: boolean }>()
const emit = defineEmits<{ cerrar: [] }>()
const sesion = useSesionStore()
</script>

<template>
  <HojaInferior :abierta="abierto" titulo="Aviso de privacidad" @cerrar="emit('cerrar')" @enviar="emit('cerrar')">
    <p>
      <strong>{{ sesion.usuario?.negocio?.nombre ?? 'Este negocio' }}</strong> guarda su nombre y su número de celular solo para llevar la cuenta de lo que
      usted debe y para recordarle el pago.
    </p>
    <ul class="puntos">
      <li>Sus datos no se le muestran ni se le comparten a nadie más, ni a otros negocios.</li>
      <li>Puede pedir que los corrijan cuando quiera.</li>
      <li>Cuando termine de pagar, puede pedir que los borren.</li>
    </ul>
    <p class="suave pequeno">
      El negocio es el responsable de sus datos y la aplicación Cierre de Caja solo los guarda de forma segura, según la Ley 1581 de 2012 de
      protección de datos personales.
    </p>
    <template #acciones>
      <button class="boton entendido">Entendido</button>
    </template>
  </HojaInferior>
</template>

<style scoped>
.puntos {
  margin: 0;
  padding-left: 22px;
  display: grid;
  gap: 6px;
}

.entendido {
  grid-column: 1 / -1;
}
</style>
