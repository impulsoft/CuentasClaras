/*
 * RNF-03: los WebViews viejos de Android 7 (Chrome menor a 84) no entienden "gap" en flexbox y los
 * elementos quedan pegados ("Total del mes$5.211.000"). Este plugin de PostCSS agrega, junto a cada
 * regla flex con gap, una regla de márgenes equivalente que solo aplica cuando <html> tiene la clase
 * "sin-gap" (la pone src/main.ts si el navegador no soporta gap en flexbox). En navegadores nuevos
 * no cambia nada.
 */
export default function respaldoGap() {
  return {
    postcssPlugin: 'respaldo-gap-flex',
    Once(raiz) {
      raiz.walkRules((regla) => {
        if (regla.parent?.type === 'atrule' && /keyframes$/.test(regla.parent.name)) return
        let display = ''
        let gap = ''
        let direccion = ''
        regla.each((d) => {
          if (d.type !== 'decl') return
          if (d.prop === 'display') display = d.value
          if (d.prop === 'gap') gap = d.value
          if (d.prop === 'flex-direction') direccion = d.value
        })
        if (!gap || !/(^|-)flex$/.test(display)) return

        const [fila, columna = fila] = gap.trim().split(/\s+/)
        const vertical = direccion.startsWith('column')
        const margen = vertical ? fila : columna
        if (/^0(px)?$/.test(margen)) return

        const selectores = regla.selectors.map((s) => `.sin-gap ${s} > * + *`).join(',\n')
        const respaldo = regla.clone({ selector: selectores })
        respaldo.removeAll()
        respaldo.append({ prop: vertical ? 'margin-top' : 'margin-left', value: margen })
        regla.after(respaldo)

        // Ícono o casilla seguidos de texto suelto ("✓ Confirmar", "◉ Este mes"): el texto no es un
        // elemento, así que la regla de arriba no lo alcanza y el margen se pone a la derecha del
        // ícono. Los botones de solo ícono llevan aria-label y quedan por fuera para no descentrarlos.
        if (!vertical) {
          const iconos = regla.clone({
            selector: regla.selectors
              .flatMap((s) => [`.sin-gap ${s}:not([aria-label]) > svg:only-child`, `.sin-gap ${s} > input:only-child`])
              .join(',\n'),
          })
          iconos.removeAll()
          iconos.append({ prop: 'margin-right', value: margen })
          respaldo.after(iconos)
        }
      })
    },
  }
}
respaldoGap.postcss = true
