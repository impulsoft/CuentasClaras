"""Saca los datos de un comprobante de pago a partir de las líneas que leyó el OCR (RF-17).

Funciona con el comprobante del celular del cliente ("Envío realizado", "Para ...") y con el
detalle del movimiento en el Nequi del dueño ("Envío recibido", "De ..."). Si un dato no aparece,
queda en None y el dueño lo escribe al confirmar (RF-18).
"""

import re
import unicodedata

MESES = {
    'ene': 1, 'feb': 2, 'mar': 3, 'abr': 4, 'may': 5, 'jun': 6,
    'jul': 7, 'ago': 8, 'sep': 9, 'set': 9, 'oct': 10, 'nov': 11, 'dic': 12,
}


def _sin_tildes(texto: str) -> str:
    return ''.join(c for c in unicodedata.normalize('NFD', texto) if unicodedata.category(c) != 'Mn')


def _normal(texto: str) -> str:
    return _sin_tildes(texto).lower().strip()


# ---------- Valor ----------

_PESOS = re.compile(r'\$\s*([\d]{1,3}(?:[.\s]\d{3})+|\d+)(?:,(\d{1,2}))?')
_NUMERO_SUELTO = re.compile(r'^\s*([\d]{1,3}(?:\.\d{3})+|\d{4,})(?:,\d{1,2})?\s*$')


def _a_pesos(texto: str) -> int:
    return int(re.sub(r'[.\s]', '', texto))


def leer_valor(lineas: list[str]) -> int | None:
    # 1) Un valor con "$"; si hay varios, el mayor (el monto suele ser el número más grande)
    valores = [_a_pesos(m.group(1)) for linea in lineas for m in _PESOS.finditer(linea)]
    valores = [v for v in valores if v > 0]
    if valores:
        return max(valores)
    # 2) Un número solo en la línea siguiente a "Cuánto" o "Valor"
    for i, linea in enumerate(lineas[:-1]):
        if _normal(linea) in ('cuanto', 'valor', 'monto', 'total'):
            m = _NUMERO_SUELTO.match(lineas[i + 1])
            if m:
                return _a_pesos(m.group(1))
    return None


# ---------- Fecha ----------

_FECHA_LARGA = re.compile(r'(\d{1,2})\s*(?:de\s+)?([a-z]{3,})\.?\s*(?:de\s+|del\s+)?(\d{4})')
_FECHA_CORTA = re.compile(r'\b(\d{1,2})[/-](\d{1,2})[/-](\d{4})\b')


def leer_fecha(lineas: list[str]) -> str | None:
    for linea in lineas:
        texto = _normal(linea)
        m = _FECHA_LARGA.search(texto)
        if m and m.group(2)[:3] in MESES:
            return _fecha(int(m.group(3)), MESES[m.group(2)[:3]], int(m.group(1)))
        m = _FECHA_CORTA.search(texto)
        if m:
            return _fecha(int(m.group(3)), int(m.group(2)), int(m.group(1)))
    return None


def _fecha(anio: int, mes: int, dia: int) -> str | None:
    if 1 <= mes <= 12 and 1 <= dia <= 31:
        return f'{anio:04d}-{mes:02d}-{dia:02d}'
    return None


# ---------- Hora ----------

_HORA = re.compile(r'\b(\d{1,2}):(\d{2})(?::\d{2})?\s*(a\.?\s*m\.?|p\.?\s*m\.?)?', re.IGNORECASE)


def _hora_en(linea: str, exigir_sufijo: bool = False) -> str | None:
    for m in _HORA.finditer(_normal(linea)):
        hora, minuto = int(m.group(1)), int(m.group(2))
        sufijo = (m.group(3) or '').replace('.', '').replace(' ', '')
        if exigir_sufijo and not sufijo:
            continue
        if sufijo == 'pm' and hora < 12:
            hora += 12
        elif sufijo == 'am' and hora == 12:
            hora = 0
        if hora < 24 and minuto < 60:
            return f'{hora:02d}:{minuto:02d}'
    return None


def leer_hora(lineas: list[str]) -> str | None:
    """La hora del pago, no la del reloj del celular que sale arriba en los pantallazos ("2:44")."""
    # 1) En la misma línea de la fecha: "17 de noviembre de 2026 a las 01:42 p. m."
    for linea in lineas:
        if leer_fecha([linea]):
            hora = _hora_en(linea)
            if hora:
                return hora
    # 2) Después de la etiqueta "Hora", en la misma línea o en la siguiente
    for i, linea in enumerate(lineas):
        if _normal(linea).startswith('hora'):
            for candidata in (linea, lineas[i + 1] if i + 1 < len(lineas) else ''):
                hora = _hora_en(candidata)
                if hora:
                    return hora
    # 3) Una hora con a. m. o p. m. (el reloj del celular no la trae). Sin nada de eso, mejor vacía que equivocada
    for linea in lineas:
        hora = _hora_en(linea, exigir_sufijo=True)
        if hora:
            return hora
    return None


# ---------- Referencia ----------

_REFERENCIA = re.compile(r'\b([A-Z]{0,3}\d{6,14})\b')


def leer_referencia(lineas: list[str]) -> str | None:
    for i, linea in enumerate(lineas):
        if 'referencia' in _normal(linea) or 'comprobante' in _normal(linea):
            # en la misma línea ("Referencia M8423116") o en la siguiente
            for candidata in (linea, lineas[i + 1] if i + 1 < len(lineas) else ''):
                m = _REFERENCIA.search(candidata.upper())
                if m:
                    return m.group(1)
    return None


# ---------- Nombre de quien paga ----------

_ETIQUETAS_DE = ('de', 'de:', 'enviado por', 'enviado por:', 'quien envia', 'remitente')


def leer_nombre(lineas: list[str]) -> str | None:
    """Solo se toma el nombre que viene después de "De" (quien paga). "Para" es el dueño."""
    for i, linea in enumerate(lineas):
        texto = _normal(linea)
        if texto in _ETIQUETAS_DE:
            return _limpiar_nombre(lineas[i + 1]) if i + 1 < len(lineas) else None
        # "De: Ana Torres", pero no "¿De qué banco te enviaron?" si el OCR se comió el "¿"
        m = re.match(r'^(?:de|enviado por)\s*:?\s+(.+)$', linea.strip(), re.IGNORECASE)
        if m and not re.search(r'\d|\?|banco', _normal(m.group(1))):
            return _limpiar_nombre(m.group(1))
    return None


def _limpiar_nombre(texto: str) -> str | None:
    """Algunos bancos (y Bre-B) ocultan parte del nombre: "Bra*** Sti*** Fig*****".
    Se deja tal cual para que el dueño lo reconozca; si no quedó ninguna letra, no sirve."""
    nombre = re.sub(r'\s+', ' ', texto).strip(' :-')
    if not nombre or re.search(r'\d', nombre) or not re.search(r'[a-zA-ZÀ-ÿ]{2}', nombre):
        return None
    return ' '.join(p[:1].upper() + p[1:].lower() for p in nombre.split(' '))


def leer_comprobante(lineas: list[str]) -> dict:
    lineas = [l for l in (str(x).strip() for x in lineas) if l]
    return {
        'valor': leer_valor(lineas),
        'fecha': leer_fecha(lineas),
        'hora': leer_hora(lineas),
        'nombre': leer_nombre(lineas),
        'referencia': leer_referencia(lineas),
    }
