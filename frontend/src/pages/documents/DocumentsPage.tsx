import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Download, Eye, FileCode2, FileImage, FileSpreadsheet, FileText, Upload } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useAuth } from '@/context/AuthContext'
import { useGet, useMeta } from '@/hooks/useApi'
import { api, download, errorMessage, toFormData } from '@/lib/api'
import type { DocumentItem, Paginated } from '@/lib/types'
import { Badge, Button, Card, Empty, Field, Input, Loading, Modal, PageHeader, Pagination, Select, StatusBadge, TableWrap, Textarea, Toolbar } from '@/components/ui'
import { ClientPicker } from '@/components/ClientPicker'
import { FileDrop } from '@/components/FileDrop'
import { cn, fmtBytes, fmtDateTime } from '@/lib/utils'
import DocumentDetail from './DocumentDetail'

const STATUS_TABS: [string, string][] = [
  ['', 'Todos'],
  ['ingresado,reingresado', 'Nuevos'],
  ['en_proceso', 'En proceso'],
  ['observado', 'Observados'],
  ['devuelto', 'Devueltos'],
  ['procesado', 'Procesados'],
]

export function FileIcon({ ext, className }: { ext?: string; className?: string }) {
  const cls = cn('h-5 w-5', className)
  if (ext === 'xml' || ext === 'zip') return <FileCode2 className={cn(cls, 'text-emerald-600')} />
  if (['jpg', 'jpeg', 'png', 'webp'].includes(ext ?? '')) return <FileImage className={cn(cls, 'text-violet-600')} />
  if (['xlsx', 'xls', 'csv'].includes(ext ?? '')) return <FileSpreadsheet className={cn(cls, 'text-green-700')} />
  return <FileText className={cn(cls, 'text-rose-600')} />
}

