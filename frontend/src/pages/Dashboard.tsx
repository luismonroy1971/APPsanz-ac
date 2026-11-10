import { Link, useNavigate } from 'react-router-dom'
import { AlertTriangle, ArrowRight, CalendarClock, Clock, FileCheck2, FileStack, FileWarning, LogIn, MessagesSquare, Receipt, ShoppingCart, TrendingUp, Undo2, Users } from 'lucide-react'
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis, Area, AreaChart } from 'recharts'
import { useAuth } from '@/context/AuthContext'
import { useGet } from '@/hooks/useApi'
import { Badge, Card, Empty, Loading, PageHeader, PriorityBadge, StatCard, StatusBadge } from '@/components/ui'
import { fmtDate, fmtMoney, fromNow, MONTHS } from '@/lib/utils'

/* eslint-disable @typescript-eslint/no-explicit-any */

export default function Dashboard() {
  const { user, isInternal } = useAuth()
  const { data, isLoading } = useGet<{ data: any }>('/dashboard')
  if (isLoading || !data) return <Loading />
  const hello = `Hola, ${user?.name?.split(' ')[0]}`
  return isInternal ? <InternalDashboard d={data.data} hello={hello} /> : <ClientDashboard d={data.data} hello={hello} />
}

function ClientDashboard({ d, hello }: { d: any; hello: string }) {
  const navigate = useNavigate()
  const { can } = useAuth()
  const by = d.documents.by_status ?? {}
  const month = MONTHS[new Date().getMonth()]
  return (
    <>
      <PageHeader title={hello} subtitle={`${d.client.business_name} · Resumen de su servicio contable`} />

      {d.documents.returned.length > 0 && (
        <div className="mb-6 rounded-xl border border-rose-200 bg-rose-50 p-4">
          <div className="flex items-center gap-2 text-sm font-semibold text-rose-800">
            <Undo2 className="h-4 w-4" /> Tiene {d.documents.returned.length} documento(s) devuelto(s) que requieren su atención
          </div>
          <ul className="mt-2 space-y-1 text-sm text-rose-700">
            {d.documents.returned.map((r: any) => (
              <li key={r.id}>
                <b>{r.original_name}</b>: {r.return_reason}
              </li>
            ))}
          </ul>
          <Link to="/documentos?status=devuelto" className="mt-2 inline-flex items-center gap-1 text-sm font-medium text-rose-800 hover:underline">
            Reingresar documentos <ArrowRight className="h-4 w-4" />
          </Link>
        </div>
      )}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Documentos enviados" value={d.documents.total} icon={<FileStack className="h-5 w-5" />} hint={`${by.procesado ?? 0} procesados`} onClick={() => navigate('/documentos')} />
        <StatCard label="En proceso" value={(by.ingresado ?? 0) + (by.en_proceso ?? 0) + (by.reingresado ?? 0) + (by.observado ?? 0)} icon={<Clock className="h-5 w-5" />} tone="amber" />
        <StatCard label="Consultas activas" value={d.conversations.open} icon={<MessagesSquare className="h-5 w-5" />} tone="violet" hint={d.conversations.unread ? `${d.conversations.unread} respuesta(s) sin leer` : 'Al día'} onClick={() => navigate('/consultas')} />
        <StatCard label="Accesos esta semana" value={d.accesses_week} icon={<LogIn className="h-5 w-5" />} tone="gold" hint="Usuarios de su empresa" />
      </div>

      <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <StatCard label={`Compras registradas · ${month}`} value={fmtMoney(d.month.purchases, 'PEN')} icon={<ShoppingCart className="h-5 w-5" />} tone="brand" />
        <StatCard label={`Ventas registradas · ${month}`} value={fmtMoney(d.month.sales, 'PEN')} icon={<TrendingUp className="h-5 w-5" />} tone="green" />
      </div>

      <div className="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        <Card title="Últimos documentos enviados" className="xl:col-span-2" actions={<Link to="/documentos" className="text-sm font-medium text-brand-700 hover:underline">Ver todos</Link>} bodyClass="p-0">
          {d.documents.recent.length ? (
            <ul className="divide-y divide-slate-100">
              {d.documents.recent.map((doc: any) => (
                <li key={doc.id} className="flex items-center gap-3 px-5 py-3 text-sm">
                  <FileStack className="h-4 w-4 text-slate-400" />
                  <span className="min-w-0 flex-1 truncate">{doc.original_name}</span>
                  <span className="hidden text-xs text-slate-400 sm:block">{fromNow(doc.created_at)}</span>
                  <StatusBadge status={doc.status} />
                </li>
              ))}
            </ul>
          ) : (
            <Empty title="Aún no ha enviado documentos" action={can('documentos.subir') ? <Link to="/documentos" className="text-sm font-medium text-brand-700">Enviar documentos</Link> : undefined} />
          )}
        </Card>
        <div className="space-y-6">
          <Card title="Informes publicados" bodyClass="p-0">
            {d.reports.length ? (
              <ul className="divide-y divide-slate-100">
                {d.reports.map((r: any) => (
                  <li key={r.id} className="flex items-center gap-3 px-5 py-3 text-sm">
                    <Badge tone="gold">{r.report_code}</Badge>
                    <span className="min-w-0 flex-1 truncate">{r.title}</span>
                  </li>
                ))}
              </ul>
            ) : (
              <Empty title="Sin informes publicados" />
            )}
          </Card>
          <Card title="Consultas recientes" bodyClass="p-0">
            {d.conversations.recent.length ? (
              <ul className="divide-y divide-slate-100">
                {d.conversations.recent.map((c: any) => (
                  <li key={c.id} className="flex items-center gap-3 px-5 py-3 text-sm">
                    <span className="min-w-0 flex-1 truncate">{c.subject}</span>
                    <StatusBadge status={c.status} />
                  </li>
                ))}
              </ul>
            ) : (
              <Empty title="Sin consultas" />
            )}
          </Card>
        </div>
      </div>
    </>
  )
}

