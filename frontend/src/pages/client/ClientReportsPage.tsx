import { useState, type ReactNode } from 'react'
import { BookOpen, Download, FileBarChart2, FileSpreadsheet, Landmark, ScrollText } from 'lucide-react'
import { useGet } from '@/hooks/useApi'
import { download } from '@/lib/api'
import type { FinancialReport, Paginated } from '@/lib/types'
import { Badge, Button, Card, Empty, Loading, PageHeader, Pagination, Tabs } from '@/components/ui'
import { fmtDate, MONTHS } from '@/lib/utils'

const CODES = [
  { value: '', label: 'Todos' },
  { value: 'R5', label: 'R5 · Libros contables' },
  { value: 'R6', label: 'R6 · EEFF periódicos' },
  { value: 'R7', label: 'R7 · EEFF anual' },
  { value: 'R8', label: 'R8 · Otros informes' },
]

const ICONS: Record<string, ReactNode> = {
  R5: <BookOpen className="h-5 w-5" />,
  R6: <FileBarChart2 className="h-5 w-5" />,
  R7: <Landmark className="h-5 w-5" />,
  R8: <ScrollText className="h-5 w-5" />,
}

export default function ClientReportsPage() {
  const [code, setCode] = useState('')
  const [page, setPage] = useState(1)
  const { data, isLoading } = useGet<Paginated<FinancialReport>>('/financial-reports', { report_code: code, page, per_page: 12 })

  return (
    <>
      <PageHeader tag="BD.2" title="Libros e informes" subtitle="Libros contables, estados financieros e informes elaborados y publicados por SANZ & AC." />
      <Tabs tabs={CODES} value={code} onChange={(v) => { setCode(v); setPage(1) }} />
      {isLoading ? (
        <Loading />
      ) : !data?.data.length ? (
        <Card><Empty title="Aún no hay informes publicados" description="Cuando su supervisor publique libros o estados financieros, los encontrará aquí." /></Card>
      ) : (
        <>
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            {data.data.map((r) => (
              <div key={r.id} className="flex flex-col rounded-xl border border-slate-200 bg-white p-5 shadow-card">
                <div className="flex items-start gap-3">
                  <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700">{ICONS[r.report_code] ?? <FileSpreadsheet className="h-5 w-5" />}</div>
                  <div className="min-w-0 flex-1">
                    <Badge tone="gold">{r.report_code}</Badge>
                    <h3 className="mt-1 text-sm font-semibold text-slate-800">{r.title}</h3>
                    <p className="mt-0.5 text-xs text-slate-500">
                      Periodo: {r.month ? `${MONTHS[r.month - 1]} ${r.year}` : `Ejercicio ${r.year}`} · Publicado {fmtDate(r.published_at)}
                    </p>
                  </div>
                </div>
                <div className="mt-4 flex items-center justify-between border-t border-slate-100 pt-3">
                  <span className="text-xs uppercase text-slate-400">{r.format}</span>
                  <Button size="sm" variant="secondary" icon={<Download className="h-4 w-4" />} onClick={() => download(`/financial-reports/${r.id}/download`, undefined, r.original_name ?? 'informe')}>
                    Descargar
                  </Button>
                </div>
              </div>
            ))}
          </div>
          <div className="mt-4 rounded-xl border border-slate-200 bg-white">
            <Pagination page={data} onPage={setPage} />
          </div>
        </>
      )}
    </>
  )
}
