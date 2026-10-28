# Seguridad de Casa

Qué protege la app, qué tienes que configurar tú en el servidor, en Cloudflare y en Firebase, y qué hacer si algo sale mal.

---

## 1. Qué ya hace la app

| Riesgo | Protección | Dónde |
|---|---|---|
| Un desconocido encuentra la URL y se registra | Pide el **código de la casa** y además la **aprobación de un admin** | `UserController::register`, `AdminController` |
| Alguien usa el número de otro familiar para quedarse con su cuenta | Un celular nuevo con un número existente queda **pendiente**; el admin ve el aviso *“Dice ser Mamá con un celular nuevo”* | `access_requests.existing_user_id` |
| Probar códigos de la casa a lo bruto | Máx. **5 códigos incorrectos por IP cada 15 min** y **5 solicitudes por IP por hora** | `Security::assertNotLimited` |
| Celular perdido o robado | El admin cierra su sesión desde **Contactos › ⋮** | `POST /admin/users/{id}/revoke` |
| Celulares abandonados con sesión abierta | La sesión se cierra sola tras **6 meses sin abrir la app** | `Security::SESSION_MAX_IDLE_DAYS` |
| Robo del token desde el celular | El token se guarda **cifrado con Android Keystore**, sin respaldo en Google Drive ni copia a otro celular | `secureStorage`, `data_extraction_rules.xml` |
| Volcado o robo de la base de datos | Solo se guardan **hashes**: SHA-256 de los tokens y `password_hash` del código de la casa | `Auth`, `Security::setHouseCode` |
| Que nadie se entere de un ataque | **Historial de actividad** visible para admins (intentos fallidos, bloqueos, aprobaciones, eliminaciones) | `audit_log`, Ajustes › Administración |
| Quedarse sin admin | La API no deja que el último admin salga, se elimine ni se quite el rol | `AdminController::assertNotLastAdmin` |
| Subir archivos maliciosos como “foto” | Valida el tipo real del archivo (`finfo` + `getimagesize`), nombre aleatorio y Nginx no ejecuta nada en `/uploads/` | `DogController`, `nginx-casa.conf` |

### Cómo funciona el acceso

1. **El primero que se registra queda como admin.** Regístrate tú **en cuanto despliegues**, antes de pasar el APK a nadie.
2. Los demás escriben nombre, celular y código de la casa → ven *“Le avisamos al administrador”*.
3. Los admins reciben una notificación push 🔐 y aprueban en **Ajustes › Administración**.
4. La app de la persona aprobada se abre sola (revisa cada 10 s).

**Recomendado:** nombra a un **segundo admin** (Contactos › ⋮ › *Hacer administrador*) para que alguien pueda aprobar si tú no estás.

**Si alguien pide entrar como cambio de celular, llámale al número de siempre antes de aprobar.** Es la única forma de confirmar que de verdad es esa persona.

---

## 2. Servidor: aislar Casa de tus otros sistemas

Como en el servidor corren otros servicios importantes, Casa debe tener **su propio usuario de sistema y su propio pool de PHP-FPM**. Así, una falla en Casa no puede leer los archivos de tus otros sistemas.

```bash
# 1. Usuario sin login ni carpeta personal
sudo adduser --system --group --no-create-home casa

# 2. Permisos: el código es de root y solo lo lee casa; casa escribe únicamente fotos y caché
sudo chown -R root:casa /var/www/casa/api
sudo find /var/www/casa/api -type d -exec chmod 750 {} \;
sudo find /var/www/casa/api -type f -exec chmod 640 {} \;
sudo chown -R casa:casa /var/www/casa/api/public/uploads /var/www/casa/api/storage
sudo chmod 755 /var/www/casa/api/public /var/www/casa/api/public/uploads /var/www/casa/api/public/uploads/dogs
#    (Nginx, como www-data, necesita leer las fotos)
sudo usermod -aG casa www-data          # Nginx necesita llegar a public/

# 3. Llave de Firebase solo legible por casa
sudo chown root:casa /etc/casa/firebase-service-account.json
sudo chmod 640 /etc/casa/firebase-service-account.json

# 4. Logs propios
sudo mkdir -p /var/log/casa && sudo chown casa:casa /var/log/casa

# 5. Pool de PHP-FPM y Nginx
sudo cp deploy/php-fpm-casa.conf /etc/php/8.2/fpm/pool.d/casa.conf
sudo systemctl reload php8.2-fpm
sudo nginx -t && sudo systemctl reload nginx
```

