<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { IconAlertTriangle, IconCamera, IconDownload, IconFileSpreadsheet, IconPhoto, IconPlus, IconRefresh } from '@tabler/icons-vue'
import BarraVolver from '../components/BarraVolver.vue'
import CampoPesos from '../components/CampoPesos.vue'
import { fechaDe, hoy as fechaHoy, nombreMes, pesos } from '../calculos'
import { descargar, ErrorApi, pedir } from '../api'
import { reducirImagen } from '../comprobantes'
import { diasConCierre, guardarFilas, revisarFilas, type FilaCarga } from '../carga'
import { useSesionStore } from '../stores/sesion'
import { useSincronizacionStore } from '../stores/sincronizacion'
import { useDialogoStore } from '../stores/dialogo'

// RF-27 (Excel), RF-28 (foto del cuaderno) y RF-29 (revisar antes de guardar). Leer necesita internet.
const props = defineProps<{ tipo: 'excel' | 'cuaderno' }>()
const router = useRouter()
const sesion = useSesionStore()
const sync = useSincronizacionStore()
const dialogo = useDialogoStore()

const hoy = fechaHoy()
const paso = ref<'inicio' | 'leyendo' | 'revisar'>('inicio')
const error = ref('')
const filas = ref<FilaCarga[]>([])
const conCierre = ref(new Set<string>())
let siguienteId = 1

onMounted(async () => (conCierre.value = await diasConCierre()))

const revision = computed(() => revisarFilas(filas.value, conCierre.value, hoy))
const resumen = computed(() => {
  let listas = 0
  let problemas = 0
  let yaTienen = 0
  for (const f of filas.value) {
    const r = revision.value.get(f.id)!
    if (!f.incluir) continue
    if (r.errores.length) problemas++
    else if (r.yaTiene && !f.reemplazar) yaTienen++
    else listas++
  }
  return { listas, problemas, yaTienen }
})

function mensajeDe(e: unknown): string {
  if (e instanceof ErrorApi && e.sinConexion) return 'Sin conexión. Para leer el archivo o la foto necesita internet.'
  if (e instanceof ErrorApi) return e.de('archivo') ?? e.de('foto') ?? e.message
  return 'No se pudo leer. Intente de nuevo.'
}

// ---------- Excel ----------
interface FilaExcel {
  fila: number
  fecha: string | null
  tipo: FilaCarga['tipo']
  base: number | null
  efectivo: number | null
  salida_caja: number
  nequi: number | null
  otros: number | null
  total: number | null
}

async function bajarPlantilla() {
  try {
    await descargar('/excel/plantilla', sesion.token, 'plantilla-cierres.xlsx')
  } catch (e) {
    error.value = mensajeDe(e)
  }
}

async function leerExcel(evento: Event) {
  const entrada = evento.target as HTMLInputElement
  const archivo = entrada.files?.[0]
  entrada.value = ''
  if (!archivo) return
  error.value = ''
  paso.value = 'leyendo'
  try {
    const datos = new FormData()
    datos.append('archivo', archivo)
    const respuesta = await pedir<{ filas: FilaExcel[] }>('/excel/leer', { metodo: 'POST', cuerpo: datos, token: sesion.token })
    filas.value = respuesta.filas.map((f) => ({
      id: siguienteId++,
      origen: `Fila ${f.fila}`,
      fecha: f.fecha ?? '',
      tipo: f.tipo,
      base: f.base,
      efectivo: f.efectivo,
      salida_caja: f.salida_caja,
      nequi: f.nequi,
      otros: f.otros,
      total: f.total,
      incluir: true,
      reemplazar: false,
    }))
    paso.value = 'revisar'
  } catch (e) {
    error.value = mensajeDe(e)
    paso.value = 'inicio'
  }
}

