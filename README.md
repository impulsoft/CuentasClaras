# Cierre de Caja

Sistema de cierre de caja para negocios pequeños, ofrecido por suscripción mensual. Funciona sin internet, en celular y PC, e incluye pagos Nequi con OCR y el módulo de fiados.

Los requerimientos están en el documento **Requerimientos_Cierre_de_Caja v2.5** (carpeta `Documentacion cierredecaja`). Los comentarios del código citan sus IDs (RF-, RNF-, RN-).

## Estructura

| Carpeta | Qué es | Tecnología |
|---|---|---|
| `app/` | App del dueño y del admin: PWA instalable y APK para Android | Vue 3 + Vite + TypeScript, Pinia, Vue Router, Dexie, Capacitor 8 |
| `api/` | Servidor: cuentas, sincronización, suscripciones, Excel | Laravel 13, Sanctum, MySQL, OpenSpout (Excel) |
| `ocr/` | Servicio que lee el texto de las fotos (comprobantes y cuaderno) | Python, FastAPI, RapidOCR |
| `despliegue/` | Guía y piezas para montar el servidor con Dokploy (junto con `docker-compose.yml` y los `Dockerfile` de cada carpeta) | Docker, Dokploy |

El modelo de base de datos (diagrama, tablas y cómo crearla) está en [`docs/modelo-base-de-datos.md`](docs/modelo-base-de-datos.md), con el script MySQL en [`docs/cierre_caja.sql`](docs/cierre_caja.sql).

## Requisitos

- Node 22 o superior (Capacitor 8 lo exige)
- PHP 8.3 y Composer (Laragon)
- MySQL (el de Laragon) con una base de datos llamada `cierre_caja`
- Docker Desktop, para el servicio OCR
- Android Studio, solo para generar la APK (trae Java 21 en `C:\Program Files\Android\Android Studio\jbr`)

## Cómo correr cada parte

### App

```bash
cd app
npm install
npm run dev
```

- La dirección de la API se configura en `app/.env` con `VITE_API_URL` (ver `app/.env.example`).
- `npm run build` genera la PWA en `app/dist` (incluye el soporte para Android 7 sin WebView actualizado).
- La base local del celular (Dexie) está en `app/src/db/index.ts` y la sesión en `app/src/stores/sesion.ts`.
- La sincronización está en `app/src/stores/sincronizacion.ts`: corre al abrir la app, al volver el internet, después de guardar y cada 5 minutos.
- Los cálculos del cierre (RN-02, RN-03, mejor y peor día) están en `app/src/calculos.ts`.
- `npm test` corre las pruebas de cálculos, guardado y sincronización (Vitest, con IndexedDB simulado).
- El proyecto Android usa `minSdkVersion = 24`, es decir Android 7.0 (RNF-03).

### APK de Android

Hay dos tipos de APK:

| | APK de prueba | APK para los dueños |
|---|---|---|
| Comando | `npm run android:prueba` | `npm run android:produccion` |
| API | La del PC, por http (`app/.env.android-prueba`) | La real, solo https (`app/.env.production`, que hay que crear con `VITE_API_URL=https://...`) |
| Gradle | `gradlew assembleDebug` | `gradlew assembleRelease` (necesita la llave para firmarla) |

Pasos para la APK de prueba (en PowerShell, desde `app`):

```powershell
npm run android:prueba
$env:JAVA_HOME = 'C:\Program Files\Android\Android Studio\jbr'; $env:ANDROID_HOME = "$env:LOCALAPPDATA\Android\Sdk"
cd android; .\gradlew.bat assembleDebug
```

La APK queda en `app/android/app/build/outputs/apk/debug/app-debug.apk`. Desde el emulador de Android el PC es `10.0.2.2`; con un celular real en el mismo wifi, cambie la dirección en `app/.env.android-prueba` por la IP del PC y corra la API con `php artisan serve --host=0.0.0.0 --port=8000`.

En la APK el Excel no se descarga como en el navegador: se abre "Compartir" para guardarlo en el celular, en Drive o enviarlo por WhatsApp o correo (plugins `@capacitor/filesystem` y `@capacitor/share`). Si la persona cierra "Compartir" sin escoger nada, la ventana de descarga queda abierta y no se muestra error.

El botón "atrás" del celular lo maneja `src/main.ts` con `@capacitor/app`: vuelve a la pantalla anterior y cierra el panel del día (que va en la dirección, `?dia=2026-11-21`); en las pantallas principales (inicio, admin, entrar) minimiza la app sin cerrar la sesión. Sin el plugin, "atrás" cerraba la app desde cualquier pantalla.

