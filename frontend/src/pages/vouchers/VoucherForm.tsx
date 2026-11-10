import { useState } from 'react'
import { useMeta } from '@/hooks/useApi'
import { Button, Field, Input, Select } from '@/components/ui'
import { fmtMoney } from '@/lib/utils'

export interface VoucherFormValues {
  document_type: string
  series: string
  number: string
  issue_date: string
  due_date: string
  issuer_ruc: string
  issuer_name: string
  customer_doc: string
  customer_name: string
  currency: string
  exchange_rate: string
  taxable_amount: string
  exempt_amount: string
  unaffected_amount: string
  igv: string
  isc: string
  other_charges: string
  total: string
  reference_doc: string
}

const EMPTY: VoucherFormValues = {
  document_type: '01', series: '', number: '', issue_date: '', due_date: '', issuer_ruc: '', issuer_name: '', customer_doc: '',
  customer_name: '', currency: 'PEN', exchange_rate: '1', taxable_amount: '0', exempt_amount: '0', unaffected_amount: '0',
  igv: '0', isc: '0', other_charges: '0', total: '0', reference_doc: '',
}

export function VoucherForm({ initial, onSubmit, onCancel, loading, submitLabel = 'Registrar comprobante', clientRuc }: { initial?: Partial<Record<keyof VoucherFormValues, unknown>>; onSubmit: (v: VoucherFormValues) => void; onCancel?: () => void; loading?: boolean; submitLabel?: string; clientRuc?: string }) {
  const { data: meta } = useMeta()
  const [v, setV] = useState<VoucherFormValues>(() => {
    const base = { ...EMPTY }
    Object.entries(initial ?? {}).forEach(([k, val]) => {
      if (k in base && val !== null && val !== undefined) (base as Record<string, string>)[k] = String(val)
    })
    return base
  })
  const set = (k: keyof VoucherFormValues) => (e: { target: { value: string } }) => setV((s) => ({ ...s, [k]: e.target.value }))

  const sum = ['taxable_amount', 'exempt_amount', 'unaffected_amount', 'igv', 'isc', 'other_charges'].reduce((a, k) => a + Number((v as unknown as Record<string, string>)[k] || 0), 0)
  const diff = Math.abs(sum - Number(v.total || 0))

  const calcIgv = () => {
    const igv = Math.round(Number(v.taxable_amount || 0) * 0.18 * 100) / 100
    const total = Number(v.taxable_amount || 0) + igv + Number(v.exempt_amount || 0) + Number(v.unaffected_amount || 0) + Number(v.isc || 0) + Number(v.other_charges || 0)
    setV((s) => ({ ...s, igv: igv.toFixed(2), total: total.toFixed(2) }))
  }

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault()
        onSubmit(v)
      }}
      className="space-y-4"
    >
      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <Field label="Tipo">
          <Select value={v.document_type} onChange={set('document_type')} options={meta?.voucher_types} />
        </Field>
        <Field label="Serie">
          <Input required maxLength={4} value={v.series} onChange={set('series')} placeholder="F001" />
        </Field>
        <Field label="Número">
          <Input required maxLength={8} value={v.number} onChange={set('number')} />
        </Field>
        <Field label="Fecha de emisión">
          <Input required type="date" value={v.issue_date} onChange={set('issue_date')} />
        </Field>
        <Field label="RUC emisor" hint={clientRuc && v.issuer_ruc === clientRuc ? 'Venta (emitida por el cliente)' : undefined}>
          <Input required maxLength={11} value={v.issuer_ruc} onChange={set('issuer_ruc')} />
        </Field>
        <Field label="Razón social emisor" className="md:col-span-3">
          <Input required value={v.issuer_name} onChange={set('issuer_name')} />
        </Field>
        <Field label="Doc. adquirente (RUC/DNI)">
          <Input maxLength={15} value={v.customer_doc} onChange={set('customer_doc')} />
        </Field>
        <Field label="Nombre adquirente" className="md:col-span-3">
          <Input value={v.customer_name} onChange={set('customer_name')} />
        </Field>
        <Field label="Moneda">
          <Select value={v.currency} onChange={set('currency')} options={{ PEN: 'Soles (PEN)', USD: 'Dólares (USD)', EUR: 'Euros (EUR)' }} />
        </Field>
        <Field label="Tipo de cambio">
          <Input type="number" step="0.001" value={v.exchange_rate} onChange={set('exchange_rate')} disabled={v.currency === 'PEN'} />
        </Field>
        <Field label="Vencimiento">
          <Input type="date" value={v.due_date} onChange={set('due_date')} />
        </Field>
        <Field label="Doc. referencia (NC/ND)">
          <Input value={v.reference_doc} onChange={set('reference_doc')} placeholder="F001-123" />
        </Field>
      </div>
      <div className="rounded-lg bg-slate-50 p-3">
        <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
          <Field label="Base imponible">
            <Input type="number" step="0.01" value={v.taxable_amount} onChange={set('taxable_amount')} onBlur={calcIgv} />
          </Field>
          <Field label="IGV">
            <Input type="number" step="0.01" value={v.igv} onChange={set('igv')} />
          </Field>
          <Field label="Exonerado">
            <Input type="number" step="0.01" value={v.exempt_amount} onChange={set('exempt_amount')} />
          </Field>
          <Field label="Inafecto">
            <Input type="number" step="0.01" value={v.unaffected_amount} onChange={set('unaffected_amount')} />
          </Field>
          <Field label="ISC">
            <Input type="number" step="0.01" value={v.isc} onChange={set('isc')} />
          </Field>
          <Field label="Otros cargos">
            <Input type="number" step="0.01" value={v.other_charges} onChange={set('other_charges')} />
          </Field>
          <Field label="Importe total" className="md:col-span-2" error={diff > 0.05 ? `No cuadra: la suma es ${fmtMoney(sum)}` : undefined}>
            <Input required type="number" step="0.01" value={v.total} onChange={set('total')} className="font-semibold" />
          </Field>
        </div>
      </div>
      <div className="flex justify-end gap-2">
        {onCancel && (
          <Button variant="outline" onClick={onCancel}>
            Cancelar
          </Button>
        )}
        <Button type="submit" loading={loading}>
          {submitLabel}
        </Button>
      </div>
    </form>
  )
}
