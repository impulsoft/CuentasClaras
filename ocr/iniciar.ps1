# Arranca el lector OCR para desarrollo (la API lo busca en http://127.0.0.1:8001).
#   powershell -ExecutionPolicy Bypass -File ocr\iniciar.ps1
#
# El entorno de Python va FUERA del proyecto: el proyecto está en OneDrive y el entorno pesa
# unos 500 MB en miles de archivos que no deben subirse a la nube.
# Por defecto: C:\Users\<usuario>\EntornosPython\cierre-ocr (o la carpeta de CIERRE_OCR_VENV).
# Si no existe, lo crea e instala requirements.txt (solo la primera vez, unos minutos).

$ErrorActionPreference = 'Stop'
$entorno = if ($env:CIERRE_OCR_VENV) { $env:CIERRE_OCR_VENV } else { Join-Path $env:USERPROFILE 'EntornosPython\cierre-ocr' }
$python = Join-Path $entorno 'Scripts\python.exe'

if (-not (Test-Path $python)) {
  Write-Host "Creando el entorno de Python en $entorno (solo la primera vez)..."
  python -m venv $entorno
  & $python -m pip install --upgrade pip -q
  & $python -m pip install -r (Join-Path $PSScriptRoot 'requirements.txt')
}

Set-Location $PSScriptRoot
Write-Host 'Lector OCR en http://127.0.0.1:8001 (Ctrl+C para detenerlo). La primera lectura tarda unos segundos más.' -ForegroundColor Green
& $python -m uvicorn app:app --host 127.0.0.1 --port 8001
