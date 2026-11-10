import { forwardRef, useEffect, type ButtonHTMLAttributes, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes, type TextareaHTMLAttributes } from 'react'
import { createPortal } from 'react-dom'
import { ChevronLeft, ChevronRight, Inbox, Loader2, X } from 'lucide-react'
import { cn } from '@/lib/utils'
import type { Paginated } from '@/lib/types'

/* ---------- Button ---------- */
type Variant = 'primary' | 'secondary' | 'outline' | 'ghost' | 'danger' | 'gold'
const variants: Record<Variant, string> = {
  primary: 'bg-brand-700 text-white hover:bg-brand-800 shadow-sm',
  secondary: 'bg-brand-50 text-brand-700 hover:bg-brand-100',
  outline: 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 shadow-sm',
  ghost: 'text-slate-600 hover:bg-slate-100',
  danger: 'bg-rose-600 text-white hover:bg-rose-700 shadow-sm',
  gold: 'bg-gold-500 text-brand-950 hover:bg-gold-400 shadow-sm',
}

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant
  size?: 'sm' | 'md'
  loading?: boolean
  icon?: ReactNode
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  { variant = 'primary', size = 'md', loading, icon, className, children, disabled, type = 'button', ...rest },
  ref,
) {
  return (
    <button
      ref={ref}
      type={type}
      disabled={disabled || loading}
      className={cn(
        'inline-flex items-center justify-center gap-2 rounded-lg font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-60',
        size === 'sm' ? 'px-2.5 py-1.5 text-xs' : 'px-3.5 py-2 text-sm',
        variants[variant],
        className,
      )}
      {...rest}
    >
      {loading ? <Loader2 className="h-4 w-4 animate-spin" /> : icon}
      {children}
    </button>
  )
})

/* ---------- Form fields ---------- */
export function Field({ label, error, hint, children, className }: { label?: string; error?: string; hint?: string; children: ReactNode; className?: string }) {
  return (
    <div className={className}>
      {label && <label className="label">{label}</label>}
      {children}
      {hint && !error && <p className="mt-1 text-xs text-slate-400">{hint}</p>}
      {error && <p className="mt-1 text-xs text-rose-600">{error}</p>}
    </div>
  )
}

export const Input = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement>>(function Input({ className, ...rest }, ref) {
  return <input ref={ref} className={cn('input', className)} {...rest} />
})

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement>>(function Textarea({ className, ...rest }, ref) {
  return <textarea ref={ref} className={cn('input min-h-[80px]', className)} {...rest} />
})

export const Select = forwardRef<HTMLSelectElement, SelectHTMLAttributes<HTMLSelectElement> & { options?: Record<string, string> | [string, string][]; placeholder?: string }>(
  function Select({ className, options, placeholder, children, ...rest }, ref) {
    const entries = Array.isArray(options) ? options : Object.entries(options ?? {})
    return (
      <select ref={ref} className={cn('input pr-8', className)} {...rest}>
        {placeholder !== undefined && <option value="">{placeholder}</option>}
        {entries.map(([v, l]) => (
          <option key={v} value={v}>
            {l}
          </option>
        ))}
        {children}
      </select>
    )
  },
)

