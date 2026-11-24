// Prepara la parte web de la APK y la copia al proyecto Android (Capacitor).
//   node scripts/android.mjs prueba     → APK de prueba que habla con la API del PC (.env.android-prueba)
//   node scripts/android.mjs produccion → APK para los dueños (.env.production: la API real, con https)
// Después se compila con Gradle (ver README).
import { execSync } from 'node:child_process'
import { existsSync, readFileSync } from 'node:fs'

const tipo = process.argv[2]
if (!['prueba', 'produccion'].includes(tipo)) {
  console.error('Uso: node scripts/android.mjs prueba|produccion')
  process.exit(1)
}

// La APK de los dueños debe apuntar a la API real, con https (RNF-09)
if (tipo === 'produccion') {
  const env = existsSync('.env.production') ? readFileSync('.env.production', 'utf8') : ''
  const url = /^VITE_API_URL=(.+)$/m.exec(env)?.[1]?.trim()
  if (!url?.startsWith('https://')) {
    console.error('Falta la dirección de la API real. Cree app/.env.production con, por ejemplo:\n  VITE_API_URL=https://api.su-dominio.co/api')
    process.exit(1)
  }
}

const correr = (comando, env = {}) => execSync(comando, { stdio: 'inherit', env: { ...process.env, ...env } })

correr('npx vue-tsc -b')
correr(`npx vite build --mode ${tipo === 'prueba' ? 'android-prueba' : 'production'}`)
// En prueba la app (https://localhost) llama a la API por http: se permite solo en esa APK
correr('npx cap sync android', tipo === 'prueba' ? { CIERRE_API_LOCAL: '1' } : {})
