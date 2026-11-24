"""Pruebas del lector de comprobantes. Correr con: python -m unittest test_comprobante"""

import unittest

from comprobante import leer_comprobante


class LeerComprobante(unittest.TestCase):
    def test_comprobante_del_celular_del_cliente(self):
        lineas = [
            'Envío realizado',
            'Para',
            'Tienda Don José',
            'Cuánto',
            '$ 25.000,00',
            'Fecha',
            '18 de noviembre de 2026, 08:42 a. m.',
            'Referencia',
            'M8423116',
        ]
        self.assertEqual(
            leer_comprobante(lineas),
            {'valor': 25000, 'fecha': '2026-11-18', 'hora': '08:42', 'nombre': None, 'referencia': 'M8423116'},
        )

    def test_movimiento_en_el_nequi_del_dueno(self):
        lineas = ['Recibiste', '$18.000', 'De', 'CARLOS PEREZ', '18 nov 2026', '11:15 a.m.', 'Referencia M1234567']
        lectura = leer_comprobante(lineas)
        self.assertEqual(lectura['valor'], 18000)
        self.assertEqual(lectura['nombre'], 'Carlos Perez')
        self.assertEqual(lectura['fecha'], '2026-11-18')
        self.assertEqual(lectura['hora'], '11:15')
        self.assertEqual(lectura['referencia'], 'M1234567')

    def test_hora_de_la_tarde_y_fecha_con_barras(self):
        lectura = leer_comprobante(['Pago recibido', '$ 32.000', '18/11/2026', '2:30 p. m.'])
        self.assertEqual(lectura['hora'], '14:30')
        self.assertEqual(lectura['fecha'], '2026-11-18')

    def test_mediodia_y_medianoche(self):
        self.assertEqual(leer_comprobante(['12:05 p. m.'])['hora'], '12:05')
        self.assertEqual(leer_comprobante(['12:05 a. m.'])['hora'], '00:05')

    def test_nombre_en_la_misma_linea(self):
        self.assertEqual(leer_comprobante(['De: ana torres'])['nombre'], 'Ana Torres')

    def test_para_no_es_quien_paga(self):
        self.assertIsNone(leer_comprobante(['Para', 'Tienda Don José'])['nombre'])

    def test_valor_sin_signo_despues_de_cuanto(self):
        self.assertEqual(leer_comprobante(['Cuánto', '1.213.311'])['valor'], 1213311)

    # Pantallazos reales del "Detalle del movimiento" de Nequi (2026-11), con nombres y referencias cambiados
    def test_pantallazo_nequi_envio_recibido(self):
        lineas = [
            '2:35', 'Voz 5G', '←Detalle del movimiento', '?', 'R', 'Envio Recibido',
            'Escanea este QR con Nequi para verificar tu', 'i', 'envío al instante!',
            'De', 'Maria Elena Rojas Pinto',
            '¿Cuánto?', '$100.000,00',
            'Fecha', '16 de noviembre de 2026 a las 02:16 p. m.',
            'Referencia', 'M20481937',
        ]
        self.assertEqual(
            leer_comprobante(lineas),
            {'valor': 100000, 'fecha': '2026-11-16', 'hora': '14:16', 'nombre': 'Maria Elena Rojas Pinto', 'referencia': 'M20481937'},
        )

    def test_pantallazo_desde_otro_banco_con_nombre_oculto(self):
        lineas = [
            '2:44', '←Detalle del movimiento', '?', 'R', 'Envio recibido',
            'De', 'Car*** And*** Mej*****',
            '¿De qué banco te enviaron?', 'Bbva',
            '¿Cuánto?', '$4.000,00',
            'Fecha', '17 de noviembre de 2026 a las 01:51 p. m.',
            'Referencia', 'M20573311',
            'Llave a la que te enviaron', '3001234567',
            '¿Algún problema con este movimiento?',
        ]
        self.assertEqual(
            leer_comprobante(lineas),
            {'valor': 4000, 'fecha': '2026-11-17', 'hora': '13:51', 'nombre': 'Car*** And*** Mej*****', 'referencia': 'M20573311'},
        )

    def test_nombre_oculto_que_el_ocr_no_alcanzo_a_leer(self):
        # El OCR leyó solo asteriscos: no sirve como nombre
        self.assertIsNone(leer_comprobante(['De', '****** *** ****', '¿De qué banco te enviaron?', 'Bbva'])['nombre'])

    def test_la_pregunta_del_banco_no_es_un_nombre(self):
        self.assertIsNone(leer_comprobante(['De qué banco te enviaron?', 'Bbva'])['nombre'])

    def test_el_reloj_del_celular_no_es_la_hora_del_pago(self):
        self.assertIsNone(leer_comprobante(['2:44', 'Envio recibido', '$ 5.000'])['hora'])

    def test_si_no_lee_nada_devuelve_vacios(self):
        self.assertEqual(
            leer_comprobante(['foto borrosa']),
            {'valor': None, 'fecha': None, 'hora': None, 'nombre': None, 'referencia': None},
        )


if __name__ == '__main__':
    unittest.main()
