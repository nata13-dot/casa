# Notificaciones push con Firebase

Guía para que la app **Casa** reciba notificaciones en el celular cuando alguien publica un aviso.

**Cómo funciona:**

```
Celular de Ana ──(publica aviso)──▶ API PHP (tu servidor)
                                        │  firma un JWT con la cuenta de servicio
                                        ▼
                               Firebase Cloud Messaging (FCM)
                                        │
                     ┌──────────────────┼──────────────────┐
                     ▼                  ▼                  ▼
               Celular Carlos     Celular Mamá       Celular ...
```

- La **app** obtiene un *token FCM* y lo manda a la API (`PUT /api/me/push-token`).
- La **API** guarda ese token en `users.fcm_token` y, al crearse un aviso, envía la notificación a todos menos al autor (`api/src/Fcm.php`).
- Los avisos **urgentes** (y todas las emergencias) usan un canal de Android de prioridad máxima: suenan y aparecen arriba de la pantalla aunque el celular esté bloqueado.

Tiempo estimado: 20–30 minutos.

---

## 1. Crear el proyecto en Firebase

1. Entra a <https://console.firebase.google.com> con tu cuenta de Google.
2. Haz clic en **Crear un proyecto** (o **Agregar proyecto**).
3. Nombre: `Casa` (o el que quieras). Haz clic en **Continuar**.
4. **Google Analytics**: no hace falta. Desactívalo y haz clic en **Crear proyecto**.
5. Espera a que termine y haz clic en **Continuar**.

## 2. Registrar la app de Android

1. En la página principal del proyecto, haz clic en el ícono de **Android** (“Agregar app”).
2. **Nombre del paquete de Android**: escribe exactamente

   ```
   com.familia.casa
   ```

   > Debe coincidir con `appId` en `app/capacitor.config.ts` y con `applicationId` en `app/android/app/build.gradle`.
   > Si lo cambias, cámbialo en los tres lugares **antes** de compilar.

3. **Sobrenombre**: `Casa Android` (opcional).
4. **Certificado SHA-1**: déjalo vacío. FCM no lo necesita.
5. Haz clic en **Registrar app**.

## 3. Descargar `google-services.json`

1. Haz clic en **Descargar google-services.json**.
2. Copia el archivo a:

   ```
   Casa/app/android/app/google-services.json
   ```

3. En los pasos siguientes del asistente (“Agregar el SDK de Firebase”), haz clic en **Siguiente** sin cambiar nada: el proyecto de Capacitor ya tiene configurado el plugin `com.google.gms.google-services` y solo lo activa cuando encuentra este archivo (`app/android/app/build.gradle`).
4. Haz clic en **Ir a la consola**.

> `google-services.json` ya está en `.gitignore`. No es secreto como tal, pero no hace falta subirlo al repositorio.

## 4. Verificar que la API de Cloud Messaging (V1) esté activa

1. En la consola, haz clic en el engrane ⚙️ → **Configuración del proyecto**.
2. Abre la pestaña **Cloud Messaging**.
3. En **API de Firebase Cloud Messaging (V1)** debe decir **Habilitada**.
   - Si dice *Inhabilitada*, haz clic en los tres puntos ⋮ → **Administrar API en Google Cloud Console** → **Habilitar**.

> La “API heredada” (*legacy*, con *server key*) ya no existe. Este proyecto usa solo la V1.

## 5. Crear la cuenta de servicio (la “llave” del servidor)

Esta llave permite que **tu servidor** mande notificaciones. Es **secreta**: nunca la pongas dentro de la app ni en el repositorio.

