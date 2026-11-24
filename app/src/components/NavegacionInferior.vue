<script setup lang="ts">
import { IconCalendarEvent, IconChartBar, IconUsers } from '@tabler/icons-vue'
import { useDeudoresStore } from '../stores/deudores'

// Barra de abajo para pasar entre la caja, los deudores y los análisis: botones grandes con nombre
const deudores = useDeudoresStore()
</script>

<template>
  <nav class="navegacion" aria-label="Secciones">
    <RouterLink :to="{ name: 'inicio' }" class="pestana" active-class="" exact-active-class="activa">
      <IconCalendarEvent :size="26" aria-hidden="true" />
      <span>Caja</span>
    </RouterLink>
    <RouterLink :to="{ name: 'deudores' }" class="pestana" active-class="activa">
      <span class="icono">
        <IconUsers :size="26" aria-hidden="true" />
        <span v-if="deudores.totales.vencidas" class="globo">{{ deudores.totales.vencidas }}</span>
      </span>
      <span>Deudores<span v-if="deudores.totales.vencidas" class="oculto"> ({{ deudores.totales.vencidas }} vencidos)</span></span>
    </RouterLink>
    <RouterLink :to="{ name: 'analisis' }" class="pestana" active-class="activa">
      <IconChartBar :size="26" aria-hidden="true" />
      <span>Análisis</span>
    </RouterLink>
  </nav>
</template>

<style scoped>
.navegacion {
  position: fixed;
  inset: auto 0 0;
  z-index: 30;
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 6px;
  padding: 8px 12px;
  padding-bottom: max(8px, env(safe-area-inset-bottom));
  background: rgb(255 255 255 / 0.96);
  box-shadow: 0 -1px 0 var(--linea), 0 -8px 24px rgb(16 32 26 / 0.06);
}

.pestana {
  display: grid;
  justify-items: center;
  gap: 2px;
  min-height: 60px;
  padding: 6px;
  font-size: 0.9rem;
  font-weight: 800;
  color: var(--texto-suave);
  text-decoration: none;
  border-radius: var(--radio-control);
  transition-property: background-color, color;
  transition-duration: 150ms;
}

.pestana.activa {
  color: var(--verde-800);
  background: var(--verde-50);
}

.icono {
  position: relative;
  display: grid;
}

.globo {
  position: absolute;
  top: -6px;
  right: -14px;
  min-width: 22px;
  height: 22px;
  padding: 0 6px;
  display: grid;
  place-items: center;
  font-size: 0.75rem;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
  color: #ffffff;
  background: var(--error);
  border: 2px solid #ffffff;
  border-radius: var(--radio-pildora);
}

.oculto {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip-path: inset(50%);
  white-space: nowrap;
}
</style>
