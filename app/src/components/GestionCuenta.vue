<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { IconBuildingStore, IconKey, IconPencil, IconUserCheck, IconUserOff } from '@tabler/icons-vue'
import HojaInferior from './HojaInferior.vue'
import { hoy } from '../calculos'
import { haceCuanto, useAdminApi, type CuentaUsuario } from '../admin'
import { ErrorApi } from '../api'
import { useDialogoStore } from '../stores/dialogo'

/*
 * Lo que el admin puede hacer con una cuenta (un administrador o una cuenta de un negocio):
 * corregir sus datos, darle una contraseña temporal (RF-46) y desactivarla o activarla.
 * Su propia cuenta solo la corrige: su contraseña se cambia en Cuenta.
 */
const props = defineProps<{ cuenta: CuentaUsuario | null; conEnlaceNegocio?: boolean }>()
const emit = defineEmits<{ cerrar: []; cambiada: [cuenta: CuentaUsuario] }>()
const api = useAdminApi()
const dialogo = useDialogoStore()

const modo = ref<'ver' | 'editar' | 'temporal'>('ver')
const temporal = ref('')
const datos = reactive({ nombre: '', usuario: '', telefono: '' })
const errores = ref<ErrorApi | null>(null)
const enviando = ref(false)

// Cada vez que se abre otra cuenta, se empieza viendo sus datos
watch(
  () => props.cuenta?.id,
  () => {
    modo.value = 'ver'
    temporal.value = ''
    errores.value = null
  },
)

const titulo = computed(() => {
  if (modo.value === 'editar') return 'Corregir datos'
  if (modo.value === 'temporal') return 'Contraseña temporal'
  return props.cuenta?.nombre ?? ''
})
const quien = computed(() => (props.cuenta?.rol === 'admin' ? 'Administrador' : props.cuenta?.principal ? 'Dueño del negocio' : 'Cuenta del negocio'))

function abrirEdicion() {
  const c = props.cuenta!
  Object.assign(datos, { nombre: c.nombre, usuario: c.usuario, telefono: c.telefono ?? '' })
  errores.value = null
  modo.value = 'editar'
}

async function guardar() {
  errores.value = null
  enviando.value = true
  try {
    const cambiada = await api.editarUsuario(props.cuenta!.id, { nombre: datos.nombre, usuario: datos.usuario, telefono: datos.telefono.trim() || null })
    emit('cambiada', cambiada)
    modo.value = 'ver'
    dialogo.avisar('Datos guardados')
  } catch (e) {
    errores.value = e instanceof ErrorApi ? e : null
    if (!errores.value) dialogo.avisar('No se pudo guardar. Revise la conexión.')
  } finally {
    enviando.value = false
  }
}

async function darTemporal() {
  const c = props.cuenta!
  const confirmo = await dialogo.confirmar({
    titulo: '¿Darle una contraseña temporal?',
    mensaje: `Se crea una contraseña nueva para ${c.nombre}. Al entrar con ella, tendrá que crear la suya. Sus otros equipos se cierran cuando se conecten.`,
    aceptar: 'Crear contraseña',
    cancelar: 'Cancelar',
  })
  if (!confirmo) return
  try {
    temporal.value = (await api.temporalDe(c.id)).contrasena_temporal
    modo.value = 'temporal'
    emit('cambiada', { ...c, debe_cambiar_contrasena: true })
  } catch {
    dialogo.avisar('No se pudo crear la contraseña. Revise la conexión.')
  }
}

async function cambiarEstado() {
  const c = props.cuenta!
  const desactivar = c.activo
  const confirmo = await dialogo.confirmar({
    titulo: desactivar ? `¿Desactivar la cuenta de ${c.nombre}?` : `¿Activar la cuenta de ${c.nombre}?`,
    mensaje: desactivar
      ? 'No podrá entrar y se le cierra la sesión en sus equipos. Lo que registró se conserva.'
      : 'Podrá volver a entrar con su contraseña.',
    aceptar: desactivar ? 'Desactivar' : 'Activar',
    cancelar: 'Cancelar',
    peligro: desactivar,
  })
  if (!confirmo) return
  try {
    emit('cambiada', await api.accionUsuario(c.id, desactivar ? 'desactivar' : 'activar'))
    dialogo.avisar(desactivar ? 'Cuenta desactivada' : 'Cuenta activada')
  } catch {
    dialogo.avisar('No se pudo hacer el cambio. Revise la conexión.')
  }
}

function enviar() {
  if (modo.value === 'editar') guardar()
  else emit('cerrar')
}
</script>

