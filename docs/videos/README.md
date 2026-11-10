# Videos demostrativos por perfil de usuario

Recorridos **narrados en español** sobre la aplicación real con los datos de demostración. Cada video muestra el cursor y subtítulos sincronizados con la voz, que explican cada paso con su código de la propuesta (C1, S1, R3…).

Formato: **MP4 (H.264 + audio AAC)**. Se reproduce en cualquier navegador, en Windows, en celulares, en PowerPoint y en WhatsApp.

| Video | Perfil | Usuario demo | Contenido |
|---|---|---|---|
| `01-perfil-cliente.mp4` | Cliente (acceso externo) | `cliente@andina.pe` | Login externo, panel, **envío de XML SUNAT y procesamiento automático**, detalle y trazabilidad, documento devuelto, nueva consulta (C2), libros e informes publicados (R5–R8), reportes R1, R3 y R4 |
| `02-perfil-cliente-limitado.mp4` | Cliente con permisos limitados | `tesoreria@sertecsur.pe` | Menú según los permisos acordados en la coordinación previa; bloqueo del chat también en la API |
| `03-perfil-analista.mp4` | Analista (acceso interno) | `analista@sanzac.pe` | Bandeja S1, documento bancario procesado, **registro manual de un PDF con validaciones y asiento**, devolución al cliente, respuesta de consultas (S2), comprobantes y tesorería, importación desde Excel, asiento manual, libros, EEFF e impuestos, tareas S3, horas, papeles de trabajo, comunicaciones |
| `04-perfil-supervisor.mp4` | Supervisor | `supervisor@sanzac.pe` | Seguimiento semanal R1 y R2, R3 y R4, aprobación de horas, revisión de entregables, **generación y publicación de EEFF al cliente**, balance de comprobación y cierre de periodo, revisión de papeles de trabajo, alta de un usuario externo con permisos |
| `05-perfil-socio.mp4` | Socio | `socio@sanzac.pe` | Visión global, reportes R10, R9, R11, R12 y R5, directiva interna, capacitación, alta de un cliente con su coordinación previa y asignación de equipo, documentos internos |

## Volver a grabarlos

Requisitos: Windows con la voz en español **Microsoft Sabina Desktop** (o cualquier voz `es-*` instalada), Microsoft Edge, y el backend (`:8000`) y el frontend (`:5173`) en ejecución.

```bash
cd backend && php artisan migrate:fresh --seed     # datos limpios
cd ../tools/video-demo
npm install && npm run setup                        # playwright-core, ffmpeg-static y el ffmpeg de grabación
npm run record                                      # graba y narra los 5 perfiles en orden
```

- El orden importa: el analista responde la consulta que el cliente creó en su video.
- Para grabar un solo perfil: `node record.mjs supervisor`.
- Variables opcionales:
  - `TTS_VOICE`: nombre de la voz.
  - `TTS_RATE`: velocidad, de -10 a 10; por defecto 1.
  - `NO_AUDIO=1`: graba sin narración.
  - `BROWSER_CHANNEL=chrome`: graba con Chrome en lugar de Edge.
- Los textos que se narran son los mismos de los subtítulos y se definen en `tools/video-demo/record.mjs`.
