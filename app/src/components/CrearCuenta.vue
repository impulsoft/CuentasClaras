<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { IconAlertTriangle, IconPhone, IconUser, IconUserPlus } from '@tabler/icons-vue'
import HojaInferior from './HojaInferior.vue'
import { useAdminApi, type CuentaUsuario } from '../admin'
import { ErrorApi } from '../api'

/*
 * Crear un administrador del sistema o una cuenta más para un negocio (puede lo mismo que el
 * dueño). El admin no escoge la contraseña: el sistema crea una temporal que se cambia al entrar.
 */
const props = defineProps<{ abierta: boolean; negocio?: { id: string; nombre: string } }>()
const emit = defineEmits<{ cerrar: []; creada: [cuenta: CuentaUsuario] }>()
const api = useAdminApi()

const datos = reactive({ nombre: '', usuario: '', telefono: '' })
const errores = ref<ErrorApi | null>(null)
const enviando = ref(false)
const creada = ref<{ cuenta: CuentaUsuario; temporal: string } | null>(null)

watch(
  () => props.abierta,
  (abierta) => {
    if (!abierta) return
    Object.assign(datos, { nombre: '', usuario: '', telefono: '' })
    errores.value = null
    creada.value = null
  },
)

async function enviar() {
  if (creada.value) {
    emit('creada', creada.value.cuenta)
    return
  }
  errores.value = null
  enviando.value = true
  try {
    const telefono = datos.telefono.trim() || null
    const respuesta = props.negocio
      ? await api.crearCuenta(props.negocio.id, { nombre: datos.nombre, usuario: datos.usuario, telefono })
      : await api.crearAdmin({ nombre: datos.nombre, usuario: datos.usuario })
    creada.value = { cuenta: respuesta.usuario, temporal: respuesta.contrasena_temporal }
  } catch (e) {
    errores.value = e instanceof ErrorApi ? e : new ErrorApi('Ocurrió un error. Intente de nuevo.', 500)
  } finally {
    enviando.value = false
  }
}

function cerrar() {
  // Si ya se creó, la lista se actualiza aunque cierre con la X
  if (creada.value) emit('creada', creada.value.cuenta)
  else emit('cerrar')
}
</script>

<template>
  <HojaInferior :abierta="abierta" :titulo="negocio ? 'Agregar una cuenta' : 'Nuevo administrador'" @cerrar="cerrar" @enviar="enviar">
    <template v-if="!creada">
      <p v-if="negocio" class="suave pequeno">
        Otra persona de {{ negocio.nombre }} (por ejemplo, la esposa o un empleado) con su propio usuario. Puede hacer todo lo que hace el dueño.
      </p>
      <p v-else class="suave pequeno">Podrá hacer todo lo del panel del admin: negocios, pagos, cuentas y otros administradores.</p>

      <label class="campo">
        Nombre
        <span class="control">
          <IconUser class="icono" :size="22" aria-hidden="true" />
          <input v-model="datos.nombre" autocomplete="off" :placeholder="negocio ? 'Ej.: Carlos Martínez' : 'Ej.: Ana Gómez'" />
        </span>
        <span v-if="errores?.de('nombre')" class="error">{{ errores.de('nombre') }}</span>
      </label>
      <label class="campo">
        Usuario para entrar
        <span class="control">
          <IconUser class="icono" :size="22" aria-hidden="true" />
          <input v-model="datos.usuario" autocomplete="off" autocapitalize="off" spellcheck="false" :placeholder="negocio ? 'Ej.: tienda.carlos' : 'Ej.: ana.gomez'" />
        </span>
        <span v-if="errores?.de('usuario')" class="error">{{ errores.de('usuario') }}</span>
      </label>
      <label v-if="negocio" class="campo">
        Teléfono (opcional)
        <span class="control">
          <IconPhone class="icono" :size="22" aria-hidden="true" />
          <input v-model="datos.telefono" inputmode="numeric" autocomplete="off" placeholder="Ej.: 3101234567" />
        </span>
        <span v-if="errores?.de('telefono')" class="error">{{ errores.de('telefono') }}</span>
      </label>
      <p v-if="errores && Object.keys(errores.errores).length === 0" class="mensaje error" role="alert">
        <IconAlertTriangle :size="20" aria-hidden="true" />{{ errores.message }}
      </p>
    </template>

    <template v-else>
      <p>
        Listo. Díctele a {{ creada.cuenta.nombre }} su usuario <strong>{{ creada.cuenta.usuario }}</strong> y esta contraseña. Al entrar, la app le
        pedirá crear una nueva.
      </p>
      <p class="clave-grande">{{ creada.temporal }}</p>
      <p class="suave pequeno">No se vuelve a mostrar. Si se pierde, abra la cuenta y dele otra contraseña temporal.</p>
    </template>

    <template #acciones>
      <template v-if="!creada">
        <button type="button" class="boton secundario" @click="emit('cerrar')">Cancelar</button>
        <button class="boton" :disabled="enviando"><IconUserPlus :size="22" aria-hidden="true" /> Crear</button>
      </template>
      <button v-else class="boton">Listo</button>
    </template>
  </HojaInferior>
</template>
