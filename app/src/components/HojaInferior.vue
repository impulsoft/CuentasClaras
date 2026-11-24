<script setup lang="ts">
import { IconX } from '@tabler/icons-vue'

// Ventana que sube desde abajo (en el computador queda centrada). Las acciones van en un pie fijo.
defineProps<{ abierta: boolean; titulo: string }>()
const emit = defineEmits<{ cerrar: []; enviar: [] }>()
</script>

<template>
  <Transition name="hoja">
    <div v-if="abierta" class="fondo" @click.self="emit('cerrar')">
      <form class="hoja" role="dialog" aria-modal="true" :aria-label="titulo" @submit.prevent="emit('enviar')">
        <header class="cabecera">
          <h2>{{ titulo }}</h2>
          <button type="button" class="cerrar" aria-label="Cerrar" @click="emit('cerrar')"><IconX :size="24" /></button>
        </header>
        <div class="cuerpo"><slot /></div>
        <footer v-if="$slots.acciones" class="acciones"><slot name="acciones" /></footer>
      </form>
    </div>
  </Transition>
</template>

<style scoped>
.fondo {
  position: fixed;
  inset: 0;
  z-index: 40;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  background: rgb(4 30 24 / 0.45);
}

.hoja {
  width: 100%;
  max-width: 480px;
  max-height: 94vh;
  display: flex;
  flex-direction: column;
  background: var(--superficie);
  border-radius: 24px 24px 0 0;
  box-shadow: var(--sombra-3);
}

@media (min-width: 600px) {
  .fondo {
    align-items: center;
    padding: 16px;
  }

  .hoja {
    border-radius: 24px;
  }
}

.cabecera {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 18px 16px 10px 20px;
}

.cerrar {
  width: 44px;
  height: 44px;
  flex: none;
  display: grid;
  place-items: center;
  color: var(--texto-suave);
  background: var(--superficie-suave);
  border: none;
  border-radius: 50%;
  cursor: pointer;
}

.cuerpo {
  display: grid;
  gap: 14px;
  padding: 4px 20px 20px;
  overflow-y: auto;
}

.cuerpo > * {
  min-width: 0;
}

.acciones {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  padding: 12px 20px 20px;
  padding-bottom: max(20px, env(safe-area-inset-bottom));
  border-top: 1px solid var(--linea);
}

/* Si solo hay un botón, ocupa todo el ancho */
.acciones > :only-child {
  grid-column: 1 / -1;
}

.hoja-enter-active,
.hoja-leave-active {
  transition-property: opacity;
  transition-duration: 200ms;
}

.hoja-enter-active .hoja,
.hoja-leave-active .hoja {
  transition-property: transform;
  transition-duration: 240ms;
  transition-timing-function: cubic-bezier(0.2, 0.9, 0.3, 1);
}

.hoja-enter-from,
.hoja-leave-to {
  opacity: 0;
}

.hoja-enter-from .hoja {
  transform: translateY(40px);
}

.hoja-leave-to .hoja {
  transform: translateY(12px);
}
</style>
