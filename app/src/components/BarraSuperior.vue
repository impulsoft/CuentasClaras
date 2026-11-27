<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { Capacitor } from '@capacitor/core'
import { IconBell, IconBuildingStore, IconCamera, IconKey, IconLifebuoy, IconLogout, IconShieldCheck, IconUserCircle } from '@tabler/icons-vue'
import HojaInferior from './HojaInferior.vue'
import { useSesionStore } from '../stores/sesion'
import { useDialogoStore } from '../stores/dialogo'
import { useSincronizacionStore } from '../stores/sincronizacion'
import { useComprobantesStore } from '../stores/comprobantes'
import { guardarAjuste, leerAjuste, programarRecordatorios } from '../recordatorio'
import { useAdminApi } from '../admin'
import { fechaCorta } from '../calculos'

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

/*
 * Acceso directo a la cámara para un pago Nequi (RF-17), desde cualquier pantalla del dueño: la
 * foto sigue el mismo camino que "Pago Nequi > Tomar foto" (la ventana del pago se abre de una vez
 * y los datos aparecen al leerla). No sale para el admin ni si el negocio solo puede consultar.
 */
const sync = useSincronizacionStore()
const comprobantes = useComprobantesStore()
const entradaCamara = ref<HTMLInputElement | null>(null)

async function alTomarFoto(evento: Event) {
  const entrada = evento.target as HTMLInputElement
  const archivo = entrada.files?.[0]
  entrada.value = '' // para poder tomar otra foto después
  if (archivo) await comprobantes.agregar('foto', archivo)
}

async function cambiarRecordatorio() {
  guardarAjuste({ ...recordatorio })
  await programarRecordatorios()
  const hora = HORAS.find((h) => h.valor === recordatorio.hora)?.texto ?? recordatorio.hora
  dialogo.avisar(recordatorio.activo ? `Le recordaremos el cierre a las ${hora}` : 'Recordatorio apagado')
}

/*
 * Código de recuperación del admin: si olvida la contraseña, en la pantalla de entrar usa este
 * código y pone una nueva, sin ayuda de nadie. Se muestra una sola vez; uno nuevo anula el anterior.
 */
const codigoCreadoEl = computed(() => sesion.usuario?.codigo_recuperacion_en ?? null)
const codigo = ref<string | null>(null)

async function crearCodigo() {
  if (codigoCreadoEl.value) {
    const confirmo = await dialogo.confirmar({
      titulo: '¿Crear un código nuevo?',
      mensaje: 'El código que tiene guardado dejará de servir. Guarde el nuevo en su lugar.',
      aceptar: 'Crear código nuevo',
      cancelar: 'Cancelar',
    })
    if (!confirmo) return
  }
  try {
    codigo.value = (await useAdminApi().crearCodigo()).codigo
    abierta.value = false
    await sesion.actualizar() // para que aquí diga desde cuándo lo tiene
  } catch {
    dialogo.avisar('No se pudo crear el código. Revise la conexión.')
  }
}

function cambiarContrasena() {
  abierta.value = false
  router.push({ name: 'contrasena' })
}

function salir() {
  abierta.value = false
  emit('salir')
}

// El panel del admin lo usa para el aviso "Cree su código de recuperación"
defineExpose({ crearCodigo })
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
      <template v-if="!admin && !sync.soloConsulta">
        <button class="salir" aria-label="Tomar foto de un pago Nequi" :disabled="comprobantes.leyendo" @click="entradaCamara?.click()">
          <IconCamera :size="24" aria-hidden="true" />
          <span>Nequi</span>
        </button>
        <input ref="entradaCamara" type="file" accept="image/*" capture="environment" hidden @change="alTomarFoto" />
      </template>
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
    <section v-if="admin" class="recuadro codigo">
      <strong><IconLifebuoy :size="20" aria-hidden="true" class="campana" /> Código de recuperación</strong>
      <p class="suave pequeno">
        Si olvida su contraseña, con este código pone una nueva desde la pantalla de entrar.
        {{ codigoCreadoEl ? `Tiene uno creado el ${fechaCorta(codigoCreadoEl.slice(0, 10))} ${codigoCreadoEl.slice(0, 4)}.` : 'Todavía no tiene uno.' }}
      </p>
      <button type="button" class="boton secundario" @click="crearCodigo">{{ codigoCreadoEl ? 'Crear un código nuevo' : 'Crear mi código' }}</button>
    </section>
    <button type="button" class="boton secundario salir-cuenta" @click="salir"><IconLogout :size="22" aria-hidden="true" /> Salir</button>
  </HojaInferior>

  <!-- El código se muestra una sola vez -->
  <HojaInferior :abierta="!!codigo" titulo="Su código de recuperación" @cerrar="codigo = null" @enviar="codigo = null">
    <p>Escríbalo en un papel y guárdelo en un lugar seguro, o tómele una foto. Si olvida la contraseña, en la pantalla de entrar toque la rueda de ajustes (arriba a la derecha).</p>
    <p class="clave-grande">{{ codigo }}</p>
    <p class="suave pequeno">No se vuelve a mostrar y sirve una sola vez. Si lo pierde, cree uno nuevo aquí mismo.</p>
    <template #acciones>
      <button class="boton listo-codigo">Ya lo guardé</button>
    </template>
  </HojaInferior>
</template>

<style scoped>
/* Barra blanca con línea fina abajo: el verde queda para las acciones */
.barra {
  position: sticky;
  top: 0;
  z-index: 20;
  background: var(--superficie);
  color: var(--texto);
  border-bottom: 1px solid var(--linea);
  padding: 10px 12px 10px 16px;
  padding-top: max(10px, env(safe-area-inset-top));
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
  border-radius: 12px;
  color: var(--verde-700);
  background: var(--verde-50);
}

.textos {
  flex: 1;
  min-width: 0;
}

h1 {
  font-size: 1.12rem;
  font-weight: 650;
  letter-spacing: -0.015em;
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
  font-size: 0.7rem;
  font-weight: 600;
  color: var(--texto-suave);
  background: none;
  border: none;
  border-radius: 12px;
  cursor: pointer;
  transition-property: background-color;
  transition-duration: 150ms;
}

.salir:hover {
  color: var(--texto);
  background: var(--superficie-suave);
}

.salir:disabled {
  opacity: 0.5;
  cursor: default;
}

/* Los dos botones de la barra (Nequi y Cuenta) van juntos; el título se acorta si no cabe */
.salir + input + .salir,
.salir + .salir {
  margin-left: -6px;
}

/* En el celular (hasta 430 px) el ícono de la tienda (solo adorno) se quita para que quepa el nombre del negocio */
@media (max-width: 430px) {
  .logo {
    display: none;
  }
}

.quien {
  display: grid;
  padding-bottom: 4px;
}

.salir-cuenta {
  color: var(--error);
}

.recordatorio,
.codigo {
  display: grid;
  gap: 12px;
}

.codigo p {
  margin: 0;
}

/* "Ya lo guardé" ocupa todo el ancho (es el único botón) */
.listo-codigo {
  grid-column: 1 / -1;
}

.campana {
  margin-right: 4px;
  vertical-align: -3px;
  color: var(--verde-700);
}
</style>
