import { useState } from 'react'
import { AlertTriangle, CheckCircle2, Cog, Download, PencilLine, RotateCcw, Undo2, UserPlus } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useAuth } from '@/context/AuthContext'
import { useGet, useSend, useStaff } from '@/hooks/useApi'
import { api, download, errorMessage, toFormData } from '@/lib/api'
import type { DocumentItem } from '@/lib/types'
import { Badge, Button, Card, KeyValue, Loading, Modal, Select, StatusBadge, Textarea } from '@/components/ui'
import { FileDrop } from '@/components/FileDrop'
import { fmtBytes, fmtDateTime, fmtMoney } from '@/lib/utils'
import { VoucherForm, type VoucherFormValues } from '@/pages/vouchers/VoucherForm'
import { FileIcon } from './DocumentsPage'

export default function DocumentDetail({ id, onClose }: { id: number; onClose: () => void }) {
  const { isInternal } = useAuth()
  const qc = useQueryClient()
  const { data, isLoading } = useGet<{ data: DocumentItem }>(`/documents/${id}`)
  const doc = data?.data
  const [mode, setMode] = useState<'view' | 'return' | 'register' | 'resubmit'>('view')
  const [reason, setReason] = useState('')
  const [files, setFiles] = useState<File[]>([])
  const [busy, setBusy] = useState(false)
  const { data: staff } = useStaff()

  const refresh = () => qc.invalidateQueries({ predicate: (q) => String(q.queryKey[0]).startsWith('/documents') || q.queryKey[0] === '/dashboard' })
  const opts = { invalidate: ['/documents', '/dashboard', '/vouchers'] }
  const process = useSend('post', `/documents/${id}/process`, opts)
  const complete = useSend('post', `/documents/${id}/complete`, opts)
  const ret = useSend<{ reason: string }>('post', `/documents/${id}/return`, { ...opts, onSuccess: () => setMode('view') })
  const register = useSend<VoucherFormValues>('post', `/documents/${id}/register`, { ...opts, onSuccess: () => setMode('view') })
  const assign = useSend<{ assigned_to: string }>('patch', `/documents/${id}/assign`, opts)

  const resubmit = async () => {
    if (!files[0]) return toast.error('Seleccione el archivo corregido.')
    setBusy(true)
    try {
      const { data } = await api.post(`/documents/${id}/resubmit`, toFormData({ file: files[0] }))
      toast.success(data.message)
      refresh()
      onClose()
    } catch (e) {
      toast.error(errorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  const extracted = (doc?.extracted_data ?? {}) as Partial<VoucherFormValues>
  const canAct = isInternal && doc && !['procesado', 'devuelto'].includes(doc.status)

  return (
    <Modal open onClose={onClose} size="xl" title={doc ? doc.original_name : 'Documento'}>
      {isLoading || !doc ? (
        <Loading />
      ) : (
        <div className="space-y-5">
          <div className="flex flex-wrap items-center gap-3">
            <FileIcon ext={doc.extension} className="h-8 w-8" />
            <StatusBadge status={doc.status} />
            {doc.processing_method && <Badge tone="brand">Método: {{ xml_ubl: 'XML SUNAT (automático)', ia: 'Extracción IA', manual: 'Registro manual', excel: 'Importación Excel' }[doc.processing_method] ?? doc.processing_method}</Badge>}
            {doc.parent && <Badge tone="violet">Reingreso del documento #{doc.parent.id}</Badge>}
            <div className="ml-auto flex flex-wrap gap-2">
              <Button variant="outline" size="sm" icon={<Download className="h-4 w-4" />} onClick={() => download(`/documents/${doc.id}/download`, undefined, doc.original_name)}>
                Descargar
              </Button>
              {!isInternal && doc.status === 'devuelto' && !doc.resubmissions?.length && (
                <Button size="sm" variant="gold" icon={<RotateCcw className="h-4 w-4" />} onClick={() => setMode('resubmit')}>
                  Reingresar corregido
                </Button>
              )}
              {canAct && doc.category === 'comprobante' && !doc.voucher && (
                <>
                  <Button size="sm" variant="secondary" icon={<Cog className="h-4 w-4" />} loading={process.isPending} onClick={() => process.mutate(undefined)}>
                    Procesar automáticamente
                  </Button>
                  <Button size="sm" variant="outline" icon={<PencilLine className="h-4 w-4" />} onClick={() => setMode('register')}>
                    Registrar manualmente
                  </Button>
                </>
              )}
              {canAct && doc.category !== 'comprobante' && (
                <Button size="sm" variant="secondary" icon={<CheckCircle2 className="h-4 w-4" />} loading={complete.isPending} onClick={() => complete.mutate({ comment: 'Revisado y procesado por el analista.' } as never)}>
                  Marcar como procesado
                </Button>
              )}
              {canAct && (
                <Button size="sm" variant="danger" icon={<Undo2 className="h-4 w-4" />} onClick={() => setMode('return')}>
                  Devolver al cliente
                </Button>
              )}
            </div>
          </div>

          {doc.status === 'devuelto' && doc.return_reason && (
            <div className="flex gap-2 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">
              <Undo2 className="mt-0.5 h-4 w-4 shrink-0" />
              <div>
                <b>Motivo de devolución:</b> {doc.return_reason}
                {doc.resubmissions?.length ? <p className="mt-1 text-xs">Reingresado como documento #{doc.resubmissions[0].id}.</p> : null}
              </div>
            </div>
          )}
          {doc.validation_errors && doc.validation_errors.length > 0 && (
            <div className="flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
              <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
              <ul className="list-disc space-y-0.5 pl-4">
                {doc.validation_errors.map((e, i) => (
                  <li key={i}>{e}</li>
                ))}
              </ul>
            </div>
          )}

          {mode === 'return' && (
            <Card title="Devolver documento al cliente" subtitle="El cliente verá el motivo y podrá reingresar el documento corregido (indicador R3).">
              <Textarea rows={3} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Ej.: El comprobante no corresponde a su empresa / imagen ilegible / falta el XML..." />
              <div className="mt-3 flex justify-end gap-2">
                <Button variant="outline" onClick={() => setMode('view')}>Cancelar</Button>
                <Button variant="danger" loading={ret.isPending} disabled={reason.trim().length < 5} onClick={() => ret.mutate({ reason })}>
                  Confirmar devolución
                </Button>
              </div>
            </Card>
          )}

          {mode === 'resubmit' && (
            <Card title="Reingresar documento corregido">
              <FileDrop files={files} onChange={setFiles} multiple={false} />
              <div className="mt-3 flex justify-end gap-2">
                <Button variant="outline" onClick={() => setMode('view')}>Cancelar</Button>
                <Button loading={busy} onClick={resubmit}>Reingresar</Button>
              </div>
            </Card>
          )}

          {mode === 'register' && (
            <Card title="Registro del comprobante" subtitle="Verifique o complete los datos. Se validará RUC, cuadre de importes, IGV y duplicados, y se generará el asiento contable.">
              <VoucherForm initial={extracted} loading={register.isPending} onCancel={() => setMode('view')} onSubmit={(v) => register.mutate(v)} />
            </Card>
          )}

          <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
            <Card title="Datos del documento" className="lg:col-span-2">
              <KeyValue
                cols={3}
                items={[
                  ['Cliente', doc.client?.business_name],
                  ['Categoría', <span className="capitalize">{doc.category}</span>],
                  ['Periodo', doc.period],
                  ['Enviado por', doc.uploader?.name],
                  ['Fecha de envío', fmtDateTime(doc.created_at)],
                  ['Tamaño', fmtBytes(doc.size)],
                  ['Procesado', fmtDateTime(doc.processed_at)],
                  ['Origen', doc.source === 'portal' ? 'Portal del cliente' : 'Otras fuentes'],
                  [
                    'Responsable',
                    isInternal ? (
                      <Select
                        value={doc.assignee?.id ? String(doc.assignee.id) : ''}
                        onChange={(e) => assign.mutate({ assigned_to: e.target.value })}
                        placeholder="Sin asignar"
                        options={(staff ?? []).map((s) => [String(s.id), s.name] as [string, string])}
                        className="py-1"
                      />
                    ) : (
                      doc.assignee?.name ?? 'Equipo SANZ & AC'
                    ),
                  ],
                ]}
              />
              {doc.client_notes && (
                <div className="mt-4 rounded-lg bg-slate-50 p-3 text-sm text-slate-600">
                  <b>Comentario del cliente:</b> {doc.client_notes}
                </div>
              )}
              {doc.voucher && (
                <div className="mt-5 rounded-lg border border-emerald-200 bg-emerald-50/50 p-4">
                  <p className="mb-3 flex items-center gap-2 text-sm font-semibold text-emerald-800">
                    <CheckCircle2 className="h-4 w-4" /> Comprobante registrado: {doc.voucher.document_type_name} {doc.voucher.full_number}
                  </p>
                  <KeyValue
                    cols={4}
                    items={[
                      ['Operación', <span className="capitalize">{doc.voucher.operation}</span>],
                      ['Emisión', doc.voucher.issue_date],
                      ['Emisor', `${doc.voucher.issuer_ruc} · ${doc.voucher.issuer_name}`],
                      ['Adquirente', doc.voucher.customer_name ?? doc.voucher.customer_doc],
                      ['Base imponible', fmtMoney(doc.voucher.taxable_amount, doc.voucher.currency)],
                      ['IGV', fmtMoney(doc.voucher.igv, doc.voucher.currency)],
                      ['Total', <b>{fmtMoney(doc.voucher.total, doc.voucher.currency)}</b>],
                      ['Asiento', doc.voucher.journal_entry?.number],
                    ]}
                  />
                  {isInternal && doc.voucher.journal_entry && (
                    <table className="table-base mt-4 rounded-lg bg-white">
                      <thead>
                        <tr><th>Cuenta</th><th>Denominación</th><th className="num">Debe</th><th className="num">Haber</th></tr>
                      </thead>
                      <tbody className="divide-y divide-slate-100">
                        {doc.voucher.journal_entry.lines.map((l) => (
                          <tr key={l.id}>
                            <td className="font-mono text-xs">{l.account?.code}</td>
                            <td>{l.account?.name}</td>
                            <td className="num">{Number(l.debit) ? fmtMoney(l.debit) : ''}</td>
                            <td className="num">{Number(l.credit) ? fmtMoney(l.credit) : ''}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  )}
                </div>
              )}
            </Card>
            <Card title="Trazabilidad">
              <ol className="relative space-y-4 border-l border-slate-200 pl-5">
                {doc.history?.map((h) => (
                  <li key={h.id} className="relative">
                    <span className="absolute -left-[26px] top-1 h-3 w-3 rounded-full border-2 border-white bg-brand-500 ring-1 ring-brand-200" />
                    <div className="flex items-center gap-2">
                      <StatusBadge status={h.to_status} />
                      <span className="text-xs text-slate-400">{fmtDateTime(h.created_at)}</span>
                    </div>
                    {h.comment && <p className="mt-1 text-xs text-slate-600">{h.comment}</p>}
                    {h.user && <p className="mt-0.5 flex items-center gap-1 text-[11px] text-slate-400"><UserPlus className="h-3 w-3" /> {h.user.name}</p>}
                  </li>
                ))}
              </ol>
            </Card>
          </div>
        </div>
      )}
    </Modal>
  )
}
