# APPsanz&ac

Portal contable que conecta a **SANZ & AC** con sus clientes. Integra la recepción de documentos, la contabilización automática de comprobantes electrónicos SUNAT, el chat de consultas, los procesos contable/tributario/RRHH/tesorería y los reportes de gestión R1–R12 descritos en la propuesta (`image003.png`).

El proyecto tiene **dos aplicaciones independientes**:

| Carpeta | Tecnología | Rol |
|---|---|---|
| `backend/` | Laravel 12 · PHP 8.4 · MySQL/MariaDB · Sanctum (tokens) · spatie/permission · maatwebsite/excel · dompdf · SDK de Anthropic (opcional) | API REST `/api/v1` |
| `frontend/` | React 19 · Vite · TypeScript · Tailwind CSS · TanStack Query · React Router · Recharts | SPA del portal (clientes y equipo interno) |

---

## Cómo se implementa la propuesta

| Propuesta | Implementación |
|---|---|
| **Acceso APP · Externo** (usuario 1..N del cliente, login/clave) | Usuarios `externo` vinculados a un cliente. La **coordinación previa** se registra en el cliente (contrato, n.º máximo de usuarios, notas de acceso), y cada usuario tiene permisos acordados: *ingresar documentos*, *chat* y *ver informes*. |
| **Acceso APP · Interno** (Socio, Supervisor, Analistas) | Roles `socio`, `supervisor` y `analista`. El analista ve solo los clientes asignados; el supervisor y el socio ven todos. |
| **C1 · Ingresa documentos → BD.1** | Carga múltiple (XML, ZIP de CPE, PDF, imágenes, Excel) con detección de duplicados por hash y trazabilidad de estados: ingresado → en proceso → procesado / observado / devuelto → reingresado. |
| **C2 · Chat de consultas ↔ BD.1** | Hilos de consulta por cliente con adjuntos, asignación, prioridad, estados y control de lectura. |
| **S1 · Procesa documentos + otras fuentes** | Lectura automática del **XML UBL 2.1 de SUNAT** (factura, boleta, NC y ND); extracción opcional de PDF e imágenes con IA; validaciones (dígito verificador del RUC, cuadre de importes, IGV 18 %, cliente correcto, duplicados y periodo cerrado); registro en compras/ventas con **asiento contable automático** (60/40/42 y 12/40/70; las NC se invierten). Importación masiva desde Excel/CSV para "otras fuentes". |
| **S2 · Respuestas a consultas** | Bandeja interna con métricas de primera respuesta y seguimiento. |
| **S3 · Proceso contable + tributario + RRHH + tesorería** | Tablero kanban de actividades por cliente y periodo, con una plantilla mensual (validación, conciliaciones, libros, impuestos, planillas, tesorería, EEFF, informes). Incluye asientos manuales y de ajuste, cierre de periodos, cuentas por cobrar y por pagar, estimado de IGV y renta, y registro de pagos. |
| **BD.2 · R5–R8** | Libros (Registro de Compras y de Ventas, Diario, Mayor), Balance de comprobación, Situación financiera, Resultados y EEFF anual, generados en línea desde la contabilidad. Exportan a Excel, PDF y **PLE SUNAT** (8.1 / 14.1). El flujo es borrador → revisado → **publicado al cliente**. |
| **BD.3 · R1–R4, R9–R12** | Accesos, horas hombre, % de documentos devueltos y reingresados, % de chats contestados, papeles de trabajo, % de cumplimiento de R5–R8, comunicaciones internas con confirmación de lectura, y capacitación y recomendaciones. |
| **Reportes APP** | Visor único de R1–R12 con filtros por cliente y fechas, gráficos y exportación a Excel y PDF. El cliente ve R1, R3, R4 y R5–R8 de su empresa. |

---

## Requisitos

- PHP 8.2 o superior con las extensiones `pdo_mysql`, `mbstring`, `intl`, `gd`, `zip`, `fileinfo` y `dom`
- Composer 2
- MySQL 8 o MariaDB 10.4 o superior (por ejemplo, el de XAMPP)
- Node.js 20 o superior y npm

