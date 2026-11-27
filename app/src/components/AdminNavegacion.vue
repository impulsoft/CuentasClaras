<script setup lang="ts">
import { IconBuildingStore, IconLayoutDashboard, IconUsers } from '@tabler/icons-vue'

// Barra de abajo del panel del admin: inicio (lo urgente), los negocios y los usuarios
</script>

<template>
  <nav class="navegacion" aria-label="Secciones del panel">
    <RouterLink :to="{ name: 'admin' }" class="pestana" active-class="" exact-active-class="activa">
      <IconLayoutDashboard :size="24" aria-hidden="true" />
      <span>Inicio</span>
    </RouterLink>
    <RouterLink :to="{ name: 'admin-negocios' }" class="pestana" active-class="activa">
      <IconBuildingStore :size="24" aria-hidden="true" />
      <span>Negocios</span>
    </RouterLink>
    <RouterLink :to="{ name: 'admin-usuarios' }" class="pestana" active-class="activa">
      <IconUsers :size="24" aria-hidden="true" />
      <span>Usuarios</span>
    </RouterLink>
  </nav>
</template>

<style scoped>
/* Igual a la barra del dueño (NavegacionInferior.vue) */
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
  position: relative;
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
  transition-property: color;
  transition-duration: 150ms;
}

.pestana.activa {
  color: var(--verde-700);
}

/* Rayita arriba del ícono activo */
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
</style>