El pool (`deploy/php-fpm-casa.conf`) además:

- limita PHP a `/var/www/casa/api`, `/etc/casa` y `/tmp` con `open_basedir`;
- prohíbe ejecutar comandos del sistema (`exec`, `system`, `shell_exec`…);
- usa `pm = ondemand`, así que no consume RAM cuando nadie usa la app;
- limita la memoria a 64 MB por petición.

### Revisiones rápidas

```bash
# MariaDB solo debe escuchar en localhost
sudo ss -tlnp | grep 3306          # → 127.0.0.1:3306, NO 0.0.0.0:3306

# Nginx de Casa solo en localhost
sudo ss -tlnp | grep 8085          # → 127.0.0.1:8085

# No mostrar la versión de PHP
grep -n "^expose_php" /etc/php/8.2/fpm/php.ini   # debe decir: expose_php = Off

# El usuario de la BD no puede modificar tablas
sudo mariadb -e "SHOW GRANTS FOR 'casa_user'@'localhost';"
#   → solo SELECT, INSERT, UPDATE, DELETE
```

> Las migraciones (`001`, `002`, …) se corren como **root** (`sudo mariadb casa < …`), nunca con `casa_user`.

---

## 3. Cloudflare

Panel de Cloudflare → tu dominio.

### 3.1 SSL/TLS
- **SSL/TLS › Edge Certificates**
  - *Always Use HTTPS*: **On**
  - *Minimum TLS Version*: **TLS 1.2**
  - *HSTS*: actívalo (max-age 6 meses, incluye subdominios solo si **todos** tus subdominios tienen HTTPS).

### 3.2 Reglas WAF (Security › WAF › Custom rules)

El plan gratuito permite 5 reglas personalizadas. Crea estas, en este orden:

**Regla 1: solo las rutas de la app**
- Nombre: `Casa - solo api y uploads`
- Expresión (*Edit expression*):
  ```
  (http.host eq "casa.tudominio.com" and not starts_with(http.request.uri.path, "/api/") and not starts_with(http.request.uri.path, "/uploads/"))
  ```
- Acción: **Block**

**Regla 2: solo desde México** (opcional, pero corta casi todos los ataques automatizados)
- Nombre: `Casa - solo MX`
- Expresión:
  ```
  (http.host eq "casa.tudominio.com" and ip.src.country ne "MX")
  ```
- Acción: **Block**
- ⚠️ Si alguien de la familia viaja al extranjero, la app no le funcionará ahí. Puedes desactivar la regla temporalmente o agregar el país: `not ip.src.country in {"MX" "US"}`. Las notificaciones push sí le llegarán (salen de Firebase, no de tu servidor).

**No uses *Managed Challenge* ni *JS Challenge* en `casa.tudominio.com`.** La app no es un navegador y no puede resolverlos; se rompería. Por lo mismo, si activas *Bot Fight Mode*, prueba la app justo después.

### 3.3 Rate limiting (Security › WAF › Rate limiting rules)

El plan gratuito permite 1 regla, con ventana de 10 s:

- Nombre: `Casa - registro`
- Expresión:
  ```
  (http.host eq "casa.tudominio.com" and http.request.uri.path eq "/api/register" and http.request.method eq "POST")
  ```
- Mismas características: **IP**
- Cuando se superen: **5 peticiones / 10 segundos**
- Acción: **Block** durante 10 segundos

Esto frena ráfagas antes de que lleguen a tu servidor. La API además aplica sus propios límites de 15 min y 1 h.

### 3.4 SSH a través del túnel

