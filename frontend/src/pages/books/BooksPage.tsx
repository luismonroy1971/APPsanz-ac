import { useState, Fragment } from 'react'
import { Calculator, FileDown, FileSpreadsheet, FileText, Lock, Unlock } from 'lucide-react'
import { toast } from 'sonner'
import { useAuth } from '@/context/AuthContext'
import { useGet, useMeta, useSend } from '@/hooks/useApi'
import { download, errorMessage } from '@/lib/api'
import { Badge, Button, Card, Empty, Field, Loading, PageHeader, Select, StatusBadge, TableWrap, Tabs } from '@/components/ui'
import { ClientPicker } from '@/components/ClientPicker'
import { cn, fmtMoney, MONTHS } from '@/lib/utils'

/* eslint-disable @typescript-eslint/no-explicit-any */

type BookType = 'registro_compras' | 'registro_ventas' | 'libro_diario' | 'libro_mayor' | 'balance_comprobacion' | 'situacion_financiera' | 'resultados' | 'eeff_anual'

const now = new Date()
const lastMonth = new Date(now.getFullYear(), now.getMonth() - 1, 1)

export default function BooksPage() {
  const { data: meta } = useMeta()
  const [clientId, setClientId] = useState('')
  const [year, setYear] = useState(String(lastMonth.getFullYear()))
  const [month, setMonth] = useState(String(lastMonth.getMonth() + 1))
  const [tab, setTab] = useState<BookType | 'impuestos' | 'periodos'>('registro_compras')

  const params = { client_id: clientId, year, month }
  const years = Array.from({ length: 6 }, (_, i) => String(now.getFullYear() - i))

  const exportBook = async (format: 'xlsx' | 'pdf') => {
    try {
      await download(`/ledger/books/${tab}/export`, { ...params, format }, `${tab}.${format}`)
    } catch (e) {
      toast.error(errorMessage(e))
    }
  }
  const ple = async (operation: 'compra' | 'venta') => {
    try {
      await download('/ledger/ple', { ...params, operation }, 'ple.txt')
    } catch (e) {
      toast.error(errorMessage(e))
    }
  }

  return (
    <>
      <PageHeader tag="R5 · R6 · R7" title="Libros contables y estados financieros" subtitle="Generados en línea desde los comprobantes y asientos registrados. Exportación a Excel, PDF y PLE SUNAT." />
      <Card className="mb-4" bodyClass="flex flex-wrap items-end gap-3 p-4">
        <Field label="Cliente"><ClientPicker value={clientId} onChange={setClientId} allowAll={false} /></Field>
        <Field label="Ejercicio"><Select value={year} onChange={(e) => setYear(e.target.value)} options={years.map((y) => [y, y] as [string, string])} className="w-28" /></Field>
        <Field label="Mes"><Select value={month} onChange={(e) => setMonth(e.target.value)} options={MONTHS.map((m, i) => [String(i + 1), m] as [string, string])} className="w-36" /></Field>
        {clientId && !['impuestos', 'periodos'].includes(tab) && (
          <div className="ml-auto flex flex-wrap gap-2">
            <Button variant="outline" icon={<FileSpreadsheet className="h-4 w-4" />} onClick={() => exportBook('xlsx')}>Excel</Button>
            <Button variant="outline" icon={<FileText className="h-4 w-4" />} onClick={() => exportBook('pdf')}>PDF</Button>
            {tab === 'registro_compras' && <Button variant="gold" icon={<FileDown className="h-4 w-4" />} onClick={() => ple('compra')}>PLE 8.1</Button>}
            {tab === 'registro_ventas' && <Button variant="gold" icon={<FileDown className="h-4 w-4" />} onClick={() => ple('venta')}>PLE 14.1</Button>}
          </div>
        )}
      </Card>

      <Tabs
        value={tab}
        onChange={setTab}
        tabs={[
          ...Object.entries(meta?.books ?? {}).map(([value, label]) => ({ value: value as BookType, label })),
          { value: 'impuestos', label: 'Estimado de impuestos' },
          { value: 'periodos', label: 'Periodos' },
        ]}
      />

      {!clientId ? (
        <Card><Empty title="Seleccione un cliente" description="Elija el cliente y el periodo para visualizar sus libros y estados financieros." /></Card>
      ) : tab === 'impuestos' ? (
        <Taxes params={params} />
      ) : tab === 'periodos' ? (
        <Periods clientId={clientId} />
      ) : (
        <Book type={tab} params={params} />
      )}
    </>
  )
}

