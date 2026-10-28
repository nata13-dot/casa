# Casa 🏠

App familiar para avisos de la casa: checklist diario de los perritos (Luna, Romeo y Mushu), anuncios clasificados por tipo e importancia con notificaciones push, y contactos con acceso directo a llamada y WhatsApp.

```
Casa/
├── api/                    Backend PHP 8.2 + MariaDB (sin frameworks ni Composer)
│   ├── bin/casa-admin.php  Recuperación por SSH (aprobar, hacer admin)
│   ├── public/index.php    Front controller + tabla de rutas
│   ├── public/uploads/     Fotos de los perros (estáticas, nombre aleatorio)
│   ├── src/                Router, Auth, Database, Fcm, Categories, controllers/
│   ├── database/migrations/001_init.sql, 002_security.sql
│   ├── deploy/nginx-casa.conf, php-fpm-casa.conf
│   └── .env.example
├── app/                    React 19 + Vite + TypeScript + Capacitor 8
│   ├── src/pages/          Registro, Inicio, Avisos, Contactos, Ajustes
│   ├── src/components/     Tarjetas, hoja inferior, barra de pestañas, estados
│   ├── src/services/       Cliente API, push, almacenamiento
│   ├── src/styles/global.css
│   └── android/            Proyecto nativo generado por Capacitor
├── FIREBASE_NOTIFICACIONES.md
├── SEGURIDAD.md            Aislamiento del servidor, Cloudflare, Firebase, qué hacer si…
└── README.md
```

---

## 1. Desplegar la API en el servidor (Debian)

### Paquetes

```bash
sudo apt install php8.2-fpm php8.2-mysql php8.2-curl php8.2-mbstring
# fileinfo, openssl y json ya vienen con PHP
```

### Base de datos

Un solo script crea la base, el usuario `casa_user` (solo `SELECT/INSERT/UPDATE/DELETE`, solo por socket local) y todas las tablas. En el repositorio va como plantilla: copia `api/database/instalar_servidor.example.sql` a `instalar_servidor.sql` y reemplaza `CAMBIA_ESTA_CONTRASENA` por el `DB_PASS` de `api/.env` (el archivo con la contraseña real está en `.gitignore`):

```bash
scp api/database/instalar_servidor.sql usuario@servidor:/tmp/
ssh usuario@servidor
sudo mariadb < /tmp/instalar_servidor.sql
shred -u /tmp/instalar_servidor.sql        # contiene la contraseña
```

Es idempotente (no borra datos si se vuelve a correr). Los archivos `001_init.sql` y `002_security.sql` quedan como historial de migraciones para instalaciones anteriores.

**Pasar datos de local al servidor** (o sacar respaldos):

```bash
./api/deploy/exportar_bd.sh                  # → backups/casa_AAAAMMDD_HHMM.sql
scp backups/casa_*.sql usuario@servidor:/tmp/
sudo mariadb casa < /tmp/casa_AAAAMMDD_HHMM.sql
```

Las filas del respaldo reemplazan a las del servidor con el mismo id; no se borra nada más.

### Archivos

```bash
sudo mkdir -p /var/www/casa
sudo rsync -a --exclude node_modules api /var/www/casa/
cd /var/www/casa/api
sudo cp .env.example .env
sudo nano .env                     # DB_*, HOUSE_CODE, PUBLIC_URL, FCM_SERVICE_ACCOUNT
```

