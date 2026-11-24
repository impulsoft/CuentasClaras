import type { CapacitorConfig } from '@capacitor/cli';

// APK de prueba contra la API del PC (http). Se activa con "npm run android:prueba";
// la APK para los dueños se sincroniza sin esto y solo habla con la API por https.
const pruebaLocal = process.env.CIERRE_API_LOCAL === '1';

const config: CapacitorConfig = {
  appId: 'co.cierredecaja.app',
  appName: 'Cierre de Caja',
  webDir: 'dist',
  android: {
    // La app corre en https://localhost: para llamar a http://10.0.2.2 hay que permitir contenido mixto
    allowMixedContent: pruebaLocal,
  },
  plugins: {
    // Recordatorio diario del cierre (src/recordatorio.ts): ícono blanco de la barra de estado y color verde
    LocalNotifications: {
      smallIcon: 'ic_stat_cierre',
      iconColor: '#0F6E56',
    },
  },
};

export default config;