**Recordatorio diario** (`src/recordatorio.ts`, plugin `@capacitor/local-notifications`): "¿Ya hizo el cierre de hoy?" a las 7:00 p. m. (el dueño lo apaga o cambia la hora en **Cuenta**). Lo programa el celular, sin internet: los próximos 14 días que no tienen cierre ni "No abrió"; al guardar el cierre se vuelve a programar y ese día ya no llega. Tocar el aviso abre el cierre de ese día. El ícono de la barra de estado es `android/app/src/main/res/drawable/ic_stat_cierre.xml`. En el navegador no hay recordatorio (no deja programar avisos sin servidor de notificaciones).

**Actividad de los negocios** (panel del admin): la API devuelve en cada negocio el último día registrado (cierre o "No abrió", sin contar lo cargado del cuaderno o de Excel) y la última vez que la app se conectó (`personal_access_tokens.last_used_at`). El panel muestra "Necesitan ayuda" con los que llevan 3 días o más sin cierre, con botones para llamar o escribir por WhatsApp al dueño.

"Tomar foto" abre la app de cámara del celular y la foto vuelve a la app; no hace falta el permiso de cámara en el `AndroidManifest.xml` (si se agrega y el dueño no lo acepta, Android bloquea la cámara).

La APK de prueba se probó en un emulador con Android 7.0 (WebView Chrome 69, sin actualizar): entrar, sincronizar, calendario, cierre, gráficas, "Tomar foto", "Subir pantallazo", descartar la foto, descargar el Excel, nuevo fiado y abono. Para revisar la pantalla del WebView desde el PC: `adb forward tcp:9222 localabstract:webview_devtools_remote_<pid>` y abrir `chrome://inspect`.

#### Llave para firmar la APK

La APK de los dueños se firma con una llave que se crea **una sola vez** y vive fuera del proyecto (no va a OneDrive ni a git):

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\crear-llave.ps1
```

El script pide la contraseña (no se ve al escribirla) y crea `C:\Users\<usuario>\LlaveCierreDeCaja\` con `cierre-de-caja.jks` (la llave, válida hasta 2054) y `firma.properties` (la ruta y la contraseña, que lee `app/android/app/build.gradle`). Si la llave ya existe, no hace nada. Para usar una llave guardada en otra parte, ponga la ruta de su `firma.properties` en la variable de entorno `CIERRE_FIRMA`.

- **No se puede perder.** Android solo instala una actualización si viene firmada con la misma llave; sin ella, los dueños tendrían que borrar la app e instalarla de nuevo, y perderían lo que no se haya sincronizado. Guarde una copia de la carpeta y la contraseña fuera del PC (memoria USB guardada y gestor de contraseñas).
- Sin la llave, `gradlew assembleRelease` se detiene con un mensaje que explica cómo crearla.
- En cada versión nueva para los dueños, suba `versionCode` (y `versionName`) en `app/android/app/build.gradle`; Android no instala una versión con un número igual o menor.

Pasos para la APK de los dueños (desde `app`, con `app/.env.production` creado):

```powershell
npm run android:produccion
$env:JAVA_HOME = 'C:\Program Files\Android\Android Studio\jbr'; $env:ANDROID_HOME = "$env:LOCALAPPDATA\Android\Sdk"
cd android; .\gradlew.bat assembleRelease
```

Queda en `app/android/app/build/outputs/apk/release/app-release.apk`.

### API

```bash
cd api
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

- `GET /api/estado` responde con el nombre de la app y la hora del servidor (hora de Colombia, RNF-13).
- La configuración de MySQL está en `.env` (`DB_DATABASE=cierre_caja`, usuario `root` sin contraseña, como viene Laragon).
- `php artisan migrate --seed` crea el admin y, en desarrollo (`APP_ENV=local`), un negocio de prueba. Los usuarios y contraseñas están en `.env` (`ADMIN_*` y `PRUEBA_*`); las de `.env.example` son solo para desarrollo local.
- `php artisan test` corre las pruebas con SQLite en memoria (no necesita MySQL).