// ---------- Cuaderno ----------
// El año no se escribe en el cuaderno: el dueño lo escoge (por defecto, el de este mes)
const anio = ref(Number(hoy.slice(0, 4)))
const mesPorDefecto = ref(Number(hoy.slice(5, 7)))
const anios = Array.from({ length: 6 }, (_, i) => Number(hoy.slice(0, 4)) - i)

const entradaFoto = ref<HTMLInputElement | null>(null)
const entradaGaleria = ref<HTMLInputElement | null>(null)

async function leerCuaderno(evento: Event) {
  const entrada = evento.target as HTMLInputElement
  const archivo = entrada.files?.[0]
  entrada.value = ''
  if (!archivo) return
  error.value = ''
  paso.value = 'leyendo'
  try {
    const datos = new FormData()
    datos.append('foto', await reducirImagen(archivo, 2000), 'cuaderno.jpg')
    const respuesta = await pedir<{ filas: { mes: number | null; dia: number; total: number; texto: string }[] }>('/cuaderno', { metodo: 'POST', cuerpo: datos, token: sesion.token })
    filas.value = respuesta.filas.map((f) => ({
      ...filaVacia(),
      origen: `Leído: "${f.texto}"`,
      fecha: fechaDe(anio.value, f.mes ?? mesPorDefecto.value, f.dia),
      total: f.total,
    }))
    if (!filas.value.length) error.value = 'No se encontraron días con su total en la foto. Pruebe con más luz y con la página derecha, o agréguelos a mano.'
    paso.value = 'revisar'
  } catch (e) {
    error.value = mensajeDe(e)
    paso.value = 'inicio'
  }
}

function filaVacia(): FilaCarga {
  return { id: siguienteId++, origen: 'Agregado a mano', fecha: '', tipo: 'historico', base: null, efectivo: null, salida_caja: null, nequi: null, otros: null, total: null, incluir: true, reemplazar: false }
}

// RN-33 para muchos días a la vez: un solo toque en lugar de fila por fila
const conCierreEnArchivo = computed(() => filas.value.filter((f) => f.incluir && revision.value.get(f.id)!.yaTiene && !revision.value.get(f.id)!.errores.length))
function todosLosRepetidos(reemplazar: boolean) {
  for (const f of conCierreEnArchivo.value) f.reemplazar = reemplazar
}

function agregarDia() {
  filas.value.push(filaVacia())
}

// ---------- Guardar ----------
const guardando = ref(false)
async function guardar() {
  guardando.value = true
  try {
    const guardadas = await guardarFilas(sesion.usuario!.negocio!.id, filas.value, hoy)
    sync.programar()
    dialogo.avisar(guardadas === 1 ? 'Se guardó 1 día' : `Se guardaron ${guardadas} días`)
    router.replace({ name: 'analisis' })
  } finally {
    guardando.value = false
  }
}

const detalleNormal = (f: FilaCarga) =>
  [
    `Base ${pesos(f.base ?? 0)}`,
    `Efectivo ${pesos(f.efectivo ?? 0)}`,
    f.salida_caja ? `Sacó ${pesos(f.salida_caja)}` : '',
    f.nequi ? `Nequi ${pesos(f.nequi)}` : '',
    f.otros ? `Otros ${pesos(f.otros)}` : '',
  ]
    .filter(Boolean)
    .join(' · ')
</script>