function InternalDashboard({ d, hello }: { d: any; hello: string }) {
  const navigate = useNavigate()
  const k = d.kpis
  const tasks = d.tasks_by_status ?? {}
  const taskData = [
    { label: 'Pendiente', value: tasks.pendiente ?? 0 },
    { label: 'En proceso', value: tasks.en_proceso ?? 0 },
    { label: 'En revisión', value: tasks.en_revision ?? 0 },
    { label: 'Completado', value: tasks.completado ?? 0 },
  ]
  return (
    <>
      <PageHeader title={hello} subtitle="Resumen operativo de S1 · S2 · S3 y seguimiento semanal" />

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Documentos por procesar" value={k.pending_documents} icon={<FileStack className="h-5 w-5" />} hint={`${k.observed_documents} observados`} onClick={() => navigate('/documentos')} />
        <StatCard label="Consultas sin cerrar" value={k.unanswered_chats} icon={<MessagesSquare className="h-5 w-5" />} tone="violet" onClick={() => navigate('/consultas')} />
        <StatCard label="Tareas vencidas" value={k.overdue_tasks} icon={<AlertTriangle className="h-5 w-5" />} tone="red" hint={`${k.tasks_this_week} vencen esta semana`} onClick={() => navigate('/procesos')} />
        <StatCard label="Informes por revisar" value={k.reports_to_review} icon={<FileCheck2 className="h-5 w-5" />} tone="gold" onClick={() => navigate('/informes')} />
      </div>
      <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Mis horas (semana)" value={k.my_hours_week} icon={<Clock className="h-5 w-5" />} tone="green" onClick={() => navigate('/horas')} />
        <StatCard label="Horas del equipo (semana)" value={k.team_hours_week} icon={<Users className="h-5 w-5" />} tone="brand" hint="R2" />
        <StatCard label="Accesos de clientes (semana)" value={k.client_accesses_week} icon={<LogIn className="h-5 w-5" />} tone="amber" hint="R1" />
        <StatCard label="Comprobantes del mes" value={k.vouchers_month} icon={<Receipt className="h-5 w-5" />} tone="violet" hint={`${k.clients} clientes`} />
      </div>

      <div className="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        <Card title="Documentos ingresados vs procesados" subtitle="Últimos 14 días" className="xl:col-span-2">
          <div className="h-64">
            <ResponsiveContainer>
              <AreaChart data={d.documents_series}>
                <defs>
                  <linearGradient id="gIn" x1="0" y1="0" x2="0" y2="1"><stop offset="5%" stopColor="#3f6290" stopOpacity={0.3} /><stop offset="95%" stopColor="#3f6290" stopOpacity={0} /></linearGradient>
                  <linearGradient id="gOut" x1="0" y1="0" x2="0" y2="1"><stop offset="5%" stopColor="#c9a227" stopOpacity={0.35} /><stop offset="95%" stopColor="#c9a227" stopOpacity={0} /></linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" vertical={false} />
                <XAxis dataKey="label" tick={{ fontSize: 11 }} stroke="#94a3b8" />
                <YAxis allowDecimals={false} tick={{ fontSize: 11 }} stroke="#94a3b8" width={30} />
                <Tooltip />
                <Legend wrapperStyle={{ fontSize: 12 }} />
                <Area type="monotone" dataKey="Ingresados" stroke="#3f6290" fill="url(#gIn)" strokeWidth={2} />
                <Area type="monotone" dataKey="Procesados" stroke="#c9a227" fill="url(#gOut)" strokeWidth={2} />
              </AreaChart>
            </ResponsiveContainer>
          </div>
        </Card>
        <Card title="Tareas S3 por estado">
          <div className="h-64">
            <ResponsiveContainer>
              <BarChart data={taskData} layout="vertical" margin={{ left: 10 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" horizontal={false} />
                <XAxis type="number" allowDecimals={false} tick={{ fontSize: 11 }} stroke="#94a3b8" />
                <YAxis type="category" dataKey="label" tick={{ fontSize: 11 }} stroke="#94a3b8" width={80} />
                <Tooltip />
                <Bar dataKey="value" name="Tareas" fill="#1e3a5f" radius={[0, 4, 4, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </div>
        </Card>
      </div>

      <div className="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        <Card title="Mis próximas tareas" bodyClass="p-0" actions={<Link to="/procesos?mine=1" className="text-sm font-medium text-brand-700 hover:underline">Ver</Link>}>
          {d.my_tasks.length ? (
            <ul className="divide-y divide-slate-100">
              {d.my_tasks.map((t: any) => (
                <li key={t.id} className="px-5 py-3 text-sm">
                  <div className="flex items-center gap-2">
                    <span className="min-w-0 flex-1 truncate font-medium text-slate-800">{t.title}</span>
                    {t.deliverable && <Badge tone="gold">{t.deliverable}</Badge>}
                  </div>
                  <div className="mt-1 flex items-center gap-2 text-xs text-slate-500">
                    <CalendarClock className="h-3.5 w-3.5" /> {fmtDate(t.due_date)} · {t.client?.business_name}
                    <span className="ml-auto"><StatusBadge status={t.status} /></span>
                  </div>
                </li>
              ))}
            </ul>
          ) : (
            <Empty title="No tiene tareas pendientes" />
          )}
        </Card>
        <Card title="Consultas esperando respuesta" bodyClass="p-0" actions={<Link to="/consultas" className="text-sm font-medium text-brand-700 hover:underline">Ver</Link>}>
          {d.latest_chats.length ? (
            <ul className="divide-y divide-slate-100">
              {d.latest_chats.map((c: any) => (
                <li key={c.id} className="px-5 py-3 text-sm">
                  <div className="flex items-center gap-2">
                    <span className="min-w-0 flex-1 truncate font-medium text-slate-800">{c.subject}</span>
                    <PriorityBadge priority={c.priority} />
                  </div>
                  <p className="mt-1 text-xs text-slate-500">{c.client?.business_name} · {fromNow(c.last_message_at)}</p>
                </li>
              ))}
            </ul>
          ) : (
            <Empty title="Todas las consultas están atendidas" />
          )}
        </Card>
        <Card title="Pendientes por cliente" bodyClass="p-0">
          {d.pending_by_client.length ? (
            <ul className="divide-y divide-slate-100">
              {d.pending_by_client.map((c: any) => (
                <li key={c.id} className="flex items-center gap-3 px-5 py-3 text-sm">
                  <FileWarning className="h-4 w-4 text-amber-500" />
                  <span className="min-w-0 flex-1 truncate">{c.business_name}</span>
                  <Badge tone="amber">{c.total}</Badge>
                </li>
              ))}
            </ul>
          ) : (
            <Empty title="Sin pendientes" />
          )}
          {d.hours_by_client.length > 0 && (
            <div className="border-t border-slate-100 p-5">
              <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Horas por cliente (semana)</p>
              <div className="h-40">
                <ResponsiveContainer>
                  <BarChart data={d.hours_by_client}>
                    <XAxis dataKey="label" hide />
                    <YAxis tick={{ fontSize: 10 }} width={25} stroke="#94a3b8" />
                    <Tooltip />
                    <Bar dataKey="Horas" fill="#c9a227" radius={[4, 4, 0, 0]} />
                  </BarChart>
                </ResponsiveContainer>
              </div>
            </div>
          )}
        </Card>
      </div>
    </>
  )
}
