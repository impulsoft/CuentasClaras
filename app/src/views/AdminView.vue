<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { IconAlertTriangle, IconBrandWhatsapp, IconChevronRight, IconCircleCheck, IconLifebuoy, IconPhone } from '@tabler/icons-vue'
import AdminNavegacion from '../components/AdminNavegacion.vue'
import BarraSuperior from '../components/BarraSuperior.vue'
import RegistrarPagoSuscripcion from '../components/RegistrarPagoSuscripcion.vue'
import { hoy, nombreMes, pesos } from '../calculos'
import {
  contarGrupos,
  enlaceAyuda,
  enlaceCobro,
  enGrupo,
  porAyudar,
  porVencer,
  textoUltimoCierre,
  textoVencimiento,
  useAdminApi,
  type NegocioAdmin,
  type RecaudoAdmin,
  type ResumenAdmin,
} from '../admin'
import { ErrorApi } from '../api'
import { useSesionStore } from '../stores/sesion'
import { useDialogoStore } from '../stores/dialogo'

// RF-11, RF-13: inicio del panel del admin. Lo urgente primero; la lista completa está en "Negocios".
const sesion = useSesionStore()
const dialogo = useDialogoStore()
const api = useAdminApi()
const barra = ref<InstanceType<typeof BarraSuperior> | null>(null)

/*
 * El código de recuperación solo se puede crear con la sesión abierta: si el admin olvida la
 * contraseña sin haberlo creado, ya no le sirve. Por eso se le recuerda hasta que lo cree.
 */
const sinCodigo = computed(() => sesion.usuario?.rol === 'admin' && sesion.usuario.codigo_recuperacion_en === null)

const negocios = ref<NegocioAdmin[]>([])
const resumen = ref<ResumenAdmin | null>(null)
const recaudo = ref<RecaudoAdmin | null>(null)
const cargando = ref(true)
const error = ref('')
// Si no se pudo cargar, las cifras muestran "–" en lugar de un 0 que parece real
const sinDatos = computed(() => cargando.value || !resumen.value)

// El panel del admin necesita internet (RNF-01)
async function cargar() {
  error.value = ''
  try {
    ;[negocios.value, resumen.value] = await Promise.all([api.negocios(), api.resumen()])
  } catch (e) {
    error.value = e instanceof ErrorApi && e.sinConexion ? 'Sin conexión: el panel del admin necesita internet.' : 'No se pudo cargar la información.'
  } finally {
    cargando.value = false
  }
  // El mini gráfico de lo recaudado es un adorno: si falla, no se muestra
  api
    .recaudo(6)
    .then((r) => (recaudo.value = r))
    .catch(() => {})
}
onMounted(cargar)

const mesActual = nombreMes(Number(hoy().slice(5, 7)))
const barras = computed(() => {
  const meses = recaudo.value?.meses ?? []
  const maximo = Math.max(1, ...meses.map((m) => m.total))
  return meses.map((m, i) => ({ mes: m.mes, alto: Math.max(4, (m.total / maximo) * 100), actual: i === meses.length - 1 }))
})

// Las cifras se cuentan igual que el filtro de "Negocios"; al tocarlas se abre esa lista filtrada
const cuenta = computed(() => contarGrupos(negocios.value))
const cifras = computed(() => [
  { valor: cuenta.value.al_dia, texto: 'Al día', clase: 'activo', grupo: 'al_dia' },
  { valor: cuenta.value.por_vencer, texto: 'Por vencer', clase: 'aviso', grupo: 'por_vencer' },
  { valor: cuenta.value.vencidos, texto: 'Vencidos', clase: 'peligro', grupo: 'vencidos' },
])

/*
 * Requieren atención, en una sola tarjeta:
 * - vencidos (RF-11): cobrar, "Ya pagó" o bloquear
 * - por vencer: un recordatorio a tiempo evita tener que bloquear
 * - sin cierres: llevan días sin registrar; una llamada evita que dejen de usar la app
 */