| Ruta | Quién | Para qué |
|---|---|---|
| `POST /api/sesion` | Todos | Iniciar sesión con usuario y contraseña; devuelve el token (RF-01) |
| `DELETE /api/sesion` | Con sesión | Cerrar la sesión en este dispositivo (RF-02) |
| `GET /api/yo` | Con sesión | Datos del usuario, su rol y su negocio |
| `POST /api/contrasena` | Con sesión | Cambiar la contraseña (RF-47) o la temporal que dio el admin (RF-46). Devuelve un token nuevo; las otras sesiones se cierran cuando sincronicen |
| `POST /api/terminos` | Dueño | Aceptar los términos y condiciones (RF-03, RN-15) |
| `GET /api/negocio` | Dueño con términos aceptados | Datos de su negocio |
| `POST /api/sincronizar` | Dueño con términos aceptados | Envía cierres y pagos guardados en el celular y trae los que cambiaron (RF-30). Si un cierre cambió en otro dispositivo, lo devuelve como conflicto sin sobrescribirlo (RNF-12) |
| `POST /api/comprobantes` | Dueño con términos aceptados | Recibe la foto de un comprobante Nequi, la guarda y devuelve lo que leyó el OCR (valor, fecha, hora, nombre, referencia) y si ya estaba cargado (RF-17, RN-05) |
| `GET /api/comprobantes/foto?ruta=` | Dueño con términos aceptados | La foto del comprobante de un pago, solo de su negocio y mientras se guarda (RN-43) |
| `DELETE /api/comprobantes/foto?ruta=` | Dueño con términos aceptados | Borra la foto de un comprobante descartado; la de un pago no se borra |
| `GET /api/excel/cierres?desde=&hasta=` | Dueño con términos aceptados | Descarga los cierres en Excel: un día por fila, con la plata que sacó de la caja y los otros pagos (RF-26). Funciona aunque el negocio esté bloqueado (RN-13) |
| `GET /api/excel/plantilla` | Dueño con términos aceptados | Plantilla para cargar cierres; tiene las mismas columnas del Excel que se exporta (RF-27) |
| `POST /api/excel/leer` | Dueño con términos aceptados | Lee el Excel (campo `archivo`) y devuelve cada fila con sus errores, sin guardar nada (RF-27, RF-29, RN-08) |
| `POST /api/cuaderno` | Dueño con términos aceptados | Lee la foto de una página del cuaderno (campo `foto`) y devuelve los días con su total; la foto no se guarda (RF-28, RN-43) |
| `GET /api/admin/resumen` | Admin | Negocios al día, por vencer y vencidos, y lo recaudado en el mes (RF-13) |
| `GET /api/admin/negocios` | Admin | Lista de negocios con su suscripción y lo que les deben, sin nombres de deudores (RF-06, RF-08, RF-44) |
| `POST /api/admin/negocios` | Admin | Registrar negocio, dueño y primer pago (RF-04) |
| `GET /api/admin/negocios/{id}` | Admin | Detalle con el historial de pagos (RF-08, RF-09) |
| `PUT /api/admin/negocios/{id}` | Admin | Corregir datos del negocio y del dueño (RF-05) |
| `POST /api/admin/negocios/{id}/pagos` | Admin | Registrar un pago: extiende el vencimiento (RN-12) y desbloquea (RF-12) |
| `POST /api/admin/negocios/{id}/bloquear` · `/desbloquear` | Admin | Bloquear o desbloquear el negocio (RF-12) |
| `POST /api/admin/negocios/{id}/desactivar` · `/activar` | Admin | Desactivar o activar la cuenta del dueño (RF-05, RN-16) |
| `POST /api/admin/negocios/{id}/contrasena` | Admin | Crear una contraseña temporal para el dueño (RF-46) |
| `GET /api/admin/negocios/{id}/mes?anio=&mes=` (o `?desde=&hasta=`) | Admin | Cierres y pagos de un mes o de un rango, en solo lectura, para el calendario y los análisis; de los abonos solo el total del día (RF-07, RN-26) |
| `GET /api/admin/negocios/{id}/excel?desde=&hasta=` | Admin | Descarga los cierres de un negocio en Excel (RF-26) |

Cada tabla de un negocio (`cierres`, `pagos`, `deudores`, `fiados`, `abonos`) se filtra sola por el negocio del dueño que inició sesión (`app/Models/Concerns/PerteneceANegocio.php`, RNF-08).

- Las fotos de los comprobantes se guardan en `storage/app/private/comprobantes/{negocio}` y se borran a los 90 días (RN-43) con la tarea `php artisan comprobantes:borrar-fotos`. Las que nunca se volvieron pago se borran al descartarlas o, si se descartaron sin internet, a los 7 días, programada todos los días a las 3 a. m. Para que corra sola, en el servidor debe estar activo el programador de Laravel (`php artisan schedule:work` en desarrollo, o una tarea de Windows/cron que corra `php artisan schedule:run` cada minuto).
- Los negocios con 6 meses sin servicio (suscripción vencida, bloqueados o con el dueño desactivado) pierden sus datos con `php artisan negocios:borrar-datos-vencidos`, programada todos los días a las 3:30 a. m. (RN-34). A los 5 meses el dueño y el admin ven el aviso con la fecha. Con `--simular` solo muestra qué negocios se borrarían.
- La dirección del servicio OCR va en `OCR_URL` (por defecto `http://127.0.0.1:8001`). Si el OCR está apagado, la foto igual se guarda y el dueño escribe los datos a mano.

