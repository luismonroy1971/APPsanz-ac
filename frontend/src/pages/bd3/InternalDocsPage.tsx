import { useState } from 'react'
import { Download, FolderLock, Trash2, Upload } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useGet, useSend } from '@/hooks/useApi'
import { api, download, errorMessage, toFormData } from '@/lib/api'
import type { Paginated } from '@/lib/types'
import { Badge, Button, Card, Empty, Field, Input, Loading, Modal, PageHeader, Pagination, Select, TableWrap, Textarea, Toolbar } from '@/components/ui'
import { ClientPicker } from '@/components/ClientPicker'
import { FileDrop } from '@/components/FileDrop'
import { fmtBytes, fmtDate } from '@/lib/utils'

interface Doc {
  id: number
  title: string
  category: string
  description?: string
  original_name: string
  size: number
  client?: { business_name: string } | null
  uploader?: { name: string }
  created_at: string
}

const CATS = { manual: 'Manual / procedimiento', politica: 'Política', plantilla: 'Plantilla', normativa: 'Normativa', general: 'General' }

export default function InternalDocsPage() {
  const [f, setF] = useState({ category: '', search: '', page: 1 })
  const { data, isLoading } = useGet<Paginated<Doc>>('/internal-documents', f)
  const [up, setUp] = useState(false)
  const del = useSend<{ id: number }>('delete', (v) => `/internal-documents/${v.id}`, { invalidate: ['/internal-documents'] })
  return (
    <>
      <PageHeader tag="BD.3" title="Documentos internos" subtitle="Manuales, políticas, plantillas y normativa del equipo SANZ & AC." actions={<Button icon={<Upload className="h-4 w-4" />} onClick={() => setUp(true)}>Subir documento</Button>} />
      <Card bodyClass="p-0">
        <Toolbar>
          <Select value={f.category} onChange={(e) => setF({ ...f, category: e.target.value, page: 1 })} placeholder="Todas las categorías" options={CATS} className="w-52" />
          <Input placeholder="Buscar por título..." value={f.search} onChange={(e) => setF({ ...f, search: e.target.value, page: 1 })} className="w-64" />
        </Toolbar>
        {isLoading ? <Loading /> : !data?.data.length ? <Empty title="Sin documentos internos" icon={<FolderLock className="h-6 w-6" />} /> : (
          <TableWrap>
            <table className="table-base">
              <thead><tr><th>Título</th><th>Categoría</th><th>Cliente</th><th>Archivo</th><th>Subido por</th><th /></tr></thead>
              <tbody className="divide-y divide-slate-100">
                {data.data.map((d) => (
                  <tr key={d.id}>
                    <td><p className="font-medium">{d.title}</p>{d.description && <p className="max-w-md truncate text-xs text-slate-500">{d.description}</p>}</td>
                    <td><Badge tone="brand">{CATS[d.category as keyof typeof CATS] ?? d.category}</Badge></td>
                    <td className="text-xs">{d.client?.business_name ?? '—'}</td>
                    <td className="text-xs">{d.original_name}<br /><span className="text-slate-400">{fmtBytes(d.size)}</span></td>
                    <td className="text-xs">{d.uploader?.name}<br /><span className="text-slate-400">{fmtDate(d.created_at)}</span></td>
                    <td className="whitespace-nowrap text-right">
                      <Button size="sm" variant="ghost" icon={<Download className="h-4 w-4" />} onClick={() => download(`/internal-documents/${d.id}/download`, undefined, d.original_name)} aria-label="Descargar" />
                      <Button size="sm" variant="ghost" icon={<Trash2 className="h-4 w-4 text-rose-500" />} onClick={() => del.mutate({ id: d.id })} aria-label="Eliminar" />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </TableWrap>
        )}
        <Pagination page={data} onPage={(p) => setF({ ...f, page: p })} />
      </Card>
      {up && <UploadDoc onClose={() => setUp(false)} />}
    </>
  )
}

function UploadDoc({ onClose }: { onClose: () => void }) {
  const qc = useQueryClient()
  const [v, setV] = useState({ title: '', category: 'manual', description: '', client_id: '' })
  const [files, setFiles] = useState<File[]>([])
  const [loading, setLoading] = useState(false)
  const submit = async () => {
    setLoading(true)
    try {
      const { data } = await api.post('/internal-documents', toFormData({ ...v, file: files[0] }))
      toast.success(data.message)
      qc.invalidateQueries({ predicate: (q) => String(q.queryKey[0]).startsWith('/internal-documents') })
      onClose()
    } catch (e) {
      toast.error(errorMessage(e))
    } finally {
      setLoading(false)
    }
  }
  return (
    <Modal open onClose={onClose} title="Subir documento interno" footer={<><Button variant="outline" onClick={onClose}>Cancelar</Button><Button loading={loading} disabled={!v.title || !files.length} onClick={submit}>Subir</Button></>}>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field label="Título" className="sm:col-span-2"><Input value={v.title} onChange={(e) => setV({ ...v, title: e.target.value })} /></Field>
        <Field label="Categoría"><Select value={v.category} onChange={(e) => setV({ ...v, category: e.target.value })} options={CATS} /></Field>
        <Field label="Cliente (opcional)"><ClientPicker value={v.client_id} onChange={(c) => setV({ ...v, client_id: c })} className="w-full" /></Field>
        <Field label="Descripción" className="sm:col-span-2"><Textarea rows={2} value={v.description} onChange={(e) => setV({ ...v, description: e.target.value })} /></Field>
        <div className="sm:col-span-2"><FileDrop files={files} onChange={setFiles} multiple={false} hint="Cualquier formato · máx. 20 MB" /></div>
      </div>
    </Modal>
  )
}