<template>
  <div>
    <BarraVolver :titulo="tipo === 'excel' ? 'Cargar desde Excel' : 'Foto del cuaderno'" :volver-a="{ name: 'analisis' }" />

    <main class="pantalla">
      <p v-if="error" class="mensaje error" role="alert"><IconAlertTriangle :size="20" aria-hidden="true" />{{ error }}</p>

      <!-- Paso 1 -->
      <section v-if="paso === 'inicio' && tipo === 'excel'" class="tarjeta">
        <ol class="pasos">
          <li>Descargue la plantilla.</li>
          <li>Escriba un día por fila. Si solo sabe el total del día, escriba la fecha y el total.</li>
          <li>Súbala aquí. Antes de guardar, usted revisa lo que se leyó.</li>
        </ol>
        <button class="boton secundario" @click="bajarPlantilla"><IconDownload :size="22" aria-hidden="true" /> Descargar plantilla</button>
        <label class="boton">
          <IconFileSpreadsheet :size="22" aria-hidden="true" /> Escoger el archivo
          <input type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" hidden @change="leerExcel" />
        </label>
      </section>

      <section v-if="paso === 'inicio' && tipo === 'cuaderno'" class="tarjeta">
        <p>Tome la foto de una página del cuaderno, derecha y con buena luz. La app busca cada día con su total.</p>
        <div class="dos">
          <label class="campo">
            Año de la página
            <span class="control">
              <select v-model.number="anio"><option v-for="a in anios" :key="a" :value="a">{{ a }}</option></select>
            </span>
          </label>
          <label class="campo">
            Mes, si no se ve
            <span class="control">
              <select v-model.number="mesPorDefecto">
                <option v-for="m in 12" :key="m" :value="m">{{ nombreMes(m) }}</option>
              </select>
            </span>
          </label>
        </div>
        <button class="boton" @click="entradaFoto?.click()"><IconCamera :size="22" aria-hidden="true" /> Tomar foto</button>
        <button class="boton secundario" @click="entradaGaleria?.click()"><IconPhoto :size="22" aria-hidden="true" /> Escoger una foto</button>
        <p class="suave pequeno">La foto no se guarda: solo se usa para leer los datos.</p>
        <input ref="entradaFoto" type="file" accept="image/*" capture="environment" hidden @change="leerCuaderno" />
        <input ref="entradaGaleria" type="file" accept="image/*" hidden @change="leerCuaderno" />
      </section>

      <p v-if="paso === 'leyendo'" class="tarjeta leyendo" role="status">
        <IconRefresh class="girando" :size="24" aria-hidden="true" />
        {{ tipo === 'excel' ? 'Leyendo el archivo…' : 'Leyendo la foto…' }}
      </p>

      <!-- Paso 2: revisar (RF-29) -->
      <template v-if="paso === 'revisar'">
        <section class="tarjeta resumen">
          <h2>Revise antes de guardar</h2>
          <p>
            <strong>{{ resumen.listas }}</strong> {{ resumen.listas === 1 ? 'día listo' : 'días listos' }}
            <template v-if="resumen.problemas"> · <span class="error">{{ resumen.problemas }} con problemas</span></template>
            <template v-if="resumen.yaTienen"> · {{ resumen.yaTienen }} ya {{ resumen.yaTienen === 1 ? 'tiene' : 'tienen' }} cierre</template>
          </p>
          <p class="suave pequeno">Los días con problemas no se guardan hasta que los corrija o los quite.</p>
          <div v-if="conCierreEnArchivo.length > 1" class="todos">
            <span class="pequeno">Días que ya tienen cierre:</span>
            <div class="eleccion">
              <button type="button" :class="{ activa: conCierreEnArchivo.every((f) => !f.reemplazar) }" @click="todosLosRepetidos(false)">Omitir todos</button>
              <button type="button" :class="{ activa: conCierreEnArchivo.every((f) => f.reemplazar) }" @click="todosLosRepetidos(true)">Reemplazar todos</button>
            </div>
          </div>
        </section>

        <ul class="filas">
          <li v-for="f in filas" :key="f.id" class="tarjeta fila" :class="{ apagada: !f.incluir, mal: f.incluir && revision.get(f.id)!.errores.length }">
            <div class="cabeza">
              <span class="suave pequeno origen">{{ f.origen }}</span>
              <label class="incluir">
                <input v-model="f.incluir" type="checkbox" />
                Incluir
              </label>
            </div>
            <template v-if="f.incluir">
              <label class="campo">
                Fecha
                <span class="control"><input v-model="f.fecha" type="date" :max="hoy" /></span>
              </label>
              <CampoPesos v-if="f.tipo === 'historico'" v-model="f.total" etiqueta="Total del día" />
              <p v-else-if="f.tipo === 'normal'" class="suave">{{ detalleNormal(f) }}</p>
              <p v-else-if="f.tipo === 'no_abrio'" class="suave">No abrió</p>

              <ul v-if="revision.get(f.id)!.errores.length" class="errores">
                <li v-for="e in revision.get(f.id)!.errores" :key="e" class="error"><IconAlertTriangle :size="18" aria-hidden="true" /> {{ e }}</li>
              </ul>
              <!-- RN-33 -->
              <div v-else-if="revision.get(f.id)!.yaTiene" class="ya-tiene">
                <p class="mensaje aviso"><IconAlertTriangle :size="20" aria-hidden="true" /> Este día ya tiene cierre.</p>
                <div class="eleccion" role="group" aria-label="Qué hacer con este día">
                  <button type="button" :class="{ activa: !f.reemplazar }" :aria-pressed="!f.reemplazar" @click="f.reemplazar = false">Omitir</button>
                  <button type="button" :class="{ activa: f.reemplazar }" :aria-pressed="f.reemplazar" @click="f.reemplazar = true">Reemplazar</button>
                </div>
                <p v-if="f.reemplazar && f.tipo === 'normal'" class="suave pequeno">Se conservan los pagos Nequi y otros pagos que ya tiene ese día.</p>
              </div>
            </template>
          </li>
        </ul>

        <button v-if="tipo === 'cuaderno'" class="boton-texto agregar" @click="agregarDia"><IconPlus :size="20" aria-hidden="true" /> Agregar un día a mano</button>

        <button class="boton secundario" @click="paso = 'inicio'">{{ tipo === 'excel' ? 'Escoger otro archivo' : 'Tomar otra foto' }}</button>
        <div class="pie">
          <button class="boton" :disabled="!resumen.listas || guardando" @click="guardar">
            {{ guardando ? 'Guardando…' : resumen.listas === 1 ? 'Guardar 1 día' : `Guardar ${resumen.listas} días` }}
          </button>
        </div>
      </template>
    </main>
  </div>
