<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  IconBrandWhatsapp,
  IconCash,
  IconCheck,
  IconDeviceMobile,
  IconPhone,
  IconPlus,
  IconTrash,
  IconUserEdit,
} from '@tabler/icons-vue'
import BarraVolver from '../components/BarraVolver.vue'
import HojaInferior from '../components/HojaInferior.vue'
import { fechaCorta, fechaLarga, hoy, pesos } from '../calculos'
import { NOMBRE_ESTADO, normalizarTelefono, telefonoLegible, textoPlazo, type FiadoConSaldo } from '../deudas'
import { anularAbono, anularFiado, borrarDatosDeudor, corregirDeudor, corregirFiado, deudorConTelefono, marcarPagado, resumenDe } from '../datosDeudores'
import { useConsulta } from '../datos'
import type { Abono, Fiado } from '../db'
import { useSesionStore } from '../stores/sesion'
import { useSincronizacionStore } from '../stores/sincronizacion'
import { useDialogoStore } from '../stores/dialogo'

// RF-37: datos del deudor, lo que se llevó, sus abonos y el saldo
const props = defineProps<{ id: string }>()
const router = useRouter()
const sesion = useSesionStore()
const sync = useSincronizacionStore()
const dialogo = useDialogoStore()

// null = cargando; undefined = no existe (p. ej. se unió con otro al sincronizar, RN-32)
const resumen = useConsulta(() => resumenDe(props.id), null as Awaited<ReturnType<typeof resumenDe>> | null, [() => props.id])
const r = computed(() => resumen.value ?? undefined)
const negocioId = computed(() => sesion.usuario!.negocio!.id)
const anonimo = computed(() => !!r.value?.deudor.anonimizado_en)

const estadoSaldo = computed(() => {
  const d = r.value
  if (!d) return ''
  if (d.estado === 'pagado') return d.pagoEl ? `Pagó todo el ${fechaLarga(d.pagoEl)}` : 'No debe nada'
  if (!d.pagaEl) return ''
  // "Debía pagar el 1 de septiembre (venció hace 81 días)"; "Fecha acordada de pago: hoy"
  const plazo = textoPlazo(d.pagaEl, d.estado === 'vencido', hoy())
  if (d.estado === 'vencido') return `Debía pagar el ${fechaLarga(d.pagaEl)} (${plazo})`
  if (plazo === 'paga hoy' || plazo === 'paga mañana') return `Fecha acordada de pago: ${plazo.replace('paga ', '')}`
  return `Fecha acordada de pago: ${fechaLarga(d.pagaEl)}`
})

// RF-42: llamar o escribir por WhatsApp para recordarle el pago
const telefono = computed(() => r.value?.deudor.telefono ?? null)
const enlaceWhatsapp = computed(() => {
  const d = r.value
  if (!d || !telefono.value) return ''
  const negocio = sesion.usuario?.negocio?.nombre ?? 'la tienda'
  const texto = `Hola ${d.nombre.split(' ')[0]}, le escribo de ${negocio}. Le recuerdo que tiene un saldo pendiente de ${pesos(d.saldo)}. ¡Gracias!`
  return `https://wa.me/57${telefono.value}?text=${encodeURIComponent(texto)}`
})

// RF-39 / RN-29
async function pagado() {
  const d = r.value!
  const confirmo = await dialogo.confirmar({
    titulo: '¿Pagó todo?',
    mensaje: `Se registra un abono de ${pesos(d.saldo)} con la fecha de hoy y ${d.nombre} queda a paz y salvo.`,
    aceptar: 'Sí, pagó todo',
    cancelar: 'Cancelar',
  })
  if (!confirmo) return
  await marcarPagado(negocioId.value, props.id)
  sync.programar()
  dialogo.avisar(`${d.nombre} quedó a paz y salvo`)
}

// ---------- Corregir un fiado (RF-40) o anularlo (RF-50) ----------
const fiadoAbierto = ref<FiadoConSaldo | null>(null)
const descripcion = ref('')
const fechaPago = ref('')
const errorFiado = ref('')

function abrirFiado(f: FiadoConSaldo) {
  if (sync.soloConsulta) return
  fiadoAbierto.value = f
  descripcion.value = f.fiado.descripcion
  fechaPago.value = f.fiado.fecha_pago
  errorFiado.value = ''
}