/* ---------- Card ---------- */
export function Card({ className, children, title, actions, subtitle, bodyClass }: { className?: string; children: ReactNode; title?: ReactNode; subtitle?: ReactNode; actions?: ReactNode; bodyClass?: string }) {
  return (
    <section className={cn('rounded-xl border border-slate-200 bg-white shadow-card', className)}>
      {(title || actions) && (
        <header className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-3.5">
          <div>
            {title && <h3 className="text-sm font-semibold text-slate-800">{title}</h3>}
            {subtitle && <p className="text-xs text-slate-500">{subtitle}</p>}
          </div>
          {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
        </header>
      )}
      <div className={cn('p-5', bodyClass)}>{children}</div>
    </section>
  )
}

/* ---------- Badge ---------- */
const tones = {
  slate: 'bg-slate-100 text-slate-700 ring-slate-200',
  blue: 'bg-sky-50 text-sky-700 ring-sky-200',
  brand: 'bg-brand-50 text-brand-700 ring-brand-200',
  green: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
  amber: 'bg-amber-50 text-amber-800 ring-amber-200',
  red: 'bg-rose-50 text-rose-700 ring-rose-200',
  violet: 'bg-violet-50 text-violet-700 ring-violet-200',
  gold: 'bg-gold-50 text-gold-700 ring-gold-300',
}
export type Tone = keyof typeof tones

export function Badge({ tone = 'slate', children, className }: { tone?: Tone; children: ReactNode; className?: string }) {
  return <span className={cn('inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset', tones[tone], className)}>{children}</span>
}

const STATUS: Record<string, [string, Tone]> = {
  ingresado: ['Ingresado', 'blue'],
  en_proceso: ['En proceso', 'amber'],
  procesado: ['Procesado', 'green'],
  observado: ['Observado', 'red'],
  devuelto: ['Devuelto', 'red'],
  reingresado: ['Reingresado', 'violet'],
  error: ['Error', 'red'],
  abierta: ['Abierta', 'blue'],
  respondida: ['Respondida', 'green'],
  en_seguimiento: ['En seguimiento', 'amber'],
  cerrada: ['Cerrada', 'slate'],
  pendiente: ['Pendiente', 'slate'],
  en_revision: ['En revisión', 'violet'],
  completado: ['Completado', 'green'],
  registrado: ['Registrado', 'blue'],
  validado: ['Validado', 'green'],
  anulado: ['Anulado', 'slate'],
  borrador: ['Borrador', 'slate'],
  revisado: ['Revisado', 'violet'],
  publicado: ['Publicado', 'green'],
  preparado: ['Preparado', 'blue'],
  aprobado: ['Aprobado', 'green'],
  abierto: ['Abierto', 'green'],
  cerrado: ['Cerrado', 'slate'],
  planificado: ['Planificado', 'blue'],
  en_curso: ['En curso', 'amber'],
  implementado: ['Implementado', 'green'],
  cancelado: ['Cancelado', 'slate'],
  pagado: ['Pagado', 'green'],
  parcial: ['Parcial', 'amber'],
}

export function StatusBadge({ status }: { status: string }) {
  const [label, tone] = STATUS[status] ?? [status, 'slate' as Tone]
  return <Badge tone={tone}>{label}</Badge>
}

export function PriorityBadge({ priority }: { priority: string }) {
  const tone: Tone = priority === 'alta' ? 'red' : priority === 'media' ? 'amber' : 'slate'
  return <Badge tone={tone}>{priority.charAt(0).toUpperCase() + priority.slice(1)}</Badge>
}

/* ---------- Page header ---------- */
export function PageHeader({ title, subtitle, actions, tag }: { title: string; subtitle?: ReactNode; actions?: ReactNode; tag?: string }) {
  return (
    <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
      <div>
        <div className="flex items-center gap-2">
          {tag && <span className="rounded-md bg-gold-100 px-1.5 py-0.5 text-[11px] font-bold tracking-wide text-gold-700">{tag}</span>}
          <h1 className="text-xl font-semibold text-slate-900 sm:text-2xl">{title}</h1>
        </div>
        {subtitle && <p className="mt-1 text-sm text-slate-500">{subtitle}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  )
}

/* ---------- Stat card ---------- */
export function StatCard({ label, value, icon, tone = 'brand', hint, onClick }: { label: string; value: ReactNode; icon?: ReactNode; tone?: 'brand' | 'gold' | 'green' | 'red' | 'amber' | 'violet'; hint?: ReactNode; onClick?: () => void }) {
  const toneCls = {
    brand: 'bg-brand-50 text-brand-700',
    gold: 'bg-gold-50 text-gold-700',
    green: 'bg-emerald-50 text-emerald-700',
    red: 'bg-rose-50 text-rose-700',
    amber: 'bg-amber-50 text-amber-700',
    violet: 'bg-violet-50 text-violet-700',
  }[tone]
  return (
    <div
      onClick={onClick}
      className={cn('flex items-start gap-4 rounded-xl border border-slate-200 bg-white p-4 shadow-card', onClick && 'cursor-pointer transition hover:border-brand-300')}
    >
      {icon && <div className={cn('flex h-10 w-10 shrink-0 items-center justify-center rounded-lg', toneCls)}>{icon}</div>}
      <div className="min-w-0">
        <p className="truncate text-xs font-medium uppercase tracking-wide text-slate-500">{label}</p>
        <p className="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{value}</p>
        {hint && <p className="mt-0.5 text-xs text-slate-500">{hint}</p>}
      </div>
    </div>
  )
}

/* ---------- Modal ---------- */
export function Modal({ open, onClose, title, children, footer, size = 'md' }: { open: boolean; onClose: () => void; title: ReactNode; children: ReactNode; footer?: ReactNode; size?: 'sm' | 'md' | 'lg' | 'xl' }) {
  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    document.addEventListener('keydown', onKey)
    const prev = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = prev
    }
  }, [open, onClose])

  if (!open) return null
  const width = { sm: 'max-w-md', md: 'max-w-xl', lg: 'max-w-3xl', xl: 'max-w-5xl' }[size]
  return createPortal(
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 backdrop-blur-[2px] sm:p-8" onMouseDown={onClose}>
      <div className={cn('my-auto w-full rounded-xl bg-white shadow-2xl', width)} onMouseDown={(e) => e.stopPropagation()} role="dialog" aria-modal="true">
        <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4">
          <h2 className="text-base font-semibold text-slate-900">{title}</h2>
          <button onClick={onClose} className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Cerrar">
            <X className="h-5 w-5" />
          </button>
        </div>
        <div className="max-h-[75vh] overflow-y-auto px-5 py-4">{children}</div>
        {footer && <div className="flex flex-wrap justify-end gap-2 border-t border-slate-100 bg-slate-50/60 px-5 py-3">{footer}</div>}
      </div>
    </div>,
    document.body,
  )
}

