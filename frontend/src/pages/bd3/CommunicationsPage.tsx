import { useState } from 'react'
import { CheckCircle2, Megaphone, Paperclip, Plus } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useAuth } from '@/context/AuthContext'
import { useGet, useSend, useStaff } from '@/hooks/useApi'
import { api, download, errorMessage, toFormData } from '@/lib/api'
import type { Paginated } from '@/lib/types'
import { Badge, Button, Card, Empty, Field, Input, Loading, Modal, PageHeader, Pagination, PriorityBadge, Select, Textarea } from '@/components/ui'
import { ClientPicker } from '@/components/ClientPicker'
import { cn, fmtDateTime } from '@/lib/utils'

interface Comm {
  id: number
  type: string
  title: string
  body: string
  priority: string
  author?: { id: number; name: string }
  author_id: number
  client?: { business_name: string } | null
  attachment_name?: string | null
  recipients_count: number
  read_count: number
  my_read_at?: string | null
  my_acknowledged_at?: string | null
  created_at: string
}

const TYPE_LABEL: Record<string, string> = { instruccion: 'Instrucción', directiva: 'Directiva', comunicado: 'Comunicado' }

export default function CommunicationsPage() {
  const { user, hasRole } = useAuth()
  const [f, setF] = useState({ type: '', inbox: false, page: 1 })
  const { data, isLoading } = useGet<Paginated<Comm>>('/communications', f)
  const [open, setOpen] = useState<Comm | null>(null)
  const [creating, setCreating] = useState(false)
  const qc = useQueryClient()
  const ack = useSend<{ id: number }>('post', (v) => `/communications/${v.id}/acknowledge`, { invalidate: ['/communications'] })

  const openComm = async (c: Comm) => {
    setOpen(c)
    if (!c.my_read_at && c.author_id !== user?.id) {
      await api.get(`/communications/${c.id}`).catch(() => null)
      qc.invalidateQueries({ predicate: (q) => String(q.queryKey[0]).startsWith('/communications') })
    }
  }

  return (
    <>
      <PageHeader
        tag="R11"
        title="Instrucciones, directivas y comunicaciones internas"
        subtitle="Comunicación formal del Socio y Supervisor al equipo, con control de lectura y confirmación."
        actions={hasRole('socio', 'supervisor') && <Button icon={<Plus className="h-4 w-4" />} onClick={() => setCreating(true)}>Nueva comunicación</Button>}
      />
      <Card bodyClass="p-0">
        <div className="flex flex-wrap items-end gap-3 border-b border-slate-100 px-4 py-3">
          <Select value={f.type} onChange={(e) => setF({ ...f, type: e.target.value, page: 1 })} placeholder="Todos los tipos" options={TYPE_LABEL} className="w-48" />
          {hasRole('socio', 'supervisor') && <label className="flex items-center gap-2 pb-2 text-sm text-slate-600"><input type="checkbox" checked={f.inbox} onChange={(e) => setF({ ...f, inbox: e.target.checked, page: 1 })} /> Solo mi bandeja</label>}
        </div>
        {isLoading ? <Loading /> : !data?.data.length ? <Empty title="Sin comunicaciones" icon={<Megaphone className="h-6 w-6" />} /> : (
          <ul className="divide-y divide-slate-100">
            {data.data.map((c) => {
              const unread = !c.my_read_at && c.author_id !== user?.id
              return (
                <li key={c.id}>
                  <button onClick={() => openComm(c)} className="flex w-full items-start gap-4 px-5 py-4 text-left hover:bg-slate-50">
                    <span className={cn('mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full', unread ? 'bg-gold-500' : 'bg-transparent')} />
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <Badge tone={c.type === 'directiva' ? 'red' : c.type === 'instruccion' ? 'brand' : 'slate'}>{TYPE_LABEL[c.type]}</Badge>
                        <PriorityBadge priority={c.priority} />
                        <span className={cn('truncate text-sm', unread ? 'font-semibold text-slate-900' : 'font-medium text-slate-700')}>{c.title}</span>
                      </div>
                      <p className="mt-1 line-clamp-2 text-sm text-slate-500">{c.body}</p>
                      <p className="mt-1 text-xs text-slate-400">{c.author?.name} · {fmtDateTime(c.created_at)} {c.client && `· ${c.client.business_name}`}</p>
                    </div>
                    <div className="hidden shrink-0 text-right text-xs text-slate-500 sm:block">
                      <p>{c.read_count}/{c.recipients_count} leídos</p>
                      {c.my_acknowledged_at && <p className="mt-1 flex items-center justify-end gap-1 text-emerald-600"><CheckCircle2 className="h-3 w-3" /> Confirmado</p>}
                    </div>
                  </button>
                </li>
              )
            })}
          </ul>
        )}
        <Pagination page={data} onPage={(p) => setF({ ...f, page: p })} />
      </Card>

      {open && (
        <Modal
          open
          onClose={() => setOpen(null)}
          title={open.title}
          footer={
            open.author_id !== user?.id && !open.my_acknowledged_at ? (
              <Button icon={<CheckCircle2 className="h-4 w-4" />} loading={ack.isPending} onClick={() => ack.mutate({ id: open.id }, { onSuccess: () => setOpen(null) })}>Confirmar lectura</Button>
            ) : undefined
          }
        >
          <div className="mb-3 flex flex-wrap gap-2">
            <Badge tone="brand">{TYPE_LABEL[open.type]}</Badge>
            <PriorityBadge priority={open.priority} />
            <span className="text-xs text-slate-500">{open.author?.name} · {fmtDateTime(open.created_at)}</span>
          </div>
          <p className="whitespace-pre-wrap text-sm leading-relaxed text-slate-700">{open.body}</p>
          {open.attachment_name && (
            <button onClick={() => download(`/communications/${open.id}/attachment`, undefined, open.attachment_name!)} className="mt-4 flex items-center gap-2 text-sm text-brand-700 hover:underline">
              <Paperclip className="h-4 w-4" /> {open.attachment_name}
            </button>
          )}
        </Modal>
      )}
      {creating && <NewComm onClose={() => setCreating(false)} />}
    </>
  )
}