async function guardarFiado() {
  const f = fiadoAbierto.value!.fiado
  if (!descripcion.value.trim()) return (errorFiado.value = 'Escriba qué se llevó.')
  if (!fechaPago.value || fechaPago.value < f.fecha) return (errorFiado.value = 'La fecha de pago no puede ser antes del día en que se fió.') // RN-24
  await corregirFiado(f.id, { descripcion: descripcion.value, fecha_pago: fechaPago.value })
  fiadoAbierto.value = null
  sync.programar()
  dialogo.avisar('Fiado corregido')
}

async function anularEsteFiado() {
  const f = fiadoAbierto.value!.fiado
  fiadoAbierto.value = null
  const motivo = await dialogo.pedirTexto(`¿Anular el fiado de ${pesos(f.valor)}?`, 'Motivo', 'Se anotó mal', 'Anular fiado')
  if (!motivo) return
  await anularFiado(f.id, motivo)
  sync.programar()
  dialogo.avisar('Fiado anulado')
}

// ---------- Anular un abono (RF-50) ----------
async function anularEsteAbono(abono: Abono) {
  if (sync.soloConsulta) return
  const motivo = await dialogo.pedirTexto(`¿Anular el abono de ${pesos(abono.valor)}?`, 'Motivo', 'Valor equivocado', 'Anular abono')
  if (!motivo) return
  const modifico = await anularAbono(abono.id, motivo)
  sync.programar()
  dialogo.avisar(modifico ? `Abono anulado; cambió el cierre del ${fechaLarga(abono.fecha)}` : 'Abono anulado')
}

// ---------- Corregir nombre y teléfono (RF-40) ----------
const corrigiendo = ref(false)
const nombre = ref('')
const nuevoTelefono = ref('')
const errorDatos = ref('')

function abrirDatos() {
  nombre.value = r.value!.deudor.nombre ?? ''
  nuevoTelefono.value = telefonoLegible(telefono.value)
  errorDatos.value = ''
  corrigiendo.value = true
}

async function guardarDatos() {
  const numeros = normalizarTelefono(nuevoTelefono.value)
  if (!nombre.value.trim()) return (errorDatos.value = 'Escriba el nombre.')
  if (numeros.length !== 10) return (errorDatos.value = 'Escriba los 10 números del celular.')
  const otro = await deudorConTelefono(numeros)
  if (otro && otro.id !== props.id) return (errorDatos.value = `Ese número ya es de ${otro.nombre}.`) // RN-32
  await corregirDeudor(props.id, { nombre: nombre.value, telefono: numeros })
  corrigiendo.value = false
  sync.programar()
  dialogo.avisar('Datos corregidos')
}

// ---------- Borrar datos (RF-53, RN-42): se permite aunque el negocio esté bloqueado (RN-13) ----------
async function borrarDatos() {
  const d = r.value!
  const confirmo = await dialogo.confirmar({
    titulo: '¿Borrar los datos de esta persona?',
    mensaje: `Se borran el nombre y el teléfono de ${d.nombre} y quedará como "Cliente anónimo". Los valores de sus fiados y abonos se conservan. Esto no se puede deshacer.`,
    aceptar: 'Borrar datos',
    cancelar: 'Cancelar',
    peligro: true,
  })
  if (!confirmo) return
  await borrarDatosDeudor(props.id)
  sync.programar()
  dialogo.avisar('Datos borrados')
}

const esFiado = (registro: Fiado | Abono): registro is Fiado => 'descripcion' in registro
</script>

