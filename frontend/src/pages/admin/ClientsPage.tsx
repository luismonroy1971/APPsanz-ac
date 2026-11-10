import { useState } from 'react'
import { Building2, Pencil, Plus, Trash2, UsersRound } from 'lucide-react'
import { useGet, useMeta, useSend, useStaff } from '@/hooks/useApi'
import type { Client, Paginated } from '@/lib/types'
import { Badge, Button, Card, Empty, Field, Input, Loading, Modal, PageHeader, Pagination, Select, TableWrap, Textarea, Toolbar } from '@/components/ui'
import { fmtDate, ROLE_LABEL } from '@/lib/utils'

export default function ClientsPage() {
  const [f, setF] = useState({ search: '', page: 1 })
  const { data, isLoading } = useGet<Paginated<Client>>('/clients', f)
  const [edit, setEdit] = useState<Client | 'new' | null>(null)
  const [team, setTeam] = useState<Client | null>(null)

  return (
    <>
      <PageHeader title="Clientes" subtitle="Empresas atendidas, contrato y coordinación previa de accesos." actions={<Button icon={<Plus className="h-4 w-4" />} onClick={() => setEdit('new')}>Nuevo cliente</Button>} />
      <Card bodyClass="p-0">
        <Toolbar><Input placeholder="Buscar por RUC o razón social..." value={f.search} onChange={(e) => setF({ search: e.target.value, page: 1 })} className="w-72" /></Toolbar>
        {isLoading ? <Loading /> : !data?.data.length ? <Empty title="Sin clientes" icon={<Building2 className="h-6 w-6" />} /> : (
          <TableWrap>
            <table className="table-base">
              <thead><tr><th>Cliente</th><th>Régimen</th><th>Contrato</th><th>Equipo</th><th className="num">Usuarios</th><th className="num">Docs. pend.</th><th className="num">Consultas</th><th>Estado</th><th /></tr></thead>
              <tbody className="divide-y divide-slate-100">
                {data.data.map((c) => (
                  <tr key={c.id}>
                    <td><p className="font-medium">{c.business_name}</p><p className="text-xs text-slate-500">RUC {c.ruc}</p></td>
                    <td className="text-xs capitalize">{c.tax_regime}</td>
                    <td className="text-xs">{c.contract_number ?? '—'}<br /><span className="text-slate-400">hasta {fmtDate(c.contract_end)}</span></td>
                    <td className="max-w-[200px] truncate text-xs">{c.team?.map((t) => t.name.split(' ')[0]).join(', ') || '—'}</td>
                    <td className="num">{c.users_count}/{c.max_external_users}</td>
                    <td className="num">{c.pending_documents_count ? <Badge tone="amber">{c.pending_documents_count}</Badge> : 0}</td>
                    <td className="num">{c.open_conversations_count}</td>
                    <td>{c.is_active ? <Badge tone="green">Activo</Badge> : <Badge tone="slate">Inactivo</Badge>}</td>
                    <td className="whitespace-nowrap text-right">
                      <Button size="sm" variant="ghost" icon={<UsersRound className="h-4 w-4" />} onClick={() => setTeam(c)}>Equipo</Button>
                      <Button size="sm" variant="ghost" icon={<Pencil className="h-4 w-4" />} onClick={() => setEdit(c)} aria-label="Editar" />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </TableWrap>
        )}
        <Pagination page={data} onPage={(p) => setF({ ...f, page: p })} />
      </Card>
      {edit && <ClientModal client={edit === 'new' ? null : edit} onClose={() => setEdit(null)} />}
      {team && <TeamModal client={team} onClose={() => setTeam(null)} />}
    </>
  )
}

function ClientModal({ client, onClose }: { client: Client | null; onClose: () => void }) {
  const { data: meta } = useMeta()
  const [v, setV] = useState({
    ruc: client?.ruc ?? '', business_name: client?.business_name ?? '', trade_name: client?.trade_name ?? '', tax_regime: client?.tax_regime ?? 'general',
    address: client?.address ?? '', contact_name: client?.contact_name ?? '', contact_email: client?.contact_email ?? '', contact_phone: client?.contact_phone ?? '',
    contract_number: client?.contract_number ?? '', contract_start: client?.contract_start ?? '', contract_end: client?.contract_end ?? '',
    max_external_users: String(client?.max_external_users ?? 5), access_notes: client?.access_notes ?? '', is_active: client?.is_active ?? true,
  })
  const opts = { invalidate: ['/clients'], onSuccess: onClose }
  const create = useSend('post', '/clients', opts)
  const update = useSend('put', `/clients/${client?.id}`, opts)
  const remove = useSend('delete', `/clients/${client?.id}`, opts)
  const set = (k: keyof typeof v) => (e: { target: { value: string } }) => setV({ ...v, [k]: e.target.value })

  return (
    <Modal
      open
      onClose={onClose}
      size="lg"
      title={client ? 'Editar cliente' : 'Nuevo cliente'}
      footer={
        <>
          {client && <Button variant="ghost" className="mr-auto text-rose-600" icon={<Trash2 className="h-4 w-4" />} onClick={() => remove.mutate(undefined)}>Dar de baja</Button>}
          <Button variant="outline" onClick={onClose}>Cancelar</Button>
          <Button loading={create.isPending || update.isPending} onClick={() => (client ? update.mutate(v) : create.mutate(v))}>Guardar</Button>
        </>
      }
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <Field label="RUC"><Input maxLength={11} value={v.ruc} onChange={set('ruc')} /></Field>
        <Field label="Razón social" className="sm:col-span-2"><Input value={v.business_name} onChange={set('business_name')} /></Field>
        <Field label="Nombre comercial"><Input value={v.trade_name} onChange={set('trade_name')} /></Field>
        <Field label="Régimen tributario"><Select value={v.tax_regime} onChange={set('tax_regime')} options={meta?.tax_regimes} /></Field>
        <Field label="Estado"><Select value={v.is_active ? '1' : '0'} onChange={(e) => setV({ ...v, is_active: e.target.value === '1' })} options={{ '1': 'Activo', '0': 'Inactivo' }} /></Field>
        <Field label="Dirección" className="sm:col-span-3"><Input value={v.address} onChange={set('address')} /></Field>
        <Field label="Contacto"><Input value={v.contact_name} onChange={set('contact_name')} /></Field>
        <Field label="Correo"><Input type="email" value={v.contact_email} onChange={set('contact_email')} /></Field>
        <Field label="Teléfono"><Input value={v.contact_phone} onChange={set('contact_phone')} /></Field>
        <Field label="N° contrato"><Input value={v.contract_number} onChange={set('contract_number')} /></Field>
        <Field label="Inicio contrato"><Input type="date" value={v.contract_start} onChange={set('contract_start')} /></Field>
        <Field label="Fin contrato"><Input type="date" value={v.contract_end} onChange={set('contract_end')} /></Field>
        <Field label="Máx. usuarios externos"><Input type="number" min={1} value={v.max_external_users} onChange={set('max_external_users')} /></Field>
        <Field label="Coordinación previa: personas con acceso y limitaciones" className="sm:col-span-3"><Textarea rows={3} value={v.access_notes} onChange={set('access_notes')} /></Field>
      </div>
    </Modal>
  )
}

function TeamModal({ client, onClose }: { client: Client; onClose: () => void }) {
  const { data: staff } = useStaff()
  const { data: detail } = useGet<{ data: Client }>(`/clients/${client.id}`)
  const [members, setMembers] = useState<{ user_id: string; role: string }[] | null>(null)
  const current = members ?? (detail?.data.team ?? []).map((t) => ({ user_id: String(t.id), role: t.pivot.role }))
  const save = useSend('put', `/clients/${client.id}/team`, { invalidate: ['/clients'], onSuccess: onClose })
  const upd = (list: typeof current) => setMembers(list)

  return (
    <Modal open onClose={onClose} title={`Equipo asignado · ${client.business_name}`} footer={<><Button variant="outline" onClick={onClose}>Cancelar</Button><Button loading={save.isPending} onClick={() => save.mutate({ members: current })}>Guardar equipo</Button></>}>
      <p className="mb-3 text-sm text-slate-500">Analista + Supervisor + Socio responsables del cliente. Los analistas solo ven los clientes asignados.</p>
      <div className="space-y-2">
        {current.map((m, i) => (
          <div key={i} className="flex gap-2">
            <Select value={m.user_id} onChange={(e) => upd(current.map((x, j) => (j === i ? { ...x, user_id: e.target.value } : x)))} options={(staff ?? []).map((s) => [String(s.id), `${s.name} (${ROLE_LABEL[s.role]})`] as [string, string])} placeholder="Seleccione" />
            <Select value={m.role} onChange={(e) => upd(current.map((x, j) => (j === i ? { ...x, role: e.target.value } : x)))} options={{ analista: 'Analista', supervisor: 'Supervisor', socio: 'Socio' }} className="w-40" />
            <Button variant="ghost" icon={<Trash2 className="h-4 w-4" />} onClick={() => upd(current.filter((_, j) => j !== i))} aria-label="Quitar" />
          </div>
        ))}
      </div>
      <Button size="sm" variant="secondary" className="mt-3" icon={<Plus className="h-4 w-4" />} onClick={() => upd([...current, { user_id: '', role: 'analista' }])}>Agregar miembro</Button>
    </Modal>
  )
}
