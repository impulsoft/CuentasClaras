"""Servicio OCR: recibe una imagen y devuelve lo que leyó RapidOCR.

La API de Laravel le envía las fotos de los comprobantes (RF-17) y, más adelante, del cuaderno (RF-28).
"""

from io import BytesIO

from fastapi import FastAPI, File, HTTPException, UploadFile
from fastapi.concurrency import run_in_threadpool
from PIL import Image, UnidentifiedImageError
from rapidocr import RapidOCR

from comprobante import leer_comprobante
from cuaderno import agrupar_renglones, leer_cuaderno

app = FastAPI(title='OCR Cierre de Caja')

# Comprobantes Nequi: llegan derechos y con letra grande, así que no se revisa si el texto está al
# revés (Global.use_cls) y el texto se busca en la imagen achicada a 960 px (Det.limit_type max).
# Con 8 comprobantes reales lee el mismo valor, fecha, hora y referencia, y es un 38 % más rápido.
motor_comprobantes = RapidOCR(params={'Global.use_cls': False, 'Det.limit_type': 'max', 'Det.limit_side_len': 960})

# Cuaderno: letra a mano y más pequeña; se deja la búsqueda a tamaño completo para no perder números
motor_cuaderno = RapidOCR()


async def _reconocer(datos: bytes, motor: RapidOCR = motor_comprobantes):
    # La lectura ocupa el procesador varios segundos: va en otro hilo para que el servicio siga
    # respondiendo (por ejemplo, a /estado o a la foto de otro negocio) mientras tanto
    return await run_in_threadpool(motor, datos)


@app.get('/estado')
def estado():
    return {'ok': True}


async def _lineas(imagen: UploadFile) -> list[dict]:
    datos = await imagen.read()
    try:
        Image.open(BytesIO(datos)).verify()
    except UnidentifiedImageError:
        raise HTTPException(status_code=400, detail='El archivo no es una imagen.')

    resultado = await _reconocer(datos)
    if resultado.txts is None:
        return []
    return [
        {'texto': texto, 'confianza': round(float(confianza), 3)}
        for texto, confianza in zip(resultado.txts, resultado.scores)
    ]


@app.post('/leer')
async def leer(imagen: UploadFile = File(...)):
    return {'lineas': await _lineas(imagen)}


# Página del cuaderno (RF-28): los textos se unen por renglón y se sacan los días con su total.
# La foto no se guarda en ningún lado (RN-43).
@app.post('/cuaderno')
async def cuaderno(imagen: UploadFile = File(...)):
    datos = await imagen.read()
    try:
        Image.open(BytesIO(datos)).verify()
    except UnidentifiedImageError:
        raise HTTPException(status_code=400, detail='El archivo no es una imagen.')

    resultado = await _reconocer(datos, motor_cuaderno)
    if resultado.txts is None:
        return {'renglones': [], 'filas': []}

    cajas = []
    for caja, texto in zip(resultado.boxes, resultado.txts):
        ys = [float(p[1]) for p in caja]
        cajas.append((min(float(p[0]) for p in caja), sum(ys) / len(ys), max(ys) - min(ys), texto))
    renglones = agrupar_renglones(cajas)
    return {'renglones': renglones, 'filas': leer_cuaderno(renglones)}


# Comprobante de pago: las líneas y, aparte, el valor, la fecha, la hora, el nombre y la referencia
@app.post('/comprobante')
async def comprobante(imagen: UploadFile = File(...)):
    lineas = await _lineas(imagen)
    return {'lineas': lineas, 'campos': leer_comprobante([l['texto'] for l in lineas])}
