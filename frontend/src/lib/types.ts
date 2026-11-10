export type Role = 'socio' | 'supervisor' | 'analista' | 'cliente'

export interface User {
  id: number
  name: string
  email: string
  type: 'interno' | 'externo'
  position?: string | null
  phone?: string | null
  hourly_cost?: string | null
  is_active: boolean
  last_login_at?: string | null
  client_id?: number | null
  client?: { id: number; ruc: string; business_name: string } | null
  role?: Role | null
  permissions?: string[]
  assigned_clients?: { id: number; business_name: string; role: string }[]
}

export interface ClientLite {
  id: number
  ruc: string
  business_name: string
  trade_name?: string | null
  is_active?: boolean
}

export interface Client extends ClientLite {
  tax_regime: string
  address?: string | null
  contact_name?: string | null
  contact_email?: string | null
  contact_phone?: string | null
  contract_number?: string | null
  contract_start?: string | null
  contract_end?: string | null
  max_external_users: number
  access_notes?: string | null
  users_count?: number
  pending_documents_count?: number
  open_conversations_count?: number
  team?: { id: number; name: string; email?: string; position?: string; pivot: { role: string } }[]
  users?: (User & { permissions: { name: string }[] })[]
}

export interface Paginated<T> {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number | null
  to: number | null
}

export interface DocumentItem {
  id: number
  client_id: number
  client?: ClientLite
  uploader?: { id: number; name: string; type: string }
  assignee?: { id: number; name: string } | null
  parent_id?: number | null
  category: string
  source: string
  period?: string | null
  original_name: string
  mime_type?: string
  extension?: string
  size: number
  status: string
  processing_method?: string | null
  extracted_data?: Record<string, unknown> | null
  validation_errors?: string[] | null
  client_notes?: string | null
  return_reason?: string | null
  processed_at?: string | null
  returned_at?: string | null
  created_at: string
  voucher?: Voucher | null
  history?: { id: number; from_status: string | null; to_status: string; comment: string | null; created_at: string; user?: { name: string } }[]
  parent?: { id: number; original_name: string; status: string; return_reason: string | null } | null
  resubmissions?: { id: number; original_name: string; status: string; created_at: string }[]
}

export interface VoucherItem {
  id: number
  code?: string | null
  description: string
  unit?: string | null
  quantity: string
  unit_price: string
  subtotal: string
  igv: string
  total: string
}

export interface JournalLine {
  id: number
  account_id: number
  account?: { id: number; code: string; name: string }
  description?: string | null
  debit: string
  credit: string
}

export interface JournalEntry {
  id: number
  client_id: number
  client?: ClientLite
  number: string
  date: string
  book: string
  description: string
  origin: string
  status: string
  total_debit: string
  total_credit: string
  lines: JournalLine[]
  creator?: { name: string } | null
}

export interface Voucher {
  id: number
  client_id: number
  client?: ClientLite
  document_id?: number | null
  operation: 'compra' | 'venta'
  document_type: string
  document_type_name: string
  series: string
  number: string
  full_number: string
  issue_date: string
  due_date?: string | null
  issuer_ruc: string
  issuer_name: string
  customer_doc?: string | null
  customer_name?: string | null
  currency: string
  exchange_rate: string
  taxable_amount: string
  exempt_amount: string
  unaffected_amount: string
  igv: string
  isc: string
  other_charges: string
  total: string
  reference_doc?: string | null
  status: string
  payment_status: string
  amount_paid: string
  items?: VoucherItem[]
  journal_entry?: JournalEntry | null
}

export interface Conversation {
  id: number
  client_id: number
  client?: ClientLite
  subject: string
  category: string
  priority: string
  status: string
  last_message_at?: string | null
  first_response_at?: string | null
  created_at: string
  unread_count?: number
  creator?: { id: number; name: string }
  assignee?: { id: number; name: string } | null
  latest_message?: Message | null
  messages?: Message[]
}

export interface Message {
  id: number
  conversation_id: number
  user_id: number
  user?: { id: number; name: string; type: string }
  from_client: boolean
  body: string
  attachment_name?: string | null
  read_at?: string | null
  created_at: string
}

export interface Task {
  id: number
  client_id: number
  client?: ClientLite
  period_id?: number | null
  period?: { id: number; year: number; month: number; label: string } | null
  assigned_to?: number | null
  assignee?: { id: number; name: string } | null
  reviewer?: { id: number; name: string } | null
  area: string
  activity: string
  title: string
  description?: string | null
  deliverable?: string | null
  status: string
  priority: string
  due_date?: string | null
  completed_at?: string | null
  estimated_hours?: string | null
  hours_logged?: string | null
}

export interface FinancialReport {
  id: number
  client_id: number
  client?: ClientLite
  report_code: string
  type: string
  title: string
  year: number
  month?: number | null
  format: string
  original_name?: string | null
  status: string
  published: boolean
  published_at?: string | null
  generator?: { name: string } | null
  reviewer?: { name: string } | null
  created_at: string
}

export interface ReportColumn {
  key: string
  label: string
  type?: 'number' | 'money' | 'percent'
}

export interface ReportData {
  code: string
  title: string
  description: string
  database: string
  range: { from: string; to: string }
  summary: { label: string; value: number | string; format?: 'percent' | 'money' }[]
  chart: { type: 'bar' | 'pie'; keys: string[]; data: Record<string, string | number>[]; stacked?: boolean; unit?: string } | null
  columns: ReportColumn[]
  rows: Record<string, unknown>[]
}

export interface Meta {
  document_categories: Record<string, string>
  document_statuses: Record<string, string>
  voucher_types: Record<string, string>
  task_areas: Record<string, string>
  task_activities: Record<string, string>
  task_statuses: Record<string, string>
  report_codes: Record<string, string>
  report_types: Record<string, { code: string; label: string }>
  books: Record<string, string>
  conversation_categories: Record<string, string>
  tax_regimes: Record<string, string>
  client_permissions: Record<string, string>
  ai_extraction: boolean
}

export interface Staff {
  id: number
  name: string
  email: string
  position?: string | null
  role: Role
}