## Instalación

### 1. Base de datos

```bash
mysql -u root -e "CREATE DATABASE appsanzac CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE appsanzac_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 2. Backend (API)

```bash
cd backend
composer install
cp .env.example .env        # ajustar DB_* si corresponde
php artisan key:generate
php artisan migrate --seed  # en APP_ENV=local carga también los datos de demostración
php artisan serve           # http://127.0.0.1:8000
```

### 3. Frontend (SPA)

```bash
cd frontend
npm install
cp .env.example .env
npm run dev                 # http://localhost:5173 (proxy /api -> :8000)
```

Para producción, ejecute `npm run build` y publique `frontend/dist` en cualquier hosting estático. Defina `VITE_API_URL` con la URL pública de la API, y en el backend ajuste `FRONTEND_URL` y `CORS_ALLOWED_ORIGINS`.

## Usuarios de demostración

Todos usan la clave **`Demo2026!`** (definida en `backend/database/seeders/DemoSeeder.php`). Cámbiela o elimine estos usuarios antes de producción.

| Acceso | Usuario | Rol |
|---|---|---|
| Interno | `socio@sanzac.pe` | Socio |
| Interno | `supervisor@sanzac.pe` | Supervisor |
| Interno | `analista@sanzac.pe`, `analista2@sanzac.pe`, `analista3@sanzac.pe` | Analistas |
| Externo | `cliente@andina.pe`, `contabilidad@andina.pe` | Comercial Andina S.A.C. |
| Externo | `cliente@pacifico.pe` | Inversiones Pacífico E.I.R.L. |
| Externo | `cliente@sertecsur.pe` / `tesoreria@sertecsur.pe` (solo puede ingresar documentos) | Servicios Técnicos del Sur S.A. |

En `backend/storage/app/private/samples/` hay XML de prueba (factura de compra, factura de venta y nota de crédito) para subirlos desde **Mis documentos** con el usuario `cliente@andina.pe`.

## Extracción con IA (opcional)

Los XML se procesan sin IA. Para extraer los datos de PDF e imágenes:

```dotenv
AI_EXTRACTION_ENABLED=true
ANTHROPIC_API_KEY=su-api-key
ANTHROPIC_MODEL=claude-opus-5-5
```

El servicio `App\Services\Ai\InvoiceExtractor` usa el SDK oficial de Anthropic con salida estructurada (JSON Schema). Los datos extraídos pasan por las mismas validaciones que el XML. Si la IA no puede procesar el documento, este queda **observado** para que el analista lo registre a mano.

## Pruebas

```bash
cd backend && php artisan test     # usa la BD appsanzac_test
cd frontend && npm run build && npx eslint src
```

## Estructura principal

```
backend/
  app/Services/Sunat/           Parser UBL 2.1 y validador de RUC
  app/Services/Documents/       Procesamiento S1 (XML / IA / manual)
  app/Services/Accounting/      Comprobantes, asientos, libros, EEFF, informes R5-R7
  app/Services/Reports/         Reportes R1-R12
  app/Services/Exports/         PLE SUNAT, Excel y PDF
  app/Http/Controllers/Api/     Endpoints REST /api/v1
  database/migrations/          Esquema (BD.1, BD.2, BD.3)
  database/seeders/             Roles, plan contable PCGE y datos demo
frontend/
  src/pages/                    Pantallas por módulo (documentos, consultas, libros, procesos, reportes, BD.3, administración)
  src/components/               UI reutilizable y layout
  src/lib/ · src/hooks/         Cliente API, tipos y utilidades
```

## Notas

- Los archivos se guardan en `backend/storage/app/private` y se descargan solo por endpoints autenticados que validan el acceso al cliente.
- El procesamiento de documentos se ejecuta después de responder la petición (`dispatchAfterResponse`), así que no hace falta un *worker*. Para volúmenes altos, cambie a `dispatch()` y ejecute `php artisan queue:work`.
- Los reportes y la contabilidad se calculan en soles. Los comprobantes en moneda extranjera se convierten con su tipo de cambio.
