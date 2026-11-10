import { useState, type ReactNode } from 'react'
import { CalendarDays, Download, GraduationCap, Lightbulb, Plus, ScrollText, Users } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useGet, useStaff } from '@/hooks/useApi'
import { api, download, errorMessage, toFormData } from '@/lib/api'
import type { Paginated } from '@/lib/types'
import { Badge, Button, Card, Empty, Field, Input, Loading, Modal, PageHeader, Pagination, Select, StatusBadge, Tabs, Textarea } from '@/components/ui'
import { ClientPicker } from '@/components/ClientPicker'
import { cn, fmtDate } from '@/lib/utils'

interface Training {
  id: number
  type: string
  title: string
  description?: string
  client_id?: number | null
  client?: { business_name: string } | null
  responsible?: { id: number; name: string } | null
  responsible_id?: number | null
  scheduled_date?: string | null
  due_date?: string | null
  duration_hours?: string | null
  status: string
  progress: number
  original_name?: string | null
  participants: { id: number; name: string; pivot: { attended: boolean } }[]
}

const TYPES = { capacitacion: 'Capacitación', recomendacion: 'Recomendación', informe: 'Informe' }
const ICON: Record<string, ReactNode> = {
  capacitacion: <GraduationCap className="h-5 w-5" />,
  recomendacion: <Lightbulb className="h-5 w-5" />,
  informe: <ScrollText className="h-5 w-5" />,
}

export default function TrainingsPage() {
  const [type, setType] = useState('')
  const [page, setPage] = useState(1)
  const { data, isLoading } = useGet<Paginated<Training>>('/trainings', { type, page, per_page: 12 })
  const [edit, setEdit] = useState<Training | 'new' | null>(null)

  return (
    <>
      <PageHeader tag="R12" title="Informes, capacitación e implementación de recomendaciones" subtitle="Plan de capacitación del equipo y seguimiento de recomendaciones a clientes." actions={<Button icon={<Plus className="h-4 w-4" />} onClick={() => setEdit('new')}>Nuevo registro</Button>} />
      <Tabs value={type} onChange={(v) => { setType(v); setPage(1) }} tabs={[{ value: '', label: 'Todos' }, ...Object.entries(TYPES).map(([value, label]) => ({ value, label }))]} />
      {isLoading ? <Loading /> : !data?.data.length ? <Card><Empty title="Sin registros" /></Card> : (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
          {data.data.map((t) => (
            <button key={t.id} onClick={() => setEdit(t)} className="flex flex-col rounded-xl border border-slate-200 bg-white p-5 text-left shadow-card transition hover:border-brand-300">
              <div className="flex items-start gap-3">
                <div className={cn('flex h-10 w-10 shrink-0 items-center justify-center rounded-lg', t.type === 'capacitacion' ? 'bg-brand-50 text-brand-700' : t.type === 'recomendacion' ? 'bg-gold-50 text-gold-700' : 'bg-violet-50 text-violet-700')}>{ICON[t.type]}</div>
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2"><Badge tone="slate">{TYPES[t.type as keyof typeof TYPES]}</Badge><StatusBadge status={t.status} /></div>
                  <h3 className="mt-1.5 text-sm font-semibold leading-snug text-slate-800">{t.title}</h3>
                  <p className="mt-0.5 text-xs text-slate-500">{t.client?.business_name ?? 'Interno SANZ & AC'}</p>
                </div>
              </div>
              <div className="mt-4">
                <div className="mb-1 flex justify-between text-xs text-slate-500"><span>Avance</span><span>{t.progress}%</span></div>
                <div className="h-1.5 overflow-hidden rounded-full bg-slate-100"><div className="h-full rounded-full bg-gold-500" style={{ width: `${t.progress}%` }} /></div>
              </div>
              <div className="mt-3 flex flex-wrap gap-3 text-xs text-slate-500">
                <span className="flex items-center gap-1"><CalendarDays className="h-3.5 w-3.5" /> {fmtDate(t.scheduled_date ?? t.due_date)}</span>
                {t.participants.length > 0 && <span className="flex items-center gap-1"><Users className="h-3.5 w-3.5" /> {t.participants.length}</span>}
                {t.responsible && <span>Resp.: {t.responsible.name}</span>}
              </div>
            </button>
          ))}
        </div>
      )}
      {data && <div className="mt-4 rounded-xl border border-slate-200 bg-white"><Pagination page={data} onPage={setPage} /></div>}
      {edit && <TrainingModal item={edit === 'new' ? null : edit} onClose={() => setEdit(null)} />}
    </>
  )
}