</template>

<style scoped>
.pasos {
  margin: 0;
  padding-left: 22px;
  display: grid;
  gap: 6px;
}

label.boton {
  cursor: pointer;
}

.dos {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: 10px;
}

.leyendo {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
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

.resumen {
  gap: 6px;
}

.filas {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 10px;
}

.fila {
  gap: 10px;
  padding: 14px 16px;
  border-left: 5px solid var(--verde-600);
}

.fila.mal {
  border-left-color: var(--error);
}

.fila.apagada {
  border-left-color: var(--borde-campo);
  opacity: 0.7;
}

.cabeza {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.origen {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.incluir {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-height: 44px;
  font-weight: 700;
  cursor: pointer;
}

.incluir input {
  width: 22px;
  height: 22px;
  margin: 0;
  accent-color: var(--verde-600);
}

.errores {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 4px;
}

.errores li {
  display: flex;
  gap: 6px;
  align-items: center;
}

.ya-tiene {
  display: grid;
  gap: 8px;
}

.eleccion {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
}

.eleccion button {
  min-height: 48px;
  font: inherit;
  font-weight: 700;
  color: var(--verde-800);
  background: var(--superficie);
  border: 1.5px solid var(--borde-campo);
  border-radius: var(--radio-control);
  cursor: pointer;
}

.eleccion button.activa {
  color: #ffffff;
  background: var(--verde-700);
  border-color: var(--verde-700);
}

.todos {
  display: grid;
  gap: 6px;
  margin-top: 4px;
}

.agregar {
  justify-self: center;
}

.pie {
  position: sticky;
  bottom: 0;
  display: grid;
  gap: 8px;
  padding: 12px 0;
  padding-bottom: max(12px, env(safe-area-inset-bottom));
  background: linear-gradient(transparent, var(--fondo) 24px);
}
</style>
