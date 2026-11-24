"""Saca los días y los totales de una página del cuaderno a partir de lo que leyó el OCR (RF-28).

En el cuaderno el dueño escribe el mes y, debajo, cada día con su total:

    Noviembre
    1   410.000
    2 - 385.000
    Lunes 3  $ 402.500

El año no se escribe: lo escoge el dueño al revisar. Lo que no se entienda queda por fuera y el
dueño lo agrega o lo corrige antes de guardar (RF-29).
"""

import re
import unicodedata

MESES = {
    'enero': 1, 'febrero': 2, 'marzo': 3, 'abril': 4, 'mayo': 5, 'junio': 6, 'julio': 7,
    'agosto': 8, 'septiembre': 9, 'setiembre': 9, 'octubre': 10, 'noviembre': 11, 'diciembre': 12,
}
DIAS_SEMANA = ('lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo')


def _normal(texto: str) -> str:
    sin_tildes = ''.join(c for c in unicodedata.normalize('NFD', texto) if unicodedata.category(c) != 'Mn')
    return sin_tildes.lower().strip()


def agrupar_renglones(cajas: list[tuple[float, float, float, str]]) -> list[str]:
    """Une en un renglón los textos que están a la misma altura, de izquierda a derecha.

    Cada caja es (x, y_centro, alto, texto). Dos textos van en el mismo renglón si sus centros
    están a menos de media altura de distancia.
    """
    renglones: list[list[tuple[float, float, float, str]]] = []
    for caja in sorted(cajas, key=lambda c: c[1]):
        if renglones:
            ultimo = renglones[-1]
            centro = sum(c[1] for c in ultimo) / len(ultimo)
            alto = max(c[2] for c in ultimo)
            if abs(caja[1] - centro) < max(alto, caja[2]) * 0.5:
                ultimo.append(caja)
                continue
        renglones.append([caja])
    return [' '.join(c[3] for c in sorted(r, key=lambda c: c[0])).strip() for r in renglones]


_MONTO = re.compile(r'\$?\s*(\d{1,3}(?:[.,\s]\d{3})+|\d{3,9})(?:[.,]\d{2})?\s*$')
_DIA = re.compile(r'^(?:[a-z]+\.?\s+)?(\d{1,2})\b\s*[-–:.)]?\s*')


def _mes_en(texto: str) -> int | None:
    palabras = re.findall(r'[a-z]+', texto)
    for palabra in palabras:
        if palabra in MESES:
            return MESES[palabra]
    return None


def leer_cuaderno(lineas: list[str]) -> list[dict]:
    """Devuelve [{mes, dia, total, texto}] en el orden del cuaderno. mes es None si no se vio."""
    filas = []
    mes = None
    for linea in (l.strip() for l in lineas):
        if not linea:
            continue
        texto = _normal(linea)

        # Un renglón que solo tiene el nombre del mes (y quizás el año) cambia el mes de lo que sigue
        mes_visto = _mes_en(texto)
        sin_anio = re.sub(r'\b(?:19|20)\d{2}\b', '', texto)  # "Noviembre 2026": el año no es un total
        if mes_visto and not _MONTO.search(sin_anio):
            mes = mes_visto
            continue

        sin_dia_semana = texto
        for dia_semana in DIAS_SEMANA:
            if sin_dia_semana.startswith(dia_semana):
                sin_dia_semana = sin_dia_semana[len(dia_semana):].strip(' .,')
                break

        m_dia = _DIA.match(sin_dia_semana)
        if not m_dia:
            continue
        dia = int(m_dia.group(1))
        resto = sin_dia_semana[m_dia.end():]
        m_monto = _MONTO.search(resto)
        if not (1 <= dia <= 31) or not m_monto:
            continue
        total = int(re.sub(r'\D', '', m_monto.group(1)))
        if total <= 0:
            continue
        filas.append({'mes': mes, 'dia': dia, 'total': total, 'texto': linea})
    return filas
