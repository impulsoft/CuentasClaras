# Crea, UNA SOLA VEZ, la llave para firmar la APK de los dueños.
#   powershell -ExecutionPolicy Bypass -File app\scripts\crear-llave.ps1
#
# - La llave queda FUERA del proyecto: C:\Users\<usuario>\LlaveCierreDeCaja\
#     cierre-de-caja.jks  -> la llave
#     firma.properties    -> dónde está la llave y su contraseña (lo lee Gradle al compilar)
# - La contraseña se escribe aquí y no se muestra en pantalla.
# - Si la llave ya existe, el script no hace nada: nunca la reemplaza.
#
# IMPORTANTE: si se pierde la llave o su contraseña, la app ya no se puede actualizar en los
# celulares de los dueños (tendrían que borrarla e instalarla de nuevo, y perderían lo que no
# se haya sincronizado). Guarde una copia de la carpeta y la contraseña en un lugar seguro.

$ErrorActionPreference = 'Stop'

$carpeta = Join-Path $env:USERPROFILE 'LlaveCierreDeCaja'
$llave = Join-Path $carpeta 'cierre-de-caja.jks'
$propiedades = Join-Path $carpeta 'firma.properties'
$alias = 'cierre-de-caja'
$keytool = 'C:\Program Files\Android\Android Studio\jbr\bin\keytool.exe'

if (Test-Path $llave) {
  Write-Host "Ya existe una llave en $llave" -ForegroundColor Yellow
  Write-Host 'No se cambió nada. Si de verdad necesita otra, primero mueva la actual a otro lugar.'
  exit 1
}
if (-not (Test-Path $keytool)) {
  Write-Host "No se encontró keytool en $keytool (viene con Android Studio)." -ForegroundColor Red
  exit 1
}

function Leer-Clave([string]$pregunta) {
  $segura = Read-Host -AsSecureString $pregunta
  $puntero = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($segura)
  try { return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($puntero) }
  finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($puntero) }
}

Write-Host ''
Write-Host 'Contraseña de la llave: mínimo 8 caracteres, solo letras sin tilde y números.'
Write-Host 'Anótela en un lugar seguro: sin ella no se puede actualizar la app.'
Write-Host ''
$clave = Leer-Clave 'Contraseña'
if ($clave.Length -lt 8 -or $clave -notmatch '^[A-Za-z0-9]+$') {
  Write-Host 'La contraseña debe tener mínimo 8 caracteres, solo letras sin tilde y números. No se creó nada.' -ForegroundColor Red
  exit 1
}
if ((Leer-Clave 'Repita la contraseña') -ne $clave) {
  Write-Host 'Las contraseñas no coinciden. No se creó nada.' -ForegroundColor Red
  exit 1
}

New-Item -ItemType Directory -Force $carpeta | Out-Null

# La contraseña va a keytool por una variable de entorno de este proceso, no en el comando
$env:CIERRE_CLAVE_LLAVE = $clave
try {
  & $keytool -genkeypair -noprompt -storetype PKCS12 -keystore $llave -alias $alias `
    -keyalg RSA -keysize 4096 -validity 10000 `
    -dname 'CN=Cierre de Caja, O=Cierre de Caja, C=CO' `
    -storepass:env CIERRE_CLAVE_LLAVE -keypass:env CIERRE_CLAVE_LLAVE
  if ($LASTEXITCODE -ne 0) { throw 'keytool no pudo crear la llave.' }

  # Gradle lee este archivo (sin BOM: con BOM la primera línea no se reconoce)
  $ruta = $llave -replace '\\', '/'
  $texto = "storeFile=$ruta`nstorePassword=$clave`nkeyAlias=$alias`nkeyPassword=$clave`n"
  [IO.File]::WriteAllText($propiedades, $texto, (New-Object Text.UTF8Encoding($false)))

  $huella = & $keytool -list -v -keystore $llave -alias $alias -storepass:env CIERRE_CLAVE_LLAVE |
    Select-String 'SHA256:' | Select-Object -First 1
} finally {
  Remove-Item Env:\CIERRE_CLAVE_LLAVE -ErrorAction SilentlyContinue
  $clave = $null
}

Write-Host ''
Write-Host 'Llave creada.' -ForegroundColor Green
Write-Host "  Llave:   $llave"
Write-Host "  Archivo: $propiedades"
Write-Host "  Huella:  $(($huella -replace '^\s*SHA256:\s*', '').Trim())"
Write-Host ''
Write-Host 'Ahora guarde una copia de la carpeta LlaveCierreDeCaja y la contraseña fuera de este PC' -ForegroundColor Yellow
Write-Host '(por ejemplo, en una memoria USB guardada y en un gestor de contraseñas).' -ForegroundColor Yellow
