<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;

/** Plan Contable General Empresarial (PCGE) - cuentas principales. */
class AccountSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            // [código, nombre, tipo, naturaleza, acepta movimientos]
            ['10', 'Efectivo y equivalentes de efectivo', 'activo', 'deudora', false],
            ['101', 'Caja', 'activo', 'deudora', true],
            ['1041', 'Cuentas corrientes operativas', 'activo', 'deudora', true],
            ['12', 'Cuentas por cobrar comerciales - terceros', 'activo', 'deudora', false],
            ['1212', 'Facturas, boletas y otros comprobantes por cobrar - emitidas en cartera', 'activo', 'deudora', true],
            ['14', 'Cuentas por cobrar al personal, accionistas y directores', 'activo', 'deudora', false],
            ['1411', 'Préstamos al personal', 'activo', 'deudora', true],
            ['16', 'Cuentas por cobrar diversas - terceros', 'activo', 'deudora', false],
            ['1629', 'Otras cuentas por cobrar diversas', 'activo', 'deudora', true],
            ['18', 'Servicios y otros contratados por anticipado', 'activo', 'deudora', false],
            ['1821', 'Seguros pagados por anticipado', 'activo', 'deudora', true],
            ['20', 'Mercaderías', 'activo', 'deudora', false],
            ['20111', 'Mercaderías manufacturadas - costo', 'activo', 'deudora', true],
            ['33', 'Propiedades, planta y equipo', 'activo', 'deudora', false],
            ['3361', 'Equipo para procesamiento de información', 'activo', 'deudora', true],
            ['3341', 'Vehículos motorizados', 'activo', 'deudora', true],
            ['39', 'Depreciación, amortización y agotamiento acumulados', 'activo', 'acreedora', false],
            ['3913', 'Depreciación acumulada - PPE', 'activo', 'acreedora', true],
            ['40', 'Tributos, contraprestaciones y aportes al sistema de pensiones y salud por pagar', 'pasivo', 'acreedora', false],
            ['40111', 'IGV - Cuenta propia', 'pasivo', 'acreedora', true],
            ['4017', 'Impuesto a la renta', 'pasivo', 'acreedora', true],
            ['4031', 'ESSALUD', 'pasivo', 'acreedora', true],
            ['4032', 'ONP', 'pasivo', 'acreedora', true],
            ['41', 'Remuneraciones y participaciones por pagar', 'pasivo', 'acreedora', false],
            ['4111', 'Sueldos y salarios por pagar', 'pasivo', 'acreedora', true],
            ['417', 'Administradoras de fondos de pensiones', 'pasivo', 'acreedora', true],
            ['42', 'Cuentas por pagar comerciales - terceros', 'pasivo', 'acreedora', false],
            ['4212', 'Facturas, boletas y otros comprobantes por pagar - emitidas', 'pasivo', 'acreedora', true],
            ['45', 'Obligaciones financieras', 'pasivo', 'acreedora', false],
            ['4511', 'Préstamos de instituciones financieras', 'pasivo', 'acreedora', true],
            ['46', 'Cuentas por pagar diversas - terceros', 'pasivo', 'acreedora', false],
            ['4699', 'Otras cuentas por pagar diversas', 'pasivo', 'acreedora', true],
            ['50', 'Capital', 'patrimonio', 'acreedora', false],
            ['501', 'Capital social', 'patrimonio', 'acreedora', true],
            ['58', 'Reservas', 'patrimonio', 'acreedora', false],
            ['582', 'Reserva legal', 'patrimonio', 'acreedora', true],
            ['59', 'Resultados acumulados', 'patrimonio', 'acreedora', false],
            ['5911', 'Utilidades acumuladas', 'patrimonio', 'acreedora', true],
            ['60', 'Compras', 'gasto', 'deudora', false],
            ['6011', 'Mercaderías manufacturadas', 'gasto', 'deudora', true],
            ['6031', 'Suministros', 'gasto', 'deudora', true],
            ['62', 'Gastos de personal, directores y gerentes', 'gasto', 'deudora', false],
            ['6211', 'Sueldos y salarios', 'gasto', 'deudora', true],
            ['6271', 'Régimen de prestaciones de salud', 'gasto', 'deudora', true],
            ['63', 'Gastos de servicios prestados por terceros', 'gasto', 'deudora', false],
            ['6311', 'Transporte de carga', 'gasto', 'deudora', true],
            ['6321', 'Asesoría y consultoría administrativa', 'gasto', 'deudora', true],
            ['6352', 'Alquileres de edificaciones', 'gasto', 'deudora', true],
            ['6361', 'Energía eléctrica', 'gasto', 'deudora', true],
            ['6365', 'Teléfono e internet', 'gasto', 'deudora', true],
            ['64', 'Gastos por tributos', 'gasto', 'deudora', false],
            ['6419', 'Otros tributos', 'gasto', 'deudora', true],
            ['65', 'Otros gastos de gestión', 'gasto', 'deudora', false],
            ['6591', 'Donaciones y otros gastos de gestión', 'gasto', 'deudora', true],
            ['67', 'Gastos financieros', 'gasto', 'deudora', false],
            ['6731', 'Intereses por préstamos', 'gasto', 'deudora', true],
            ['68', 'Valuación y deterioro de activos y provisiones', 'gasto', 'deudora', false],
            ['6814', 'Depreciación de PPE', 'gasto', 'deudora', true],
            ['69', 'Costo de ventas', 'gasto', 'deudora', false],
            ['6911', 'Costo de ventas - mercaderías', 'gasto', 'deudora', true],
            ['70', 'Ventas', 'ingreso', 'acreedora', false],
            ['7011', 'Mercaderías manufacturadas - ventas', 'ingreso', 'acreedora', true],
            ['7041', 'Prestación de servicios', 'ingreso', 'acreedora', true],
            ['75', 'Otros ingresos de gestión', 'ingreso', 'acreedora', false],
            ['759', 'Otros ingresos de gestión', 'ingreso', 'acreedora', true],
            ['77', 'Ingresos financieros', 'ingreso', 'acreedora', false],
            ['7721', 'Intereses en depósitos', 'ingreso', 'acreedora', true],
            ['88', 'Impuesto a la renta', 'gasto', 'deudora', false],
            ['881', 'Impuesto a la renta - corriente', 'gasto', 'deudora', true],
            ['89', 'Determinación del resultado del ejercicio', 'patrimonio', 'acreedora', false],
            ['891', 'Utilidad', 'patrimonio', 'acreedora', true],
        ];

        foreach ($accounts as [$code, $name, $type, $nature, $postable]) {
            Account::updateOrCreate(['code' => $code], [
                'name' => $name,
                'type' => $type,
                'nature' => $nature,
                'level' => strlen($code) <= 2 ? 1 : (strlen($code) === 3 ? 2 : 3),
                'accepts_entries' => $postable,
            ]);
        }
    }
}
