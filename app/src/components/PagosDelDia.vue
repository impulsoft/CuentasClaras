<script setup lang="ts">
import { computed, ref } from 'vue'
import { IconBan, IconCamera, IconChevronRight, IconCreditCard, IconDeviceMobile, IconPencil, IconPhoto, IconPlus, IconRefresh, IconReceipt } from '@tabler/icons-vue'
import CampoPesos from './CampoPesos.vue'
import HojaInferior from './HojaInferior.vue'
import VerComprobante from './VerComprobante.vue'
import { horaActual, hoy, pesos, sumaPagos } from '../calculos'
import { agregarPago, anularPago } from '../datos'
import { useSincronizacionStore } from '../stores/sincronizacion'
import { useDialogoStore } from '../stores/dialogo'
import { useComprobantesStore } from '../stores/comprobantes'
import { db, type Pago } from '../db'

// Pagos digitales de un día: Nequi (RF-51, por ahora escrito a mano) y otros pagos (RF-52)
const props = defineProps<{ fecha: string; negocioId: string; pagos: Pago[]; soloConsulta: boolean }>()
const emit = defineEmits<{ cierreModificado: [] }>()

const sync = useSincronizacionStore()
const dialogo = useDialogoStore()
const comprobantes = useComprobantesStore()

const secciones = computed(() => [
  { medio: 'nequi' as const, titulo: 'Nequi', ayuda: 'Transferencias recibidas', boton: 'Pago Nequi', icono: IconDeviceMobile },
  { medio: 'otro' as const, titulo: 'Otros pagos', ayuda: 'Daviplata, banco o tarjeta', boton: 'Otro pago', icono: IconCreditCard },
])

const pagosDe = (medio: 'nequi' | 'otro') => props.pagos.filter((p) => p.medio === medio)

// Formulario abierto: un pago a la vez. Para Nequi primero se escoge cómo cargarlo (RF-17)
const opcionesNequi = ref(false)
const abierto = ref<'nequi' | 'otro' | null>(null)
const valor = ref<number | null>(null)
const hora = ref('')
const error = ref('')

function tocarAgregar(medio: 'nequi' | 'otro') {
  if (medio === 'nequi') opcionesNequi.value = true
  else abrir('otro')
}

function abrir(medio: 'nequi' | 'otro') {
  opcionesNequi.value = false
  abierto.value = medio
  valor.value = null
  hora.value = props.fecha === hoy() ? horaActual() : ''
  error.value = ''
}

async function guardar() {
  if (!valor.value) {
    error.value = 'Escriba el valor del pago.'
    return
  }
  const modifico = await agregarPago(props.negocioId, props.fecha, {
    medio: abierto.value!,
    valor: valor.value,
    hora: hora.value || null,
  })
  abierto.value = null
  dialogo.avisar('Pago guardado')
  sync.programar()
  if (modifico) emit('cierreModificado') // RN-30
}

// RF-17: foto al celular del cliente o pantallazo del Nequi propio
// Los campos están dentro del v-for de las secciones: Vue entrega cada ref como una lista
const entradaFoto = ref<HTMLInputElement[]>([])
const entradaPantallazo = ref<HTMLInputElement[]>([])

async function alEscogerImagen(origen: 'foto' | 'pantallazo', evento: Event) {
  const entrada = evento.target as HTMLInputElement
  const archivo = entrada.files?.[0]
  entrada.value = '' // para poder escoger la misma foto otra vez
  if (!archivo) return
  opcionesNequi.value = false
  await comprobantes.agregar(origen, archivo)
}

// RN-43: ver la foto del comprobante de un pago (mientras se guarda en el servidor)
const viendo = ref<Pago | null>(null)

// Detalle de un pago (se abre al tocarlo en la lista)
const detalle = ref<Pago | null>(null)
const ORIGEN: Record<Pago['origen'], string> = { foto: 'Foto del comprobante', pantallazo: 'Pantallazo', manual: 'Escrito a mano' }

function verComprobante() {
  viendo.value = detalle.value
  detalle.value = null
}

async function anularDesdeDetalle() {
  const pago = detalle.value
  if (!pago) return
  detalle.value = null
  await anular(pago)
}

// RN-36: se anula con un motivo; no se borra. Si era abono de un fiado, el abono también se quita.
async function anular(pago: Pago) {
  const eraAbono = (await db.abonos.where('pago_id').equals(pago.id).filter((a) => !a.anulado_en).count()) > 0
  const titulo = `¿Anular el pago de ${pesos(pago.valor ?? 0)}?${eraAbono ? ' También se quita el abono a la deuda.' : ''}`
  const motivo = await dialogo.pedirTexto(titulo, 'Motivo', 'Valor equivocado', 'Anular pago')
  if (!motivo) return
  await anularPago(pago.id, motivo)
  dialogo.avisar('Pago anulado')
  sync.programar()
  emit('cierreModificado')
}
</script>