function Book({ type, params }: { type: BookType; params: Record<string, string> }) {
  const { data, isLoading } = useGet<{ type: string; title: string; data: any }>(`/ledger/books/${type}`, params)
  // keepPreviousData puede devolver momentáneamente el libro anterior (otra estructura)
  if (isLoading || !data || data.type !== type) return <Loading />
  const d = data.data

  if (type === 'registro_compras' || type === 'registro_ventas') {
    if (!d.rows.length) return <Card><Empty title="Sin comprobantes en el periodo" /></Card>
    return (
      <Card bodyClass="p-0" title={data.title}>
        <TableWrap>
          <table className="table-base">
            <thead><tr><th>N°</th><th>Fecha</th><th>TD</th><th>Serie-Número</th><th>{type === 'registro_compras' ? 'RUC proveedor' : 'Doc. cliente'}</th><th>Razón social</th><th className="num">Base imp.</th><th className="num">Exon./Inaf.</th><th className="num">IGV</th><th className="num">Otros</th><th className="num">Total</th></tr></thead>
            <tbody className="divide-y divide-slate-100">
              {d.rows.map((r: any) => (
                <tr key={r.id}>
                  <td>{r.correlativo}</td><td className="whitespace-nowrap">{r.fecha}</td><td>{r.tipo}</td><td className="whitespace-nowrap">{r.serie}-{r.numero}</td><td>{r.doc_contraparte}</td><td className="max-w-[240px] truncate">{r.contraparte}</td>
                  <td className="num">{fmtMoney(r.base_imponible)}</td><td className="num">{fmtMoney(r.exonerado + r.inafecto)}</td><td className="num">{fmtMoney(r.igv)}</td><td className="num">{fmtMoney(r.otros)}</td><td className="num font-medium">{fmtMoney(r.total)}</td>
                </tr>
              ))}
            </tbody>
            <tfoot className="bg-brand-50 font-semibold">
              <tr><td colSpan={6} className="px-3 py-2.5">TOTALES</td><td className="num px-3">{fmtMoney(d.totals.base_imponible)}</td><td className="num px-3">{fmtMoney(d.totals.exonerado + d.totals.inafecto)}</td><td className="num px-3">{fmtMoney(d.totals.igv)}</td><td className="num px-3">{fmtMoney(d.totals.otros)}</td><td className="num px-3">{fmtMoney(d.totals.total)}</td></tr>
            </tfoot>
          </table>
        </TableWrap>
      </Card>
    )
  }

  if (type === 'libro_diario') {
    if (!d.entries.length) return <Card><Empty title="Sin asientos en el periodo" /></Card>
    return (
      <Card bodyClass="p-0" title="Libro Diario" subtitle={`Debe ${fmtMoney(d.totals.debe)} · Haber ${fmtMoney(d.totals.haber)}`}>
        <TableWrap>
          <table className="table-base">
            <thead><tr><th>Asiento</th><th>Fecha</th><th>Cuenta</th><th>Denominación / glosa</th><th className="num">Debe</th><th className="num">Haber</th></tr></thead>
            <tbody>
              {d.entries.map((e: any) => (
                <Fragment key={e.id}>
                  <tr key={e.id} className="border-t-2 border-slate-200 bg-slate-50/70">
                    <td className="font-mono text-xs font-semibold">{e.numero}</td><td>{e.fecha}</td><td colSpan={4} className="font-medium">{e.glosa}</td>
                  </tr>
                  {e.lineas.map((l: any, i: number) => (
                    <tr key={`${e.id}-${i}`}>
                      <td /><td /><td className={cn('font-mono text-xs', l.haber > 0 && 'pl-8')}>{l.cuenta}</td><td>{l.denominacion}</td>
                      <td className="num">{l.debe ? fmtMoney(l.debe) : ''}</td><td className="num">{l.haber ? fmtMoney(l.haber) : ''}</td>
                    </tr>
                  ))}
                </Fragment>
              ))}
            </tbody>
          </table>
        </TableWrap>
      </Card>
    )
  }

  if (type === 'libro_mayor') {
    if (!d.accounts.length) return <Card><Empty title="Sin movimientos" /></Card>
    return (
      <div className="space-y-4">
        {d.accounts.map((a: any) => (
          <Card key={a.cuenta} bodyClass="p-0" title={`${a.cuenta} · ${a.denominacion}`} subtitle={`Saldo inicial ${fmtMoney(a.saldo_inicial)} · Saldo final ${fmtMoney(a.saldo_final)}`}>
            <TableWrap>
              <table className="table-base">
                <thead><tr><th>Fecha</th><th>Asiento</th><th>Glosa</th><th className="num">Debe</th><th className="num">Haber</th><th className="num">Saldo</th></tr></thead>
                <tbody className="divide-y divide-slate-100">
                  {a.movimientos.map((m: any, i: number) => (
                    <tr key={i}><td>{m.fecha}</td><td className="font-mono text-xs">{m.asiento}</td><td className="max-w-[320px] truncate">{m.glosa}</td><td className="num">{m.debe ? fmtMoney(m.debe) : ''}</td><td className="num">{m.haber ? fmtMoney(m.haber) : ''}</td><td className="num font-medium">{fmtMoney(m.saldo)}</td></tr>
                  ))}
                </tbody>
              </table>
            </TableWrap>
          </Card>
        ))}
      </div>
    )
  }

  if (type === 'balance_comprobacion') {
    return (
      <Card bodyClass="p-0" title="Balance de comprobación" actions={<Badge tone={d.cuadra ? 'green' : 'red'}>{d.cuadra ? 'Cuadrado' : 'Descuadrado'}</Badge>}>
        <TableWrap>
          <table className="table-base">
            <thead><tr><th>Cuenta</th><th>Denominación</th><th className="num">Debe</th><th className="num">Haber</th><th className="num">Saldo deudor</th><th className="num">Saldo acreedor</th></tr></thead>
            <tbody className="divide-y divide-slate-100">
              {d.rows.map((r: any) => (
                <tr key={r.cuenta}><td className="font-mono text-xs">{r.cuenta}</td><td>{r.denominacion}</td><td className="num">{fmtMoney(r.debe)}</td><td className="num">{fmtMoney(r.haber)}</td><td className="num">{r.saldo_deudor ? fmtMoney(r.saldo_deudor) : ''}</td><td className="num">{r.saldo_acreedor ? fmtMoney(r.saldo_acreedor) : ''}</td></tr>
              ))}
            </tbody>
            <tfoot className="bg-brand-50 font-semibold">
              <tr><td colSpan={2} className="px-3 py-2.5">TOTALES</td><td className="num px-3">{fmtMoney(d.totals.debe)}</td><td className="num px-3">{fmtMoney(d.totals.haber)}</td><td className="num px-3">{fmtMoney(d.totals.saldo_deudor)}</td><td className="num px-3">{fmtMoney(d.totals.saldo_acreedor)}</td></tr>
            </tfoot>
          </table>
        </TableWrap>
      </Card>
    )
  }

  const position = type === 'situacion_financiera' ? d : type === 'eeff_anual' ? d.situacion : null
  const income = type === 'resultados' ? d : type === 'eeff_anual' ? d.resultados : null

  return (
    <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
      {position && (
        <Card title="Estado de Situación Financiera" actions={<Badge tone={position.cuadra ? 'green' : 'red'}>{position.cuadra ? 'Activo = Pasivo + Patrimonio' : 'Descuadre'}</Badge>} className={income ? '' : 'xl:col-span-2'}>
          <Statement title="ACTIVO" rows={position.activo} total={position.total_activo} />
          <Statement title="PASIVO" rows={position.pasivo} total={position.total_pasivo} />
          <Statement title="PATRIMONIO" rows={position.patrimonio} total={position.total_patrimonio} />
          <div className="mt-2 flex justify-between rounded-lg bg-brand-700 px-3 py-2 text-sm font-semibold text-white"><span>TOTAL PASIVO Y PATRIMONIO</span><span className="tabular-nums">{fmtMoney(position.total_pasivo_patrimonio)}</span></div>
        </Card>
      )}
      {income && (
        <Card title="Estado de Resultados" className={position ? '' : 'xl:col-span-2'}>
          <ul className="divide-y divide-slate-100 text-sm">
            {income.lines.map((l: any) => (
              <li key={l.concepto} className={cn('flex justify-between py-2', ['subtotal', 'total'].includes(l.tipo) && 'font-semibold text-slate-900', l.tipo === 'total' && 'rounded-lg bg-brand-700 px-3 text-white')}>
                <span>{l.concepto}</span>
                <span className={cn('tabular-nums', l.importe < 0 && l.tipo !== 'total' && 'text-rose-600')}>{fmtMoney(l.importe)}</span>
              </li>
            ))}
          </ul>
        </Card>
      )}
    </div>
  )
}