### OCR

```bash
cd ocr
docker build -t cierre-ocr .
docker run -p 8001:8001 cierre-ocr
```

Sin Docker, con Python (probado con Python 3.14):

```bash
cd ocr
python -m venv .venv
.venv\Scripts\activate
pip install -r requirements.txt
uvicorn app:app --port 8001
```

- `GET /estado` comprueba que el servicio está arriba.
- `POST /comprobante` recibe la foto de un comprobante en el campo `imagen` y devuelve las líneas leídas y los datos: valor, fecha, hora, nombre de quien paga y referencia (`comprobante.py`).
- `POST /cuaderno` recibe la foto de una página del cuaderno en el campo `imagen`, une los textos por renglón y devuelve los días con su total y el mes si aparece (`cuaderno.py`).
- `POST /leer` devuelve solo las líneas de texto con su confianza.
- `python -m unittest test_comprobante test_cuaderno` corre las pruebas de los lectores de comprobantes y del cuaderno.

**Si la app dice "No se pudo leer la foto" en todas las fotos**, casi siempre es que el OCR no está corriendo: el registro de la API (`api/storage/logs/laravel.log`) muestra "El servicio OCR no responde". Arránquelo con los pasos de arriba; la API lo busca en `OCR_URL` (`http://127.0.0.1:8001`).

Velocidad: para los comprobantes, el servicio no revisa si el texto está girado y busca el texto en la imagen achicada a 960 px (`motor_comprobantes` en `app.py`). Con 8 comprobantes reales lee el mismo valor, fecha, hora y referencia y tarda un 38 % menos (de 2,8 a 1,7 s en un portátil de 12 núcleos con batería). El cuaderno usa la lectura completa, porque la letra a mano es más pequeña. En la app, la ventana del pago se abre apenas se toma la foto y los datos aparecen al terminar de leer.

## Servidor (producción)

Se monta en un VPS con Dokploy usando [`docker-compose.yml`](docker-compose.yml): API, página web, OCR, MySQL, tareas de cada noche y copias diarias de la base de datos. Los pasos están en [`despliegue/README.md`](despliegue/README.md).

## Pendiente

- Guardar una copia de la llave de firma (`C:\Users\<usuario>\LlaveCierreDeCaja\`) y su contraseña fuera del PC.
- Montar el servidor ([`despliegue/README.md`](despliegue/README.md)): VPS de 4 GB, dominio con `api` y `app`, repositorio privado en GitHub y un espacio S3 para las copias. Las imágenes de Docker no se pudieron construir en este PC (falta WSL); la primera construcción real será en Dokploy. Sí se verificó cada paso por separado: las imágenes base existen y están al día, el OCR tiene sus paquetes para Python 3.12 en Linux (sin librerías gráficas), la API se instala solo con dependencias de producción y arranca en modo producción (migraciones, cachés, admin, `/up`, CORS y tareas de cada noche), y `npm ci` de la página web está al día.
- Crear `app/.env.production` con la dirección https de la API real para poder generar la APK de los dueños.
- Cambiar el identificador de la app (`co.cierredecaja.app` en `app/capacitor.config.ts`) si se va a publicar con otro dominio.
- Instalar la APK en un celular real con Android 7 (y en uno nuevo) antes de entregarla: en el emulador funciona, pero la cámara y "Compartir" dependen de las apps que traiga cada celular.
- Probar RapidOCR con fotos tomadas con la cámara a otro celular y con páginas reales del cuaderno escritas a mano (sección 7.1 del documento). Los pantallazos de Nequi ya se leen bien; el cuaderno se probó solo con letra manuscrita simulada.
- Texto del aviso de privacidad para deudores (`app/src/components/AvisoPrivacidad.vue`): es provisional hasta que lo revise un abogado.
- Suscripción: definir el precio, si habrá periodo de prueba y cuántos días antes cuenta como "por vencer" (hoy 5, `Negocio::DIAS_POR_VENCER`). Por ahora al registrar un negocio se anota su primer pago.