<template>
  <section v-for="seccion in secciones" :key="seccion.medio" class="recuadro pagos">
    <div class="titulo">
      <span class="icono" aria-hidden="true"><component :is="seccion.icono" :size="22" /></span>
      <div class="nombre">
        <strong>{{ seccion.titulo }}</strong>
        <span class="suave pequeno">{{ seccion.ayuda }}</span>
      </div>
      <strong class="monto total">{{ pesos(sumaPagos(pagosDe(seccion.medio), seccion.medio)) }}</strong>
    </div>

    <!-- Cada pago se toca para ver su detalle; ahí están "Ver comprobante" y "Anular" (no al lado del valor) -->
    <ul v-if="pagosDe(seccion.medio).length" class="lista">
      <li v-for="pago in pagosDe(seccion.medio)" :key="pago.id" :class="{ anulado: pago.anulado_en }">
        <button type="button" class="fila-pago" :aria-label="`Pago de ${pesos(pago.valor ?? 0)}${pago.anulado_en ? ', anulado' : ''}. Ver detalle`" @click="detalle = pago">
          <span class="fila-contenido">
            <span class="suave quien">
              {{ pago.hora?.slice(0, 5) ?? 'Sin hora' }}<template v-if="pago.nombre_pagador"> · {{ pago.nombre_pagador }}</template>
            </span>
            <span class="derecha">
              <IconReceipt v-if="pago.foto_ruta" class="con-foto" :size="18" aria-hidden="true" />
              <span v-if="pago.anulado_en" class="etiqueta no_abrio">Anulado</span>
              <span class="monto valor">{{ pesos(pago.valor ?? 0) }}</span>
              <IconChevronRight class="flecha-fila" :size="18" aria-hidden="true" />
            </span>
          </span>
        </button>
      </li>
    </ul>

    <form v-if="abierto === seccion.medio" class="nuevo" @submit.prevent="guardar">
      <CampoPesos v-model="valor" etiqueta="Valor del pago" />
      <label v-if="seccion.medio === 'nequi'" class="campo">
        Hora
        <span class="control"><input v-model="hora" type="time" /></span>
      </label>
      <p v-if="error" class="error">{{ error }}</p>
      <div class="acciones">
        <button type="button" class="boton secundario" @click="abierto = null">Cancelar</button>
        <button class="boton">Guardar</button>
      </div>
    </form>
    <!-- RF-17: tres formas de cargar un pago Nequi -->
    <div v-else-if="seccion.medio === 'nequi' && opcionesNequi" class="opciones">
      <button type="button" class="opcion" @click="entradaFoto[0]?.click()">
        <IconCamera :size="26" aria-hidden="true" />
        <span><strong>Tomar foto</strong><span class="suave pequeno">Al comprobante en el celular del cliente</span></span>
      </button>
      <button type="button" class="opcion" @click="entradaPantallazo[0]?.click()">
        <IconPhoto :size="26" aria-hidden="true" />
        <span><strong>Subir pantallazo</strong><span class="suave pequeno">De su propio Nequi</span></span>
      </button>
      <div class="opciones-pie">
        <button type="button" class="boton-texto" @click="abrir('nequi')">
          <IconPencil :size="20" aria-hidden="true" />
          Escribir el valor a mano
        </button>
        <button type="button" class="boton-texto" @click="opcionesNequi = false">Cancelar</button>
      </div>
      <input ref="entradaFoto" type="file" accept="image/*" capture="environment" hidden @change="alEscogerImagen('foto', $event)" />
      <input ref="entradaPantallazo" type="file" accept="image/*" hidden @change="alEscogerImagen('pantallazo', $event)" />
    </div>
    <p v-else-if="seccion.medio === 'nequi' && comprobantes.leyendo" class="leyendo" role="status">
      <IconRefresh class="girando" :size="20" aria-hidden="true" />
      Leyendo el comprobante…
    </p>
    <button v-else-if="!soloConsulta" type="button" class="agregar" @click="tocarAgregar(seccion.medio)">
      <IconPlus :size="20" :stroke-width="2.5" aria-hidden="true" />
      {{ seccion.boton }}
    </button>
  </section>

  <!-- Detalle de un pago -->
  <HojaInferior :abierta="!!detalle" :titulo="detalle?.medio === 'otro' ? 'Otro pago' : 'Pago Nequi'" @cerrar="detalle = null">
    <template v-if="detalle">
      <p class="detalle-valor monto" :class="{ tachado: detalle.anulado_en }">{{ pesos(detalle.valor ?? 0) }}</p>
      <dl class="detalle-datos">
        <div><dt>Hora</dt><dd>{{ detalle.hora?.slice(0, 5) ?? 'Sin hora' }}</dd></div>
        <div v-if="detalle.nombre_pagador"><dt>De</dt><dd>{{ detalle.nombre_pagador }}</dd></div>
        <div v-if="detalle.referencia"><dt>Referencia</dt><dd>{{ detalle.referencia }}</dd></div>
        <div><dt>Cómo se cargó</dt><dd>{{ ORIGEN[detalle.origen] }}</dd></div>
      </dl>
      <p v-if="detalle.anulado_en" class="mensaje aviso">
        <IconBan :size="20" aria-hidden="true" />
        Anulado{{ detalle.motivo_anulacion ? `: ${detalle.motivo_anulacion}` : '' }}. No cuenta en el total.
      </p>
    </template>
    <template #acciones>
      <button v-if="detalle?.foto_ruta" type="button" class="boton secundario" @click="verComprobante">
        <IconReceipt :size="22" aria-hidden="true" />
        Ver comprobante
      </button>
      <button v-if="detalle && !detalle.anulado_en && !soloConsulta" type="button" class="boton peligro" @click="anularDesdeDetalle">
        <IconBan :size="22" aria-hidden="true" />
        Anular este pago
      </button>
      <button v-if="!detalle?.foto_ruta && (detalle?.anulado_en || soloConsulta)" type="button" class="boton secundario" @click="detalle = null">Cerrar</button>
    </template>
  </HojaInferior>

  <VerComprobante :ruta="viendo?.foto_ruta ?? null" :titulo="viendo ? `Comprobante de ${pesos(viendo.valor ?? 0)}` : ''" @cerrar="viendo = null" />