const vencidos = computed(() => negocios.value.filter((n) => enGrupo(n, 'vencidos')))
const proximos = computed(() => porVencer(negocios.value))
const ayudar = computed(() => porAyudar(negocios.value, hoy()))
const pendientes = computed(() => vencidos.value.length + proximos.value.length + ayudar.value.length)
const telefonoDe = (n: NegocioAdmin) => (n.dueno?.telefono ?? n.telefono ?? '').replace(/\D/g, '')

const pagando = ref<NegocioAdmin | null>(null)
async function alPagar() {
  pagando.value = null
  await cargar()
}

async function bloquear(negocio: NegocioAdmin) {
  const confirmo = await dialogo.confirmar({
    titulo: `¿Bloquear ${negocio.nombre}?`,
    mensaje: 'El dueño podrá ver y descargar su información, pero no registrar cierres, fiados ni abonos hasta que pague.',
    aceptar: 'Bloquear',
    cancelar: 'Cancelar',
    peligro: true,
  })
  if (!confirmo) return
  try {
    await api.accion(negocio.id, 'bloquear')
    dialogo.avisar(`${negocio.nombre} quedó bloqueado`)
    await cargar()
  } catch {
    dialogo.avisar('No se pudo bloquear. Revise la conexión.')
  }
}
</script>