function Statement({ title, rows, total }: { title: string; rows: { concepto: string; importe: number }[]; total: number }) {
  return (
    <div className="mb-4">
      <p className="mb-1 text-xs font-bold tracking-wide text-brand-700">{title}</p>
      <ul className="divide-y divide-slate-100 text-sm">
        {rows.map((r) => (
          <li key={r.concepto} className="flex justify-between py-1.5"><span>{r.concepto}</span><span className="tabular-nums">{fmtMoney(r.importe)}</span></li>
        ))}
        <li className="flex justify-between py-1.5 font-semibold"><span>Total {title.toLowerCase()}</span><span className="tabular-nums">{fmtMoney(total)}</span></li>
      </ul>
    </div>
  )
}

function Taxes({ params }: { params: Record<string, string> }) {
  const { data, isLoading } = useGet<{ data: any }>('/ledger/taxes', params)
  const { data: cxc } = useGet<{ data: any[] }>('/ledger/open-items', { client_id: params.client_id, operation: 'venta' })
  const { data: cxp } = useGet<{ data: any[] }>('/ledger/open-items', { client_id: params.client_id, operation: 'compra' })
  if (isLoading || !data) return <Loading />
  const t = data.data
  return (
    <div className="grid grid-cols-1 gap-6 xl:grid-cols-3">
      <Card title={`Estimado de impuestos ${t.periodo}`} subtitle="IGV y pago a cuenta del Impuesto a la Renta (referencial)">
        <ul className="divide-y divide-slate-100 text-sm">
          {[
            ['Ventas netas del periodo', t.ventas_netas],
            ['IGV débito fiscal (ventas)', t.igv_debito],
            ['(-) IGV crédito fiscal (compras)', -t.igv_credito],
            ['IGV por pagar', t.igv_por_pagar],
            ['Saldo a favor IGV', t.saldo_a_favor],
            [`Pago a cuenta renta (${(t.renta_tasa * 100).toFixed(1)}%)`, t.renta_pago_a_cuenta],
          ].map(([l, v]) => (
            <li key={l as string} className="flex justify-between py-2"><span>{l}</span><span className="tabular-nums">{fmtMoney(v)}</span></li>
          ))}
        </ul>
        <div className="mt-3 flex items-center justify-between rounded-lg bg-gold-50 px-3 py-3 text-sm font-semibold text-gold-700 ring-1 ring-gold-300">
          <span className="flex items-center gap-2"><Calculator className="h-4 w-4" /> Total estimado a pagar</span>
          <span className="tabular-nums">S/ {fmtMoney(t.total_a_pagar)}</span>
        </div>
      </Card>
      <OpenItems title="Cuentas por cobrar" rows={cxc?.data ?? []} />
      <OpenItems title="Cuentas por pagar" rows={cxp?.data ?? []} />
    </div>
  )
}