</template>

<style scoped>
.pagos {
  display: grid;
  gap: 10px;
}

.titulo {
  display: flex;
  align-items: center;
  gap: 12px;
}

.icono {
  width: 40px;
  height: 40px;
  flex: none;
  display: grid;
  place-items: center;
  border-radius: 12px;
  color: var(--verde-700);
  background: var(--superficie);
  box-shadow: var(--sombra-1);
}

.nombre {
  flex: 1;
  display: grid;
  line-height: 1.25;
}

.total {
  font-size: 1.1rem;
}

.lista {
  list-style: none;
  margin: 0;
  padding: 0;
}

.lista li {
  border-top: 1px solid rgb(0 0 0 / 0.06);
}

/* Toda la fila se toca. El acomodo va en un span: en Android 7 un <button> no puede ser flex (RNF-03) */
.fila-pago {
  display: block;
  width: 100%;
  min-height: 48px;
  padding: 0 4px;
  font: inherit;
  color: inherit;
  text-align: left;
  background: none;
  border: none;
  border-radius: 10px;
  cursor: pointer;
  transition-property: background-color;
  transition-duration: 150ms;
}

.fila-pago:hover,
.fila-pago:active {
  background: rgb(15 110 86 / 0.06);
}

.fila-contenido {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  min-height: 48px;
}

.quien {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.derecha {
  flex: none;
  display: flex;
  align-items: center;
  gap: 6px;
  font-weight: 700;
}

.con-foto {
  color: var(--verde-600);
}

.flecha-fila {
  color: var(--texto-tenue);
}

.anulado .valor {
  text-decoration: line-through;
  color: var(--texto-tenue);
}

/* Detalle del pago */
.detalle-valor {
  margin: 0;
  font-size: 2rem;
  font-weight: 800;
  color: var(--verde-800);
}

.detalle-valor.tachado {
  text-decoration: line-through;
  color: var(--texto-tenue);
}

.detalle-datos {
  margin: 0;
  display: grid;
}

.detalle-datos > div {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 0;
  border-bottom: 1px solid var(--linea);
}

.detalle-datos dt {
  color: var(--texto-suave);
}

.detalle-datos dd {
  margin: 0;
  font-weight: 700;
  text-align: right;
  overflow-wrap: anywhere;
}

.agregar {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-height: 52px;
  font: inherit;
  font-weight: 800;
  color: var(--verde-700);
  background: var(--superficie);
  border: 1.5px dashed var(--verde-600);
  border-radius: 12px;
  cursor: pointer;
  transition-property: background-color, transform;
  transition-duration: 150ms;
}

.agregar:hover {
  background: var(--verde-50);
}

.agregar:active {
  transform: scale(0.98);
}

.opciones {
  display: grid;
  gap: 8px;
}

.opcion {
  display: flex;
  align-items: center;
  gap: 14px;
  min-height: 64px;
  padding: 12px 16px;
  font: inherit;
  text-align: left;
  color: var(--verde-700);
  background: var(--superficie);
  border: 1.5px solid var(--borde-campo);
  border-radius: 14px;
  cursor: pointer;
  transition-property: border-color, background-color, transform;
  transition-duration: 150ms;
}

.opcion:hover {
  border-color: var(--verde-600);
  background: var(--verde-50);
}

.opcion:active {
  transform: scale(0.98);
}

.opcion > span {
  display: grid;
  color: var(--texto);
}

.opciones-pie {
  display: flex;
  justify-content: space-between;
  flex-wrap: wrap;
}

.leyendo {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  min-height: 52px;
  font-weight: 700;
  color: var(--verde-700);
}

.girando {
  animation: girar 1s linear infinite;
}

@keyframes girar {
  to {
    transform: rotate(360deg);
  }
}

.nuevo {
  display: grid;
  gap: 12px;
  padding: 14px;
  background: var(--superficie);
  border-radius: 12px;
}

.acciones {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
</style>
