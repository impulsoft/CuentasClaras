<script setup lang="ts">
import { IconCalendarEvent, IconChartBar, IconUsers } from '@tabler/icons-vue'
import { useDeudoresStore } from '../stores/deudores'

// Barra de abajo para pasar entre la caja, los deudores y los análisis: botones grandes con nombre
const deudores = useDeudoresStore()
</script>

<template>
  <nav class="navegacion" aria-label="Secciones">
    <RouterLink :to="{ name: 'inicio' }" class="pestana" active-class="" exact-active-class="activa">
      <IconCalendarEvent :size="24" aria-hidden="true" />
      <span>Caja</span>
    </RouterLink>
    <RouterLink :to="{ name: 'deudores' }" class="pestana" active-class="activa">
      <span class="icono">
        <IconUsers :size="24" aria-hidden="true" />
        <span v-if="deudores.totales.vencidas" class="globo">{{ deudores.totales.vencidas }}</span>
      </span>
      <span>Deudores<span v-if="deudores.totales.vencidas" class="oculto"> ({{ deudores.totales.vencidas }} vencidos)</span></span>
    </RouterLink>
    <RouterLink :to="{ name: 'analisis' }" class="pestana" active-class="activa">
      <IconChartBar :size="24" aria-hidden="true" />
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
  padding: 6px 12px;
  padding-bottom: max(6px, env(safe-area-inset-bottom));
  background: var(--superficie);
  border-top: 1px solid var(--linea);
}

.pestana {
  display: grid;
  justify-items: center;
  gap: 2px;
  min-height: 56px;
  padding: 6px;
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--texto-tenue);
  text-decoration: none;
  border-radius: var(--radio-control);
  transition-property: background-color, color;
  transition-duration: 150ms;
}

.pestana.activa {
  color: var(--verde-700);
}

/* Rayita arriba del ícono activo */
.pestana {
  position: relative;
}

.pestana.activa::before {
  content: '';
  position: absolute;
  top: -7px;
  left: 50%;
  width: 28px;
  height: 3px;
  margin-left: -14px;
  border-radius: 0 0 3px 3px;
  background: var(--verde-700);
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
  font-weight: 700;
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