<template>
  <div>
    <BarraVolver :titulo="r?.nombre ?? 'Deudor'" :volver-a="{ name: 'deudores' }" />

    <main class="pantalla">
      <section v-if="resumen === undefined" class="tarjeta">
        <p>Este deudor ya no está en la lista.</p>
        <button class="boton secundario" @click="router.replace({ name: 'deudores' })">Ver deudores</button>
      </section>

      <template v-else-if="r">
        <div class="contacto">
          <span v-if="telefono" class="suave">Tel. {{ telefonoLegible(telefono) }}</span>
          <span v-else-if="anonimo" class="suave">Sus datos se borraron a pedido suyo.</span>
          <span class="etiqueta" :class="r.estado">{{ NOMBRE_ESTADO[r.estado] }}</span>
        </div>

        <!-- Saldo -->
        <section class="saldo" :class="r.estado">
          <span>Saldo pendiente</span>
          <strong class="monto">{{ pesos(r.saldo) }}</strong>
          <span v-if="estadoSaldo">{{ estadoSaldo }}</span>
        </section>

        <!-- Acciones -->
        <div v-if="!sync.soloConsulta" class="acciones">
          <RouterLink v-if="r.saldo > 0" :to="{ name: 'abono', params: { id } }" class="boton">
            <IconCash :size="22" aria-hidden="true" />
            Registrar abono
          </RouterLink>
          <button v-if="r.saldo > 0" class="boton secundario" @click="pagado">
            <IconCheck :size="22" aria-hidden="true" />
            Marcar como pagado
          </button>
          <RouterLink v-if="!anonimo" :to="{ name: 'nuevo-fiado', query: { deudor: id } }" class="boton secundario">
            <IconPlus :size="22" aria-hidden="true" />
            Agregar otro fiado
          </RouterLink>
        </div>

        <!-- RF-42 -->
        <div v-if="telefono" class="contactar">
          <a :href="`tel:${telefono}`" class="boton secundario"><IconPhone :size="22" aria-hidden="true" /> Llamar</a>
          <a :href="enlaceWhatsapp" target="_blank" rel="noopener" class="boton secundario whatsapp">
            <IconBrandWhatsapp :size="22" aria-hidden="true" /> WhatsApp
          </a>
        </div>

        <!-- Lo que se llevó -->
        <section class="tarjeta">
          <h3>Lo que se llevó fiado</h3>
          <ul class="movimientos">
            <li v-for="f in r.fiados" :key="f.fiado.id">
              <button type="button" class="fila" :disabled="sync.soloConsulta" @click="abrirFiado(f)">
                <span class="texto">
                  <span>{{ fechaCorta(f.fiado.fecha) }} · {{ f.fiado.descripcion }}</span>
                  <span class="suave pequeno">
                    <template v-if="f.saldo === 0">Pagado</template>
                    <template v-else-if="f.abonado > 0">Abonado {{ pesos(f.abonado) }} · falta {{ pesos(f.saldo) }}</template>
                    <template v-else>Paga el {{ fechaCorta(f.fiado.fecha_pago) }}</template>
                  </span>
                </span>
                <span class="monto">{{ pesos(f.fiado.valor) }}</span>
              </button>
            </li>
          </ul>
          <p v-if="!r.fiados.length" class="suave">No tiene fiados.</p>

          <h3>Abonos</h3>
          <ul class="movimientos">
            <li v-for="a in r.abonos" :key="a.id">
              <button type="button" class="fila" :disabled="sync.soloConsulta" @click="anularEsteAbono(a)">
                <span class="texto">
                  <span>
                    <IconDeviceMobile v-if="a.pago_id" class="nequi" :size="18" aria-label="Por Nequi" />
                    {{ fechaCorta(a.fecha) }} · {{ a.nota ?? 'Abono' }}
                  </span>
                </span>
                <span class="monto abono">− {{ pesos(a.valor) }}</span>
              </button>
            </li>
          </ul>
          <p v-if="!r.abonos.length" class="suave">Todavía no ha abonado.</p>

          <div class="linea-saldo">
            <strong>Saldo</strong>
            <strong class="monto">{{ pesos(r.saldo) }}</strong>
          </div>
          <p v-if="!sync.soloConsulta && (r.fiados.length || r.abonos.length)" class="suave pequeno">
            Toque un fiado para corregirlo o anularlo, o un abono para anularlo.
          </p>
        </section>

        <!-- RN-36: lo anulado queda tachado -->
        <section v-if="r.anulados.length" class="tarjeta">
          <h3>Anulados</h3>
          <ul class="movimientos anulados">
            <li v-for="registro in r.anulados" :key="registro.id" class="fila">
              <span class="texto">
                <span>{{ fechaCorta(registro.fecha) }} · {{ esFiado(registro) ? registro.descripcion : (registro.nota ?? 'Abono') }}</span>
                <span class="suave pequeno">Motivo: {{ registro.motivo_anulacion }}</span>
              </span>
              <span class="monto">{{ pesos(registro.valor) }}</span>
            </li>
          </ul>
        </section>

        <div class="pie">
          <button v-if="!anonimo && !sync.soloConsulta" class="boton-texto" @click="abrirDatos">
            <IconUserEdit :size="20" aria-hidden="true" /> Corregir nombre o teléfono
          </button>
          <button v-if="!anonimo && r.saldo === 0" class="boton-texto peligro" @click="borrarDatos">
            <IconTrash :size="20" aria-hidden="true" /> Borrar datos
          </button>
          <p v-if="!anonimo && r.saldo > 0" class="suave pequeno">Si la persona pide borrar sus datos, se puede hacer cuando ya no deba nada.</p>
        </div>
      </template>
    </main>

    <!-- Corregir fiado -->
    <HojaInferior :abierta="!!fiadoAbierto" :titulo="fiadoAbierto ? `Fiado del ${fechaLarga(fiadoAbierto.fiado.fecha)}` : ''" @cerrar="fiadoAbierto = null" @enviar="guardarFiado">
      <template v-if="fiadoAbierto">
        <div class="recuadro valor-fijo">
          <span class="suave">Valor fiado</span>
          <strong class="monto">{{ pesos(fiadoAbierto.fiado.valor) }}</strong>
          <span class="suave pequeno">El valor no se cambia. Si está mal, anule este fiado y regístrelo de nuevo.</span>
        </div>
        <label class="campo">
          ¿Qué se llevó?
          <textarea v-model="descripcion" maxlength="255" />
        </label>
        <label class="campo">
          ¿Cuándo va a pagar?
          <input v-model="fechaPago" type="date" :min="fiadoAbierto.fiado.fecha" />
        </label>
        <p v-if="errorFiado" class="mensaje error" role="alert">{{ errorFiado }}</p>
        <button type="button" class="boton-texto peligro" @click="anularEsteFiado"><IconTrash :size="20" aria-hidden="true" /> Anular este fiado</button>
      </template>
      <template #acciones>
        <button type="button" class="boton secundario" @click="fiadoAbierto = null">Cancelar</button>
        <button class="boton">Guardar</button>
      </template>
    </HojaInferior>

    <!-- Corregir nombre y teléfono -->
    <HojaInferior :abierta="corrigiendo" titulo="Corregir datos" @cerrar="corrigiendo = false" @enviar="guardarDatos">
      <label class="campo">
        Nombre del cliente
        <span class="control"><input v-model="nombre" autocomplete="off" maxlength="120" /></span>
      </label>
      <label class="campo">
        Número de celular
        <span class="control"><input v-model="nuevoTelefono" type="tel" inputmode="numeric" autocomplete="off" /></span>
      </label>
      <p v-if="errorDatos" class="mensaje error" role="alert">{{ errorDatos }}</p>
      <template #acciones>
        <button type="button" class="boton secundario" @click="corrigiendo = false">Cancelar</button>
        <button class="boton">Guardar</button>
      </template>
    </HojaInferior>
  </div>
