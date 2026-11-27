<script setup lang="ts">
import { IconArrowLeft } from '@tabler/icons-vue'
import { useRouter, type RouteLocationRaw } from 'vue-router'

// Barra verde de las pantallas internas: flecha para volver y el título
const props = defineProps<{ titulo: string; volverA: RouteLocationRaw }>()
const router = useRouter()

// Si se llegó desde la app, vuelve atrás; si se abrió directo, va a la pantalla de arriba
function volver() {
  if (window.history.state?.back) router.back()
  else router.replace(props.volverA)
}
</script>

<template>
  <header class="barra">
    <div class="contenido">
      <button class="volver" aria-label="Volver" @click="volver"><IconArrowLeft :size="26" /></button>
      <h1>{{ titulo }}</h1>
    </div>
  </header>
</template>

<style scoped>
.barra {
  position: sticky;
  top: 0;
  z-index: 20;
  background: var(--superficie);
  color: var(--texto);
  border-bottom: 1px solid var(--linea);
  padding: 8px 16px;
  padding-top: max(10px, env(safe-area-inset-top));
}

.contenido {
  max-width: 520px;
  margin: 0 auto;
  display: flex;
  align-items: center;
  gap: 8px;
}

.volver {
  width: 48px;
  height: 48px;
  flex: none;
  margin-left: -8px;
  display: grid;
  place-items: center;
  color: var(--texto);
  background: none;
  border: none;
  border-radius: 12px;
  cursor: pointer;
  transition-property: background-color, transform;
  transition-duration: 150ms;
}

.volver:hover {
  background: var(--superficie-suave);
}

.volver:active {
  transform: scale(0.94);
}

h1 {
  min-width: 0;
  font-size: 1.12rem;
  font-weight: 650;
  letter-spacing: -0.015em;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
