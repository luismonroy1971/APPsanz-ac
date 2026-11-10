import { useState } from 'react'
import { CheckCheck, Download, EyeOff, Send, Sparkles, Trash2, Upload } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useAuth } from '@/context/AuthContext'
import { useGet, useMeta, useSend } from '@/hooks/useApi'
import { api, download, errorMessage, toFormData } from '@/lib/api'
import type { FinancialReport, Paginated } from '@/lib/types'
import { Badge, Button, Card, Empty, Field, Input, Loading, Modal, PageHeader, Pagination, Select, StatusBadge, TableWrap, Toolbar } from '@/components/ui'
import { ClientPicker } from '@/components/ClientPicker'
import { FileDrop } from '@/components/FileDrop'
import { fmtDate, MONTHS } from '@/lib/utils'

export default function FinancialReportsPage() {
  const { hasRole } = useAuth()
  const { data: meta } = useMeta()
  const [f, setF] = useState({ client_id: '', report_code: '', status: '', year: '', page: 1 })
  const set = (p: Partial<typeof f>) => setF((s) => ({ ...s, page: 1, ...p }))
  const { data, isLoading } = useGet<Paginated<FinancialReport>>('/financial-reports', f)
  const [gen, setGen] = useState(false)
  const [up, setUp] = useState(false)
  const opts = { invalidate: ['/financial-reports', '/dashboard'] }
  const action = useSend<{ id: number; action: string }>('patch', (v) => `/financial-reports/${v.id}`, opts)
  const del = useSend<{ id: number }>('delete', (v) => `/financial-reports/${v.id}`, opts)
  const manager = hasRole('socio', 'supervisor')

  return (
    <>
      <PageHeader
        tag="BD.2"
        title="Informes R5 – R8"
        subtitle="Libros contables, EEFF periódicos, EEFF anual y otros informes. Flujo: borrador → revisado → publicado al cliente."
        actions={
          <>
            <Button variant="outline" icon={<Upload className="h-4 w-4" />} onClick={() => setUp(true)}>Cargar informe</Button>
            <Button icon={<Sparkles className="h-4 w-4" />} onClick={() => setGen(true)}>Generar desde contabilidad</Button>
          </>
        }
      />
      <Card bodyClass="p-0">
        <Toolbar>
          <ClientPicker value={f.client_id} onChange={(v) => set({ client_id: v })} />
          <Select value={f.report_code} onChange={(e) => set({ report_code: e.target.value })} placeholder="R5 a R8" options={Object.entries(meta?.report_codes ?? {}).map(([k, v]) => [k, `${k} · ${v}`] as [string, string])} className="w-60" />
          <Select value={f.status} onChange={(e) => set({ status: e.target.value })} placeholder="Todos los estados" options={{ borrador: 'Borrador', revisado: 'Revisado', publicado: 'Publicado' }} className="w-44" />
          <Input type="number" placeholder="Año" value={f.year} onChange={(e) => set({ year: e.target.value })} className="w-28" />
        </Toolbar>
        {isLoading ? <Loading /> : !data?.data.length ? <Empty title="Sin informes" /> : (
          <TableWrap>
            <table className="table-base">
              <thead><tr><th>Código</th><th>Informe</th><th>Cliente</th><th>Periodo</th><th>Elaborado</th><th>Revisado</th><th>Estado</th><th /></tr></thead>
              <tbody className="divide-y divide-slate-100">
                {data.data.map((r) => (
                  <tr key={r.id}>
                    <td><Badge tone="gold">{r.report_code}</Badge></td>
                    <td className="max-w-[300px] truncate font-medium">{r.title}</td>
                    <td className="max-w-[180px] truncate">{r.client?.business_name}</td>
                    <td className="whitespace-nowrap">{r.month ? `${MONTHS[r.month - 1].slice(0, 3)} ${r.year}` : r.year}</td>
                    <td className="whitespace-nowrap text-xs">{r.generator?.name}<br /><span className="text-slate-400">{fmtDate(r.created_at)}</span></td>
                    <td className="text-xs">{r.reviewer?.name ?? '—'}</td>
                    <td><StatusBadge status={r.status} /></td>
                    <td className="whitespace-nowrap text-right">
                      <Button size="sm" variant="ghost" icon={<Download className="h-4 w-4" />} onClick={() => download(`/financial-reports/${r.id}/download`, undefined, r.original_name ?? 'informe')} aria-label="Descargar" />
                      {r.status === 'borrador' && <Button size="sm" variant="ghost" icon={<CheckCheck className="h-4 w-4" />} onClick={() => action.mutate({ id: r.id, action: 'review' })}>Revisado</Button>}
                      {manager && !r.published && <Button size="sm" variant="secondary" icon={<Send className="h-4 w-4" />} onClick={() => action.mutate({ id: r.id, action: 'publish' })}>Publicar</Button>}
                      {manager && r.published && <Button size="sm" variant="ghost" icon={<EyeOff className="h-4 w-4" />} onClick={() => action.mutate({ id: r.id, action: 'unpublish' })}>Retirar</Button>}
                      {!r.published && <Button size="sm" variant="ghost" icon={<Trash2 className="h-4 w-4 text-rose-500" />} onClick={() => del.mutate({ id: r.id })} aria-label="Eliminar" />}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </TableWrap>
        )}
        <Pagination page={data} onPage={(p) => setF((s) => ({ ...s, page: p }))} />
      </Card>
      {gen && <GenerateModal onClose={() => setGen(false)} />}
      {up && <UploadModal onClose={() => setUp(false)} />}
    </>
  )
}

function GenerateModal({ onClose }: { onClose: () => void }) {
  const { data: meta } = useMeta()
  const now = new Date()
  const [v, setV] = useState({ client_id: '', type: 'registro_compras', year: String(now.getFullYear()), month: String(now.getMonth() || 12), format: 'pdf' })
  const gen = useSend('post', '/financial-reports/generate', { invalidate: ['/financial-reports', '/dashboard'], onSuccess: onClose })
  const types = Object.entries(meta?.report_types ?? {}).filter(([k]) => k !== 'otro').map(([k, t]) => [k, `${t.code} · ${t.label}`] as [string, string])
  return (
    <Modal open onClose={onClose} title="Generar informe desde la contabilidad" footer={<><Button variant="outline" onClick={onClose}>Cancelar</Button><Button loading={gen.isPending} disabled={!v.client_id} onClick={() => gen.mutate(v)}>Generar</Button></>}>
      <div className="grid grid-cols-2 gap-4">
        <Field label="Cliente" className="col-span-2"><ClientPicker value={v.client_id} onChange={(c) => setV({ ...v, client_id: c })} allowAll={false} className="w-full" /></Field>
        <Field label="Informe" className="col-span-2"><Select value={v.type} onChange={(e) => setV({ ...v, type: e.target.value })} options={types} /></Field>
        <Field label="Ejercicio"><Input type="number" value={v.year} onChange={(e) => setV({ ...v, year: e.target.value })} /></Field>
        {v.type !== 'eeff_anual' && <Field label="Mes"><Select value={v.month} onChange={(e) => setV({ ...v, month: e.target.value })} options={MONTHS.map((m, i) => [String(i + 1), m] as [string, string])} /></Field>}
        <Field label="Formato"><Select value={v.format} onChange={(e) => setV({ ...v, format: e.target.value })} options={{ pdf: 'PDF', xlsx: 'Excel' }} /></Field>
      </div>
    </Modal>
  )
}

function UploadModal({ onClose }: { onClose: () => void }) {
  const qc = useQueryClient()
  const now = new Date()
  const [v, setV] = useState({ client_id: '', report_code: 'R8', title: '', year: String(now.getFullYear()), month: '' })
  const [files, setFiles] = useState<File[]>([])
  const [loading, setLoading] = useState(false)
  const submit = async () => {
    setLoading(true)
    try {
      const { data } = await api.post('/financial-reports', toFormData({ ...v, file: files[0] }))
      toast.success(data.message)
      qc.invalidateQueries({ predicate: (q) => String(q.queryKey[0]).startsWith('/financial-reports') })
      onClose()
    } catch (e) {
      toast.error(errorMessage(e))
    } finally {
      setLoading(false)
    }
  }
  return (
    <Modal open onClose={onClose} title="Cargar informe elaborado" footer={<><Button variant="outline" onClick={onClose}>Cancelar</Button><Button loading={loading} disabled={!v.client_id || !v.title || !files.length} onClick={submit}>Cargar</Button></>}>
      <div className="grid grid-cols-2 gap-4">
        <Field label="Cliente" className="col-span-2"><ClientPicker value={v.client_id} onChange={(c) => setV({ ...v, client_id: c })} allowAll={false} className="w-full" /></Field>
        <Field label="Código"><Select value={v.report_code} onChange={(e) => setV({ ...v, report_code: e.target.value })} options={{ R5: 'R5 · Libros', R6: 'R6 · EEFF periódicos', R7: 'R7 · EEFF anual', R8: 'R8 · Otros informes' }} /></Field>
        <Field label="Ejercicio"><Input type="number" value={v.year} onChange={(e) => setV({ ...v, year: e.target.value })} /></Field>
        <Field label="Título" className="col-span-2"><Input value={v.title} onChange={(e) => setV({ ...v, title: e.target.value })} placeholder="Ej.: Declaración Jurada Anual de Renta 2025" /></Field>
        <Field label="Mes (opcional)"><Select value={v.month} onChange={(e) => setV({ ...v, month: e.target.value })} placeholder="—" options={MONTHS.map((m, i) => [String(i + 1), m] as [string, string])} /></Field>
        <div className="col-span-2"><FileDrop files={files} onChange={setFiles} multiple={false} hint="PDF, Excel, Word o ZIP" /></div>
      </div>
    </Modal>
  )
}