export default function DocumentsPage() {
  const { isInternal, can } = useAuth()
  const { data: meta } = useMeta()
  const [params] = useSearchParams()
  const [filters, setFilters] = useState({ status: params.get('status') ?? '', client_id: '', category: '', search: '', page: 1, mine: false })
  const [upload, setUpload] = useState(false)
  const [selected, setSelected] = useState<number | null>(null)

  const { data, isLoading } = useGet<Paginated<DocumentItem> & { stats: Record<string, number> }>('/documents', filters, { refetchInterval: 20_000 })
  const set = (patch: Partial<typeof filters>) => setFilters((f) => ({ ...f, page: 1, ...patch }))
  const stats = data?.stats ?? {}

  return (
    <>
      <PageHeader
        tag={isInternal ? 'S1' : 'C1'}
        title={isInternal ? 'Bandeja de documentos' : 'Mis documentos'}
        subtitle={isInternal ? 'Documentos recibidos de clientes y otras fuentes: procesamiento automático, validación y registro.' : 'Envíe sus comprobantes y documentos a SANZ & AC. Los XML de comprobantes electrónicos se procesan automáticamente.'}
        actions={
          can('documentos.subir') && (
            <Button icon={<Upload className="h-4 w-4" />} onClick={() => setUpload(true)}>
              {isInternal ? 'Cargar documentos' : 'Enviar documentos'}
            </Button>
          )
        }
      />

      <div className="mb-4 flex gap-2 overflow-x-auto">
        {STATUS_TABS.map(([value, label]) => {
          const count = value ? value.split(',').reduce((a, s) => a + (stats[s] ?? 0), 0) : Object.values(stats).reduce((a, b) => a + b, 0)
          return (
            <button
              key={label}
              onClick={() => set({ status: value })}
              className={cn(
                'flex items-center gap-2 whitespace-nowrap rounded-full border px-3 py-1.5 text-sm transition',
                filters.status === value ? 'border-brand-700 bg-brand-700 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-brand-300',
              )}
            >
              {label}
              <span className={cn('rounded-full px-1.5 text-xs', filters.status === value ? 'bg-white/20' : 'bg-slate-100')}>{count}</span>
            </button>
          )
        })}
      </div>

      <Card bodyClass="p-0">
        <Toolbar>
          <ClientPicker value={filters.client_id} onChange={(v) => set({ client_id: v })} />
          <Select value={filters.category} onChange={(e) => set({ category: e.target.value })} options={meta?.document_categories} placeholder="Todas las categorías" className="w-56" />
          <Input placeholder="Buscar por nombre de archivo..." value={filters.search} onChange={(e) => set({ search: e.target.value })} className="w-64" />
          {isInternal && (
            <label className="flex items-center gap-2 pb-2 text-sm text-slate-600">
              <input type="checkbox" checked={filters.mine} onChange={(e) => set({ mine: e.target.checked })} className="rounded border-slate-300" /> Asignados a mí
            </label>
          )}
        </Toolbar>
        {isLoading ? (
          <Loading />
        ) : !data?.data.length ? (
          <Empty title="No hay documentos" description="Los documentos enviados aparecerán aquí con su estado de procesamiento." />
        ) : (
          <TableWrap>
            <table className="table-base">
              <thead>
                <tr>
                  <th>Documento</th>
                  {isInternal && <th>Cliente</th>}
                  <th>Categoría</th>
                  <th>Periodo</th>
                  <th>Recibido</th>
                  <th>Comprobante</th>
                  <th>Estado</th>
                  <th />
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {data.data.map((d) => (
                  <tr key={d.id} className="cursor-pointer" onClick={() => setSelected(d.id)}>
                    <td>
                      <div className="flex items-center gap-3">
                        <FileIcon ext={d.extension} />
                        <div className="min-w-0">
                          <p className="max-w-[280px] truncate font-medium text-slate-800">{d.original_name}</p>
                          <p className="text-xs text-slate-400">
                            {fmtBytes(d.size)} · {d.uploader?.name}
                            {d.parent_id && <Badge tone="violet" className="ml-2">Reingreso</Badge>}
                            {d.source === 'otras_fuentes' && <Badge tone="slate" className="ml-2">Otras fuentes</Badge>}
                          </p>
                        </div>
                      </div>
                    </td>
                    {isInternal && <td className="max-w-[200px] truncate">{d.client?.business_name}</td>}
                    <td className="capitalize">{d.category}</td>
                    <td>{d.period ?? '—'}</td>
                    <td className="whitespace-nowrap text-xs">{fmtDateTime(d.created_at)}</td>
                    <td className="whitespace-nowrap text-xs">{d.voucher ? `${d.voucher.series}-${d.voucher.number}` : '—'}</td>
                    <td>
                      <StatusBadge status={d.status} />
                    </td>
                    <td className="whitespace-nowrap text-right" onClick={(e) => e.stopPropagation()}>
                      <Button size="sm" variant="ghost" icon={<Eye className="h-4 w-4" />} onClick={() => setSelected(d.id)} aria-label="Ver" />
                      <Button size="sm" variant="ghost" icon={<Download className="h-4 w-4" />} onClick={() => download(`/documents/${d.id}/download`, undefined, d.original_name)} aria-label="Descargar" />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </TableWrap>
        )}
        <Pagination page={data} onPage={(p) => setFilters((f) => ({ ...f, page: p }))} />
      </Card>

      <UploadModal open={upload} onClose={() => setUpload(false)} />
      {selected && <DocumentDetail id={selected} onClose={() => setSelected(null)} />}
    </>
  )
}

function UploadModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { isInternal } = useAuth()
  const { data: meta } = useMeta()
  const qc = useQueryClient()
  const [files, setFiles] = useState<File[]>([])
  const [form, setForm] = useState({ client_id: '', category: 'comprobante', period: '', notes: '' })
  const [loading, setLoading] = useState(false)

  const submit = async () => {
    if (!files.length) return toast.error('Seleccione al menos un archivo.')
    if (isInternal && !form.client_id) return toast.error('Seleccione el cliente.')
    setLoading(true)
    try {
      const { data } = await api.post('/documents', toFormData({ ...form, files }))
      toast.success(data.message)
      qc.invalidateQueries({ predicate: (q) => String(q.queryKey[0]).startsWith('/documents') || q.queryKey[0] === '/dashboard' })
      setFiles([])
      onClose()
    } catch (e) {
      toast.error(errorMessage(e))
    } finally {
      setLoading(false)
    }
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      size="lg"
      title={isInternal ? 'Cargar documentos (otras fuentes)' : 'Enviar documentos a SANZ & AC'}
      footer={
        <>
          <Button variant="outline" onClick={onClose}>
            Cancelar
          </Button>
          <Button onClick={submit} loading={loading} icon={<Upload className="h-4 w-4" />}>
            Enviar {files.length > 0 && `(${files.length})`}
          </Button>
        </>
      }
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        {isInternal && (
          <Field label="Cliente" className="sm:col-span-2">
            <ClientPicker value={form.client_id} onChange={(v) => setForm({ ...form, client_id: v })} allowAll={false} className="w-full" />
          </Field>
        )}
        <Field label="Tipo de documento">
          <Select value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} options={meta?.document_categories} />
        </Field>
        <Field label="Periodo (opcional)" hint="Mes contable al que corresponde">
          <Input type="month" value={form.period} onChange={(e) => setForm({ ...form, period: e.target.value })} />
        </Field>
        <Field label="Comentarios para su analista (opcional)" className="sm:col-span-2">
          <Textarea rows={2} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
        </Field>
        <div className="sm:col-span-2">
          <FileDrop files={files} onChange={setFiles} />
          {form.category === 'comprobante' && (
            <p className="mt-2 rounded-lg bg-emerald-50 px-3 py-2 text-xs text-emerald-800">
              Recomendado: envíe el <b>XML</b> (o el ZIP del CPE) de cada factura, boleta o nota. Se registra automáticamente en compras/ventas con su asiento contable.
              {meta?.ai_extraction && ' Los PDF e imágenes se procesan con extracción inteligente.'}
            </p>
          )}
        </div>
      </div>
    </Modal>
  )
}
