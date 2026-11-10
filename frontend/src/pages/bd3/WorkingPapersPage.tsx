import { useState } from 'react'
import { CheckCheck, Download, Plus, ShieldCheck } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useAuth } from '@/context/AuthContext'
import { useGet, useMeta, useSend } from '@/hooks/useApi'
import { api, download, errorMessage, toFormData } from '@/lib/api'
import type { Paginated } from '@/lib/types'
import { Badge, Button, Card, Empty, Field, Input, Loading, Modal, PageHeader, Pagination, Select, StatusBadge, TableWrap, Textarea, Toolbar } from '@/components/ui'
import { ClientPicker } from '@/components/ClientPicker'
import { FileDrop } from '@/components/FileDrop'
import { fmtDate } from '@/lib/utils'

interface Paper {
  id: number
  reference?: string
  title: string
  area: string
  status: string
  description?: string
  conclusion?: string
  original_name?: string | null
  client?: { business_name: string }
  preparer?: { name: string }
  reviewer?: { name: string } | null
  period?: { year: number; month: number } | null
  created_at: string
}

export default function WorkingPapersPage() {
  const { hasRole } = useAuth()
  const { data: meta } = useMeta()
  const [f, setF] = useState({ client_id: '', area: '', status: '', search: '', page: 1 })
  const set = (p: Partial<typeof f>) => setF((s) => ({ ...s, page: 1, ...p }))
  const { data, isLoading } = useGet<Paginated<Paper>>('/working-papers', f)
  const [creating, setCreating] = useState(false)
  const review = useSend<{ id: number; status: string }>('post', (v) => `/working-papers/${v.id}`, { invalidate: ['/working-papers'] })
  const manager = hasRole('socio', 'supervisor')

  return (
    <>
      <PageHeader tag="R9" title="Papeles de trabajo" subtitle="Evidencia del trabajo realizado por cliente y periodo, con revisión del supervisor." actions={<Button icon={<Plus className="h-4 w-4" />} onClick={() => setCreating(true)}>Nuevo papel de trabajo</Button>} />
      <Card bodyClass="p-0">
        <Toolbar>
          <ClientPicker value={f.client_id} onChange={(v) => set({ client_id: v })} />
          <Select value={f.area} onChange={(e) => set({ area: e.target.value })} placeholder="Todas las áreas" options={meta?.task_areas} className="w-44" />
          <Select value={f.status} onChange={(e) => set({ status: e.target.value })} placeholder="Todos los estados" options={{ preparado: 'Preparado', revisado: 'Revisado', aprobado: 'Aprobado' }} className="w-44" />
          <Input placeholder="Buscar..." value={f.search} onChange={(e) => set({ search: e.target.value })} className="w-56" />
        </Toolbar>
        {isLoading ? <Loading /> : !data?.data.length ? <Empty title="Sin papeles de trabajo" /> : (
          <TableWrap>
            <table className="table-base">
              <thead><tr><th>Ref.</th><th>Título</th><th>Cliente</th><th>Área</th><th>Preparado</th><th>Revisado</th><th>Estado</th><th /></tr></thead>
              <tbody className="divide-y divide-slate-100">
                {data.data.map((p) => (
                  <tr key={p.id}>
                    <td className="font-mono text-xs font-semibold">{p.reference}</td>
                    <td className="max-w-[280px]"><p className="truncate font-medium">{p.title}</p>{p.conclusion && <p className="truncate text-xs text-slate-500">{p.conclusion}</p>}</td>
                    <td className="max-w-[180px] truncate">{p.client?.business_name}</td>
                    <td><Badge tone="brand">{meta?.task_areas[p.area] ?? p.area}</Badge></td>
                    <td className="text-xs">{p.preparer?.name}<br /><span className="text-slate-400">{fmtDate(p.created_at)}</span></td>
                    <td className="text-xs">{p.reviewer?.name ?? '—'}</td>
                    <td><StatusBadge status={p.status} /></td>
                    <td className="whitespace-nowrap text-right">
                      {p.original_name && <Button size="sm" variant="ghost" icon={<Download className="h-4 w-4" />} onClick={() => download(`/working-papers/${p.id}/download`, undefined, p.original_name!)} aria-label="Descargar" />}
                      {manager && p.status === 'preparado' && <Button size="sm" variant="ghost" icon={<CheckCheck className="h-4 w-4" />} onClick={() => review.mutate({ id: p.id, status: 'revisado' })}>Revisar</Button>}
                      {manager && p.status === 'revisado' && <Button size="sm" variant="secondary" icon={<ShieldCheck className="h-4 w-4" />} onClick={() => review.mutate({ id: p.id, status: 'aprobado' })}>Aprobar</Button>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </TableWrap>
        )}
        <Pagination page={data} onPage={(p) => setF((s) => ({ ...s, page: p }))} />
      </Card>
      {creating && <NewPaper onClose={() => setCreating(false)} />}
    </>
  )
}

function NewPaper({ onClose }: { onClose: () => void }) {
  const { data: meta } = useMeta()
  const qc = useQueryClient()
  const [v, setV] = useState({ client_id: '', reference: '', title: '', area: 'contable', description: '', conclusion: '' })
  const [files, setFiles] = useState<File[]>([])
  const [loading, setLoading] = useState(false)
  const submit = async () => {
    setLoading(true)
    try {
      const { data } = await api.post('/working-papers', toFormData({ ...v, file: files[0] }))
      toast.success(data.message)
      qc.invalidateQueries({ predicate: (q) => String(q.queryKey[0]).startsWith('/working-papers') })
      onClose()
    } catch (e) {
      toast.error(errorMessage(e))
    } finally {
      setLoading(false)
    }
  }
  return (
    <Modal open onClose={onClose} size="lg" title="Nuevo papel de trabajo" footer={<><Button variant="outline" onClick={onClose}>Cancelar</Button><Button loading={loading} disabled={!v.client_id || !v.title} onClick={submit}>Guardar</Button></>}>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <Field label="Cliente" className="sm:col-span-3"><ClientPicker value={v.client_id} onChange={(c) => setV({ ...v, client_id: c })} allowAll={false} className="w-full" /></Field>
        <Field label="Referencia"><Input value={v.reference} onChange={(e) => setV({ ...v, reference: e.target.value })} placeholder="A-1" /></Field>
        <Field label="Título" className="sm:col-span-2"><Input value={v.title} onChange={(e) => setV({ ...v, title: e.target.value })} /></Field>
        <Field label="Área"><Select value={v.area} onChange={(e) => setV({ ...v, area: e.target.value })} options={meta?.task_areas} /></Field>
        <Field label="Procedimientos aplicados" className="sm:col-span-3"><Textarea rows={3} value={v.description} onChange={(e) => setV({ ...v, description: e.target.value })} /></Field>
        <Field label="Conclusión" className="sm:col-span-3"><Textarea rows={2} value={v.conclusion} onChange={(e) => setV({ ...v, conclusion: e.target.value })} /></Field>
        <div className="sm:col-span-3"><FileDrop files={files} onChange={setFiles} multiple={false} hint="Archivo de soporte (Excel, PDF...)" /></div>
      </div>
    </Modal>
  )
}
