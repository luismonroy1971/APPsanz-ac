# Despliegue en Hostinger (hosting compartido) con GitHub Actions

Cada **push a `main`** ejecuta las pruebas, compila el frontend, empaqueta Laravel y lo despliega por SSH en Hostinger. También se puede lanzar a mano desde **Actions → CI y despliegue a Hostinger → Run workflow**.

## Estructura en el servidor

```
~/domains/TU-DOMINIO/
├── appsanzac/        Laravel (fuera de la carpeta pública: código, vendor, storage, .env)
└── public_html/      Frontend React + laravel.php + .htaccess
                      /api/* y /up  -> laravel.php -> Laravel
                      todo lo demás -> index.html (SPA)
```

## 1. Hostinger (hPanel)

1. **PHP**: en *Avanzado → Configuración de PHP*, elija **PHP 8.4** (el `composer.lock` requiere PHP ≥ 8.4.1) y verifique que estén activas las extensiones `intl`, `gd`, `zip`, `mbstring`, `pdo_mysql`, `fileinfo` y `dom`.
2. **Base de datos**: en *Bases de datos → Administración*, cree una base MySQL con su usuario y contraseña. Anote los tres valores, que tienen la forma `u123456789_xxx`.
3. **SSH**:
   - En *Avanzado → Acceso SSH*, active SSH.
   - En **Claves SSH → Agregar clave SSH**, pegue la clave pública `~/.ssh/appsanzac_deploy.pub`.
   - Anote la **IP**, el **puerto** (normalmente `65002`) y el **usuario** (`u123456789`).
4. **SSL**: active el certificado SSL gratuito del dominio. El `.htaccess` redirige todo a HTTPS.

## 2. GitHub (Settings → Secrets and variables → Actions)

**Secrets**

| Nombre | Valor |
|---|---|
| `SSH_HOST` | IP del servidor (hPanel → Acceso SSH) |
| `SSH_PORT` | `65002` |
| `SSH_USER` | `u123456789` |
| `SSH_PRIVATE_KEY` | Contenido completo de la clave privada `~/.ssh/appsanzac_deploy`, incluidas las líneas `-----BEGIN…` y `-----END…` |
| `DB_DATABASE` | Nombre de la base de datos |
| `DB_USERNAME` | Usuario de la base de datos |
| `DB_PASSWORD` | Contraseña de la base de datos (sin comillas dobles) |
| `ANTHROPIC_API_KEY` | *(Opcional)* Activa la extracción de PDF e imágenes con IA |

**Variables**

| Nombre | Valor |
|---|---|
| `APP_DOMAIN` | Su dominio, sin `https://` ni `www`. Ejemplo: `sanzac.pe` |
| `SEED_DEMO` | `true` para cargar los datos de demostración en el primer despliegue; `false` para empezar vacío |
| `PHP_BIN` | *(Opcional)* Ruta del PHP de línea de comandos. Por defecto `/opt/alt/php84/usr/bin/php` |
| `DB_HOST` | *(Opcional)* Por defecto `localhost` |

## 3. Qué hace cada despliegue

1. **test**: corre `php artisan test` contra MySQL 8, `eslint` y `npm run build`. Si algo falla, no se despliega.
2. **deploy**:
   - Ejecuta `composer install --no-dev` y genera el build de React con `VITE_API_URL=/api/v1`.
   - Genera el `.env` de producción a partir de los secrets, conservando el `APP_KEY` que ya existe en el servidor.
   - Sube los paquetes por `scp` y ejecuta `deploy/hostinger/remote-deploy.sh` en el servidor, que hace:
     - poner el sitio en mantenimiento (`down`);
     - `migrate --force`;
     - la carga inicial de datos, **solo la primera vez**;
     - `config:cache`, `route:cache` y `view:cache`;
     - volver a poner el sitio en línea (`up`);
     - copiar el frontend a `public_html`.
   - Verifica que `https://dominio/up` y la página principal respondan.

## Notas

- Los documentos subidos y el `.env` viven en `~/domains/TU-DOMINIO/appsanzac`. Ningún despliegue los borra.
- Para repetir la carga inicial, borre `appsanzac/storage/app/.seeded` en el servidor. Ojo: si la base ya tiene datos, esto duplica registros.
- Si usó datos demo, la clave de todos los usuarios es `Demo2026!`. Cámbiela antes de entregar el sistema.
- Errores de la aplicación: `appsanzac/storage/logs/laravel-AAAA-MM-DD.log`.
