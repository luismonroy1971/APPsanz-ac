import { useState } from 'react'
import { CheckCheck, Plus, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { useAuth } from '@/context/AuthContext'
import { useGet, useMeta, useSend, useStaff } from '@/hooks/useApi'
import type { Paginated, Task } from '@/lib/types'
import { Badge, Button, Card, Empty, Field, Input, Loading, Modal, PageHeader, Pagination, Select, StatCard, TableWrap, Toolbar } from '@/components/ui'
import { ClientPicker } from '@/components/ClientPicker'
import { currentWeek, fmtDate, isoDate } from '@/lib/utils'

interface Entry {
  id: number
  user_id: number
  user?: { name: string }
  client?: { business_name: string }
  task?: { title: string } | null
  date: string
  hours: string
  activity?: string | null
  description?: string | null
  approved: boolean
}

export default function TimesheetPage() {
  const { user, hasRole } = useAuth()
  const manager = hasRole('socio', 'supervisor')
  const { data: staff } = useStaff()
  const week = currentWeek()
  const [f, setF] = useState({ client_id: '', user_id: '', from: week.from, to: week.to, approved: '', mine: !manager, page: 1, per_page: 50 })
  const set = (p: Partial<typeof f>) => setF((s) => ({ ...s, page: 1, ...p }))
  const { data, isLoading } = useGet<Paginated<Entry> & { total_hours: number }>('/time-entries', f)
  const [adding, setAdding] = useState(false)
  const [selected, setSelected] = useState<number[]>([])
  const approve = useSend<{ ids: number[] }>('post', '/time-entries/approve', { invalidate: ['/time-entries'], onSuccess: () => setSelected([]) })
  const del = useSend<{ id: number }>('delete', (v) => `/time-entries/${v.id}`, { invalidate: ['/time-entries'] })
  const pending = (data?.data ?? []).filter((e) => !e.approved)

  return (
    <>
      <PageHeader
        tag="R2"
        title="Horas hombre"
        subtitle={manager ? 'Seguimiento semanal de horas dedicadas por trabajador y cliente. Aprobación del supervisor.' : 'Registre las horas dedicadas a cada cliente y actividad.'}
        actions={<Button icon={<Plus className="h-4 w-4" />} onClick={() => setAdding(true)}>Registrar horas</Button>}
      />
      <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <StatCard label="Horas en el rango" value={data?.total_hours ?? 0} tone="brand" />
        <StatCard label="Registros" value={data?.total ?? 0} tone="gold" />
        <StatCard label="Pendientes de aprobación (página)" value={pending.length} tone="amber" />
      </div>
      <Card bodyClass="p-0">
        <Toolbar>
          <ClientPicker value={f.client_id} onChange={(v) => set({ client_id: v })} />
          {manager && <Select value={f.user_id} onChange={(e) => set({ user_id: e.target.value, mine: false })} placeholder="Todo el equipo" options={(staff ?? []).map((s) => [String(s.id), s.name] as [string, string])} className="w-48" />}
          <Field label="Desde"><Input type="date" value={f.from} onChange={(e) => set({ from: e.target.value })} /></Field>
          <Field label="Hasta"><Input type="date" value={f.to} onChange={(e) => set({ to: e.target.value })} /></Field>
          <Select value={f.approved} onChange={(e) => set({ approved: e.target.value })} placeholder="Todos" options={{ '1': 'Aprobados', '0': 'Pendientes' }} className="w-36" />
          {manager && selected.length > 0 && <Button icon={<CheckCheck className="h-4 w-4" />} loading={approve.isPending} onClick={() => approve.mutate({ ids: selected })} className="ml-auto">Aprobar ({selected.length})</Button>}
        </Toolbar>
        {isLoading ? <Loading /> : !data?.data.length ? <Empty title="Sin horas registradas en el rango" /> : (
          <TableWrap>
            <table className="table-base">
              <thead>
                <tr>
                  {manager && <th><input type="checkbox" checked={pending.length > 0 && selected.length === pending.length} onChange={(e) => setSelected(e.target.checked ? pending.map((p) => p.id) : [])} /></th>}
                  <th>Fecha</th><th>Trabajador</th><th>Cliente</th><th>Actividad</th><th>Detalle</th><th className="num">Horas</th><th>Estado</th><th />
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {data.data.map((e) => (
                  <tr key={e.id}>
                    {manager && <td>{!e.approved && <input type="checkbox" checked={selected.includes(e.id)} onChange={(ev) => setSelected(ev.target.checked ? [...selected, e.id] : selected.filter((x) => x !== e.id))} />}</td>}
                    <td className="whitespace-nowrap">{fmtDate(e.date)}</td>
                    <td>{e.user?.name}</td>
                    <td className="max-w-[180px] truncate">{e.client?.business_name}</td>
                    <td className="max-w-[220px] truncate text-xs">{e.task?.title ?? e.activity ?? '—'}</td>
                    <td className="max-w-[220px] truncate text-xs text-slate-500">{e.description}</td>
                    <td className="num font-medium">{Number(e.hours)}</td>
                    <td>{e.approved ? <Badge tone="green">Aprobado</Badge> : <Badge tone="amber">Pendiente</Badge>}</td>
                    <td>{!e.approved && e.user_id === user?.id && <Button size="sm" variant="ghost" icon={<Trash2 className="h-4 w-4" />} onClick={() => del.mutate({ id: e.id })} aria-label="Eliminar" />}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </TableWrap>
        )}
        <Pagination page={data} onPage={(p) => setF((s) => ({ ...s, page: p }))} />
      </Card>
      {adding && <AddHours onClose={() => setAdding(false)} />}
    </>
  )
}

function AddHours({ onClose }: { onClose: () => void }) {
  const { data: meta } = useMeta()
  const [v, setV] = useState({ client_id: '', date: isoDate(new Date()), hours: '', task_id: '', activity: '', description: '' })
  const { data: tasks } = useGet<{ data: Task[] }>(v.client_id ? '/tasks' : null, { client_id: v.client_id, all: 1, status: 'pendiente,en_proceso,en_revision' })
  const save = useSend('post', '/time-entries', { invalidate: ['/time-entries', '/dashboard', '/tasks'], onSuccess: onClose })
  return (
    <Modal open onClose={onClose} title="Registrar horas" footer={<><Button variant="outline" onClick={onClose}>Cancelar</Button><Button loading={save.isPending} onClick={() => (v.client_id && v.hours ? save.mutate(v) : toast.error('Complete cliente y horas.'))}>Guardar</Button></>}>
      <div className="grid grid-cols-2 gap-4">
        <Field label="Cliente" className="col-span-2"><ClientPicker value={v.client_id} onChange={(c) => setV({ ...v, client_id: c, task_id: '' })} allowAll={false} className="w-full" /></Field>
        <Field label="Fecha"><Input type="date" value={v.date} max={isoDate(new Date())} onChange={(e) => setV({ ...v, date: e.target.value })} /></Field>
        <Field label="Horas"><Input type="number" step="0.25" min="0.25" max="16" value={v.hours} onChange={(e) => setV({ ...v, hours: e.target.value })} /></Field>
        <Field label="Actividad S3 vinculada" className="col-span-2"><Select value={v.task_id} onChange={(e) => setV({ ...v, task_id: e.target.value })} placeholder="(Opcional)" options={(tasks?.data ?? []).map((t) => [String(t.id), t.title] as [string, string])} /></Field>
        <Field label="Tipo de actividad"><Select value={v.activity} onChange={(e) => setV({ ...v, activity: e.target.value })} placeholder="—" options={meta?.task_activities} /></Field>
        <Field label="Detalle"><Input value={v.description} onChange={(e) => setV({ ...v, description: e.target.value })} /></Field>
      </div>
    </Modal>
  )
}