Si tu SSH también pasa por Cloudflare Tunnel, protégelo con **Cloudflare Access** (Zero Trust › Access › Applications › *Self-hosted*), con una política que permita solo tu correo. Así, para llegar a SSH primero hay que iniciar sesión en Cloudflare con tu cuenta.

**No pongas Access frente a `casa.tudominio.com`**: la app no puede iniciar sesión en Access y dejaría de funcionar.

---

## 4. Firebase: llave con el mínimo permiso

La clave “Firebase Admin SDK” que se genera en la consola de Firebase tiene permisos de administrador sobre todo el proyecto. Casa solo necesita **mandar notificaciones**, así que crea una cuenta de servicio dedicada:

1. Entra a <https://console.cloud.google.com> y selecciona el **mismo proyecto** que creaste en Firebase (arriba a la izquierda).
2. Menú ☰ → **IAM y administración** → **Cuentas de servicio** → **+ Crear cuenta de servicio**.
3. Nombre: `casa-push`. Descripción: *Envío de notificaciones de la app Casa*. → **Crear y continuar**.
4. **Rol**: busca y elige **Firebase Cloud Messaging API Admin** (`roles/firebasecloudmessaging.admin`). No agregues ningún otro. → **Continuar** → **Listo**.
5. Abre la cuenta `casa-push` → pestaña **Claves** → **Agregar clave** → **Crear clave nueva** → **JSON** → **Crear**.
6. Sube ese JSON al servidor como indica `FIREBASE_NOTIFICACIONES.md` (paso 5), en `/etc/casa/firebase-service-account.json`.

Si antes generaste una clave del **Firebase Admin SDK**:

- En **Cuentas de servicio** abre `firebase-adminsdk-…` → pestaña **Claves** → **borra** la clave que creaste.
- No borres la cuenta en sí; Firebase la usa internamente.

**Rotación:** crea una clave nueva una vez al año (o de inmediato si sospechas una filtración), súbela al servidor y borra la anterior en la consola.

> El `google-services.json` que va dentro del APK **no es secreto**: identifica tu proyecto, pero no permite enviar notificaciones. La llave secreta es solo el JSON de la cuenta de servicio, que nunca sale del servidor.

---

## 5. Buenas prácticas de uso

- **APK firmado de release.** Guarda el `.jks` y su contraseña en un lugar seguro (por ejemplo, un gestor de contraseñas). Sin ese archivo no podrás publicar actualizaciones que se instalen encima de la versión anterior.
- **Comparte el APK solo con la familia**, por mensaje directo y no en grupos grandes.
- **Cambia el código de la casa** (Ajustes › Administración) si crees que alguien de fuera lo conoce. Quienes ya usan la app no se ven afectados.
- **Revisa el historial** de vez en cuando. Si ves muchos *“Intento de registro con código incorrecto”*, cambia el código.

---

## 6. Si pasa algo

| Situación | Qué hacer |
|---|---|
| Un familiar perdió su celular | Contactos › ⋮ › **Cerrar sesión de su celular**. Cuando tenga otro, pide acceso y lo apruebas. |
| Aprobaste a alguien por error | Contactos › ⋮ › **Eliminar de la casa**, y cambia el código de la casa. |
| Muchos intentos fallidos en el historial | Cambia el código de la casa y activa la regla “solo MX” en Cloudflare. |
| Se filtró la llave de Firebase | En Google Cloud borra esa clave, crea una nueva (sección 4) y súbela al servidor. |
| Te quedaste sin acceso de admin (perdiste tu celular y no hay otro admin) | Regístrate desde el celular nuevo y apruébate por SSH (ver abajo). |

### Recuperación por SSH

Para cuando ningún admin puede entrar a la app:

```bash
cd /var/www/casa/api
sudo -u casa php bin/casa-admin.php solicitudes            # ver solicitudes pendientes
sudo -u casa php bin/casa-admin.php aprobar 12             # aprobar la #12
sudo -u casa php bin/casa-admin.php hacer-admin 5512345678 # dar rol de admin a un miembro
sudo -u casa php bin/casa-admin.php miembros               # lista con rol y última conexión
```

Todo lo que hagas así queda en el historial como *“Consola del servidor”*.
