<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Exporta una o varias tablas (cada una en su hoja) a Excel.
 * Estructura de tabla: ['title' => string, 'columns' => [['key','label','type'?]], 'rows' => [...], 'totals' => [...]?]
 */
class TablesExport implements Export, WithMultipleSheets
{
    public function __construct(private readonly string $title, private readonly array $tables, private readonly ?string $subtitle = null) {}

    public function sheets(): array
    {
        return array_map(fn ($t) => new TableSheet($this->title, $t, $this->subtitle), $this->tables);
    }
}
