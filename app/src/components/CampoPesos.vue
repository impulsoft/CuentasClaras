<script setup lang="ts">
import { computed } from 'vue'
import { leerPesos } from '../calculos'

// Campo de pesos: muestra "$ 450.000" mientras se escribe y guarda el número (RNF-05)
defineProps<{ etiqueta: string; ayuda?: string; deshabilitado?: boolean }>()
const valor = defineModel<number | null>({ required: true })

const texto = computed({
  get: () => (valor.value === null ? '' : String(valor.value).replace(/\B(?=(\d{3})+(?!\d))/g, '.')),
  set: (escrito: string) => (valor.value = leerPesos(escrito)),
})
</script>

<template>
  <label class="campo">
    <span>
      {{ etiqueta }}
      <span v-if="ayuda" class="ayuda">{{ ayuda }}</span>
    </span>
    <span class="control">
      <span class="prefijo" aria-hidden="true">$</span>
      <input v-model="texto" inputmode="numeric" autocomplete="off" placeholder="0" :disabled="deshabilitado" />
    </span>
  </label>
</template>

<style scoped>
.ayuda {
  margin-left: 4px;
}

.prefijo {
  position: absolute;
  left: 18px;
  font-size: 1.15rem;
  font-weight: 700;
  color: var(--texto-tenue);
  pointer-events: none;
}
</style>
