<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { IconArrowLeft } from '@tabler/icons-vue'

/*
 * Panel que entra desde la izquierda y cubre la pantalla (en el computador queda a la izquierda,
 * con el resto oscurecido). Lo usa el calendario para el cierre de cada día: así la pantalla
 * principal no se alarga y el dueño no tiene que bajar para encontrar el formulario.
 */
const props = defineProps<{ abierto: boolean; titulo: string }>()
const emit = defineEmits<{ cerrar: [] }>()

const encabezado = ref<HTMLElement | null>(null)

// Mientras está abierto, la pantalla de atrás no se desplaza; el foco va al título (lectores de pantalla)
watch(
  () => props.abierto,
  async (abierto) => {
    document.body.classList.toggle('sin-desplazar', abierto)
    if (abierto) {
      await nextTick()
      encabezado.value?.focus()
    }
  },
  { immediate: true },
)
onBeforeUnmount(() => document.body.classList.remove('sin-desplazar'))

function tecla(evento: KeyboardEvent) {
  if (evento.key === 'Escape') emit('cerrar')
}
</script>

<template>
  <!-- v-show y no v-if: el contenido queda armado y escondido. Así, al abrir y cerrar solo se
       mueve el panel; armarlo cada vez trababa la animación en celulares viejos (Android 7) -->
  <Transition name="lateral">
    <div v-show="abierto" class="fondo" @click.self="emit('cerrar')" @keydown="tecla">
      <section class="lateral" role="dialog" aria-modal="true" aria-labelledby="titulo-lateral">
        <header class="barra">
          <button type="button" class="volver" aria-label="Volver al calendario" @click="emit('cerrar')"><IconArrowLeft :size="26" /></button>
          <h2 id="titulo-lateral" ref="encabezado" tabindex="-1">{{ titulo }}</h2>
          <slot name="etiqueta" />
        </header>
        <div class="cuerpo">
          <slot />
        </div>
      </section>
    </div>
  </Transition>
</template>

<style scoped>
.fondo {
  position: fixed;
  inset: 0;
  z-index: 35; /* sobre la navegación de abajo (30), debajo de la ventana del pago Nequi (40) */
  background: rgb(4 30 24 / 0.45);
}

.lateral {
  position: absolute;
  top: 0;
  bottom: 0;
  left: 0;
  width: 100%;
  display: flex;
  flex-direction: column;
  background: var(--fondo);
}

/* En el computador o la tableta: una columna a la izquierda, el resto oscurecido. La sombra va solo
   aquí: en el celular el panel ocupa todo el ancho (no se vería) y dibujarla hace lenta la animación */
@media (min-width: 600px) {
  .lateral {
    width: 480px;
    box-shadow: var(--sombra-3);
  }
}

.barra {
  flex: none;
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 16px;
  padding-top: max(8px, env(safe-area-inset-top));
  color: var(--texto);
  background: var(--superficie);
  border-bottom: 1px solid var(--linea);
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

h2 {
  flex: 1;
  min-width: 0;
  font-size: 1.12rem;
  font-weight: 650;
  letter-spacing: -0.015em;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  outline: none;
}

.cuerpo {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
  overscroll-behavior: contain;
  /* max() va en su propia línea: Android 7 (Chrome 69) no lo entiende y botaría también los márgenes */
  padding: 16px 16px 24px;
  padding-bottom: max(24px, env(safe-area-inset-bottom));
}

.cuerpo > * {
  max-width: 520px;
  margin: 0 auto;
}

/* Entrada: se desliza desde la izquierda y frena suave. Salida: vuelve a la izquierda, más rápido. */
.lateral-enter-active {
  transition: opacity 320ms ease-out;
}

.lateral-enter-active .lateral {
  transition: transform 320ms cubic-bezier(0.22, 1, 0.36, 1);
}

/* Solo mientras se mueve: el celular prepara el panel como una capa aparte y lo desliza sin redibujarlo */
.lateral-enter-active .lateral,
.lateral-leave-active .lateral {
  will-change: transform;
}

.lateral-leave-active {
  transition: opacity 240ms ease-in;
}

.lateral-leave-active .lateral {
  transition: transform 240ms cubic-bezier(0.55, 0, 0.75, 0.2);
}

.lateral-enter-from,
.lateral-leave-to {
  opacity: 0;
}

.lateral-enter-from .lateral,
.lateral-leave-to .lateral {
  transform: translateX(-100%);
}

/*
 * Si el equipo pide menos movimiento (por ejemplo, Windows con los "Efectos de animación" apagados),
 * el panel no se desliza: aparece y se va con un desvanecido suave. Las reglas llevan !important y
 * dos clases para ganarle a la regla general de style.css que apaga todas las transiciones.
 */
@media (prefers-reduced-motion: reduce) {
  .fondo.lateral-enter-active,
  .fondo.lateral-leave-active {
    transition: opacity 200ms ease !important;
  }

  .lateral-enter-active .lateral,
  .lateral-leave-active .lateral {
    transition: none;
  }

  .lateral-enter-from .lateral,
  .lateral-leave-to .lateral {
    transform: none;
  }
}
</style>