function OpenItems({ title, rows }: { title: string; rows: any[] }) {
  return (
    <Card title={title} subtitle={`${rows.length} documento(s) · Saldo ${fmtMoney(rows.reduce((a, r) => a + r.saldo, 0))}`} bodyClass="p-0">
      {!rows.length ? <Empty title="Sin saldos pendientes" /> : (
        <ul className="max-h-[360px] divide-y divide-slate-100 overflow-y-auto">
          {rows.map((r) => (
            <li key={r.id} className="px-5 py-2.5 text-sm">
              <div className="flex justify-between gap-2"><span className="truncate">{r.contraparte}</span><span className="tabular-nums font-medium">{fmtMoney(r.saldo, r.moneda)}</span></div>
              <p className="text-xs text-slate-500">{r.comprobante} · vence {r.vencimiento ?? '—'} {r.dias_vencido > 0 && <span className="text-rose-600">· {r.dias_vencido} días vencido</span>}</p>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}

function Periods({ clientId }: { clientId: string }) {
  const { hasRole } = useAuth()
  const { data, isLoading } = useGet<{ data: any[] }>('/periods', { client_id: clientId })
  const update = useSend<{ id: number; status: string }>('patch', (v) => `/periods/${v.id}`, { invalidate: ['/periods'] })
  if (isLoading) return <Loading />
  return (
    <Card bodyClass="p-0" title="Periodos contables" subtitle="Un periodo cerrado no admite nuevos registros ni modificaciones.">
      {!data?.data.length ? <Empty title="Sin periodos" /> : (
        <table className="table-base">
          <thead><tr><th>Periodo</th><th>Actividades S3</th><th>Estado</th><th /></tr></thead>
          <tbody className="divide-y divide-slate-100">
            {data.data.map((p) => (
              <tr key={p.id}>
                <td className="font-medium">{MONTHS[p.month - 1]} {p.year}</td>
                <td>{p.tasks_count}</td>
                <td><StatusBadge status={p.status} /></td>
                <td className="text-right">
                  {p.status !== 'cerrado' && p.status !== 'en_revision' && <Button size="sm" variant="outline" onClick={() => update.mutate({ id: p.id, status: 'en_revision' })}>Enviar a revisión</Button>}
                  {hasRole('socio', 'supervisor') && (p.status === 'cerrado'
                    ? <Button size="sm" variant="ghost" icon={<Unlock className="h-4 w-4" />} onClick={() => update.mutate({ id: p.id, status: 'abierto' })}>Reabrir</Button>
                    : <Button size="sm" variant="secondary" icon={<Lock className="h-4 w-4" />} className="ml-2" onClick={() => update.mutate({ id: p.id, status: 'cerrado' })}>Cerrar periodo</Button>)}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </Card>
  )
}
