import { useMemo, useState, Fragment } from 'react'
import { ChevronDown, ChevronRight, Plus, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { useGet, useSend } from '@/hooks/useApi'
import type { JournalEntry, Paginated } from '@/lib/types'
import { Badge, Button, Card, Empty, Field, Input, Loading, Modal, PageHeader, Pagination, Select, StatusBadge, TableWrap, Toolbar } from '@/components/ui'
import { ClientPicker } from '@/components/ClientPicker'
import { cn, fmtDate, fmtMoney } from '@/lib/utils'

const BOOKS = { compras: 'Compras', ventas: 'Ventas', diario: 'Diario', caja: 'Caja y bancos', ajustes: 'Ajustes' }

export default function JournalPage() {
  const [f, setF] = useState({ client_id: '', book: '', origin: '', from: '', to: '', search: '', page: 1 })
  const set = (p: Partial<typeof f>) => setF((s) => ({ ...s, page: 1, ...p }))
  const { data, isLoading } = useGet<Paginated<JournalEntry>>('/journal-entries', f)
  const [open, setOpen] = useState<Record<number, boolean>>({})
  const [creating, setCreating] = useState(false)
  const voidIt = useSend<{ id: number }>('post', (v) => `/journal-entries/${v.id}/void`, { invalidate: ['/journal-entries'] })

  return (
    <>
      <PageHeader
        tag="S3"
        title="Asientos contables"
        subtitle="Asientos automáticos (desde comprobantes) y manuales: conciliaciones, ajustes, provisiones, planillas."
        actions={<Button icon={<Plus className="h-4 w-4" />} onClick={() => setCreating(true)}>Nuevo asiento</Button>}
      />
      <Card bodyClass="p-0">
        <Toolbar>
          <ClientPicker value={f.client_id} onChange={(v) => set({ client_id: v })} />
          <Select value={f.book} onChange={(e) => set({ book: e.target.value })} placeholder="Todos los libros" options={BOOKS} className="w-40" />
          <Select value={f.origin} onChange={(e) => set({ origin: e.target.value })} placeholder="Todo origen" options={{ automatico: 'Automático', manual: 'Manual', ajuste: 'Ajuste' }} className="w-40" />
          <Field label="Desde"><Input type="date" value={f.from} onChange={(e) => set({ from: e.target.value })} /></Field>
          <Field label="Hasta"><Input type="date" value={f.to} onChange={(e) => set({ to: e.target.value })} /></Field>
          <Input placeholder="Glosa o número..." value={f.search} onChange={(e) => set({ search: e.target.value })} className="w-56" />
        </Toolbar>
        {isLoading ? (
          <Loading />
        ) : !data?.data.length ? (
          <Empty title="No hay asientos" />
        ) : (
          <TableWrap>
            <table className="table-base">
              <thead><tr><th /><th>Número</th><th>Fecha</th><th>Cliente</th><th>Libro</th><th>Glosa</th><th>Origen</th><th className="num">Debe</th><th className="num">Haber</th><th>Estado</th><th /></tr></thead>
              <tbody className="divide-y divide-slate-100">
                {data.data.map((e) => (
                  <Fragment key={e.id}>
                    <tr key={e.id} className="cursor-pointer" onClick={() => setOpen((o) => ({ ...o, [e.id]: !o[e.id] }))}>
                      <td>{open[e.id] ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}</td>
                      <td className="font-mono text-xs">{e.number}</td>
                      <td className="whitespace-nowrap">{fmtDate(e.date)}</td>
                      <td className="max-w-[160px] truncate">{e.client?.business_name}</td>
                      <td>{BOOKS[e.book as keyof typeof BOOKS] ?? e.book}</td>
                      <td className="max-w-[300px] truncate">{e.description}</td>
                      <td><Badge tone={e.origin === 'automatico' ? 'green' : e.origin === 'ajuste' ? 'amber' : 'brand'}>{e.origin}</Badge></td>
                      <td className="num">{fmtMoney(e.total_debit)}</td>
                      <td className="num">{fmtMoney(e.total_credit)}</td>
                      <td><StatusBadge status={e.status} /></td>
                      <td onClick={(ev) => ev.stopPropagation()}>
                        {e.origin !== 'automatico' && e.status === 'registrado' && (
                          <Button size="sm" variant="ghost" onClick={() => voidIt.mutate({ id: e.id })}>Anular</Button>
                        )}
                      </td>
                    </tr>
                    {open[e.id] && (
                      <tr key={`${e.id}-d`} className="bg-slate-50/60">
                        <td />
                        <td colSpan={10} className="py-3">
                          <table className="w-full text-xs">
                            <tbody>
                              {e.lines.map((l) => (
                                <tr key={l.id}>
                                  <td className={cn('w-24 py-1 font-mono', Number(l.credit) > 0 && 'pl-6')}>{l.account?.code}</td>
                                  <td className="py-1">{l.account?.name}{l.description && <span className="text-slate-400"> — {l.description}</span>}</td>
                                  <td className="w-28 py-1 text-right tabular-nums">{Number(l.debit) ? fmtMoney(l.debit) : ''}</td>
                                  <td className="w-28 py-1 text-right tabular-nums">{Number(l.credit) ? fmtMoney(l.credit) : ''}</td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </td>
                      </tr>
                    )}
                  </Fragment>
                ))}
              </tbody>
            </table>
          </TableWrap>
        )}
        <Pagination page={data} onPage={(p) => setF((s) => ({ ...s, page: p }))} />
      </Card>
      {creating && <NewEntry onClose={() => setCreating(false)} />}
    </>
  )
}

interface Line { account_id: string; description: string; debit: string; credit: string }

function NewEntry({ onClose }: { onClose: () => void }) {
  const { data: accounts } = useGet<{ data: { id: number; code: string; name: string }[] }>('/accounts', { postable: 1 })
  const [head, setHead] = useState({ client_id: '', date: new Date().toISOString().slice(0, 10), book: 'diario', description: '' })
  const [lines, setLines] = useState<Line[]>([
    { account_id: '', description: '', debit: '', credit: '' },
    { account_id: '', description: '', debit: '', credit: '' },
  ])
  const create = useSend('post', '/journal-entries', { invalidate: ['/journal-entries'], onSuccess: onClose })
  const totals = useMemo(() => ({
    d: lines.reduce((a, l) => a + Number(l.debit || 0), 0),
    c: lines.reduce((a, l) => a + Number(l.credit || 0), 0),
  }), [lines])
  const balanced = Math.abs(totals.d - totals.c) < 0.005 && totals.d > 0
  const upd = (i: number, p: Partial<Line>) => setLines((ls) => ls.map((l, j) => (j === i ? { ...l, ...p } : l)))
  const options = (accounts?.data ?? []).map((a) => [String(a.id), `${a.code} · ${a.name}`] as [string, string])

  return (
    <Modal
      open
      onClose={onClose}
      size="xl"
      title="Nuevo asiento contable"
      footer={
        <>
          <span className={cn('mr-auto self-center text-sm font-medium', balanced ? 'text-emerald-700' : 'text-rose-600')}>
            Debe {fmtMoney(totals.d)} · Haber {fmtMoney(totals.c)} {balanced ? '· Cuadrado' : '· No cuadra'}
          </span>
          <Button variant="outline" onClick={onClose}>Cancelar</Button>
          <Button disabled={!balanced} loading={create.isPending} onClick={() => (head.client_id ? create.mutate({ ...head, lines }) : toast.error('Seleccione el cliente.'))}>Registrar asiento</Button>
        </>
      }
    >
      <div className="grid grid-cols-1 gap-3 md:grid-cols-4">
        <Field label="Cliente" className="md:col-span-2"><ClientPicker value={head.client_id} onChange={(v) => setHead({ ...head, client_id: v })} allowAll={false} className="w-full" /></Field>
        <Field label="Fecha"><Input type="date" value={head.date} onChange={(e) => setHead({ ...head, date: e.target.value })} /></Field>
        <Field label="Libro"><Select value={head.book} onChange={(e) => setHead({ ...head, book: e.target.value })} options={BOOKS} /></Field>
        <Field label="Glosa" className="md:col-span-4"><Input value={head.description} onChange={(e) => setHead({ ...head, description: e.target.value })} placeholder="Ej.: Conciliación bancaria - comisiones" /></Field>
      </div>
      <table className="mt-4 w-full text-sm">
        <thead><tr className="text-left text-xs uppercase text-slate-500"><th className="pb-2">Cuenta</th><th className="pb-2">Detalle</th><th className="w-32 pb-2 text-right">Debe</th><th className="w-32 pb-2 text-right">Haber</th><th /></tr></thead>
        <tbody>
          {lines.map((l, i) => (
            <tr key={i}>
              <td className="pr-2 pb-2"><Select value={l.account_id} onChange={(e) => upd(i, { account_id: e.target.value })} placeholder="Seleccione cuenta" options={options} /></td>
              <td className="pr-2 pb-2"><Input value={l.description} onChange={(e) => upd(i, { description: e.target.value })} /></td>
              <td className="pr-2 pb-2"><Input type="number" step="0.01" className="text-right" value={l.debit} onChange={(e) => upd(i, { debit: e.target.value, credit: '' })} /></td>
              <td className="pr-2 pb-2"><Input type="number" step="0.01" className="text-right" value={l.credit} onChange={(e) => upd(i, { credit: e.target.value, debit: '' })} /></td>
              <td className="pb-2">{lines.length > 2 && <button onClick={() => setLines(lines.filter((_, j) => j !== i))} className="p-1 text-slate-400 hover:text-rose-600" aria-label="Eliminar línea"><Trash2 className="h-4 w-4" /></button>}</td>
            </tr>
          ))}
        </tbody>
      </table>
      <Button size="sm" variant="secondary" icon={<Plus className="h-4 w-4" />} onClick={() => setLines([...lines, { account_id: '', description: '', debit: '', credit: '' }])}>Agregar línea</Button>
    </Modal>
  )
}