function NewComm({ onClose }: { onClose: () => void }) {
  const { data: staff } = useStaff()
  const qc = useQueryClient()
  const [v, setV] = useState({ type: 'instruccion', priority: 'media', title: '', body: '', client_id: '' })
  const [recipients, setRecipients] = useState<number[]>([])
  const [file, setFile] = useState<File | null>(null)
  const [loading, setLoading] = useState(false)
  const submit = async () => {
    setLoading(true)
    try {
      const { data } = await api.post('/communications', toFormData({ ...v, recipients, attachment: file }))
      toast.success(data.message)
      qc.invalidateQueries({ predicate: (q) => String(q.queryKey[0]).startsWith('/communications') })
      onClose()
    } catch (e) {
      toast.error(errorMessage(e))
    } finally {
      setLoading(false)
    }
  }
  return (
    <Modal open onClose={onClose} size="lg" title="Nueva comunicación interna" footer={<><Button variant="outline" onClick={onClose}>Cancelar</Button><Button loading={loading} disabled={!v.title || !v.body} onClick={submit}>Publicar</Button></>}>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field label="Tipo"><Select value={v.type} onChange={(e) => setV({ ...v, type: e.target.value })} options={TYPE_LABEL} /></Field>
        <Field label="Prioridad"><Select value={v.priority} onChange={(e) => setV({ ...v, priority: e.target.value })} options={{ baja: 'Baja', media: 'Media', alta: 'Alta' }} /></Field>
        <Field label="Título" className="sm:col-span-2"><Input value={v.title} onChange={(e) => setV({ ...v, title: e.target.value })} /></Field>
        <Field label="Contenido" className="sm:col-span-2"><Textarea rows={6} value={v.body} onChange={(e) => setV({ ...v, body: e.target.value })} /></Field>
        <Field label="Cliente relacionado (opcional)"><ClientPicker value={v.client_id} onChange={(c) => setV({ ...v, client_id: c })} className="w-full" /></Field>
        <Field label="Adjunto"><input type="file" onChange={(e) => setFile(e.target.files?.[0] ?? null)} className="text-sm" /></Field>
        <Field label="Destinatarios" hint="Si no selecciona, se envía a todo el equipo interno" className="sm:col-span-2">
          <div className="flex flex-wrap gap-2">
            {(staff ?? []).map((s) => (
              <label key={s.id} className={cn('flex cursor-pointer items-center gap-2 rounded-full border px-3 py-1 text-xs', recipients.includes(s.id) ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-slate-200')}>
                <input type="checkbox" className="hidden" checked={recipients.includes(s.id)} onChange={(e) => setRecipients(e.target.checked ? [...recipients, s.id] : recipients.filter((x) => x !== s.id))} />
                {s.name}
              </label>
            ))}
          </div>
        </Field>
      </div>
    </Modal>
  )
}
