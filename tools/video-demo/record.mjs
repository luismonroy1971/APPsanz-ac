/**
 * Graba videos demostrativos de APPsanz&ac, uno por perfil de usuario.
 *
 * Requisitos: backend (http://127.0.0.1:8000) y frontend (http://localhost:5173) en ejecución,
 * base de datos con datos de demostración recién cargados (php artisan migrate:fresh --seed).
 *
 *   npm install && npm run setup     # instala playwright-core y su ffmpeg
 *   npm run record                   # graba todos los perfiles
 *   node record.mjs cliente socio    # graba perfiles específicos
 *
 * Los videos (.webm) quedan en docs/videos/.
 */
import { chromium } from 'playwright-core'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
const ROOT = path.resolve(here, '../..')
const OUT = path.join(ROOT, 'docs', 'videos')
const SAMPLES = path.join(ROOT, 'backend', 'storage', 'app', 'private', 'samples')
const BASE = process.env.APP_URL ?? 'http://localhost:5173'
const PASSWORD = process.env.DEMO_PASSWORD ?? 'Demo2026!'
const SIZE = { width: 1440, height: 900 }
const CHANNEL = process.env.BROWSER_CHANNEL ?? 'msedge'

fs.mkdirSync(OUT, { recursive: true })

/* ------------------------------------------------------------------ utilidades */

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

/** RUC con dígito verificador (para datos de ejemplo válidos). */
function ruc(base10) {
  const w = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2]
  const sum = [...base10].reduce((a, d, i) => a + Number(d) * w[i], 0)
  let c = 11 - (sum % 11)
  c = c === 10 ? 0 : c === 11 ? 1 : c
  return base10 + c
}

/** Script que se inyecta en cada página: cursor visible + panel de subtítulos. */
const OVERLAY = () => {
  const install = () => {
    if (document.getElementById('__demo_cursor')) return
    const style = document.createElement('style')
    style.textContent = `
      #__demo_cursor{position:fixed;z-index:2147483647;width:22px;height:22px;margin:-11px 0 0 -11px;border-radius:50%;
        background:rgba(201,162,39,.45);border:2px solid #c9a227;pointer-events:none;transition:transform .12s, background .12s;left:-50px;top:-50px}
      #__demo_cursor.down{transform:scale(.7);background:rgba(201,162,39,.85)}
      #__demo_caption{position:fixed;z-index:2147483646;left:50%;bottom:28px;transform:translateX(-50%);max-width:980px;width:calc(100% - 80px);
        background:rgba(10,22,40,.92);color:#fff;border-radius:14px;padding:16px 22px;font:15px/1.45 Inter,Segoe UI,sans-serif;
        box-shadow:0 12px 40px rgba(0,0,0,.35);border-left:5px solid #c9a227;pointer-events:none;transition:opacity .25s}
      #__demo_caption b{display:block;font-size:17px;margin-bottom:4px;color:#e3c766}
      #__demo_caption .tag{display:inline-block;font-size:11px;font-weight:700;letter-spacing:.08em;background:#c9a227;color:#0a1628;
        border-radius:6px;padding:2px 7px;margin-right:8px;vertical-align:2px}`
    document.head.appendChild(style)
    const c = document.createElement('div')
    c.id = '__demo_cursor'
    document.body.appendChild(c)
    const cap = document.createElement('div')
    cap.id = '__demo_caption'
    cap.style.opacity = '0'
    document.body.appendChild(cap)
    document.addEventListener('mousemove', (e) => { c.style.left = e.clientX + 'px'; c.style.top = e.clientY + 'px' }, true)
    document.addEventListener('mousedown', () => c.classList.add('down'), true)
    document.addEventListener('mouseup', () => c.classList.remove('down'), true)
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install)
  else install()
  window.__setCaption = (tag, title, text) => {
    install()
    const cap = document.getElementById('__demo_caption')
    if (!title) { cap.style.opacity = '0'; return }
    cap.innerHTML = `${tag ? `<span class="tag">${tag}</span>` : ''}<b style="display:inline">${title}</b><div style="margin-top:6px;color:#dbe4ef">${text ?? ''}</div>`
    cap.style.opacity = '1'
  }
}

class Demo {
  constructor(page, name) {
    this.page = page
    this.name = name
    this.failures = []
  }

  async caption(tag, title, text, wait = 3500) {
    await this.page.evaluate(([a, b, c]) => window.__setCaption?.(a, b, c), [tag, title, text]).catch(() => {})
    await sleep(wait)
  }

  async hideCaption() {
    await this.page.evaluate(() => window.__setCaption?.(null)).catch(() => {})
  }

  /** Mueve el cursor visible hasta el elemento y hace clic. */
  async click(locator, { pauseAfter = 900 } = {}) {
    const el = typeof locator === 'string' ? this.page.locator(locator).first() : locator.first()
    await el.scrollIntoViewIfNeeded({ timeout: 8000 })
    const box = await el.boundingBox()
    if (box) {
      await this.page.mouse.move(box.x + box.width / 2, box.y + box.height / 2, { steps: 18 })
      await sleep(250)
    }
    await el.click({ timeout: 8000 })
    await sleep(pauseAfter)
  }

  async type(locator, text, delay = 35) {
    const el = typeof locator === 'string' ? this.page.locator(locator).first() : locator.first()
    await this.click(el, { pauseAfter: 150 })
    await el.fill('')
    await el.pressSequentially(String(text), { delay })
    await sleep(250)
  }

  /** Campo de formulario por su etiqueta visible (componente Field). */
  field(label, scope = this.page) {
    return scope.locator(`div:has(> label:text-is("${label}"))`).locator('input, select, textarea').first()
  }

