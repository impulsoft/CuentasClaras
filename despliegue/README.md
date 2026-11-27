# Montar el servidor con Dokploy

Guía para poner en internet la API, el lector OCR, la base de datos y la página web, en un VPS con [Dokploy](https://dokploy.com). Todo está en [`docker-compose.yml`](../docker-compose.yml) (raíz del proyecto).

| Servicio | Qué hace | ¿Dominio? |
|---|---|---|
| `api` | API de Laravel | Sí, por ejemplo `api.su-dominio.co` |
| `web` | Página web de la app (para el admin o un dueño en el PC) | Sí, por ejemplo `app.su-dominio.co` |
| `programador` | Tareas de cada noche: borra fotos de comprobantes viejas y los datos de negocios vencidos | No |
| `ocr` | Lee los comprobantes Nequi y las fotos del cuaderno | No |
| `mysql` | Base de datos | No |
| `respaldo` | Copia de la base de datos todos los días a las 2:30 a. m. | No |

## Qué se necesita

- **Un VPS con Ubuntu 24.04, 2 núcleos y 4 GB de RAM como mínimo.** El lector OCR y MySQL juntos no caben bien en 2 GB, y la primera construcción también pide memoria.
- **Un dominio** con dos registros tipo A apuntando a la IP del VPS: `api` y `app` (en el panel donde compró el dominio).
- **El código en un repositorio privado de GitHub** (Dokploy lo descarga de ahí).
- **Un espacio S3 para las copias**, fuera del VPS. Por ejemplo Cloudflare R2 o Backblaze B2, que tienen un plan gratis suficiente para empezar.

## 1. Instalar Dokploy

En el VPS, por SSH, como root:

```bash
curl -sSL https://dokploy.com/install.sh | sh
```

Al terminar, abra `http://IP-DEL-VPS:3000` y cree la cuenta de administrador de Dokploy. En el firewall del proveedor del VPS deje abiertos solo los puertos 22, 80, 443 y 3000.

## 2. Crear el servicio

1. En Dokploy: **Projects → Create Project** (por ejemplo, "Cierre de Caja").
2. Dentro del proyecto: **Create Service → Compose**.
3. En **Provider** escoja GitHub, conecte la cuenta, y escoja el repositorio y la rama `main`.
4. **Compose Path:** `./docker-compose.yml`.

## 3. Poner las variables

Copie el contenido de [`variables.example`](variables.example) en la pestaña **Environment** del servicio y cambie cada valor:

- **`APP_KEY`:** en el PC, dentro de la carpeta `api`, corra `php artisan key:generate --show` y pegue la línea completa (empieza con `base64:`).
- **Contraseñas (`DB_PASSWORD`, `DB_ROOT_PASSWORD`, `ADMIN_CONTRASENA`):** en el VPS, `openssl rand -hex 24` da una al azar; corra el comando una vez por cada contraseña. Guárdelas en un gestor de contraseñas.
- **`APP_URL` y `CORS_ORIGENES`:** con su dominio real.

Si falta alguna, el despliegue se detiene y dice cuál (por ejemplo, "Falta DB_PASSWORD").

## 4. Desplegar

Pulse **Deploy**. La primera vez tarda entre 10 y 20 minutos, porque descarga los modelos del OCR y arma la página web. En **Logs** se ve el avance.

## 5. Asignar los dominios

En la pestaña **Domains** del servicio agregue dos:

| Servicio | Host | Puerto | HTTPS |
|---|---|---|---|
| `api` | `api.su-dominio.co` | 80 | Sí, con Let's Encrypt |
| `web` | `app.su-dominio.co` | 80 | Sí, con Let's Encrypt |

Pulse **Deploy** otra vez para que tomen efecto. Revise que funcionen:

- `https://api.su-dominio.co/up` debe mostrar una página que dice que la aplicación está en funcionamiento.
- `https://app.su-dominio.co` debe abrir la pantalla para entrar.

## 6. Crear la cuenta del administrador

La API la crea sola al arrancar: si no existe el usuario `ADMIN_USUARIO`, lo crea con la contraseña `ADMIN_CONTRASENA` (en **Logs** del servicio `api` sale "Se creó el admin"). Si ya existe, no le cambia nada. Entre a la app con esa cuenta y cámbiele la contraseña desde **Cuenta**.

**Si el admin no puede entrar** ("El usuario o la contraseña no son correctos"), por ejemplo porque olvidó la contraseña que puso en la app o porque cambió `ADMIN_CONTRASENA` después de crearlo: en Dokploy abra la terminal del contenedor `api` (pestaña **Docker** o **Terminal** del servicio) y corra:

```bash
php artisan cierre:admin --restablecer
```

Le vuelve a poner la contraseña que está en `ADMIN_CONTRASENA`, lo activa y cierra sus sesiones abiertas. Escríbala tal cual, sin comillas.

**Si la app dice que necesita internet** al entrar, la página no llega a la API: revise que `https://api.su-dominio.co/up` abra, que `APP_URL` sea esa dirección (sin `/api`) y que `CORS_ORIGENES` tenga la dirección exacta de la página (`https://app.su-dominio.co`). Si cambia `APP_URL`, pulse **Deploy** otra vez: la página web la lleva por dentro.

## 7. Copias de seguridad fuera del servidor

El servicio `respaldo` guarda cada noche una copia de la base de datos en el volumen `respaldos` (las de los últimos 14 días). Para que también queden fuera del VPS:

1. En Dokploy: **Settings → Destinations (S3)** y agregue el espacio de R2 o B2.
2. En el servicio: **Volume Backups → Create**. Volumen: `respaldos`. Programación: `0 9 * * *` (todos los días a las 9:00 UTC, que son las 4:00 a. m. en Colombia, después de la copia de las 2:30). Escoja el destino S3.
3. Pruebe que funciona: en la terminal del contenedor `respaldo`, corra `respaldar ahora`. Después, en **Volume Backups**, lance la copia a mano y revise que el archivo aparezca en el S3.

Las fotos de los comprobantes (volumen `almacenamiento`) no hace falta copiarlas: el sistema las borra solo después de un tiempo (RN-43).

### Recuperar una copia

En el VPS, por SSH, con el archivo `.sql` descargado del S3 (por ejemplo en `/root/cierre_caja-2026-12-01_0230.sql`):

```bash
docker ps --format '{{.Names}}' | grep mysql
docker exec -i NOMBRE-DEL-CONTENEDOR-MYSQL sh -c 'mysql -u root -p"$MYSQL_ROOT_PASSWORD" cierre_caja' < /root/cierre_caja-2026-12-01_0230.sql
```

Esto reemplaza los datos actuales por los de la copia. Saque antes una copia de lo que hay (`respaldar ahora`).

## 8. La APK de los dueños

Con el servidor andando, en el PC cree `app/.env.production` con:

```
VITE_API_URL=https://api.su-dominio.co/api
```

y siga los pasos de "APK para los dueños" en el [README principal](../README.md).

## Actualizar el servidor

1. Si el cambio toca la base de datos, saque antes una copia: `respaldar ahora` en el contenedor `respaldo`.
2. Suba los cambios a GitHub y pulse **Deploy** (o active **Autodeploy** para que se despliegue solo al subir).

La API actualiza la base de datos sola al arrancar (`php artisan migrate --force`). Los dueños no tienen que hacer nada: la app se sincroniza como siempre.

## Detalles técnicos

- **Proxy:** la API confía solo en el proxy de Dokploy (redes internas de Docker, variable `PROXIES_CONFIABLES`). Así cada negocio tiene su propio límite de intentos para entrar. No la cambie a `*`: cualquiera podría inventarse una IP para saltarse ese límite (ver `api/tests/Feature/ProxyProduccionTest.php`).
- **Registros:** la API escribe sus registros en la salida del contenedor; se ven en **Logs** de Dokploy.
- **Hora:** la API y el programador usan la hora de Colombia (`APP_TIMEZONE`).
- **Sin puertos publicados:** ningún servicio expone puertos al exterior; todo entra por el proxy con https. No agregue `ports:` ni `container_name` al compose.
