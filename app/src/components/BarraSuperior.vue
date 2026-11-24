<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { Capacitor } from '@capacitor/core'
import { IconBell, IconBuildingStore, IconKey, IconLogout, IconShieldCheck, IconUserCircle } from '@tabler/icons-vue'
import HojaInferior from './HojaInferior.vue'
import { useSesionStore } from '../stores/sesion'
import { useDialogoStore } from '../stores/dialogo'
import { guardarAjuste, leerAjuste, programarRecordatorios } from '../recordatorio'

// Barra verde de arriba: el negocio y el botón "Cuenta" (cambiar contraseña, RF-47, y salir, RF-02)
const props = defineProps<{ titulo: string; admin?: boolean }>()
const emit = defineEmits<{ salir: [] }>()
const sesion = useSesionStore()
const dialogo = useDialogoStore()
const router = useRouter()
const abierta = ref(false)

// Recordatorio diario del cierre: solo para el dueño y en la APK (el navegador no deja programar avisos)
const conRecordatorio = !props.admin && Capacitor.isNativePlatform()
const recordatorio = reactive(leerAjuste())
const HORAS = Array.from({ length: 13 }, (_, i) => {
  const minutos = 16 * 60 + i * 30 // de 4:00 p. m. a 10:00 p. m., cada media hora
  const h = Math.floor(minutos / 60)
  const m = String(minutos % 60).padStart(2, '0')
  return { valor: `${String(h).padStart(2, '0')}:${m}`, texto: `${h - 12}:${m} p. m.` }
})

async function cambiarRecordatorio() {
  guardarAjuste({ ...recordatorio })
  await programarRecordatorios()
  const hora = HORAS.find((h) => h.valor === recordatorio.hora)?.texto ?? recordatorio.hora
  dialogo.avisar(recordatorio.activo ? `Le recordaremos el cierre a las ${hora}` : 'Recordatorio apagado')
}

function cambiarContrasena() {
  abierta.value = false
  router.push({ name: 'contrasena' })
}

function salir() {
  abierta.value = false
  emit('salir')
}
</script>

<template>
  <header class="barra">
    <div class="contenido">
      <span class="logo" aria-hidden="true">
        <IconShieldCheck v-if="admin" :size="24" :stroke-width="2" />
        <IconBuildingStore v-else :size="24" :stroke-width="2" />
      </span>
      <div class="textos">
        <h1>{{ titulo }}</h1>
        <slot />
      </div>
      <button class="salir" @click="abierta = true">
        <IconUserCircle :size="24" aria-hidden="true" />
        <span>Cuenta</span>
      </button>
    </div>
  </header>

  <HojaInferior :abierta="abierta" titulo="Mi cuenta" @cerrar="abierta = false">
    <div class="quien">
      <strong>{{ sesion.usuario?.nombre }}</strong>
      <span class="suave">Usuario: {{ sesion.usuario?.usuario }}</span>
    </div>
    <section v-if="conRecordatorio" class="recuadro recordatorio">
      <label class="casilla">
        <input v-model="recordatorio.activo" type="checkbox" @change="cambiarRecordatorio" />
        <span>
          <IconBell :size="20" aria-hidden="true" class="campana" />
          Recordarme hacer el cierre todos los días
        </span>
      </label>
      <label v-if="recordatorio.activo" class="campo">
        A qué hora
        <span class="control">
          <select v-model="recordatorio.hora" @change="cambiarRecordatorio">
            <option v-for="h in HORAS" :key="h.valor" :value="h.valor">{{ h.texto }}</option>
          </select>
        </span>
      </label>
      <p v-if="recordatorio.activo" class="suave pequeno">Si ya hizo el cierre ese día, no le llega el aviso.</p>
    </section>
    <button type="button" class="boton secundario" @click="cambiarContrasena"><IconKey :size="22" aria-hidden="true" /> Cambiar contraseña</button>
    <button type="button" class="boton secundario salir-cuenta" @click="salir"><IconLogout :size="22" aria-hidden="true" /> Salir</button>
  </HojaInferior>
</template>

<style scoped>
.barra {
  background: var(--verde-700);
  color: #ffffff;
  padding: 14px 16px 18px;
  padding-top: max(14px, env(safe-area-inset-top));
}

.contenido {
  max-width: 760px;
  margin: 0 auto;
  display: flex;
  align-items: center;
  gap: 12px;
}

.logo {
  width: 44px;
  height: 44px;
  flex: none;
  display: grid;
  place-items: center;
  border-radius: 14px;
  background: rgb(255 255 255 / 0.14);
}

.textos {
  flex: 1;
  min-width: 0;
}

h1 {
  font-size: 1.2rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.salir {
  display: inline-flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  min-width: 52px;
  min-height: 48px;
  padding: 4px 8px;
  font: inherit;
  font-size: 0.72rem;
  font-weight: 700;
  color: #ffffff;
  background: none;
  border: none;
  border-radius: 12px;
  cursor: pointer;
  transition-property: background-color;
  transition-duration: 150ms;
}

.salir:hover {
  background: rgb(255 255 255 / 0.12);
}

.quien {
  display: grid;
  padding-bottom: 4px;
}

.salir-cuenta {
  color: var(--error);
}

.recordatorio {
  display: grid;
  gap: 12px;
}

.campana {
  margin-right: 4px;
  vertical-align: -3px;
  color: var(--verde-700);
}
</style>