  dialog() {
    return this.page.locator('[role="dialog"]').last()
  }

  async select(locator, option) {
    const el = typeof locator === 'string' ? this.page.locator(locator).first() : locator.first()
    await el.scrollIntoViewIfNeeded()
    const box = await el.boundingBox()
    if (box) await this.page.mouse.move(box.x + box.width / 2, box.y + box.height / 2, { steps: 15 })
    await sleep(200)
    await el.selectOption(option)
    await sleep(700)
  }

  async go(pathname, wait = 1800) {
    await this.page.goto(BASE + pathname)
    await this.page.waitForLoadState('networkidle').catch(() => {})
    await sleep(wait)
  }

  async nav(label, wait = 1800) {
    // Coincidencia exacta del texto del menú (admite el contador de pendientes al final)
    const escaped = label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
    const name = new RegExp(`^${escaped}(\\s*\\d+)?$`)
    await this.click(this.page.locator('aside').getByRole('link', { name }), { pauseAfter: wait })
    await this.page.waitForLoadState('networkidle').catch(() => {})
  }

  async scroll(dy = 500, times = 1) {
    for (let i = 0; i < times; i++) {
      await this.page.mouse.wheel(0, dy)
      await sleep(900)
    }
  }

  async scrollTop() {
    await this.page.evaluate(() => window.scrollTo({ top: 0, behavior: 'smooth' }))
    await sleep(700)
  }

  async closeDialog() {
    await this.page.keyboard.press('Escape')
    await sleep(700)
  }

  /** Ejecuta un paso; si falla, lo registra y continúa con el siguiente. */
  async step(title, fn) {
    try {
      await fn()
    } catch (e) {
      this.failures.push(`${title}: ${e.message.split('\n')[0]}`)
      console.warn(`  ! [${this.name}] ${title}: ${e.message.split('\n')[0]}`)
      await this.page.keyboard.press('Escape').catch(() => {})
      await sleep(500)
    }
  }

  async titleCard(title, subtitle, bullets) {
    const html = `<!doctype html><html><head><meta charset="utf-8"><style>
      body{margin:0;height:100vh;display:flex;align-items:center;justify-content:center;font-family:Inter,Segoe UI,sans-serif;
        background:linear-gradient(135deg,#172e4c,#0a1628);color:#fff}
      .box{max-width:900px;padding:40px}
      .logo{display:inline-flex;align-items:center;gap:14px;margin-bottom:36px}
      .mark{width:56px;height:56px;border-radius:14px;background:#c9a227;color:#0a1628;font-weight:900;font-size:22px;display:flex;align-items:center;justify-content:center}
      .brand{font-size:26px;font-weight:700}.brand span{color:#c9a227}
      h1{font-size:46px;margin:0 0 10px}h2{font-size:22px;font-weight:400;color:#b9cadf;margin:0 0 30px}
      li{font-size:19px;margin:10px 0;color:#dbe4ef}li::marker{color:#c9a227}
    </style></head><body><div class="box">
      <div class="logo"><div class="mark">S&amp;</div><div class="brand">APPsanz<span>&amp;</span>ac</div></div>
      <h1>${title}</h1><h2>${subtitle}</h2><ul>${bullets.map((b) => `<li>${b}</li>`).join('')}</ul>
    </div></body></html>`
    await this.page.setContent(html)
    await sleep(6500)
  }

  async login(email, portal) {
    await this.go('/login', 1200)
    await this.caption('LOGIN', portal === 'interno' ? 'Acceso interno' : 'Acceso externo',
      portal === 'interno'
        ? 'Socios, supervisores y analistas de SANZ & AC ingresan con su login y clave. Cada acceso queda registrado (reporte R1).'
        : 'Los usuarios del cliente, definidos en el contrato (coordinación previa), ingresan con su login y clave. Todos los accesos se registran y se reportan cada semana.', 4500)
    await this.click(this.page.getByRole('button', { name: portal === 'interno' ? /Acceso interno/ : /Acceso externo/ }))
    await this.type('input[type="email"]', email, 45)
    await this.type('input[type="password"]', PASSWORD, 60)
    await this.click(this.page.getByRole('button', { name: 'Ingresar' }), { pauseAfter: 2500 })
    await this.page.waitForLoadState('networkidle').catch(() => {})
  }

  async logout() {
    await this.page.keyboard.press('Escape').catch(() => {})
    await this.caption('', 'Cerrar sesión', 'Al salir, el token de acceso se invalida y el cierre de sesión queda registrado.', 2500)
    // Esperar a que desaparezcan los avisos emergentes que cubren el botón «Salir»
    await this.page.locator('[data-sonner-toast]').first().waitFor({ state: 'detached', timeout: 10000 }).catch(() => {})
    await this.click(this.page.getByTitle('Cerrar sesión'), { pauseAfter: 2000 })
  }
}

/* ------------------------------------------------------------------ guiones */

const today = new Date()
const iso = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10)
const prevMonth = new Date(today.getFullYear(), today.getMonth() - 1, 1)

