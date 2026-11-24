"""Pruebas del lector del cuaderno. Correr con: python -m unittest test_cuaderno"""

import unittest

from cuaderno import agrupar_renglones, leer_cuaderno


class LeerCuaderno(unittest.TestCase):
    def test_pagina_tipica(self):
        lineas = ['Noviembre 2026', '1   410.000', '2 - 385.000', 'Lunes 3  $ 402.500', '4: 398000']
        self.assertEqual(
            [(f['mes'], f['dia'], f['total']) for f in leer_cuaderno(lineas)],
            [(11, 1, 410000), (11, 2, 385000), (11, 3, 402500), (11, 4, 398000)],
        )

    def test_cambio_de_mes_en_la_misma_pagina(self):
        lineas = ['Octubre', '30 350.000', '31 420.000', 'Noviembre', '1 410.000']
        self.assertEqual([(f['mes'], f['dia']) for f in leer_cuaderno(lineas)], [(10, 30), (10, 31), (11, 1)])

    def test_sin_mes_queda_vacio_para_que_lo_escoja_el_dueno(self):
        self.assertEqual(leer_cuaderno(['5 210.000'])[0]['mes'], None)

    def test_ignora_lo_que_no_es_un_dia_con_total(self):
        lineas = ['Ventas', 'Total semana 2.400.000', '45 120.000', '7', 'gaseosas 3']
        self.assertEqual(leer_cuaderno(lineas), [])

    def test_montos_con_coma_o_espacio_de_miles(self):
        totales = [f['total'] for f in leer_cuaderno(['1 410,000', '2 385 000', '3 402.500,00'])]
        self.assertEqual(totales, [410000, 385000, 402500])

    def test_agrupa_el_dia_y_el_total_en_un_renglon(self):
        # El OCR suele leer el día y el total como textos separados
        cajas = [(300, 101, 30, '410.000'), (20, 100, 30, '1'), (22, 150, 30, '2'), (310, 152, 30, '385.000'), (10, 50, 34, 'Noviembre')]
        self.assertEqual(agrupar_renglones(cajas), ['Noviembre', '1 410.000', '2 385.000'])


if __name__ == '__main__':
    unittest.main()
