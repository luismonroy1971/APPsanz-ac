import { useEffect, useState } from 'react'
import { FileSpreadsheet, FileText } from 'lucide-react'
import { Bar, BarChart, CartesianGrid, Cell, Legend, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { toast } from 'sonner'
import { useAuth } from '@/context/AuthContext'
import { useGet } from '@/hooks/useApi'
import { download, errorMessage } from '@/lib/api'
import type { ReportData } from '@/lib/types'
import { Badge, Button, Card, Empty, Field, Input, Loading, PageHeader, StatCard, TableWrap } from '@/components/ui'
import { ClientPicker } from '@/components/ClientPicker'
import { cn, currentWeek, fmtMoney, fmtNumber, isoDate } from '@/lib/utils'

const PALETTE = ['#1e3a5f', '#c9a227', '#5f80ab', '#10b981', '#8b5cf6', '#f59e0b', '#ef4444', '#64748b']

const GROUPS: { title: string; codes: string[] }[] = [
  { title: 'Seguimiento (BD.3)', codes: ['R1', 'R2', 'R3', 'R4'] },
  { title: 'Entregables al cliente (BD.2)', codes: ['R5', 'R6', 'R7', 'R8'] },
  { title: 'Gestión interna (BD.3)', codes: ['R9', 'R10', 'R11', 'R12'] },
]

export default function ReportsPage() {
  const { isInternal } = useAuth()
  const { data: catalog } = useGet<{ data: { code: string; title: string; description: string; database: string }[] }>('/reports')
  const [code, setCode] = useState('R1')
  const week = currentWeek()
  const [range, setRange] = useState(week)
  const [clientId, setClientId] = useState('')
  const { data, isLoading, isFetching } = useGet<{ data: ReportData }>(`/reports/${code}`, { ...range, client_id: clientId })
  const available = new Set((catalog?.data ?? []).map((c) => c.code))

  useEffect(() => {
    if (catalog?.data.length && !available.has(code)) setCode(catalog.data[0].code)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [catalog])

  const preset = (p: 'week' | 'lastweek' | 'month' | 'lastmonth' | 'year') => {
    const now = new Date()
    if (p === 'week') return setRange(week)
    if (p === 'lastweek') {
      const f = new Date(week.from)
      f.setDate(f.getDate() - 7)
      const t = new Date(week.to)
      t.setDate(t.getDate() - 7)
      return setRange({ from: isoDate(f), to: isoDate(t) })
    }
    if (p === 'month') return setRange({ from: isoDate(new Date(now.getFullYear(), now.getMonth(), 1)), to: isoDate(new Date(now.getFullYear(), now.getMonth() + 1, 0)) })
    if (p === 'lastmonth') return setRange({ from: isoDate(new Date(now.getFullYear(), now.getMonth() - 1, 1)), to: isoDate(new Date(now.getFullYear(), now.getMonth(), 0)) })
    setRange({ from: `${now.getFullYear()}-01-01`, to: `${now.getFullYear()}-12-31` })
  }

  const exportAs = async (format: 'xlsx' | 'pdf') => {
    try {
      await download(`/reports/${code}`, { ...range, client_id: clientId, export: format }, `${code}.${format}`)
    } catch (e) {
      toast.error(errorMessage(e))
    }
  }

  const r = data?.data

  return (
    <>
      <PageHeader title="Reportes APP" subtitle={isInternal ? 'Indicadores R1 a R12 para el seguimiento semanal del servicio.' : 'Indicadores de su servicio: accesos, documentos, consultas e informes.'} />
      <div className="grid grid-cols-1 gap-6 xl:grid-cols-[260px_1fr]">
        <Card bodyClass="p-2" className="h-fit">
          {GROUPS.map((g) => {
            const items = (catalog?.data ?? []).filter((c) => g.codes.includes(c.code))
            if (!items.length) return null
            return (
              <div key={g.title} className="mb-2">
                <p className="px-3 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-400">{g.title}</p>
                {items.map((c) => (
                  <button key={c.code} onClick={() => setCode(c.code)} className={cn('flex w-full items-start gap-2 rounded-lg px-3 py-2 text-left text-sm transition', code === c.code ? 'bg-brand-700 text-white' : 'text-slate-700 hover:bg-slate-100')}>
                    <span className={cn('mt-0.5 w-8 shrink-0 text-xs font-bold', code === c.code ? 'text-gold-300' : 'text-gold-600')}>{c.code}</span>
                    <span className="leading-snug">{c.title}</span>
                  </button>
                ))}
              </div>
            )
          })}
        </Card>

        <div className="min-w-0 space-y-5">
          <Card bodyClass="flex flex-wrap items-end gap-3 p-4">
            {isInternal && <Field label="Cliente"><ClientPicker value={clientId} onChange={setClientId} /></Field>}
            <Field label="Desde"><Input type="date" value={range.from} onChange={(e) => setRange({ ...range, from: e.target.value })} /></Field>
            <Field label="Hasta"><Input type="date" value={range.to} onChange={(e) => setRange({ ...range, to: e.target.value })} /></Field>
            <div className="flex flex-wrap gap-1 pb-0.5">
              {([['week', 'Esta semana'], ['lastweek', 'Semana anterior'], ['month', 'Este mes'], ['lastmonth', 'Mes anterior'], ['year', 'Año']] as const).map(([k, l]) => (
                <Button key={k} size="sm" variant="ghost" onClick={() => preset(k)}>{l}</Button>
              ))}
            </div>
            <div className="ml-auto flex gap-2">
              <Button variant="outline" icon={<FileSpreadsheet className="h-4 w-4" />} onClick={() => exportAs('xlsx')}>Excel</Button>
              <Button variant="outline" icon={<FileText className="h-4 w-4" />} onClick={() => exportAs('pdf')}>PDF</Button>
            </div>
          </Card>

          {isLoading || !r ? <Loading /> : (
            <div className={cn('space-y-5 transition-opacity', isFetching && 'opacity-60')}>
              <div>
                <div className="flex items-center gap-2">
                  <Badge tone="gold">{r.code}</Badge>
                  <Badge tone="slate">{r.database}</Badge>
                  <h2 className="text-lg font-semibold text-slate-900">{r.title}</h2>
                </div>
                <p className="mt-1 text-sm text-slate-500">{r.description}</p>
              </div>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {r.summary.map((s, i) => (
                  <StatCard key={s.label} label={s.label} tone={(['brand', 'gold', 'green', 'violet'] as const)[i % 4]} value={s.format === 'percent' ? `${fmtNumber(s.value)}%` : s.format === 'money' ? fmtMoney(s.value) : fmtNumber(s.value)} />
                ))}
              </div>
              {r.chart && r.chart.data.length > 0 && (
                <Card title="Gráfico">
                  <div className="h-72">
                    <ResponsiveContainer>
                      {r.chart.type === 'pie' ? (
                        <PieChart>
                          <Pie data={r.chart.data} dataKey="value" nameKey="label" innerRadius={60} outerRadius={100} paddingAngle={2} label>
                            {r.chart.data.map((_, i) => <Cell key={i} fill={PALETTE[i % PALETTE.length]} />)}
                          </Pie>
                          <Tooltip />
                          <Legend wrapperStyle={{ fontSize: 12 }} />
                        </PieChart>
                      ) : (
                        <BarChart data={r.chart.data}>
                          <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" vertical={false} />
                          <XAxis dataKey="label" tick={{ fontSize: 11 }} stroke="#94a3b8" interval={0} height={50} tickFormatter={(v: string) => (v.length > 18 ? `${v.slice(0, 16)}…` : v)} />
                          <YAxis tick={{ fontSize: 11 }} stroke="#94a3b8" width={40} unit={r.chart.unit} />
                          <Tooltip />
                          <Legend wrapperStyle={{ fontSize: 12 }} />
                          {r.chart.keys.map((k, i) => <Bar key={k} dataKey={k} stackId={r.chart?.stacked ? 'a' : undefined} fill={PALETTE[i % PALETTE.length]} radius={r.chart?.stacked ? 0 : [4, 4, 0, 0]} />)}
                        </BarChart>
                      )}
                    </ResponsiveContainer>
                  </div>
                </Card>
              )}
              <Card title="Detalle" subtitle={`Del ${r.range.from} al ${r.range.to}`} bodyClass="p-0">
                {!r.rows.length ? <Empty title="Sin datos en el rango seleccionado" /> : (
                  <TableWrap>
                    <table className="table-base">
                      <thead><tr>{r.columns.map((c) => <th key={c.key} className={c.type ? 'num' : ''}>{c.label}</th>)}</tr></thead>
                      <tbody className="divide-y divide-slate-100">
                        {r.rows.map((row, i) => (
                          <tr key={i}>
                            {r.columns.map((c) => {
                              const v = row[c.key]
                              return (
                                <td key={c.key} className={c.type ? 'num' : 'max-w-[280px] truncate'}>
                                  {v === null || v === undefined ? '—' : c.type === 'money' ? fmtMoney(v) : c.type === 'percent' ? (
                                    <span className="inline-flex items-center gap-2">
                                      <span className="hidden h-1.5 w-16 overflow-hidden rounded-full bg-slate-100 sm:inline-block"><span className="block h-full bg-brand-600" style={{ width: `${Math.min(Number(v), 100)}%` }} /></span>
                                      {fmtNumber(v)}%
                                    </span>
                                  ) : c.type === 'number' ? fmtNumber(v) : String(v)}
                                </td>
                              )
                            })}
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </TableWrap>
                )}
              </Card>
            </div>
          )}
        </div>
      </div>
    </>
  )
}