const SCRIPTS = {
  /* ============================ CLIENTE ============================ */
  async cliente(d) {
    await d.titleCard('Perfil: Cliente', 'Acceso externo · Comercial Andina S.A.C.', [
      'C1 · Ingresar documentos (procesamiento automático de XML SUNAT)',
      'C2 · Chat de consultas con su equipo contable',
      'Libros, estados financieros e informes publicados (R5 – R8)',
      'Reportes de su servicio: accesos, documentos y consultas',
    ])
    await d.login('cliente@andina.pe', 'externo')

    await d.step('Inicio', async () => {
      await d.caption('INICIO', 'Panel del cliente', 'Resumen del servicio: documentos enviados, documentos en proceso, consultas activas, accesos de la semana y compras y ventas del mes.', 5000)
      await d.scroll(450, 2)
      await d.caption('INICIO', 'Actividad reciente', 'Últimos documentos con su estado, informes publicados por SANZ & AC y consultas recientes.', 4000)
      await d.scrollTop()
    })

    await d.step('Enviar documentos', async () => {
      await d.nav('Mis documentos')
      await d.caption('C1', 'Mis documentos', 'Aquí el cliente envía comprobantes y otros documentos y ve el estado de cada uno: ingresado, en proceso, procesado, observado o devuelto.', 4500)
      await d.click(d.page.getByRole('button', { name: 'Enviar documentos' }))
      await d.caption('C1', 'Enviar documentos', 'Se elige el tipo de documento y el periodo, y se arrastran los archivos. Se pueden subir varios a la vez: XML, ZIP, PDF, imágenes o Excel.', 4000)
      const dlg = d.dialog()
      await d.field('Periodo (opcional)', dlg).fill(iso(today).slice(0, 7))
      await d.type(d.field('Comentarios para su analista (opcional)', dlg), 'Facturas de compra y venta de esta semana.', 30)
      await dlg.locator('input[type="file"]').setInputFiles([
        path.join(SAMPLES, 'factura-compra-F005-321.xml'),
        path.join(SAMPLES, 'factura-venta-F001-900.xml'),
      ])
      await sleep(1500)
      await d.caption('C1', 'XML de comprobantes electrónicos', 'Se recomienda enviar el XML: el sistema lo lee automáticamente y lo registra en compras o ventas, con su asiento contable.', 4000)
      await d.click(dlg.getByRole('button', { name: /^Enviar/ }), { pauseAfter: 3500 })
      await d.page.reload()
      await d.page.waitForLoadState('networkidle')
      await sleep(1500)
      await d.caption('S1', 'Procesamiento automático', 'En segundos los XML aparecen como «Procesado» y muestran el número del comprobante registrado.', 4500)
    })

    await d.step('Detalle de documento', async () => {
      await d.click(d.page.getByText('factura-compra-F005-321.xml').first(), { pauseAfter: 2000 })
      await d.caption('S1', 'Detalle y trazabilidad', 'El cliente ve los datos del comprobante (emisor, base, IGV y total) y el historial de estados con fecha y hora.', 5500)
      await d.closeDialog()
    })

    await d.step('Documento devuelto', async () => {
      await d.click(d.page.getByRole('button', { name: /Devueltos/ }), { pauseAfter: 1500 })
      await d.click(d.page.locator('tbody tr').first(), { pauseAfter: 2000 })
      await d.caption('R3', 'Documentos devueltos', 'Si un documento tiene problemas, el analista lo devuelve con el motivo. El cliente lo reingresa corregido y queda vinculado al original (indicador R3).', 5500)
      await d.closeDialog()
    })

    await d.step('Consultas', async () => {
      await d.nav('Consultas')
      await d.caption('C2', 'Chat de consultas', 'Conversaciones con el equipo contable: la lista a la izquierda y el detalle a la derecha, con estados, prioridad y adjuntos.', 4500)
      await d.click(d.page.getByRole('button', { name: 'Nueva consulta' }))
      const dlg = d.dialog()
      await d.type(d.field('Asunto', dlg), 'Deducción de gastos de movilidad')
      await d.select(d.field('Tema', dlg), 'tributario')
      await d.select(d.field('Prioridad', dlg), 'alta')
      await d.type(d.field('Mensaje', dlg), 'Buen día, ¿podemos deducir los gastos de movilidad del personal de ventas? ¿Qué sustento necesitan?', 22)
      await d.click(dlg.getByRole('button', { name: 'Enviar consulta' }), { pauseAfter: 2500 })
      await d.caption('C2', 'Consulta enviada', 'Se asigna automáticamente al analista del cliente. Las respuestas aparecen aquí y en el panel de inicio.', 4500)
    })

    await d.step('Informes', async () => {
      await d.nav('Libros e informes')
      await d.caption('R5–R8', 'Libros e informes publicados', 'Registros de compras y ventas, Libro Diario y estados financieros que el supervisor revisó y publicó. Se descargan en PDF o Excel.', 5500)
      await d.click(d.page.getByRole('button', { name: /R6/ }), { pauseAfter: 2000 })
      await d.caption('R6', 'Estados financieros periódicos', 'Situación financiera y estado de resultados de cada mes cerrado.', 3500)
    })

    await d.step('Reportes', async () => {
      await d.nav('Reportes')
      await d.click(d.page.getByRole('button', { name: 'Mes anterior' }), { pauseAfter: 2000 })
      await d.caption('R1', 'Accesos realizados', 'El cliente ve cuántas veces ingresó cada usuario de su empresa, día por día.', 4500)
      await d.click(d.page.getByRole('button', { name: /R3\s*Documentos ingresados/ }), { pauseAfter: 2000 })
      await d.caption('R3', 'Documentos ingresados, devueltos y reingresados', 'Indicadores de calidad de la información enviada.', 4000)
      await d.click(d.page.getByRole('button', { name: /R4\s*Consultas/ }), { pauseAfter: 2000 })
      await d.caption('R4', 'Consultas recibidas y contestadas', 'Porcentaje de consultas atendidas y tiempo promedio de la primera respuesta.', 4000)
    })

    await d.logout()
  },

  /* ============================ CLIENTE LIMITADO ============================ */
  async 'cliente-limitado'(d) {
    await d.titleCard('Perfil: Cliente con acceso limitado', 'Acceso externo · Servicios Técnicos del Sur S.A.', [
      'Los permisos de cada usuario se definen en la coordinación previa (contrato)',
      'Este usuario de tesorería solo puede ingresar documentos',
      'No ve el chat ni los informes de la empresa',
    ])
    await d.login('tesoreria@sertecsur.pe', 'externo')
    await d.step('Menú limitado', async () => {
      await d.caption('ACCESO', 'Menú según permisos', 'El menú solo muestra lo que este usuario tiene habilitado: Inicio, Mis documentos y Reportes de accesos.', 5500)
      await d.nav('Mis documentos')
      await d.caption('C1', 'Ingreso de documentos', 'Puede enviar documentos de su empresa y seguir su estado.', 4000)
      await d.go('/consultas', 2000)
      await d.caption('SEGURIDAD', 'Acceso restringido', 'Si intenta entrar al chat por URL, la API lo rechaza (403): los permisos se validan también en el servidor.', 5500)
    })
    await d.logout()
  },

  /* ============================ ANALISTA ============================ */
  async analista(d) {
    await d.titleCard('Perfil: Analista', 'Acceso interno · SANZ & AC', [
      'S1 · Procesa documentos recibidos + otras fuentes',
      'S2 · Responde consultas de los clientes',
      'S3 · Contabilidad: comprobantes, asientos, libros y EEFF',
      'Horas hombre, papeles de trabajo y comunicaciones internas',
    ])
    await d.login('analista@sanzac.pe', 'interno')

    await d.step('Panel', async () => {
      await d.caption('PANEL', 'Panel operativo', 'Documentos por procesar, consultas sin cerrar, tareas vencidas, horas de la semana y gráficos de documentos ingresados frente a procesados.', 5500)
      await d.scroll(500, 2)
      await d.caption('PANEL', 'Mis tareas y pendientes', 'Próximas tareas S3, consultas que esperan respuesta y documentos pendientes por cliente.', 4000)
      await d.scrollTop()
    })

    await d.step('Bandeja: procesar documento bancario', async () => {
      await d.nav('Bandeja de documentos')
      await d.caption('S1', 'Bandeja de documentos', 'Todos los documentos de los clientes asignados, con filtros por estado, cliente y categoría.', 4500)
      await d.click(d.page.getByRole('button', { name: /En proceso/ }), { pauseAfter: 1500 })
      await d.click(d.page.locator('tbody tr', { hasText: 'estado_cuenta' }).first(), { pauseAfter: 2000 })
      await d.caption('S1', 'Documento no transaccional', 'Un estado de cuenta bancario se revisa para la conciliación y se marca como procesado.', 4000)
      await d.click(d.dialog().getByRole('button', { name: 'Marcar como procesado' }), { pauseAfter: 2000 })
      await d.closeDialog()
    })

    await d.step('Registro manual de PDF reingresado', async () => {
      await d.click(d.page.locator('tbody tr', { hasText: 'factura_corregida.pdf' }).first(), { pauseAfter: 2000 })
      await d.caption('S1', 'Registro manual (PDF)', 'Un PDF sin XML se registra a mano. El sistema valida el RUC, que los importes cuadren, el IGV y los duplicados, y genera el asiento.', 5000)
      const dlg = d.dialog()
      await d.click(dlg.getByRole('button', { name: 'Registrar manualmente' }), { pauseAfter: 1200 })
      await d.type(d.field('Serie', dlg), 'F002')
      await d.type(d.field('Número', dlg), '4521')
      await d.field('Fecha de emisión', dlg).fill(iso(new Date(today.getTime() - 3 * 86400000)))
      await d.type(d.field('RUC emisor', dlg), ruc('2033344455'))
      await d.type(d.field('Razón social emisor', dlg), 'SUMINISTROS INDUSTRIALES ANDES S.A.C.')
      await d.type(d.field('Doc. adquirente (RUC/DNI)', dlg), ruc('2060123456'))
      await d.type(d.field('Nombre adquirente', dlg), 'COMERCIAL ANDINA S.A.C.')
      await d.type(d.field('Base imponible', dlg), '1500')
      await d.click(d.field('IGV', dlg), { pauseAfter: 800 })
      await d.caption('S1', 'Cálculo de IGV y total', 'Al ingresar la base imponible, se calculan el IGV (18 %) y el total.', 3500)
      await d.click(dlg.getByRole('button', { name: 'Registrar comprobante' }), { pauseAfter: 3000 })
      await d.caption('S1', 'Comprobante y asiento registrados', 'El documento pasa a «Procesado» con su asiento: 60 Compras / 40 IGV / 42 Cuentas por pagar.', 5000)
      await d.closeDialog()
    })

    await d.step('Devolver documento', async () => {
      await d.click(d.page.getByRole('button', { name: /^Nuevos/ }), { pauseAfter: 1500 })
      await d.click(d.page.locator('tbody tr', { hasText: 'planilla' }).first(), { pauseAfter: 1800 })
      const dlg = d.dialog()
      await d.click(dlg.getByRole('button', { name: 'Devolver al cliente' }))
      await d.type(dlg.locator('textarea').first(), 'Falta la hoja de descuentos y aportes AFP del mes. Favor reenviar la planilla completa.', 20)
      await d.caption('R3', 'Devolución con motivo', 'El cliente recibe el motivo y puede reingresar el documento. La devolución se mide en el indicador R3.', 4000)
      await d.click(dlg.getByRole('button', { name: 'Confirmar devolución' }), { pauseAfter: 2000 })
      await d.closeDialog()
    })

    await d.step('Responder consulta', async () => {
      await d.nav('Consultas de clientes')
      await d.caption('S2', 'Respuestas a consultas', 'Bandeja de consultas de los clientes con estado, prioridad y responsable.', 4000)
      await d.click(d.page.getByRole('button', { name: /Deducción de gastos de movilidad|Consulta sobre detracciones/ }).first(), { pauseAfter: 2000 })
      await d.type(d.page.locator('textarea').last(), 'Buen día. Sí son deducibles hasta el 4 % de la remuneración, con planilla de movilidad firmada por el trabajador. Le enviamos el formato.', 18)
      await d.click(d.page.getByRole('button', { name: 'Enviar' }).last(), { pauseAfter: 2500 })
      await d.caption('R4', 'Respuesta registrada', 'Se registra la hora de la primera respuesta y la consulta pasa a «Respondida» (indicador R4).', 4500)
    })

    await d.step('Comprobantes', async () => {
      await d.nav('Comprobantes')
      await d.caption('S1', 'Comprobantes de pago', 'Facturas, boletas y notas registradas, con totales de compras, ventas e IGV. Se pueden filtrar por cliente, tipo, fecha y estado de pago.', 5000)
      await d.click(d.page.locator('tbody tr').first(), { pauseAfter: 2000 })
      await d.caption('S1', 'Detalle, asiento y tesorería', 'Detalle de ítems, asiento contable generado y registro de pagos o cobros (cuentas por pagar y por cobrar).', 5000)
      await d.scroll(500, 1)
      await d.closeDialog()
      await d.click(d.page.getByRole('button', { name: 'Importar Excel' }), { pauseAfter: 1500 })
      await d.caption('OTRAS FUENTES', 'Importación masiva', 'Otras fuentes: se importan comprobantes desde Excel o CSV, con las mismas validaciones.', 4500)
      await d.closeDialog()
    })

    await d.step('Asiento manual', async () => {
      await d.nav('Asientos contables')
      await d.caption('S3', 'Asientos contables', 'Asientos automáticos (de los comprobantes) y manuales: conciliaciones, ajustes, provisiones y planillas.', 4500)
      await d.click(d.page.locator('tbody tr').first(), { pauseAfter: 1500 })
      await d.click(d.page.getByRole('button', { name: 'Nuevo asiento' }), { pauseAfter: 1200 })
      const dlg = d.dialog()
      await d.select(d.field('Cliente', dlg), { label: 'COMERCIAL ANDINA S.A.C.' })
      await d.type(d.field('Glosa', dlg), 'Conciliación bancaria: intereses y comisiones del mes')
      const rows = dlg.locator('tbody tr')
      await d.select(rows.nth(0).locator('select'), { label: '6731 · Intereses por préstamos' })
      await d.type(rows.nth(0).locator('input[type="number"]').nth(0), '85.50')
      await d.select(rows.nth(1).locator('select'), { label: '1041 · Cuentas corrientes operativas' })
      await d.type(rows.nth(1).locator('input[type="number"]').nth(1), '85.50')
      await d.caption('S3', 'Control de partida doble', 'El asiento solo se registra si el Debe es igual al Haber y el periodo está abierto.', 4000)
      await d.click(dlg.getByRole('button', { name: 'Registrar asiento' }), { pauseAfter: 2500 })
    })

    await d.step('Libros', async () => {
      await d.nav('Libros y EEFF')
      await d.select(d.page.locator('select').first(), { label: 'COMERCIAL ANDINA S.A.C.' })
      await sleep(1500)
      await d.caption('R5', 'Registro de Compras', 'Libros generados en línea desde la contabilidad. Exportación a Excel, PDF y archivo PLE de SUNAT (8.1 / 14.1).', 5000)
      for (const tab of ['Libro Diario', 'Estado de Situación Financiera', 'Estado de Resultados', 'Estimado de impuestos']) {
        await d.click(d.page.getByRole('button', { name: tab, exact: true }), { pauseAfter: 2500 })
      }
      await d.caption('S3', 'Estimado de impuestos', 'IGV por pagar, pago a cuenta de renta y cuentas por cobrar y por pagar pendientes del periodo.', 4500)
    })

    await d.step('Procesos S3', async () => {
      await d.nav('Procesos y tareas')
      await d.caption('S3', 'Proceso contable, tributario, RRHH y tesorería', 'Tablero de actividades por cliente y periodo. Las tarjetas se arrastran entre estados.', 5000)
      await d.click(d.page.locator('.border-t-slate-400 div[draggable="true"]').first(), { pauseAfter: 1500 })
      await d.caption('S3', 'Detalle de la actividad', 'Área, actividad S3, entregable asociado (R5 – R8), responsable, fecha límite y horas estimadas.', 4000)
      await d.select(d.field('Estado', d.dialog()), 'en_proceso')
      await d.click(d.dialog().getByRole('button', { name: 'Guardar' }), { pauseAfter: 2000 })
    })

    await d.step('Horas', async () => {
      await d.nav('Horas hombre')
      await d.click(d.page.getByRole('button', { name: 'Registrar horas' }))
      const dlg = d.dialog()
      await d.select(d.field('Cliente', dlg), { label: 'COMERCIAL ANDINA S.A.C.' })
      await d.type(d.field('Horas', dlg), '3.5')
      await d.type(d.field('Detalle', dlg), 'Registro de comprobantes y conciliación bancaria')
      await d.caption('R2', 'Registro de horas hombre', 'Cada trabajador registra las horas dedicadas a cada cliente. Con esto se arma el reporte R2.', 4000)
      await d.click(dlg.getByRole('button', { name: 'Guardar' }), { pauseAfter: 2000 })
    })

    await d.step('Papeles de trabajo', async () => {
      await d.nav('Papeles de trabajo')
      await d.caption('R9', 'Papeles de trabajo', 'Evidencia del trabajo realizado, con referencia, conclusión y archivo de soporte, que luego revisa el supervisor.', 4500)
    })

    await d.step('Comunicaciones', async () => {
      await d.nav('Comunicaciones internas')
      await d.click(d.page.locator('ul li button').first(), { pauseAfter: 2000 })
      await d.caption('R11', 'Instrucciones y directivas', 'El analista lee las comunicaciones del socio y del supervisor y confirma su lectura.', 4000)
      await d.click(d.dialog().getByRole('button', { name: 'Confirmar lectura' })).catch(() => d.closeDialog())
    })

    await d.logout()
  },

  /* ============================ SUPERVISOR ============================ */
  async supervisor(d) {
    await d.titleCard('Perfil: Supervisor', 'Acceso interno · SANZ & AC', [
      'Seguimiento semanal de accesos y horas dedicadas por cliente (R1, R2)',
      'Revisión y publicación de libros y EEFF (R5 – R8)',
      'Aprobación de horas, cierre de periodos y papeles de trabajo',
      'Administración de usuarios y permisos de acceso',
    ])
    await d.login('supervisor@sanzac.pe', 'interno')

    await d.step('Panel', async () => {
      await d.caption('PANEL', 'Panel de supervisión', 'Visión de todos los clientes: pendientes, tareas vencidas, informes por revisar, horas del equipo y accesos de clientes.', 5500)
    })

    await d.step('Reportes R1-R4', async () => {
      await d.nav('Reportes R1 – R12')
      await d.click(d.page.getByRole('button', { name: 'Semana anterior' }), { pauseAfter: 2000 })
      await d.caption('R1', 'Seguimiento semanal de accesos', 'Accesos de clientes y del equipo por día y por usuario. Se exporta a Excel o PDF para reportarlo al cliente.', 5000)
      await d.click(d.page.getByRole('button', { name: /R2\s*Horas hombre/ }), { pauseAfter: 2500 })
      await d.caption('R2', 'Horas hombre por trabajador y cliente', 'Horas registradas, horas aprobadas y costo estimado por cliente en la semana.', 5000)
      await d.scroll(500, 1)
      await d.scrollTop()
      await d.click(d.page.getByRole('button', { name: /R3\s*Documentos/ }), { pauseAfter: 2000 })
      await d.click(d.page.getByRole('button', { name: 'Este mes' }), { pauseAfter: 2000 })
      await d.caption('R3', 'Calidad de documentos', 'Porcentaje de documentos devueltos y reingresados por cliente.', 4000)
      await d.click(d.page.getByRole('button', { name: /R4\s*Consultas/ }), { pauseAfter: 2500 })
      await d.caption('R4', 'Atención de consultas', 'Consultas recibidas, contestadas y en seguimiento, con horas promedio de primera respuesta.', 4000)
    })

    await d.step('Aprobar horas', async () => {
      await d.nav('Horas hombre')
      await d.caption('R2', 'Aprobación de horas', 'El supervisor revisa las horas del equipo y las aprueba de forma individual o masiva.', 4000)
      await d.click(d.page.locator('thead input[type="checkbox"]'), { pauseAfter: 1200 })
      await d.click(d.page.getByRole('button', { name: /Aprobar/ }), { pauseAfter: 2000 })
    })

    await d.step('Completar tarea en revisión', async () => {
      await d.nav('Procesos y tareas')
      await d.caption('S3', 'Revisión de entregables', 'Las actividades con entregables (R5 – R8) pasan por revisión del supervisor antes de completarse.', 4500)
      await d.click(d.page.locator('.border-t-violet-500 div[draggable="true"]').first(), { pauseAfter: 1500 })
      await d.select(d.field('Estado', d.dialog()), 'completado')
      await d.click(d.dialog().getByRole('button', { name: 'Guardar' }), { pauseAfter: 2000 })
    })

    await d.step('Generar y publicar informe', async () => {
      await d.nav('Informes R5 – R8')
      await d.caption('BD.2', 'Informes R5 – R8', 'Flujo de calidad: borrador → revisado → publicado al cliente. Solo el supervisor o el socio publican.', 4500)
      await d.click(d.page.getByRole('button', { name: 'Generar desde contabilidad' }))
      const dlg = d.dialog()
      await d.select(d.field('Cliente', dlg), { label: 'COMERCIAL ANDINA S.A.C.' })
      await d.select(d.field('Informe', dlg), 'situacion_financiera')
      await d.select(d.field('Mes', dlg), String(prevMonth.getMonth() + 1))
      await d.field('Ejercicio', dlg).fill(String(prevMonth.getFullYear()))
      await d.click(dlg.getByRole('button', { name: 'Generar' }), { pauseAfter: 3000 })
      await d.caption('R6', 'Informe generado en PDF', 'El estado financiero se genera en PDF a partir de los asientos registrados.', 3500)
      const row = d.page.locator('tbody tr').first()
      await d.click(row.getByRole('button', { name: 'Publicar' }), { pauseAfter: 2000 })
      await d.caption('R6', 'Publicado al cliente', 'Al publicarlo, el cliente lo ve de inmediato en «Libros e informes».', 4000)
    })

    await d.step('Cerrar periodo', async () => {
      await d.nav('Libros y EEFF')
      await d.select(d.page.locator('select').first(), { label: 'COMERCIAL ANDINA S.A.C.' })
      await d.click(d.page.getByRole('button', { name: 'Balance de Comprobación', exact: true }), { pauseAfter: 2500 })
      await d.caption('R6', 'Balance de comprobación', 'Control de sumas y saldos: indica si la contabilidad del periodo está cuadrada.', 4000)
      await d.click(d.page.getByRole('button', { name: 'Periodos', exact: true }), { pauseAfter: 2000 })
      await d.caption('S3', 'Cierre de periodos', 'Un periodo cerrado no admite nuevos registros ni modificaciones. Solo el supervisor o el socio pueden cerrarlo o reabrirlo.', 5000)
      const MONTHS = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Setiembre', 'Octubre', 'Noviembre', 'Diciembre']
      const row = d.page.locator('tbody tr', { hasText: `${MONTHS[prevMonth.getMonth()]} ${prevMonth.getFullYear()}` })
      await d.click(row.getByRole('button', { name: 'Cerrar periodo' }), { pauseAfter: 2000 })
    })

    await d.step('Papeles de trabajo', async () => {
      await d.nav('Papeles de trabajo')
      await d.caption('R9', 'Revisión de papeles de trabajo', 'El supervisor revisa y aprueba la evidencia preparada por los analistas.', 4000)
      await d.click(d.page.getByRole('button', { name: 'Revisar' }).first(), { pauseAfter: 2000 })
      await d.click(d.page.getByRole('button', { name: 'Aprobar' }).first(), { pauseAfter: 2000 })
    })

    await d.step('Usuarios', async () => {
      await d.nav('Usuarios y accesos')
      await d.caption('ACCESO', 'Acceso externo por cliente', 'Usuarios de cada cliente, con los permisos acordados en el contrato y su último acceso.', 4500)
      await d.click(d.page.getByRole('button', { name: 'Nuevo usuario' }))
      const dlg = d.dialog()
      await d.select(d.field('Cliente', dlg), { label: 'INVERSIONES PACIFICO E.I.R.L.' })
      await d.type(d.field('Nombre completo', dlg), 'Sofía Ramírez Lazo')
      await d.type(d.field('Correo (login)', dlg), `sramirez.${Date.now() % 10000}@pacifico.pe`)
      await d.type(d.field('Cargo', dlg), 'Asistente administrativa')
      await d.type(d.field('Clave', dlg), 'Pacifico2026')
      await d.click(dlg.getByText('Ver informes y libros (R5-R8)'), { pauseAfter: 800 })
      await d.caption('ACCESO', 'Permisos por usuario', 'En este caso el usuario puede enviar documentos y usar el chat, pero no ver informes. El sistema respeta el máximo de usuarios del contrato.', 5000)
      await d.click(dlg.getByRole('button', { name: 'Guardar' }), { pauseAfter: 2000 })
      await d.click(d.page.getByRole('button', { name: /Acceso interno/ }), { pauseAfter: 2000 })
      await d.caption('ACCESO', 'Acceso interno', 'Socio, supervisor y analistas, con los clientes asignados a cada uno.', 3500)
    })

    await d.logout()
  },

  /* ============================ SOCIO ============================ */
  async socio(d) {
    await d.titleCard('Perfil: Socio', 'Acceso interno · SANZ & AC', [
      'Visión global de clientes, cumplimiento y calidad del servicio',
      'Reportes de gestión R9 – R12',
      'Directivas, capacitación y recomendaciones',
      'Administración de clientes y equipos de trabajo',
    ])
    await d.login('socio@sanzac.pe', 'interno')

    await d.step('Panel', async () => {
      await d.caption('PANEL', 'Visión global', 'El socio ve el estado de toda la operación: S1, S2 y S3 de todos los clientes.', 4500)
      await d.scroll(500, 2)
      await d.scrollTop()
    })

    await d.step('Reportes R9-R12', async () => {
      await d.nav('Reportes R1 – R12')
      await d.click(d.page.getByRole('button', { name: 'Año' }), { pauseAfter: 1500 })
      await d.click(d.page.getByRole('button', { name: /R10\s*% cumplimiento/ }), { pauseAfter: 2500 })
      await d.caption('R10', '% de cumplimiento de R5 a R8', 'Entregables planificados frente a completados y a tiempo, por cliente y por tipo de informe.', 5500)
      await d.scroll(500, 1)
      await d.scrollTop()
      await d.click(d.page.getByRole('button', { name: /R9\s*Papeles/ }), { pauseAfter: 2500 })
      await d.caption('R9', 'Papeles de trabajo', 'Estado de revisión de la evidencia por área.', 3500)
      await d.click(d.page.getByRole('button', { name: /R11\s*Comunicaciones/ }), { pauseAfter: 2500 })
      await d.caption('R11', 'Comunicaciones internas', 'Porcentaje de lectura y confirmación de las directivas.', 3500)
      await d.click(d.page.getByRole('button', { name: /R12\s*Capacitación/ }), { pauseAfter: 2500 })
      await d.caption('R12', 'Capacitación y recomendaciones', 'Horas de capacitación y porcentaje de recomendaciones implementadas.', 4000)
      await d.click(d.page.getByRole('button', { name: /R5\s*Libros/ }), { pauseAfter: 2500 })
      await d.caption('BD.2', 'Entregables al cliente', 'Libros e informes generados y publicados por cliente.', 3500)
    })

    await d.step('Directiva', async () => {
      await d.nav('Comunicaciones internas')
      await d.click(d.page.getByRole('button', { name: 'Nueva comunicación' }))
      const dlg = d.dialog()
      await d.select(d.field('Tipo', dlg), 'directiva')
      await d.select(d.field('Prioridad', dlg), 'alta')
      await d.type(d.field('Título', dlg), 'Revisión semanal de accesos y horas por cliente')
      await d.type(d.field('Contenido', dlg), 'Cada lunes el supervisor enviará a cada cliente el reporte R1 de accesos y revisará el R2 de horas dedicadas. Las desviaciones mayores al 20 % se informan al socio.', 15)
      await d.caption('R11', 'Nueva directiva', 'Se publica a todo el equipo interno o a los destinatarios elegidos, con control de lectura.', 3500)
      await d.click(dlg.getByRole('button', { name: 'Publicar' }), { pauseAfter: 2000 })
    })

    await d.step('Capacitación', async () => {
      await d.nav('Capacitación')
      await d.caption('R12', 'Capacitación y recomendaciones', 'Plan de capacitación del equipo y seguimiento de las recomendaciones implementadas en los clientes.', 4500)
      await d.click(d.page.getByRole('button', { name: 'Nuevo registro' }))
      const dlg = d.dialog()
      await d.type(d.field('Título', dlg), 'Cierre contable y Libros Electrónicos PLE')
      await d.field('Fecha programada', dlg).fill(iso(new Date(today.getTime() + 7 * 86400000)))
      await d.type(d.field('Duración (horas)', dlg), '4')
      for (const name of ['Jorge Paredes Linares', 'Lucía Torres Medina', 'Diego Huamán Rojas']) {
        await d.click(dlg.getByRole('button', { name: new RegExp(name) }), { pauseAfter: 300 })
      }
      await d.click(dlg.getByRole('button', { name: 'Guardar' }), { pauseAfter: 2000 })
    })

    await d.step('Clientes', async () => {
      await d.nav('Clientes')
      await d.caption('ADMIN', 'Clientes', 'Datos de cada cliente, contrato, máximo de usuarios externos, equipo asignado y pendientes.', 4500)
      await d.click(d.page.getByRole('button', { name: 'Nuevo cliente' }))
      const dlg = d.dialog()
      await d.type(d.field('RUC', dlg), ruc('2061122334'))
      await d.type(d.field('Razón social', dlg), 'AGROINDUSTRIAS DEL VALLE S.A.C.')
      await d.select(d.field('Régimen tributario', dlg), 'mype')
      await d.type(d.field('Contacto', dlg), 'Martín Cáceres')
      await d.type(d.field('N° contrato', dlg), 'CT-2026-004')
      await d.type(d.field('Coordinación previa: personas con acceso y limitaciones', dlg), 'Acceso para Gerencia General y Contabilidad. Tesorería solo para envío de documentos.', 18)
      await d.caption('ADMIN', 'Coordinación previa', 'El contrato define qué personas del cliente tendrán acceso y con qué limitaciones. El RUC se valida con su dígito verificador.', 4500)
      await d.click(dlg.getByRole('button', { name: 'Guardar' }), { pauseAfter: 2000 })
      await d.click(d.page.locator('tbody tr', { hasText: 'AGROINDUSTRIAS' }).getByRole('button', { name: 'Equipo' }), { pauseAfter: 1500 })
      const team = d.dialog()
      await d.click(team.getByRole('button', { name: 'Agregar miembro' }))
      await d.select(team.locator('select').nth(0), { label: 'Lucía Torres Medina (Analista)' })
      await d.click(team.getByRole('button', { name: 'Agregar miembro' }))
      await d.select(team.locator('select').nth(2), { label: 'María Rivas Castillo (Supervisor)' })
      await d.select(team.locator('select').nth(3), 'supervisor')
      await d.caption('ADMIN', 'Analista + Supervisor + Socio', 'Se asigna el equipo responsable. El analista solo verá los clientes que tiene asignados.', 4000)
      await d.click(team.getByRole('button', { name: 'Guardar equipo' }), { pauseAfter: 2000 })
    })

    await d.step('Documentos internos', async () => {
      await d.nav('Documentos internos')
      await d.caption('BD.3', 'Documentos internos', 'Manuales, políticas, plantillas y normativa del estudio, disponibles para todo el equipo.', 4500)
    })

    await d.logout()
  },
}

