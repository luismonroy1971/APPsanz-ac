<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Permisos de acceso externo (coordinación previa con el cliente)
        foreach (['documentos.subir', 'consultas.usar', 'informes.ver'] as $p) {
            Permission::findOrCreate($p, 'web');
        }

        foreach (['socio', 'supervisor', 'analista', 'cliente'] as $r) {
            Role::findOrCreate($r, 'web');
        }
    }
}
