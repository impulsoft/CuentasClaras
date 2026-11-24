FROM python:3.12-slim

# onnxruntime necesita libgomp en imágenes slim
RUN apt-get update \
    && apt-get install -y --no-install-recommends libgomp1 \
    && rm -rf /var/lib/apt/lists/*

# El servicio no corre como root
RUN useradd --create-home --uid 1000 ocr
WORKDIR /servicio
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Descarga los modelos durante la construcción para que el primer pedido no espere.
# Va antes de USER: RapidOCR los guarda dentro de su paquete, que es de root (después solo los lee).
RUN python -c "from rapidocr import RapidOCR; RapidOCR()"

USER ocr
# app.py usa comprobante.py y cuaderno.py: se copian todos los módulos (las pruebas no, ver .dockerignore)
COPY --chown=ocr *.py ./
EXPOSE 8001
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD python -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8001/estado', timeout=4)"
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8001"]
