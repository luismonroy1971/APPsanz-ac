import { useEffect, useRef, useState } from 'react'
import { MessageSquarePlus, Paperclip, Send, X } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useAuth } from '@/context/AuthContext'
import { useGet, useMeta, useSend, useStaff } from '@/hooks/useApi'
import { api, download, errorMessage, toFormData } from '@/lib/api'
import type { Conversation, Paginated } from '@/lib/types'
import { Avatar, Badge, Button, Card, Empty, Field, Input, Loading, Modal, PageHeader, PriorityBadge, Select, StatusBadge, Textarea } from '@/components/ui'
import { ClientPicker } from '@/components/ClientPicker'
import { cn, fmtDateTime, fromNow } from '@/lib/utils'

export default function ChatPage() {
  const { isInternal } = useAuth()
  const [filters, setFilters] = useState({ status: '', client_id: '', search: '', mine: false, per_page: 50 })
  const [selected, setSelected] = useState<number | null>(null)
  const [creating, setCreating] = useState(false)
  const { data, isLoading } = useGet<Paginated<Conversation> & { stats: Record<string, number> }>('/conversations', filters, { refetchInterval: 15_000 })

  useEffect(() => {
    if (!selected && data?.data.length) setSelected(data.data[0].id)
  }, [data, selected])

  return (
    <>
      <PageHeader
        tag={isInternal ? 'S2' : 'C2'}
        title={isInternal ? 'Consultas de clientes' : 'Chat de consultas'}
        subtitle={isInternal ? 'Respuestas a consultas y seguimiento (indicador R4).' : 'Comuníquese directamente con su equipo contable de SANZ & AC.'}
        actions={
          <Button icon={<MessageSquarePlus className="h-4 w-4" />} onClick={() => setCreating(true)}>
            Nueva consulta
          </Button>
        }
      />
      <div className="grid h-[calc(100vh-210px)] min-h-[520px] grid-cols-1 gap-4 lg:grid-cols-[360px_1fr]">
        <Card bodyClass="p-0 flex h-full flex-col" className="flex h-full flex-col overflow-hidden">
          <div className="space-y-2 border-b border-slate-100 p-3">
            <Input placeholder="Buscar asunto..." value={filters.search} onChange={(e) => setFilters({ ...filters, search: e.target.value })} />
            <div className="flex gap-2">
              <Select value={filters.status} onChange={(e) => setFilters({ ...filters, status: e.target.value })} placeholder="Todos los estados" options={{ abierta: 'Abiertas', en_seguimiento: 'En seguimiento', respondida: 'Respondidas', cerrada: 'Cerradas' }} />
            </div>
            {isInternal && (
              <div className="flex items-center gap-2">
                <ClientPicker value={filters.client_id} onChange={(v) => setFilters({ ...filters, client_id: v })} className="min-w-0 flex-1" />
                <label className="flex shrink-0 items-center gap-1 text-xs text-slate-600">
                  <input type="checkbox" checked={filters.mine} onChange={(e) => setFilters({ ...filters, mine: e.target.checked })} /> Mías
                </label>
              </div>
            )}
          </div>
          <div className="flex-1 overflow-y-auto">
            {isLoading ? (
              <Loading />
            ) : !data?.data.length ? (
              <Empty title="Sin consultas" />
            ) : (
              <ul className="divide-y divide-slate-100">
                {data.data.map((c) => (
                  <li key={c.id}>
                    <button onClick={() => setSelected(c.id)} className={cn('w-full px-4 py-3 text-left transition hover:bg-slate-50', selected === c.id && 'bg-brand-50/70')}>
                      <div className="flex items-center gap-2">
                        <span className={cn('min-w-0 flex-1 truncate text-sm', c.unread_count ? 'font-semibold text-slate-900' : 'font-medium text-slate-700')}>{c.subject}</span>
                        {!!c.unread_count && <span className="rounded-full bg-gold-500 px-1.5 text-[10px] font-bold text-brand-950">{c.unread_count}</span>}
                      </div>
                      <p className="mt-0.5 truncate text-xs text-slate-500">{c.latest_message?.body}</p>
                      <div className="mt-1.5 flex items-center gap-2">
                        <StatusBadge status={c.status} />
                        {isInternal && <span className="truncate text-[11px] text-slate-400">{c.client?.business_name}</span>}
                        <span className="ml-auto whitespace-nowrap text-[11px] text-slate-400">{fromNow(c.last_message_at)}</span>
                      </div>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </Card>
        <Card bodyClass="p-0 h-full" className="h-full overflow-hidden">
          {selected ? <Thread id={selected} /> : <Empty title="Seleccione una consulta" />}
        </Card>
      </div>
      <NewConversation open={creating} onClose={() => setCreating(false)} onCreated={(id) => setSelected(id)} />
    </>
  )
}

function Thread({ id }: { id: number }) {
  const { user, isInternal } = useAuth()
  const qc = useQueryClient()
  const { data } = useGet<{ data: Conversation }>(`/conversations/${id}`, undefined, { refetchInterval: 10_000 })
  const { data: staff } = useStaff()
  const conv = data?.data
  const [body, setBody] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [sending, setSending] = useState(false)
  const endRef = useRef<HTMLDivElement>(null)
  const fileRef = useRef<HTMLInputElement>(null)
  const update = useSend<Record<string, string>>('patch', `/conversations/${id}`, { invalidate: ['/conversations'] })

  useEffect(() => {
    endRef.current?.scrollIntoView({ behavior: 'smooth' })
  }, [conv?.messages?.length])

  if (!conv) return <Loading />

  const send = async () => {
    if (!body.trim()) return
    setSending(true)
    try {
      await api.post(`/conversations/${id}/messages`, toFormData({ body, attachment: file }))
      setBody('')
      setFile(null)
      qc.invalidateQueries({ predicate: (q) => String(q.queryKey[0]).startsWith('/conversations') })
    } catch (e) {
      toast.error(errorMessage(e))
    } finally {
      setSending(false)
    }
  }

  return (
    <div className="flex h-full flex-col">
      <div className="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-3">
        <div className="min-w-0 flex-1">
          <h3 className="truncate text-sm font-semibold text-slate-900">{conv.subject}</h3>
          <p className="text-xs text-slate-500">
            {isInternal && `${conv.client?.business_name} · `}
            <span className="capitalize">{conv.category}</span> · Iniciada {fmtDateTime(conv.created_at)}
          </p>
        </div>
        <PriorityBadge priority={conv.priority} />
        {isInternal ? (
          <>
            <Select value={conv.assignee ? String(conv.assignee.id) : ''} onChange={(e) => update.mutate({ assigned_to: e.target.value })} placeholder="Sin asignar" options={(staff ?? []).map((s) => [String(s.id), s.name] as [string, string])} className="w-44 py-1.5" />
            <Select value={conv.status} onChange={(e) => update.mutate({ status: e.target.value })} options={{ abierta: 'Abierta', respondida: 'Respondida', en_seguimiento: 'En seguimiento', cerrada: 'Cerrada' }} className="w-40 py-1.5" />
          </>
        ) : (
          <>
            <StatusBadge status={conv.status} />
            {conv.status !== 'cerrada' ? (
              <Button size="sm" variant="outline" onClick={() => update.mutate({ status: 'cerrada' })}>
                Cerrar consulta
              </Button>
            ) : null}
          </>
        )}
      </div>

      <div className="flex-1 space-y-4 overflow-y-auto bg-slate-50/60 px-5 py-4">
        {conv.messages?.map((m) => {
          const mine = m.user_id === user?.id
          return (
            <div key={m.id} className={cn('flex gap-3', mine && 'flex-row-reverse')}>
              <Avatar name={m.user?.name} className={m.from_client ? 'bg-gold-100 text-gold-700' : ''} />
              <div className={cn('max-w-[75%]', mine && 'items-end text-right')}>
                <div className={cn('mb-1 flex items-center gap-2 text-[11px] text-slate-400', mine && 'justify-end')}>
                  <span className="font-medium text-slate-600">{m.user?.name}</span>
                  {!m.from_client && <Badge tone="brand" className="py-0">SANZ &amp; AC</Badge>}
                  <span>{fmtDateTime(m.created_at)}</span>
                </div>
                <div className={cn('inline-block whitespace-pre-wrap rounded-2xl px-4 py-2.5 text-left text-sm shadow-sm', mine ? 'rounded-tr-sm bg-brand-700 text-white' : 'rounded-tl-sm bg-white text-slate-800')}>
                  {m.body}
                  {m.attachment_name && (
                    <button onClick={() => download(`/messages/${m.id}/attachment`, undefined, m.attachment_name!)} className={cn('mt-2 flex items-center gap-1 text-xs underline', mine ? 'text-brand-100' : 'text-brand-700')}>
                      <Paperclip className="h-3 w-3" /> {m.attachment_name}
                    </button>
                  )}
                </div>
              </div>
            </div>
          )
        })}
        <div ref={endRef} />
      </div>

      {conv.status === 'cerrada' && !isInternal ? (
        <p className="border-t border-slate-100 px-5 py-4 text-center text-sm text-slate-500">Esta consulta está cerrada. Si necesita algo más, abra una nueva consulta.</p>
      ) : (
        <div className="border-t border-slate-100 p-3">
          {file && (
            <div className="mb-2 inline-flex items-center gap-2 rounded-lg bg-slate-100 px-2 py-1 text-xs">
              <Paperclip className="h-3 w-3" /> {file.name}
              <button onClick={() => setFile(null)} aria-label="Quitar adjunto"><X className="h-3 w-3" /></button>
            </div>
          )}
          <div className="flex items-end gap-2">
            <input ref={fileRef} type="file" className="hidden" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
            <Button variant="ghost" icon={<Paperclip className="h-4 w-4" />} onClick={() => fileRef.current?.click()} aria-label="Adjuntar" />
            <Textarea
              rows={2}
              value={body}
              onChange={(e) => setBody(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                  e.preventDefault()
                  void send()
                }
              }}
              placeholder="Escriba su mensaje... (Enter para enviar, Shift+Enter para nueva línea)"
              className="min-h-[44px] flex-1 resize-none"
            />
            <Button icon={<Send className="h-4 w-4" />} loading={sending} onClick={send}>
              Enviar
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}

function NewConversation({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: (id: number) => void }) {
  const { isInternal } = useAuth()
  const { data: meta } = useMeta()
  const qc = useQueryClient()
  const [form, setForm] = useState({ client_id: '', subject: '', category: 'general', priority: 'media', body: '' })
  const [file, setFile] = useState<File | null>(null)
  const [loading, setLoading] = useState(false)

  const submit = async () => {
    setLoading(true)
    try {
      const { data } = await api.post('/conversations', toFormData({ ...form, attachment: file }))
      toast.success(data.message)
      qc.invalidateQueries({ predicate: (q) => String(q.queryKey[0]).startsWith('/conversations') })
      onCreated(data.data.id)
      setForm({ client_id: '', subject: '', category: 'general', priority: 'media', body: '' })
      setFile(null)
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
      title="Nueva consulta"
      footer={
        <>
          <Button variant="outline" onClick={onClose}>Cancelar</Button>
          <Button onClick={submit} loading={loading} disabled={!form.subject || !form.body}>Enviar consulta</Button>
        </>
      }
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        {isInternal && (
          <Field label="Cliente" className="sm:col-span-2">
            <ClientPicker value={form.client_id} onChange={(v) => setForm({ ...form, client_id: v })} allowAll={false} className="w-full" />
          </Field>
        )}
        <Field label="Asunto" className="sm:col-span-2">
          <Input value={form.subject} onChange={(e) => setForm({ ...form, subject: e.target.value })} />
        </Field>
        <Field label="Tema">
          <Select value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} options={meta?.conversation_categories} />
        </Field>
        <Field label="Prioridad">
          <Select value={form.priority} onChange={(e) => setForm({ ...form, priority: e.target.value })} options={{ baja: 'Baja', media: 'Media', alta: 'Alta' }} />
        </Field>
        <Field label="Mensaje" className="sm:col-span-2">
          <Textarea rows={5} value={form.body} onChange={(e) => setForm({ ...form, body: e.target.value })} />
        </Field>
        <Field label="Adjunto (opcional)" className="sm:col-span-2">
          <input type="file" onChange={(e) => setFile(e.target.files?.[0] ?? null)} className="text-sm" />
        </Field>
      </div>
    </Modal>
  )
}