</template>

<style scoped>
.contacto {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.saldo {
  display: grid;
  gap: 2px;
  padding: 18px 20px;
  border-radius: var(--radio-tarjeta);
  color: var(--verde-900);
  background: var(--verde-50);
}

.saldo strong {
  font-size: 2.1rem;
  line-height: 1.15;
}

.saldo.vencido {
  color: var(--error);
  background: var(--error-fondo);
}

.acciones,
.contactar {
  display: grid;
  gap: 10px;
}

.acciones .boton,
.contactar .boton {
  text-decoration: none;
}

.contactar {
  grid-template-columns: 1fr 1fr;
}

.whatsapp {
  color: #0b6b3a;
}

h3 {
  color: var(--texto-suave);
}

.movimientos {
  list-style: none;
  margin: -6px 0 0;
  padding: 0;
}

.fila {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  min-height: 52px;
  padding: 8px 0;
  font: inherit;
  text-align: left;
  color: inherit;
  background: none;
  border: none;
  border-bottom: 1px solid var(--linea);
  cursor: pointer;
}

.fila:disabled {
  cursor: default;
}

.texto {
  min-width: 0;
  display: grid;
}

.fila .monto {
  flex: none;
  font-weight: 700;
}

.monto.abono {
  color: var(--verde-700);
}

.nequi {
  vertical-align: -3px;
  color: var(--verde-700);
}

.anulados .texto > span:first-child,
.anulados .monto {
  text-decoration: line-through;
  color: var(--texto-tenue);
}

.linea-saldo {
  display: flex;
  justify-content: space-between;
  font-size: 1.1rem;
}

.pie {
  display: grid;
  justify-items: center;
  gap: 4px;
  text-align: center;
}

.peligro {
  color: var(--error);
}

.peligro:hover {
  background: var(--error-fondo);
}

.valor-fijo {
  display: grid;
  gap: 2px;
}

.valor-fijo strong {
  font-size: 1.4rem;
}
</style>