<template>
  <div class="con-navegacion">
    <BarraSuperior ref="barra" titulo="Panel del admin" admin @salir="sesion.cerrar()">
      <p class="sub">{{ sesion.usuario?.nombre }}</p>
    </BarraSuperior>

    <main class="pantalla ancha">
      <section v-if="sinCodigo" class="tarjeta aviso-codigo" aria-label="Código de recuperación">
        <p>
          <IconLifebuoy :size="22" aria-hidden="true" class="icono-codigo" />
          <strong>Cree su código de recuperación.</strong> Si un día olvida la contraseña, con ese código pone una nueva sin ayuda. Solo se
          puede crear ahora, mientras puede entrar.
        </p>
        <button class="boton secundario" @click="barra?.crearCodigo()">Crear mi código</button>
      </section>

      <p v-if="error" class="mensaje error" role="alert">
        <IconAlertTriangle :size="20" aria-hidden="true" />{{ error }}
        <button class="boton-texto" @click="cargar">Reintentar</button>
      </p>

      <!-- RF-13: lo recaudado este mes, con los últimos meses en un mini gráfico -->
      <RouterLink :to="{ name: 'admin-recaudo' }" class="tarjeta recaudado">
        <span class="recaudado-nombre">Recaudado en {{ mesActual }}</span>
        <span class="recaudado-fila">
          <strong class="recaudado-valor monto">{{ sinDatos ? '–' : pesos(resumen?.recaudado_mes ?? 0) }}</strong>
          <span class="ver">Mes a mes <IconChevronRight :size="16" aria-hidden="true" /></span>
        </span>
        <span v-if="barras.length" class="mini" aria-hidden="true">
          <span v-for="b in barras" :key="b.mes" :class="{ actual: b.actual }" :style="{ height: `${b.alto}%` }" />
        </span>
      </RouterLink>

      <section class="cifras" aria-label="Negocios por estado">
        <RouterLink v-for="c in cifras" :key="c.texto" :to="{ name: 'admin-negocios', query: { grupo: c.grupo } }" class="cifra">
          <span class="valor monto" :class="c.clase">{{ sinDatos ? '–' : c.valor }}</span>
          <span class="cifra-texto">{{ c.texto }}</span>
        </RouterLink>
      </section>

      <!-- Lo que hay que hacer hoy -->
      <section v-if="!cargando && !error" class="tarjeta atencion" aria-label="Requieren atención">
        <div class="atencion-titulo">
          <h2>Requieren atención</h2>
          <span v-if="pendientes" class="etiqueta peligro">{{ pendientes }}</span>
        </div>

        <p v-if="!pendientes" class="todo-bien">
          <IconCircleCheck :size="22" aria-hidden="true" />
          Todo en orden: nadie vencido, nadie por vencer y todos están haciendo sus cierres.
        </p>

        <ul class="lista">
          <!-- RF-11: vencidos -->
          <li v-for="n in vencidos" :key="`v-${n.id}`" class="renglon">
            <span class="punto peligro" aria-hidden="true" />
            <div class="renglon-cuerpo">
              <strong>{{ n.nombre }}</strong>
              <span class="motivo">{{ textoVencimiento(n.suscripcion.dias) }}<template v-if="n.estado === 'bloqueado'"> · bloqueado</template></span>
              <div class="acciones">
                <a v-if="enlaceCobro(n)" class="accion whatsapp" :href="enlaceCobro(n)!" target="_blank" rel="noopener">
                  <IconBrandWhatsapp :size="18" aria-hidden="true" /> Cobrar
                </a>
                <button type="button" class="accion principal" @click="pagando = n">Ya pagó</button>
                <button v-if="n.estado !== 'bloqueado'" type="button" class="accion peligro" @click="bloquear(n)">Bloquear</button>
              </div>
            </div>
          </li>

          <!-- Por vencer -->
          <li v-for="n in proximos" :key="`p-${n.id}`" class="renglon">
            <span class="punto aviso" aria-hidden="true" />
            <div class="renglon-cuerpo">
              <strong>{{ n.nombre }}</strong>
              <span class="motivo">{{ textoVencimiento(n.suscripcion.dias) }}<template v-if="n.ultimo_valor"> · {{ pesos(n.ultimo_valor) }}</template></span>
              <div class="acciones">
                <a v-if="enlaceCobro(n)" class="accion whatsapp" :href="enlaceCobro(n)!" target="_blank" rel="noopener">
                  <IconBrandWhatsapp :size="18" aria-hidden="true" /> Recordar
                </a>
                <button type="button" class="accion principal" @click="pagando = n">Ya pagó</button>
              </div>
            </div>
          </li>

          <!-- Sin cierres: necesitan ayuda -->
          <li v-for="n in ayudar" :key="`a-${n.id}`" class="renglon">
            <span class="punto neutro" aria-hidden="true" />
            <div class="renglon-cuerpo">
              <strong>{{ n.nombre }}</strong>
              <span class="motivo">{{ textoUltimoCierre(n, hoy()) }}</span>
              <div v-if="telefonoDe(n)" class="acciones">
                <a class="accion" :href="`tel:${telefonoDe(n)}`"><IconPhone :size="18" aria-hidden="true" /> Llamar</a>
                <a class="accion whatsapp" :href="enlaceAyuda(n, hoy()) ?? undefined" target="_blank" rel="noopener">
                  <IconBrandWhatsapp :size="18" aria-hidden="true" /> WhatsApp
                </a>
              </div>
            </div>
          </li>
        </ul>

        <p v-if="pendientes" class="leyenda">
          <span><i class="punto peligro" /> Vencido</span>
          <span><i class="punto aviso" /> Por vencer</span>
          <span><i class="punto neutro" /> Sin cierres</span>
        </p>
      </section>
    </main>

    <RegistrarPagoSuscripcion :negocio="pagando" @cerrar="pagando = null" @pagado="alPagar" />
    <AdminNavegacion />
  </div>
</template>

<style scoped>
.sub {
  font-size: 0.8rem;
  color: var(--texto-suave);
}

/* ---------- Aviso del código de recuperación ---------- */
.aviso-codigo {
  background: var(--aviso-fondo);
  border-color: #f3dfbd;
  color: var(--aviso-texto);
  box-shadow: none;
}

.aviso-codigo p {
  margin: 0;
}

.icono-codigo {
  margin-right: 6px;
  vertical-align: -5px;
}

/* ---------- Recaudado ---------- */
.recaudado {
  gap: 2px;
  color: inherit;
  text-decoration: none;
}

.recaudado-nombre {
  font-size: 0.85rem;
  font-weight: 500;
  color: var(--texto-suave);
}

.recaudado-nombre::first-letter {
  text-transform: uppercase;
}

.recaudado-fila {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
}

.recaudado-valor {
  font-size: 1.9rem;
  font-weight: 700;
  letter-spacing: -0.03em;
  line-height: 1.15;
}

