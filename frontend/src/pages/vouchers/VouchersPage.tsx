import { useState } from 'react'
import { Ban, Eye, FileUp, HandCoins, Plus } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useGet, useMeta, useSend } from '@/hooks/useApi'
import { api, errorMessage, toFormData } from '@/lib/api'
import type { Paginated, Voucher } from '@/lib/types'
import { Badge, Button, Card, Confirm, Empty, Field, Input, KeyValue, Loading, Modal, PageHeader, Pagination, Select, StatCard, StatusBadge, TableWrap, Toolbar } from '@/components/ui'
import { ClientPicker } from '@/components/ClientPicker'
import { FileDrop } from '@/components/FileDrop'
import { fmtDate, fmtMoney } from '@/lib/utils'
import { VoucherForm, type VoucherFormValues } from './VoucherForm'

type Totals = Record<string, { cantidad: number; total: string; igv: string }>

export default function VouchersPage() {
  const { data: meta } = useMeta()
  const [f, setF] = useState({ client_id: '', operation: '', document_type: '', payment_status: '', from: '', to: '', search: '', page: 1 })
  const set = (p: Partial<typeof f>) => setF((s) => ({ ...s, page: 1, ...p }))
  const { data, isLoading } = useGet<Paginated<Voucher> & { totals: Totals }>('/vouchers', f)
  const [view, setView] = useState<number | null>(null)
  const [creating, setCreating] = useState(false)
  const [importing, setImporting] = useState(false)
  const t = data?.totals ?? {}

  return (
    <>
      <PageHeader
        tag="S1"
        title="Comprobantes de pago"
        subtitle="Facturas, boletas y notas registradas en compras y ventas, con su asiento contable automático."
        actions={
          <>
            <Button variant="outline" icon={<FileUp className="h-4 w-4" />} onClick={() => setImporting(true)}>
              Importar Excel
            </Button>
            <Button icon={<Plus className="h-4 w-4" />} onClick={() => setCreating(true)}>
              Registro manual
            </Button>
          </>
        }
      />
      <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Compras (filtro)" value={fmtMoney(t.compra?.total ?? 0, 'PEN')} hint={`${t.compra?.cantidad ?? 0} comprobantes`} tone="brand" />
        <StatCard label="IGV crédito fiscal" value={fmtMoney(t.compra?.igv ?? 0, 'PEN')} tone="violet" />
        <StatCard label="Ventas (filtro)" value={fmtMoney(t.venta?.total ?? 0, 'PEN')} hint={`${t.venta?.cantidad ?? 0} comprobantes`} tone="green" />
        <StatCard label="IGV débito fiscal" value={fmtMoney(t.venta?.igv ?? 0, 'PEN')} tone="gold" />
      </div>
      <Card bodyClass="p-0">
        <Toolbar>
          <ClientPicker value={f.client_id} onChange={(v) => set({ client_id: v })} />
          <Select value={f.operation} onChange={(e) => set({ operation: e.target.value })} placeholder="Compras y ventas" options={{ compra: 'Compras', venta: 'Ventas' }} className="w-40" />
          <Select value={f.document_type} onChange={(e) => set({ document_type: e.target.value })} placeholder="Todo tipo" options={meta?.voucher_types} className="w-44" />
          <Select value={f.payment_status} onChange={(e) => set({ payment_status: e.target.value })} placeholder="Estado de pago" options={{ pendiente: 'Pendiente', parcial: 'Parcial', pagado: 'Pagado' }} className="w-40" />
          <Field label="Desde"><Input type="date" value={f.from} onChange={(e) => set({ from: e.target.value })} /></Field>
          <Field label="Hasta"><Input type="date" value={f.to} onChange={(e) => set({ to: e.target.value })} /></Field>
          <Input placeholder="RUC, razón social o número..." value={f.search} onChange={(e) => set({ search: e.target.value })} className="w-60" />
        </Toolbar>
        {isLoading ? (
          <Loading />
        ) : !data?.data.length ? (
          <Empty title="No hay comprobantes" />
        ) : (
          <TableWrap>
            <table className="table-base">
              <thead>
                <tr>
                  <th>Fecha</th><th>Cliente</th><th>Op.</th><th>Comprobante</th><th>Contraparte</th><th className="num">Base</th><th className="num">IGV</th><th className="num">Total</th><th>Estado</th><th>Pago</th><th />
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {data.data.map((v) => (
                  <tr key={v.id} className="cursor-pointer" onClick={() => setView(v.id)}>
                    <td className="whitespace-nowrap">{fmtDate(v.issue_date)}</td>
                    <td className="max-w-[160px] truncate">{v.client?.business_name}</td>
                    <td><Badge tone={v.operation === 'compra' ? 'brand' : 'green'}>{v.operation === 'compra' ? 'Compra' : 'Venta'}</Badge></td>
                    <td className="whitespace-nowrap"><span className="text-xs text-slate-400">{v.document_type}</span> {v.full_number}</td>
                    <td className="max-w-[220px] truncate">{v.operation === 'compra' ? v.issuer_name : v.customer_name}</td>
                    <td className="num">{fmtMoney(v.taxable_amount)}</td>
                    <td className="num">{fmtMoney(v.igv)}</td>
                    <td className="num font-medium">{fmtMoney(v.total, v.currency)}</td>
                    <td><StatusBadge status={v.status} /></td>
                    <td><StatusBadge status={v.payment_status} /></td>
                    <td><Eye className="h-4 w-4 text-slate-400" /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </TableWrap>
        )}
        <Pagination page={data} onPage={(p) => setF((s) => ({ ...s, page: p }))} />
      </Card>

      {view && <VoucherDetail id={view} onClose={() => setView(null)} />}
      <CreateVoucher open={creating} onClose={() => setCreating(false)} />
      <ImportVouchers open={importing} onClose={() => setImporting(false)} />
    </>
  )
}

function VoucherDetail({ id, onClose }: { id: number; onClose: () => void }) {
  const { data } = useGet<{ data: Voucher }>(`/vouchers/${id}`)
  const v = data?.data
  const [editing, setEditing] = useState(false)
  const [confirmVoid, setConfirmVoid] = useState(false)
  const [amount, setAmount] = useState('')
  const opts = { invalidate: ['/vouchers', '/documents'] }
  const update = useSend<VoucherFormValues>('put', `/vouchers/${id}`, { ...opts, onSuccess: () => setEditing(false) })
  const pay = useSend<{ amount: string }>('post', `/vouchers/${id}/payment`, { ...opts, onSuccess: () => setAmount('') })
  const voidIt = useSend('post', `/vouchers/${id}/void`, { ...opts, onSuccess: () => { setConfirmVoid(false); onClose() } })

  return (
    <Modal open onClose={onClose} size="xl" title={v ? `${v.document_type_name} ${v.full_number}` : 'Comprobante'}>
      {!v ? (
        <Loading />
      ) : editing ? (
        <VoucherForm initial={v as never} submitLabel="Guardar y regenerar asiento" loading={update.isPending} onCancel={() => setEditing(false)} onSubmit={(vals) => update.mutate(vals)} />
      ) : (
        <div className="space-y-5">
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone={v.operation === 'compra' ? 'brand' : 'green'}>{v.operation === 'compra' ? 'Compra' : 'Venta'}</Badge>
            <StatusBadge status={v.status} />
            <StatusBadge status={v.payment_status} />
            {v.status !== 'anulado' && (
              <div className="ml-auto flex gap-2">
                <Button size="sm" variant="outline" onClick={() => setEditing(true)}>Editar</Button>
                <Button size="sm" variant="danger" icon={<Ban className="h-4 w-4" />} onClick={() => setConfirmVoid(true)}>Anular</Button>
              </div>
            )}
          </div>
          <Card>
            <KeyValue
              cols={4}
              items={[
                ['Cliente', v.client?.business_name],
                ['Fecha emisión', fmtDate(v.issue_date)],
                ['Vencimiento', fmtDate(v.due_date)],
                ['Moneda / T.C.', `${v.currency} / ${v.exchange_rate}`],
                ['Emisor', `${v.issuer_ruc} · ${v.issuer_name}`],
                ['Adquirente', `${v.customer_doc ?? ''} · ${v.customer_name ?? ''}`],
                ['Referencia', v.reference_doc],
                ['Documento soporte', v.document_id ? `#${v.document_id}` : 'Registro manual'],
                ['Base imponible', fmtMoney(v.taxable_amount, v.currency)],
                ['Exonerado / Inafecto', `${fmtMoney(v.exempt_amount)} / ${fmtMoney(v.unaffected_amount)}`],
                ['IGV', fmtMoney(v.igv, v.currency)],
                ['Total', <b className="text-base">{fmtMoney(v.total, v.currency)}</b>],
              ]}
            />
          </Card>
          {!!v.items?.length && (
            <Card title="Detalle" bodyClass="p-0">
              <TableWrap>
                <table className="table-base">
                  <thead><tr><th>Descripción</th><th className="num">Cant.</th><th className="num">V. unit.</th><th className="num">Subtotal</th><th className="num">IGV</th><th className="num">Total</th></tr></thead>
                  <tbody className="divide-y divide-slate-100">
                    {v.items.map((i) => (
                      <tr key={i.id}><td>{i.description}</td><td className="num">{Number(i.quantity)}</td><td className="num">{fmtMoney(i.unit_price)}</td><td className="num">{fmtMoney(i.subtotal)}</td><td className="num">{fmtMoney(i.igv)}</td><td className="num">{fmtMoney(i.total)}</td></tr>
                    ))}
                  </tbody>
                </table>
              </TableWrap>
            </Card>
          )}
          <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
            {v.journal_entry && (
              <Card title={`Asiento ${v.journal_entry.number}`} subtitle={v.journal_entry.description} bodyClass="p-0" className="lg:col-span-2">
                <table className="table-base">
                  <thead><tr><th>Cuenta</th><th>Denominación</th><th className="num">Debe</th><th className="num">Haber</th></tr></thead>
                  <tbody className="divide-y divide-slate-100">
                    {v.journal_entry.lines.map((l) => (
                      <tr key={l.id}><td className="font-mono text-xs">{l.account?.code}</td><td>{l.account?.name}</td><td className="num">{Number(l.debit) ? fmtMoney(l.debit) : ''}</td><td className="num">{Number(l.credit) ? fmtMoney(l.credit) : ''}</td></tr>
                    ))}
                  </tbody>
                </table>
              </Card>
            )}
            <Card title="Tesorería" subtitle={`Pagado: ${fmtMoney(v.amount_paid, v.currency)} de ${fmtMoney(v.total, v.currency)}`}>
              {v.payment_status !== 'pagado' && v.status !== 'anulado' ? (
                <div className="flex items-end gap-2">
                  <Field label="Monto" className="flex-1"><Input type="number" step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder={(Number(v.total) - Number(v.amount_paid)).toFixed(2)} /></Field>
                  <Button icon={<HandCoins className="h-4 w-4" />} loading={pay.isPending} onClick={() => pay.mutate({ amount: amount || (Number(v.total) - Number(v.amount_paid)).toFixed(2) })}>
                    {v.operation === 'compra' ? 'Pagar' : 'Cobrar'}
                  </Button>
                </div>
              ) : (
                <p className="text-sm text-slate-500">Sin saldo pendiente.</p>
              )}
            </Card>
          </div>
          <Confirm open={confirmVoid} onClose={() => setConfirmVoid(false)} onConfirm={() => voidIt.mutate(undefined)} loading={voidIt.isPending} danger title="Anular comprobante" message="Se anulará el comprobante y su asiento contable. Esta acción queda registrada." confirmLabel="Anular" />
        </div>
      )}
    </Modal>
  )
}

function CreateVoucher({ open, onClose }: { open: boolean; onClose: () => void }) {
  const [clientId, setClientId] = useState('')
  const create = useSend<VoucherFormValues & { client_id: string }>('post', '/vouchers', { invalidate: ['/vouchers'], onSuccess: onClose })
  return (
    <Modal open={open} onClose={onClose} size="xl" title="Registro manual de comprobante">
      <Field label="Cliente" className="mb-4 max-w-md">
        <ClientPicker value={clientId} onChange={setClientId} allowAll={false} className="w-full" />
      </Field>
      <VoucherForm loading={create.isPending} onCancel={onClose} onSubmit={(v) => (clientId ? create.mutate({ ...v, client_id: clientId }) : toast.error('Seleccione el cliente.'))} />
    </Modal>
  )
}

function ImportVouchers({ open, onClose }: { open: boolean; onClose: () => void }) {
  const qc = useQueryClient()
  const [clientId, setClientId] = useState('')
  const [files, setFiles] = useState<File[]>([])
  const [loading, setLoading] = useState(false)
  const [result, setResult] = useState<{ imported: number; failed: { fila: number; errores: string[] }[] } | null>(null)

  const submit = async () => {
    if (!clientId || !files[0]) return toast.error('Seleccione cliente y archivo.')
    setLoading(true)
    try {
      const { data } = await api.post('/vouchers/import', toFormData({ client_id: clientId, file: files[0] }))
      toast.success(data.message)
      setResult(data)
      qc.invalidateQueries({ predicate: (q) => String(q.queryKey[0]).startsWith('/vouchers') })
    } catch (e) {
      toast.error(errorMessage(e))
    } finally {
      setLoading(false)
    }
  }

  return (
    <Modal
      open={open}
      onClose={() => { setResult(null); setFiles([]); onClose() }}
      size="lg"
      title="Importar comprobantes (otras fuentes)"
      footer={<Button onClick={submit} loading={loading}>Importar</Button>}
    >
      <div className="space-y-4">
        <Field label="Cliente"><ClientPicker value={clientId} onChange={setClientId} allowAll={false} className="w-full" /></Field>
        <FileDrop files={files} onChange={setFiles} multiple={false} hint="Excel o CSV con encabezados en la primera fila" />
        <div className="rounded-lg bg-slate-50 p-3 text-xs text-slate-600">
          <p className="font-semibold">Columnas esperadas:</p>
          <code className="mt-1 block break-words">tipo, serie, numero, fecha, ruc_emisor, razon_social_emisor, doc_adquirente, nombre_adquirente, moneda, tipo_cambio, base, exonerado, inafecto, igv, otros, total</code>
        </div>
        {result && (
          <div className="rounded-lg border border-slate-200 p-3 text-sm">
            <p className="font-medium text-emerald-700">{result.imported} comprobante(s) importado(s).</p>
            {result.failed.length > 0 && (
              <ul className="mt-2 max-h-48 list-disc space-y-1 overflow-y-auto pl-5 text-xs text-rose-700">
                {result.failed.map((f) => <li key={f.fila}>Fila {f.fila}: {f.errores.join(' ')}</li>)}
              </ul>
            )}
          </div>
        )}
      </div>
    </Modal>
  )
}
