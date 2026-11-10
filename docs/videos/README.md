# Videos demostrativos por perfil de usuario

Recorridos grabados sobre la aplicación real con los datos de demostración. Cada video muestra el cursor y subtítulos que explican cada paso con su código de la propuesta (C1, S1, R3…).

| Video | Perfil | Usuario demo | Contenido |
|---|---|---|---|
| `01-perfil-cliente.webm` | Cliente (acceso externo) | `cliente@andina.pe` | Login externo, panel, **envío de XML SUNAT y procesamiento automático**, detalle y trazabilidad, documento devuelto, nueva consulta (C2), libros e informes publicados (R5–R8), reportes R1/R3/R4 |
| `02-perfil-cliente-limitado.webm` | Cliente con permisos limitados | `tesoreria@sertecsur.pe` | Menú según permisos acordados en la coordinación previa; bloqueo del chat también en la API |
| `03-perfil-analista.webm` | Analista (acceso interno) | `analista@sanzac.pe` | Bandeja S1, documento bancario procesado, **registro manual de PDF con validaciones y asiento**, devolución al cliente, respuesta de consultas (S2), comprobantes y tesorería, importación Excel, asiento manual, libros/EEFF/impuestos, tareas S3, horas, papeles de trabajo, comunicaciones |
| `04-perfil-supervisor.webm` | Supervisor | `supervisor@sanzac.pe` | Seguimiento semanal R1/R2, R3/R4, aprobación de horas, revisión de entregables, **generación y publicación de EEFF al cliente**, balance de comprobación y cierre de periodo, revisión de papeles de trabajo, alta de usuario externo con permisos |
| `05-perfil-socio.webm` | Socio | `socio@sanzac.pe` | Visión global, reportes R10/R9/R11/R12/R5, directiva interna, capacitación, alta de cliente con coordinación previa y asignación de equipo, documentos internos |

Los archivos `.webm` se pueden ver en cualquier navegador (Chrome, Edge, Firefox) o en VLC.

## Volver a grabarlos

Con el backend (`:8000`) y el frontend (`:5173`) en ejecución:

```bash
cd backend && php artisan migrate:fresh --seed     # datos limpios
cd ../tools/video-demo
npm install && npm run setup                        # playwright-core + ffmpeg
npm run record                                      # graba los 5 perfiles en orden
```

El orden importa: el analista responde la consulta que creó el cliente en su video. Para grabar un solo perfil: `node record.mjs supervisor`. Por defecto usa Microsoft Edge (`BROWSER_CHANNEL=chrome` para usar Chrome).