<template>
  <HojaInferior :abierta="!!cuenta" :titulo="titulo" @cerrar="emit('cerrar')" @enviar="enviar">
    <template v-if="cuenta && modo === 'ver'">
      <dl class="ficha">
        <dt>Tipo</dt>
        <dd>{{ quien }}</dd>
        <dt>Usuario</dt>
        <dd>{{ cuenta.usuario }}</dd>
        <template v-if="cuenta.telefono">
          <dt>Teléfono</dt>
          <dd>{{ cuenta.telefono }}</dd>
        </template>
        <template v-if="cuenta.negocio">
          <dt>Negocio</dt>
          <dd>{{ cuenta.negocio.nombre }}</dd>
        </template>
        <dt>Estado</dt>
        <dd :class="{ apagada: !cuenta.activo }">{{ cuenta.activo ? 'Activa' : 'Desactivada' }}</dd>
        <dt>Usó la app</dt>
        <dd>{{ cuenta.ultima_conexion ? haceCuanto(cuenta.ultima_conexion, hoy()) : 'Todavía no' }}</dd>
      </dl>
      <p v-if="cuenta.debe_cambiar_contrasena" class="mensaje aviso"><IconKey :size="20" aria-hidden="true" /> Tiene una contraseña temporal sin cambiar.</p>
      <p v-if="cuenta.es_yo" class="suave pequeno">Es su cuenta. Su contraseña se cambia en Cuenta → Cambiar contraseña.</p>
      <p v-else-if="cuenta.rol === 'dueno'" class="suave pequeno">Puede hacer todo lo que hace el dueño: cierres, pagos, deudores, análisis y Excel.</p>

      <div class="botones">
        <RouterLink v-if="conEnlaceNegocio && cuenta.negocio" :to="{ name: 'admin-negocio', params: { id: cuenta.negocio.id } }" class="boton secundario">
          <IconBuildingStore :size="22" aria-hidden="true" /> Ver el negocio
        </RouterLink>
        <button type="button" class="boton secundario" @click="abrirEdicion"><IconPencil :size="22" aria-hidden="true" /> Corregir datos</button>
        <template v-if="!cuenta.es_yo">
          <button type="button" class="boton secundario" @click="darTemporal"><IconKey :size="22" aria-hidden="true" /> Contraseña temporal</button>
          <button v-if="cuenta.activo" type="button" class="boton secundario peligro" @click="cambiarEstado">
            <IconUserOff :size="22" aria-hidden="true" /> Desactivar cuenta
          </button>
          <button v-else type="button" class="boton secundario" @click="cambiarEstado"><IconUserCheck :size="22" aria-hidden="true" /> Activar cuenta</button>
        </template>
      </div>
    </template>

    <template v-else-if="cuenta && modo === 'editar'">
      <label v-for="[clave, etiqueta] in [['nombre', 'Nombre'], ['usuario', 'Usuario para entrar'], ['telefono', 'Teléfono (opcional)']] as const" :key="clave" class="campo">
        {{ etiqueta }}
        <span class="control">
          <input v-model="datos[clave]" autocomplete="off" autocapitalize="off" :inputmode="clave === 'telefono' ? 'numeric' : undefined" />
        </span>
        <span v-if="errores?.de(clave)" class="error">{{ errores.de(clave) }}</span>
      </label>
    </template>

    <template v-else-if="cuenta && modo === 'temporal'">
      <p>Díctele esta contraseña a {{ cuenta.nombre }} (usuario <strong>{{ cuenta.usuario }}</strong>). Al entrar, la app le pedirá crear una nueva.</p>
      <p class="clave-grande">{{ temporal }}</p>
      <p class="suave pequeno">No se vuelve a mostrar. Si se pierde, cree otra.</p>
    </template>

    <template #acciones>
      <template v-if="modo === 'editar'">
        <button type="button" class="boton secundario" @click="modo = 'ver'">Cancelar</button>
        <button class="boton" :disabled="enviando">Guardar</button>
      </template>
      <button v-else class="boton">Listo</button>
    </template>
  </HojaInferior>
</template>

<style scoped>
.ficha {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 6px 16px;
  margin: 0;
}

.ficha dt {
  color: var(--texto-suave);
}

.ficha dd {
  margin: 0;
  font-weight: 700;
  overflow-wrap: anywhere;
}

.ficha .apagada {
  color: var(--error);
}

.botones {
  display: grid;
  gap: 10px;
}

.boton {
  text-decoration: none;
}

.peligro {
  color: var(--error);
}
</style>
