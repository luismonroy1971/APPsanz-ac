import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { CalendarClock, CalendarPlus, Clock, Plus, User } from 'lucide-react'
import { useGet, useMeta, useSend, useStaff } from '@/hooks/useApi'
import type { Task } from '@/lib/types'
import { Badge, Button, Card, Field, Input, Loading, Modal, PageHeader, PriorityBadge, Select, Textarea } from '@/components/ui'
import { ClientPicker } from '@/components/ClientPicker'
import { cn, fmtDate, MONTHS } from '@/lib/utils'

const COLUMNS: { key: string; label: string; tone: string }[] = [
  { key: 'pendiente', label: 'Pendiente', tone: 'border-t-slate-400' },
  { key: 'en_proceso', label: 'En proceso', tone: 'border-t-amber-500' },
  { key: 'en_revision', label: 'En revisión (supervisor)', tone: 'border-t-violet-500' },
  { key: 'completado', label: 'Completado', tone: 'border-t-emerald-500' },
]

const AREA_TONE: Record<string, 'brand' | 'violet' | 'amber' | 'green'> = { contable: 'brand', tributario: 'violet', rrhh: 'amber', tesoreria: 'green' }

export default function TasksPage() {
  const { data: meta } = useMeta()
  const [params] = useSearchParams()
  const [f, setF] = useState({ client_id: '', area: '', mine: params.get('mine') === '1', all: 1 })
  const { data, isLoading } = useGet<{ data: Task[] }>('/tasks', f)
  const [editing, setEditing] = useState<Task | 'new' | null>(null)
  const [gen, setGen] = useState(false)
  const move = useSend<{ id: number; status: string }>('patch', (v) => `/tasks/${v.id}`, { invalidate: ['/tasks', '/dashboard'], silent: true })
  const tasks = data?.data ?? []
  const today = new Date().toISOString().slice(0, 10)

  return (
    <>
      <PageHeader
        tag="S3"
        title="Proceso contable · tributario · RRHH · tesorería"
        subtitle="Actividades por cliente y periodo, asignadas a Analista + Supervisor + Socio."
        actions={
          <>
            <Button variant="outline" icon={<CalendarPlus className="h-4 w-4" />} onClick={() => setGen(true)}>Generar plan del mes</Button>
            <Button icon={<Plus className="h-4 w-4" />} onClick={() => setEditing('new')}>Nueva actividad</Button>
          </>
        }
      />
      <Card className="mb-4" bodyClass="flex flex-wrap items-end gap-3 p-4">
        <ClientPicker value={f.client_id} onChange={(v) => setF({ ...f, client_id: v })} />
        <Select value={f.area} onChange={(e) => setF({ ...f, area: e.target.value })} placeholder="Todas las áreas" options={meta?.task_areas} className="w-44" />
        <label className="flex items-center gap-2 pb-2 text-sm text-slate-600"><input type="checkbox" checked={f.mine} onChange={(e) => setF({ ...f, mine: e.target.checked })} /> Solo mis actividades</label>
        <div className="ml-auto flex flex-wrap gap-2 text-xs text-slate-500">
          {Object.entries(meta?.task_areas ?? {}).map(([k, v]) => <Badge key={k} tone={AREA_TONE[k]}>{v}</Badge>)}
        </div>
      </Card>

      {isLoading ? <Loading /> : (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
          {COLUMNS.map((col) => {
            const items = tasks.filter((t) => t.status === col.key)
            return (
              <div
                key={col.key}
                className={cn('flex min-h-[300px] flex-col rounded-xl border border-t-4 border-slate-200 bg-slate-100/60', col.tone)}
                onDragOver={(e) => e.preventDefault()}
                onDrop={(e) => {
                  const id = Number(e.dataTransfer.getData('text/plain'))
                  if (id) move.mutate({ id, status: col.key })
                }}
              >
                <div className="flex items-center justify-between px-3 py-2.5">
                  <span className="text-sm font-semibold text-slate-700">{col.label}</span>
                  <span className="rounded-full bg-white px-2 text-xs text-slate-500">{items.length}</span>
                </div>
                <div className="flex-1 space-y-2 overflow-y-auto px-2 pb-2" style={{ maxHeight: '65vh' }}>
                  {items.map((t) => {
                    const overdue = t.status !== 'completado' && t.due_date && t.due_date < today
                    return (
                      <div
                        key={t.id}
                        draggable
                        onDragStart={(e) => e.dataTransfer.setData('text/plain', String(t.id))}
                        onClick={() => setEditing(t)}
                        className="cursor-pointer rounded-lg border border-slate-200 bg-white p-3 shadow-sm transition hover:border-brand-300 hover:shadow"
                      >
                        <div className="mb-1.5 flex flex-wrap items-center gap-1">
                          <Badge tone={AREA_TONE[t.area]}>{meta?.task_areas[t.area] ?? t.area}</Badge>
                          {t.deliverable && <Badge tone="gold">{t.deliverable}</Badge>}
                          {t.priority === 'alta' && <PriorityBadge priority="alta" />}
                        </div>
                        <p className="text-sm font-medium leading-snug text-slate-800">{t.title}</p>
                        <p className="mt-1 truncate text-xs text-slate-500">{t.client?.business_name}</p>
                        <div className="mt-2 flex items-center gap-3 text-[11px] text-slate-500">
                          <span className={cn('flex items-center gap-1', overdue && 'font-semibold text-rose-600')}><CalendarClock className="h-3 w-3" /> {fmtDate(t.due_date)}</span>
                          <span className="flex items-center gap-1"><Clock className="h-3 w-3" /> {Number(t.hours_logged ?? 0)}/{Number(t.estimated_hours ?? 0)}h</span>
                          <span className="ml-auto flex items-center gap-1 truncate"><User className="h-3 w-3" /> {t.assignee?.name?.split(' ')[0] ?? '—'}</span>
                        </div>
                      </div>
                    )
                  })}
                </div>
              </div>
            )
          })}
        </div>
      )}
      {editing && <TaskModal task={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
      {gen && <GenerateMonth onClose={() => setGen(false)} />}
    </>
  )
}

function TaskModal({ task, onClose }: { task: Task | null; onClose: () => void }) {
  const { data: meta } = useMeta()
  const { data: staff } = useStaff()
  const [v, setV] = useState({
    client_id: task ? String(task.client_id) : '',
    area: task?.area ?? 'contable',
    activity: task?.activity ?? 'validacion',
    title: task?.title ?? '',
    description: task?.description ?? '',
    deliverable: task?.deliverable ?? '',
    status: task?.status ?? 'pendiente',
    priority: task?.priority ?? 'media',
    due_date: task?.due_date ?? '',
    assigned_to: task?.assigned_to ? String(task.assigned_to) : '',
    estimated_hours: task?.estimated_hours ?? '',
  })
  const opts = { invalidate: ['/tasks', '/dashboard'], onSuccess: onClose }
  const save = useSend('post', '/tasks', opts)
  const update = useSend('patch', `/tasks/${task?.id}`, opts)
  const del = useSend('delete', `/tasks/${task?.id}`, opts)
  const staffOpts = (staff ?? []).map((s) => [String(s.id), s.name] as [string, string])
  const set = (k: keyof typeof v) => (e: { target: { value: string } }) => setV({ ...v, [k]: e.target.value })

  return (
    <Modal
      open
      onClose={onClose}
      size="lg"
      title={task ? 'Actividad S3' : 'Nueva actividad S3'}
      footer={
        <>
          {task && <Button variant="ghost" className="mr-auto text-rose-600" loading={del.isPending} onClick={() => del.mutate(undefined)}>Eliminar</Button>}
          <Button variant="outline" onClick={onClose}>Cancelar</Button>
          <Button loading={save.isPending || update.isPending} onClick={() => (task ? update.mutate(v) : save.mutate(v))}>Guardar</Button>
        </>
      }
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        {!task && <Field label="Cliente" className="sm:col-span-2"><ClientPicker value={v.client_id} onChange={(c) => setV({ ...v, client_id: c })} allowAll={false} className="w-full" /></Field>}
        {task && <Field label="Cliente" className="sm:col-span-2"><Input value={task.client?.business_name ?? ''} disabled /></Field>}
        <Field label="Título" className="sm:col-span-2"><Input value={v.title} onChange={set('title')} /></Field>
        <Field label="Área"><Select value={v.area} onChange={set('area')} options={meta?.task_areas} /></Field>
        <Field label="Actividad (S3)"><Select value={v.activity} onChange={set('activity')} options={meta?.task_activities} /></Field>
        <Field label="Entregable" hint="Se mide en R10 (% cumplimiento)"><Select value={v.deliverable} onChange={set('deliverable')} placeholder="Ninguno" options={{ R5: 'R5 · Libros contables', R6: 'R6 · EEFF periódicos', R7: 'R7 · EEFF anual', R8: 'R8 · Otros informes' }} /></Field>
        <Field label="Estado"><Select value={v.status} onChange={set('status')} options={meta?.task_statuses} /></Field>
        <Field label="Responsable"><Select value={v.assigned_to} onChange={set('assigned_to')} placeholder="Sin asignar" options={staffOpts} /></Field>
        <Field label="Prioridad"><Select value={v.priority} onChange={set('priority')} options={{ baja: 'Baja', media: 'Media', alta: 'Alta' }} /></Field>
        <Field label="Fecha límite"><Input type="date" value={v.due_date} onChange={set('due_date')} /></Field>
        <Field label="Horas estimadas"><Input type="number" step="0.5" value={v.estimated_hours} onChange={set('estimated_hours')} /></Field>
        <Field label="Descripción / opinión / implementación" className="sm:col-span-2"><Textarea rows={3} value={v.description} onChange={set('description')} /></Field>
      </div>
    </Modal>
  )
}

function GenerateMonth({ onClose }: { onClose: () => void }) {
  const now = new Date()
  const [v, setV] = useState({ client_id: '', year: String(now.getFullYear()), month: String(now.getMonth() + 1) })
  const gen = useSend('post', '/tasks/generate-month', { invalidate: ['/tasks'], onSuccess: onClose })
  return (
    <Modal open onClose={onClose} title="Generar plan de actividades del mes" footer={<><Button variant="outline" onClick={onClose}>Cancelar</Button><Button disabled={!v.client_id} loading={gen.isPending} onClick={() => gen.mutate(v)}>Generar</Button></>}>
      <p className="mb-4 text-sm text-slate-600">Crea las actividades estándar S3 del periodo (validación, conciliaciones, libros, impuestos, planillas, tesorería, EEFF e informe mensual) asignadas al analista y supervisor del cliente.</p>
      <div className="grid grid-cols-2 gap-4">
        <Field label="Cliente" className="col-span-2"><ClientPicker value={v.client_id} onChange={(c) => setV({ ...v, client_id: c })} allowAll={false} className="w-full" /></Field>
        <Field label="Año"><Input type="number" value={v.year} onChange={(e) => setV({ ...v, year: e.target.value })} /></Field>
        <Field label="Mes"><Select value={v.month} onChange={(e) => setV({ ...v, month: e.target.value })} options={MONTHS.map((m, i) => [String(i + 1), m] as [string, string])} /></Field>
      </div>
    </Modal>
  )
}
