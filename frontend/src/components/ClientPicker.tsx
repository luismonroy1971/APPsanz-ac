import { useClients } from '@/hooks/useApi'
import { useAuth } from '@/context/AuthContext'
import { Select } from './ui'
import { cn } from '@/lib/utils'

/** Selector de cliente para el personal interno (los externos siempre ven su empresa). */
export function ClientPicker({ value, onChange, allowAll = true, className, required }: { value: string; onChange: (v: string) => void; allowAll?: boolean; className?: string; required?: boolean }) {
  const { isInternal } = useAuth()
  const { data } = useClients(isInternal)
  if (!isInternal) return null
  return (
    <Select
      value={value}
      onChange={(e) => onChange(e.target.value)}
      className={cn('min-w-[220px]', className)}
      placeholder={allowAll ? 'Todos los clientes' : 'Seleccione cliente'}
      required={required}
      options={(data ?? []).map((c) => [String(c.id), c.business_name] as [string, string])}
    />
  )
}
