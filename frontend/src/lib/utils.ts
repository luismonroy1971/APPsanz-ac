import { clsx, type ClassValue } from 'clsx'
import { twMerge } from 'tailwind-merge'
import { format, formatDistanceToNow, parseISO } from 'date-fns'
import { es } from 'date-fns/locale'

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

const money = new Intl.NumberFormat('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const int = new Intl.NumberFormat('es-PE', { maximumFractionDigits: 2 })

export function fmtMoney(v: unknown, currency?: string) {
  const n = Number(v ?? 0)
  const s = money.format(Number.isFinite(n) ? n : 0)
  if (!currency) return s
  const symbol = currency === 'PEN' ? 'S/' : currency === 'USD' ? 'US$' : currency === 'EUR' ? '€' : currency
  return `${symbol} ${s}`
}

export function fmtNumber(v: unknown) {
  const n = Number(v ?? 0)
  return int.format(Number.isFinite(n) ? n : 0)
}

export function fmtDate(v?: string | null, pattern = 'dd/MM/yyyy') {
  if (!v) return '—'
  try {
    return format(parseISO(v), pattern, { locale: es })
  } catch {
    return v
  }
}

export function fmtDateTime(v?: string | null) {
  return fmtDate(v, 'dd/MM/yyyy HH:mm')
}

export function fromNow(v?: string | null) {
  if (!v) return '—'
  try {
    return formatDistanceToNow(parseISO(v), { addSuffix: true, locale: es })
  } catch {
    return v
  }
}

export function fmtBytes(b: number) {
  if (b < 1024) return `${b} B`
  if (b < 1024 * 1024) return `${(b / 1024).toFixed(1)} KB`
  return `${(b / 1024 / 1024).toFixed(1)} MB`
}

export const MONTHS = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Setiembre', 'Octubre', 'Noviembre', 'Diciembre']

export function isoDate(d: Date) {
  const off = d.getTimezoneOffset()
  return new Date(d.getTime() - off * 60000).toISOString().slice(0, 10)
}

export function currentWeek(): { from: string; to: string } {
  const now = new Date()
  const day = (now.getDay() + 6) % 7
  const monday = new Date(now)
  monday.setDate(now.getDate() - day)
  const sunday = new Date(monday)
  sunday.setDate(monday.getDate() + 6)
  return { from: isoDate(monday), to: isoDate(sunday) }
}

export function initials(name?: string) {
  return (name ?? '?')
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0]?.toUpperCase())
    .join('')
}

export const ROLE_LABEL: Record<string, string> = {
  socio: 'Socio',
  supervisor: 'Supervisor',
  analista: 'Analista',
  cliente: 'Cliente',
}