Permisos, usuario `casa` y pool aislado de PHP-FPM: sigue **[SEGURIDAD.md §2](SEGURIDAD.md#2-servidor-aislar-casa-de-tus-otros-sistemas)**. Como el servidor tiene otros servicios importantes, no te saltes ese paso.

### Nginx + Cloudflare Tunnel

```bash
sudo cp deploy/nginx-casa.conf /etc/nginx/sites-available/casa
sudo nano /etc/nginx/sites-available/casa        # server_name, ruta, puerto
sudo ln -s ../sites-available/casa /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

Nginx escucha solo en `127.0.0.1:8085`. En la configuración de `cloudflared` agrega la regla:

```yaml
ingress:
  - hostname: casa.tudominio.com
    service: http://127.0.0.1:8085
  # ... tus otras reglas
  - service: http_status:404
```

O desde el panel *Zero Trust → Networks → Tunnels → Public Hostname*.

Comprueba: `curl https://casa.tudominio.com/api/health` → `{"success":true,...}`

**Cloudflare:** reglas WAF y de rate limiting recomendadas en [SEGURIDAD.md §3](SEGURIDAD.md#3-cloudflare).

**Primer uso:** el primero que se registra queda como **administrador**. Hazlo tú en cuanto despliegues, antes de pasar el APK a nadie.

### Consumo de recursos

Pensado para tu servidor de 2 GB:

- PHP sin framework: ~2–4 MB de RAM por petición; no hay procesos residentes extra.
- La app consulta cada 30 s **solo mientras está en pantalla** (3 peticiones ligeras). Con 5 personas eso no se nota ni en CPU ni en MariaDB.
- El checklist no crece sin control: una fila por perro/tipo/día (~2 200 filas al año).
- Las fotos se reducen en el celular a 1024 px antes de subir (~150–300 KB c/u).
- Las notificaciones se envían **después** de responder a la app (`fastcgi_finish_request`), en paralelo con `curl_multi`.
- No hace falta tocar `innodb_buffer_pool_size` (128 MiB) ni `max_connections` (151).

---

## 2. Compilar la app

```bash
cd app
npm install
cp .env.example .env.production
nano .env.production
#   VITE_API_URL=https://casa.tudominio.com/api
npm run build
npx cap sync android
npx cap open android          # Android Studio → Build → Build APK(s)
```

Requisitos: Node 20+, Android Studio con SDK 36 y JDK 21.

**Acceso:** al registrarse se pide nombre, celular y **código de la casa** (valor inicial: `HOUSE_CODE` del `.env`; luego se cambia desde Ajustes › Administración). Después, un admin aprueba la solicitud. Detalles en [SEGURIDAD.md](SEGURIDAD.md).

Para las notificaciones sigue [FIREBASE_NOTIFICACIONES.md](FIREBASE_NOTIFICACIONES.md).

### Desarrollo en el navegador

```bash
# Terminal 1 – API local (requiere MariaDB/MySQL local con la migración)
cd api && cp .env.example .env   # APP_ENV=development, PUBLIC_URL=http://localhost:8000
php -S 0.0.0.0:8000 -t public public/index.php

# Terminal 2 – App
cd app && echo "VITE_API_URL=http://localhost:8000/api" > .env && npm run dev
```

---

## 3. Referencia de la API

Base: `https://casa.tudominio.com/api` · Autenticación: `Authorization: Bearer <token>` (excepto las rutas públicas).

Todas las respuestas tienen la forma:

```json
{ "success": true,  "data": { ... }, "error": null }
{ "success": false, "data": null,    "error": { "code": "validation", "message": "Texto para mostrar", "fields": { "phone": "..." } } }
```

| Método | Ruta | Cuerpo | Respuesta (`data`) |
|---|---|---|---|
| GET | `/health` 🔓 | — | `{ ok, time }` |
| POST | `/register` 🔓 | `{ name, phone, device_id, house_code, platform? }` | 201 `{ status:"active", token, user }` (primer registro) · 202 `{ status:"pending", token }` |
| DELETE | `/register` 🔓 | — (token de solicitud) | `{ deleted }` |
| GET | `/me` | — | `User` |
| PUT | `/me` | `{ name?, phone? }` | `User` |
| DELETE | `/me` | — | `{ deleted }` |
| PUT | `/me/push-token` | `{ token \| null }` | `{ registered }` |
| GET | `/users` | — | `Contact[]` |
| GET | `/dogs` | — | `DogsToday` |
| POST | `/dogs/{id}/checks` | `{ type: "food"\|"water", done: bool }` | `DogsToday` |
| POST | `/dogs/{id}/photo` | multipart `photo` (JPG/PNG/WEBP ≤ 6 MB) | `DogsToday` |
| DELETE | `/dogs/{id}/photo` | — | `DogsToday` |
| GET | `/announcements?status=active\|done` | — | `Announcement[]` |
| GET | `/announcements/summary` | — | `{ active, urgent, unread, pending_requests }` |
| POST | `/announcements` | `{ title, body?, category, priority }` | `Announcement` (+ push) |
| POST | `/announcements/{id}/ack` | — | `Announcement` |
| PUT | `/announcements/{id}/done` | `{ done: bool }` | `Announcement` |
| DELETE | `/announcements/{id}` | — (autor o admin) | `{ deleted }` |
| GET | `/admin/requests` 🛡️ | — | `AccessRequest[]` |
| POST | `/admin/requests/{id}/approve` 🛡️ | — | `{ approved }` |
| POST | `/admin/requests/{id}/reject` 🛡️ | — | `{ rejected }` |
| POST | `/admin/users/{id}/revoke` 🛡️ | — | `{ revoked }` |
| PUT | `/admin/users/{id}/role` 🛡️ | `{ role: "admin"\|"member" }` | `{ role }` |
| DELETE | `/admin/users/{id}` 🛡️ | — | `{ deleted }` |
| PUT | `/admin/house-code` 🛡️ | `{ code }` (≥ 6) | `{ changed }` |
| GET | `/admin/audit` 🛡️ | — | `AuditEntry[]` (últimos 150) |

🔓 pública · 🛡️ solo admin · Un token de solicitud pendiente recibe `403 pending_approval` en cualquier ruta protegida.

Los tipos TypeScript equivalentes están en `app/src/types/api.ts`.

**Categorías** (`api/src/Categories.php` ↔ `app/src/lib/categories.ts`):
`general`, `compra`, `emergencia` (siempre urgente), `salud`, `mascotas`, `pago`, `tarea`, `mantenimiento`, `visita`, `evento`, `recordatorio`.

**Prioridades:** `normal` · `importante` · `urgente` (canal de notificación de máxima prioridad).

**Registro por dispositivo:**
- El primer registro de todos → administrador, sin aprobación.
- Cualquier otro (incluido un cambio de celular con un número existente) → solicitud pendiente hasta que un admin la apruebe. Al aprobar un cambio de celular, el anterior queda desconectado.
- Sesiones sin uso durante 6 meses se cierran solas.
- El token se guarda en BD solo como hash SHA-256; en el celular, cifrado con Android Keystore.

**Checklist diario:** no hay cron. Cada día empieza vacío porque se filtra por `check_date = CURDATE()` (zona horaria `APP_TIMEZONE`).

---

## 4. Diseño y accesibilidad

- **Letra grande y legible:** Atkinson Hyperlegible Next (diseñada para baja visión) para texto y Nunito para títulos, ambas dentro del APK (funcionan sin internet). Base de 18 px.
- **Se adapta al tamaño de letra:** todo está en `rem`; respeta el tamaño de fuente de Android y además hay un selector en Ajustes (Normal / Grande / Muy grande). Los botones se acomodan en columna cuando no caben.
- **Objetivos táctiles ≥ 48 px**, contrastes AA/AAA, modo oscuro automático, etiquetas `aria-*`, foco visible.
- **Botón atrás de Android:** cierra la hoja abierta → vuelve a Inicio → minimiza la app.
- **Animaciones** con Motion: transiciones entre pestañas, hojas que se deslizan desde abajo (y se cierran arrastrando), marcas con rebote, barra de avance, celebración al completar, banner urgente con pulso. Se desactivan si el celular tiene activado “Quitar animaciones” (`prefers-reduced-motion`).
- **Retroalimentación inmediata:** las marcas se ven al instante (optimista) y se revierten con aviso si falla la red; vibración corta al confirmar; skeletons al cargar; mensajes de error en lenguaje simple con botón “Intentar de nuevo”.