function TrainingModal({ item, onClose }: { item: Training | null; onClose: () => void }) {
  const { data: staff } = useStaff()
  const qc = useQueryClient()
  const [v, setV] = useState({
    type: item?.type ?? 'capacitacion',
    title: item?.title ?? '',
    description: item?.description ?? '',
    client_id: item?.client_id ? String(item.client_id) : '',
    scheduled_date: item?.scheduled_date ?? '',
    due_date: item?.due_date ?? '',
    duration_hours: item?.duration_hours ?? '',
    status: item?.status ?? 'planificado',
    progress: String(item?.progress ?? 0),
    responsible_id: item?.responsible_id ? String(item.responsible_id) : (item?.responsible ? String(item.responsible.id) : ''),
  })
  const [participants, setParticipants] = useState<number[]>(item?.participants.map((p) => p.id) ?? [])
  const [attended, setAttended] = useState<number[]>(item?.participants.filter((p) => p.pivot.attended).map((p) => p.id) ?? [])
  const [file, setFile] = useState<File | null>(null)
  const [loading, setLoading] = useState(false)
  const set = (k: keyof typeof v) => (e: { target: { value: string } }) => setV({ ...v, [k]: e.target.value })

  const submit = async () => {
    setLoading(true)
    try {
      const payload = toFormData({ ...v, participants, attended: item ? attended : undefined, file })
      if (!participants.length) payload.append('participants', '')
      const { data } = await api.post(item ? `/trainings/${item.id}` : '/trainings', payload)
      toast.success(data.message)
      qc.invalidateQueries({ predicate: (q) => String(q.queryKey[0]).startsWith('/trainings') })
      onClose()
    } catch (e) {
      toast.error(errorMessage(e))
    } finally {
      setLoading(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      size="lg"
      title={item ? 'Editar registro' : 'Nuevo registro R12'}
      footer={
        <>
          {item?.original_name && <Button variant="ghost" className="mr-auto" icon={<Download className="h-4 w-4" />} onClick={() => download(`/trainings/${item.id}/download`, undefined, item.original_name!)}>{item.original_name}</Button>}
          <Button variant="outline" onClick={onClose}>Cancelar</Button>
          <Button loading={loading} disabled={!v.title} onClick={submit}>Guardar</Button>
        </>
      }
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field label="Tipo"><Select value={v.type} onChange={set('type')} options={TYPES} /></Field>
        <Field label="Estado"><Select value={v.status} onChange={set('status')} options={{ planificado: 'Planificado', en_curso: 'En curso', implementado: 'Implementado', completado: 'Completado', cancelado: 'Cancelado' }} /></Field>
        <Field label="Título" className="sm:col-span-2"><Input value={v.title} onChange={set('title')} /></Field>
        <Field label="Descripción" className="sm:col-span-2"><Textarea rows={3} value={v.description} onChange={set('description')} /></Field>
        <Field label="Cliente (recomendaciones)"><ClientPicker value={v.client_id} onChange={(c) => setV({ ...v, client_id: c })} className="w-full" /></Field>
        <Field label="Responsable"><Select value={v.responsible_id} onChange={set('responsible_id')} placeholder="—" options={(staff ?? []).map((s) => [String(s.id), s.name] as [string, string])} /></Field>
        {v.type === 'capacitacion' ? (
          <>
            <Field label="Fecha programada"><Input type="date" value={v.scheduled_date} onChange={set('scheduled_date')} /></Field>
            <Field label="Duración (horas)"><Input type="number" step="0.5" value={v.duration_hours} onChange={set('duration_hours')} /></Field>
          </>
        ) : (
          <Field label="Fecha compromiso"><Input type="date" value={v.due_date} onChange={set('due_date')} /></Field>
        )}
        <Field label={`Avance: ${v.progress}%`}><input type="range" min={0} max={100} step={5} value={v.progress} onChange={set('progress')} className="w-full accent-brand-700" /></Field>
        <Field label="Archivo (material / informe)"><input type="file" onChange={(e) => setFile(e.target.files?.[0] ?? null)} className="text-sm" /></Field>
        {v.type === 'capacitacion' && (
          <Field label="Participantes (marque asistencia con doble clic)" className="sm:col-span-2">
            <div className="flex flex-wrap gap-2">
              {(staff ?? []).map((s) => {
                const on = participants.includes(s.id)
                const att = attended.includes(s.id)
                return (
                  <button
                    type="button"
                    key={s.id}
                    onClick={() => setParticipants(on ? participants.filter((x) => x !== s.id) : [...participants, s.id])}
                    onDoubleClick={() => on && setAttended(att ? attended.filter((x) => x !== s.id) : [...attended, s.id])}
                    className={cn('rounded-full border px-3 py-1 text-xs', on ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-slate-200 text-slate-600', att && 'ring-2 ring-emerald-400')}
                  >
                    {s.name} {att && '✓'}
                  </button>
                )
              })}
            </div>
          </Field>
        )}
      </div>
    </Modal>
  )
}