.ver {
  flex: none;
  margin-left: 10px;
  font-size: 0.88rem;
  font-weight: 600;
  color: var(--verde-700);
}

.ver svg {
  vertical-align: -3px;
}

/* Mini gráfico: una barra por mes, la del mes actual más oscura. Sin "gap" en flex (Android 7) */
.mini {
  display: flex;
  align-items: flex-end;
  height: 40px;
  margin-top: 10px;
}

.mini span {
  flex: 1;
  border-radius: 3px 3px 0 0;
  background: var(--verde-200);
}

.mini span + span {
  margin-left: 6px;
}

.mini span.actual {
  background: var(--verde-700);
}

/* ---------- Cifras por estado ---------- */
.cifras {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
}

.cifra {
  display: grid;
  justify-items: center;
  gap: 2px;
  padding: 14px 8px;
  color: inherit;
  text-decoration: none;
  background: var(--superficie);
  border: 1px solid var(--linea);
  border-radius: var(--radio-tarjeta);
  box-shadow: var(--sombra-1);
  transition-property: transform;
  transition-duration: 150ms;
}

.cifra:active {
  transform: scale(0.97);
}

.cifra .valor {
  font-size: 1.7rem;
  font-weight: 700;
  line-height: 1.1;
}

.cifra-texto {
  font-size: 0.8rem;
  font-weight: 500;
  color: var(--texto-suave);
}

.activo {
  color: var(--verde-700);
}

.aviso {
  color: #b06d0a;
}

.peligro {
  color: var(--error);
}

/* ---------- Requieren atención ---------- */
.atencion-titulo {
  display: flex;
  align-items: center;
}

.atencion-titulo h2 {
  margin-right: 8px;
}

.todo-bien {
  display: flex;
  align-items: flex-start;
  color: var(--verde-800);
}

.todo-bien svg {
  flex: none;
  margin-right: 10px;
  color: var(--verde-600);
}

.lista {
  list-style: none;
  margin: -4px 0 0;
  padding: 0;
}

.renglon {
  display: flex;
  align-items: flex-start;
  padding: 14px 0;
  border-top: 1px solid var(--linea);
}

.renglon:first-child {
  border-top: none;
}

.punto {
  display: inline-block;
  width: 10px;
  height: 10px;
  flex: none;
  margin: 7px 12px 0 2px;
  border-radius: 50%;
}

.punto.peligro {
  background: var(--error);
}

.punto.aviso {
  background: #ef9f27;
}

.punto.neutro {
  background: #b4b2a9;
}

.renglon-cuerpo {
  flex: 1;
  min-width: 0;
  display: grid;
}

.renglon-cuerpo strong {
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.motivo {
  font-size: 0.88rem;
  color: var(--texto-suave);
}

/* Botones pequeños de cada renglón. Sin "gap" en flex (Android 7): márgenes */
.acciones {
  display: flex;
  flex-wrap: wrap;
  margin-top: 10px;
}

.accion {
  display: inline-flex;
  align-items: center;
  min-height: 40px;
  margin: 0 6px 6px 0;
  padding: 0 12px;
  font: inherit;
  font-size: 0.86rem;
  font-weight: 600;
  color: var(--texto);
  text-decoration: none;
  background: var(--superficie);
  border: 1px solid var(--borde-campo);
  border-radius: var(--radio-pildora);
  cursor: pointer;
}

.accion svg {
  margin-right: 5px;
}

.accion.whatsapp svg {
  color: #1f9e57;
}

.accion.principal {
  color: #ffffff;
  background: var(--verde-700);
  border-color: var(--verde-700);
}

.accion.peligro {
  color: var(--error);
  border-color: rgb(163 45 45 / 0.35);
}

.leyenda {
  display: flex;
  flex-wrap: wrap;
  padding-top: 10px;
  border-top: 1px solid var(--linea);
  font-size: 0.76rem;
  color: var(--texto-suave);
}

.leyenda span {
  margin-right: 14px;
}

.leyenda .punto {
  width: 8px;
  height: 8px;
  margin: 0 6px 0 0;
  vertical-align: 0;
}
</style>