/* ------------------------------------------------------------------ ejecución */

const ORDER = ['cliente', 'cliente-limitado', 'analista', 'supervisor', 'socio']
const wanted = process.argv.slice(2).length ? process.argv.slice(2) : ORDER

const browser = await chromium.launch({ channel: CHANNEL, headless: true })
const summary = []
for (const [i, name] of ORDER.entries()) {
  if (!wanted.includes(name)) continue
  console.log(`> Grabando perfil: ${name}`)
  const context = await browser.newContext({
    viewport: SIZE,
    deviceScaleFactor: 1,
    locale: 'es-PE',
    recordVideo: { dir: path.join(here, '.tmp'), size: SIZE },
  })
  await context.addInitScript(OVERLAY)
  const page = await context.newPage()
  const demo = new Demo(page, name)
  const t0 = Date.now()
  try {
    await SCRIPTS[name](demo)
    await demo.hideCaption()
    await sleep(1000)
  } catch (e) {
    demo.failures.push(`fatal: ${e.message.split('\n')[0]}`)
  }
  const video = page.video()
  await context.close()
  const target = path.join(OUT, `${String(i + 1).padStart(2, '0')}-perfil-${name}.webm`)
  if (video) {
    fs.copyFileSync(await video.path(), target)
  }
  const secs = Math.round((Date.now() - t0) / 1000)
  summary.push({ perfil: name, video: path.relative(ROOT, target), duracion_aprox: `${Math.floor(secs / 60)}m ${secs % 60}s`, pasos_con_error: demo.failures })
}
await browser.close()
fs.rmSync(path.join(here, '.tmp'), { recursive: true, force: true })
console.log(JSON.stringify(summary, null, 2))
