<script setup lang="ts">
import { IconChevronRight } from '@tabler/icons-vue'
import { hoy } from '../calculos'
import { haceCuanto, type CuentaUsuario } from '../admin'

// Una cuenta en una lista del admin. Al tocarla se abre lo que se puede hacer con ella (GestionCuenta).
defineProps<{ cuenta: CuentaUsuario; conNegocio?: boolean }>()
const emit = defineEmits<{ abrir: [cuenta: CuentaUsuario] }>()
</script>

<template>
  <li>
    <!-- El botón no es contenedor flex (Android 7): los renglones son spans en bloque -->
    <button type="button" class="fila-cuenta" @click="emit('abrir', cuenta)">
      <span class="renglon nombre">
        <strong>{{ cuenta.nombre }}</strong>
        <span v-if="cuenta.es_yo" class="etiqueta cerrado">Usted</span>
        <span v-else-if="cuenta.principal" class="etiqueta info">Dueño</span>
      </span>
      <span class="renglon suave pequeno">
        {{ cuenta.usuario }}<template v-if="conNegocio && cuenta.negocio"> · {{ cuenta.negocio.nombre }}</template>
      </span>
      <span class="renglon pequeno">
        <span v-if="!cuenta.activo" class="estado apagada">Desactivada</span>
        <span v-else-if="cuenta.debe_cambiar_contrasena" class="estado aviso">Contraseña temporal sin cambiar</span>
        <span v-else class="suave">{{ cuenta.ultima_conexion ? `Usó la app ${haceCuanto(cuenta.ultima_conexion, hoy())}` : 'Todavía no ha entrado' }}</span>
      </span>
      <IconChevronRight class="flecha" :size="20" aria-hidden="true" />
    </button>
  </li>
</template>

<style scoped>
li {
  border-top: 1px solid var(--linea);
}

li:first-child {
  border-top: none;
}

.fila-cuenta {
  position: relative;
  display: block;
  width: 100%;
  min-height: 64px;
  padding: 12px 32px 12px 0;
  font: inherit;
  text-align: left;
  color: inherit;
  background: none;
  border: none;
  cursor: pointer;
}

.renglon {
  display: block;
  overflow-wrap: anywhere;
}

.nombre strong {
  margin-right: 6px;
}

.etiqueta {
  vertical-align: 2px;
}

.estado {
  font-weight: 700;
}

.apagada {
  color: var(--texto-suave);
}

.aviso {
  color: var(--aviso-texto);
}

.flecha {
  position: absolute;
  right: 4px;
  top: 50%;
  margin-top: -10px;
  color: var(--texto-tenue);
}
</style>