/* ---------- Tabs ---------- */
export function Tabs<T extends string>({ tabs, value, onChange }: { tabs: { value: T; label: ReactNode }[]; value: T; onChange: (v: T) => void }) {
  return (
    <div className="mb-4 flex gap-1 overflow-x-auto border-b border-slate-200">
      {tabs.map((t) => (
        <button
          key={t.value}
          onClick={() => onChange(t.value)}
          className={cn(
            '-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium transition-colors',
            value === t.value ? 'border-brand-700 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700',
          )}
        >
          {t.label}
        </button>
      ))}
    </div>
  )
}

/* ---------- Estados ---------- */
export function Spinner({ className }: { className?: string }) {
  return <Loader2 className={cn('h-5 w-5 animate-spin text-brand-600', className)} />
}

export function Loading({ label = 'Cargando...' }: { label?: string }) {
  return (
    <div className="flex items-center justify-center gap-2 py-12 text-sm text-slate-500">
      <Spinner /> {label}
    </div>
  )
}

export function Empty({ title = 'Sin registros', description, icon, action }: { title?: string; description?: ReactNode; icon?: ReactNode; action?: ReactNode }) {
  return (
    <div className="flex flex-col items-center justify-center py-12 text-center">
      <div className="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">{icon ?? <Inbox className="h-6 w-6" />}</div>
      <p className="text-sm font-medium text-slate-700">{title}</p>
      {description && <p className="mt-1 max-w-sm text-sm text-slate-500">{description}</p>}
      {action && <div className="mt-4">{action}</div>}
    </div>
  )
}

/* ---------- Paginación ---------- */
export function Pagination<T>({ page, onPage }: { page?: Paginated<T>; onPage: (p: number) => void }) {
  if (!page || page.last_page <= 1) {
    return page ? <p className="px-4 py-3 text-xs text-slate-500">{page.total} registro(s)</p> : null
  }
  return (
    <div className="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-xs text-slate-500">
      <span>
        {page.from}–{page.to} de {page.total}
      </span>
      <div className="flex items-center gap-1">
        <Button size="sm" variant="outline" disabled={page.current_page <= 1} onClick={() => onPage(page.current_page - 1)} icon={<ChevronLeft className="h-4 w-4" />} aria-label="Anterior" />
        <span className="px-2">
          Página {page.current_page} de {page.last_page}
        </span>
        <Button size="sm" variant="outline" disabled={page.current_page >= page.last_page} onClick={() => onPage(page.current_page + 1)} icon={<ChevronRight className="h-4 w-4" />} aria-label="Siguiente" />
      </div>
    </div>
  )
}

/* ---------- Tabla contenedora ---------- */
export function TableWrap({ children }: { children: ReactNode }) {
  return <div className="overflow-x-auto">{children}</div>
}

/* ---------- Toolbar de filtros ---------- */
export function Toolbar({ children }: { children: ReactNode }) {
  return <div className="flex flex-wrap items-end gap-3 border-b border-slate-100 px-4 py-3">{children}</div>
}

/* ---------- Confirm ---------- */
export function Confirm({ open, onClose, onConfirm, title, message, confirmLabel = 'Confirmar', danger, loading }: { open: boolean; onClose: () => void; onConfirm: () => void; title: string; message: ReactNode; confirmLabel?: string; danger?: boolean; loading?: boolean }) {
  return (
    <Modal
      open={open}
      onClose={onClose}
      title={title}
      size="sm"
      footer={
        <>
          <Button variant="outline" onClick={onClose}>
            Cancelar
          </Button>
          <Button variant={danger ? 'danger' : 'primary'} onClick={onConfirm} loading={loading}>
            {confirmLabel}
          </Button>
        </>
      }
    >
      <div className="text-sm text-slate-600">{message}</div>
    </Modal>
  )
}

export function Avatar({ name, className }: { name?: string; className?: string }) {
  const init = (name ?? '?').split(' ').filter(Boolean).slice(0, 2).map((p) => p[0]?.toUpperCase()).join('')
  return <span className={cn('inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700', className)}>{init}</span>
}

export function KeyValue({ items, cols = 2 }: { items: [string, ReactNode][]; cols?: 2 | 3 | 4 }) {
  const grid = { 2: 'sm:grid-cols-2', 3: 'sm:grid-cols-3', 4: 'sm:grid-cols-4' }[cols]
  return (
    <dl className={cn('grid grid-cols-1 gap-x-6 gap-y-3', grid)}>
      {items.map(([k, v]) => (
        <div key={k}>
          <dt className="text-xs font-medium uppercase tracking-wide text-slate-400">{k}</dt>
          <dd className="mt-0.5 text-sm text-slate-800">{v ?? '—'}</dd>
        </div>
      ))}
    </dl>
  )
}