> **Recomendado:** en lugar de la clave “Firebase Admin SDK” (que tiene permisos de administrador sobre todo el proyecto), crea una cuenta de servicio que **solo pueda enviar notificaciones**. Los pasos están en [SEGURIDAD.md › Firebase](SEGURIDAD.md#4-firebase-llave-con-el-mínimo-permiso). El JSON resultante se usa exactamente igual que aquí.

Si prefieres lo rápido (menos seguro):

1. **Configuración del proyecto** → pestaña **Cuentas de servicio**.
2. Asegúrate de que esté seleccionado **Firebase Admin SDK**.
3. Haz clic en **Generar nueva clave privada** → **Generar clave**.
4. Se descarga un archivo `.json` (algo como `casa-1a2b3-firebase-adminsdk-xxxx.json`).

### Opción A: pegar los valores en `api/.env` (la más sencilla)

Abre el `.json` descargado con un editor de texto y copia 3 valores a `api/.env`:

```ini
FCM_PROJECT_ID=casa-1a2b3                                   # "project_id"
FCM_CLIENT_EMAIL=casa-push@casa-1a2b3.iam.gserviceaccount.com  # "client_email"
FCM_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\nMIIEv...\n-----END PRIVATE KEY-----\n"  # "private_key"
```

- `FCM_PRIVATE_KEY` se copia **completa y tal cual** del JSON: es una sola línea larga con `\n` escritos, entre comillas dobles. No la partas en varias líneas.
- Si usas esta opción, no necesitas subir el `.json` al servidor; bórralo cuando termines.

### Opción B: subir el archivo `.json` al servidor

Desde tu computadora:

```bash
scp casa-*-firebase-adminsdk-*.json usuario@tu-servidor:/tmp/firebase.json
```

En el servidor (por SSH):

```bash
sudo mkdir -p /etc/casa
sudo mv /tmp/firebase.json /etc/casa/firebase-service-account.json
sudo chown root:casa /etc/casa/firebase-service-account.json   # usuario del pool PHP de Casa (SEGURIDAD.md §2)
sudo chmod 640 /etc/casa/firebase-service-account.json
```

En `api/.env` (deja vacíos `FCM_PROJECT_ID`, `FCM_CLIENT_EMAIL` y `FCM_PRIVATE_KEY`):

```ini
FCM_SERVICE_ACCOUNT=/etc/casa/firebase-service-account.json
```

La API guarda el *access token* de Google durante ~55 minutos en `api/storage/cache/`; esa carpeta debe poder escribirla PHP:

```bash
sudo chown -R casa:casa /var/www/casa/api/storage
```

Borra el `.json` de tu carpeta de descargas cuando termines.

## 6. Ícono de la notificación (recomendado)

Android pinta el ícono pequeño de las notificaciones en un solo color. Si usas el ícono normal de la app, se verá como un **cuadro blanco**.

1. Abre el proyecto en Android Studio: `cd app && npx cap open android`.
2. Clic derecho en `app` → **New** → **Image Asset**.
3. **Icon Type**: *Notification Icons*. **Name**: `ic_stat_casa`.
4. Elige un clip art (por ejemplo, una casa) → **Next** → **Finish**.
5. En `app/android/app/src/main/AndroidManifest.xml`, dentro de `<application>`, junto al `meta-data` del canal que ya existe, agrega:

   ```xml
   <meta-data
       android:name="com.google.firebase.messaging.default_notification_icon"
       android:resource="@drawable/ic_stat_casa" />
   <meta-data
       android:name="com.google.firebase.messaging.default_notification_color"
       android:resource="@color/colorAccent" />
   ```

   Si `@color/colorAccent` no existe, crea `res/values/colors.xml` con `<color name="colorAccent">#B5481F</color>`.

## 7. Compilar e instalar el APK

```bash
cd Casa/app
cp .env.example .env.production     # y llena VITE_API_URL
npm install
npm run build
npx cap sync android
npx cap open android
```

En Android Studio: **Build** → **Build App Bundle(s) / APK(s)** → **Build APK(s)**.
El APK queda en `app/android/app/build/outputs/apk/debug/app-debug.apk`. Pásalo a los celulares de la familia (WhatsApp, cable, Drive) e instálalo.

> Para un APK firmado de “release”: **Build** → **Generate Signed App Bundle / APK** → **APK**. Guarda bien el archivo `.jks` y su contraseña: lo necesitarás para cada actualización.

## 8. Probar

1. Abre la app, regístrate y **acepta el permiso de notificaciones** (Android 13+ lo pide).
2. En **Ajustes** de la app debe decir: *Notificaciones: Activadas*.
3. Revisa en el servidor que se guardó el token:

   ```bash
   mysql -u casa_user -p casa -e "SELECT name, fcm_token IS NOT NULL AS tiene_token FROM users;"
   ```

4. **Prueba desde Firebase** (sin la API):
   - Consola → **Messaging** (menú *Ejecutar*) → **Crear la primera campaña** → **Mensajes de Firebase Notifications**.
   - Escribe título y texto → **Enviar mensaje de prueba**.
   - Pega el token FCM (`SELECT fcm_token FROM users WHERE name = 'Tu nombre';`) → **Probar**.
5. **Prueba real**: con dos celulares registrados, publica un aviso en uno. El otro debe recibirlo en segundos. Tocar la notificación abre la app en **Avisos**.

## 9. Si algo falla

| Síntoma | Causa probable | Solución |
|---|---|---|
| La app se cierra al abrir | Falta `google-services.json` y el plugin de push intenta registrarse | Copia el archivo (paso 3) y vuelve a correr `npx cap sync android` |
| *Notificaciones: Desactivadas* | Se negó el permiso | Ajustes del celular → Apps → Casa → Notificaciones → Permitir |
| `tiene_token = 0` | La app no pudo llamar a la API | Revisa `VITE_API_URL` y que `https://…/api/health` responda |
| Llega a Firebase pero no a la API | Ruta o permisos del JSON | `sudo -u casa cat /etc/casa/firebase-service-account.json` debe funcionar |
| Error en log: `invalid_grant` | Reloj del servidor desfasado o llave revocada | `timedatectl` (activa NTP) o genera una nueva clave |
| Error en log: `SENDER_ID_MISMATCH` | `google-services.json` de otro proyecto | Descarga el del mismo proyecto que la cuenta de servicio |
| Notificación como cuadro blanco | Falta ícono monocromático | Paso 6 |
| No llega con la pantalla apagada (Xiaomi, Huawei, Oppo, Samsung) | Ahorro de batería agresivo | Ajustes → Batería → Casa → **Sin restricciones** y activar **Inicio automático** |

Los errores de envío se escriben en el log de PHP-FPM con el prefijo `[FCM]`:

```bash
sudo grep FCM /var/log/casa/php-error.log | tail -20
```

La API limpia sola los tokens inválidos (app desinstalada o datos borrados): cuando Firebase responde `UNREGISTERED`, pone `fcm_token = NULL`.

## 10. Tonos, prueba rápida y recordatorios de los perritos (v1.2)

**Tonos.** Cada persona elige en *Ajustes › Notificaciones* el tono de cada tipo:

| Tipo | Tono por defecto | Ícono |
|---|---|---|
| Avisos | Campanita | casa con huellita |
| Urgentes | Alarma | campana con "!" |
| Perritos | Silbido | huella con corazón |

Los tonos van dentro del APK (`res/raw/tono_*.ogg`). Android no deja cambiar el sonido de un canal ya creado, así que la app crea un canal por tipo y tono, por ejemplo `avisos_campanita`, y borra el anterior. La API manda cada notificación al canal del tono que eligió esa persona (`users.notify_prefs`).

**Probar.** Usa cualquiera de estas dos:
- En el celular: *Ajustes › Notificaciones › Enviar notificación de prueba*. Si falla, muestra el motivo exacto que da Firebase.
- En el servidor:
  ```bash
  cd /var/www/casa/api
  sudo -u casa php bin/casa-admin.php diagnostico          # revisa llaves, curl/openssl y quién tiene celular registrado
  sudo -u casa php bin/casa-admin.php probar-push 5512345678
  ```

**Recordatorios de los perritos.** `bin/recordatorios.php` corre cada 5 minutos:
- Desde las **10:00**, mientras falte por marcar la comida (o la pastilla de Mushu) de la *Mañana*.
- Desde las **21:30**, lo mismo con la de la *Noche*.

Se detiene en cuanto alguien lo marca en la app. Cada recordatorio reemplaza al anterior, así que no se amontonan. Cada persona los puede apagar en Ajustes. Las horas se cambian en `.env` con `RECORDATORIO_MANANA=10:00` y `RECORDATORIO_NOCHE=21:30`.

Instalar el timer, una sola vez (`deploy/subir_api.sh` ya lo hace solo):

```bash
sudo cp /var/www/casa/api/deploy/casa-recordatorios.* /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now casa-recordatorios.timer
systemctl list-timers casa-recordatorios.timer     # próxima ejecución
journalctl -u casa-recordatorios -n 20             # qué envió
sudo -u casa php bin/recordatorios.php --probar    # enviar uno ya, sin esperar la hora
```
