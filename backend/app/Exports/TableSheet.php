<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class TableSheet implements Export, FromArray, ShouldAutoSize, WithStyles, WithTitle
{
    private int $headerRow = 4;

    public function __construct(private readonly string $reportTitle, private readonly array $table, private readonly ?string $subtitle = null) {}

    public function array(): array
    {
        $cols = $this->table['columns'];
        $out = [
            [$this->reportTitle],
            [$this->subtitle ?? ''],
            [$this->table['title'] ?? ''],
            array_column($cols, 'label'),
        ];

        foreach ($this->table['rows'] as $row) {
            $out[] = array_map(fn ($c) => $this->cell($row[$c['key']] ?? null), $cols);
        }

        if (! empty($this->table['totals'])) {
            $out[] = array_map(function ($c, $i) {
                if ($i === 0) {
                    return 'TOTAL';
                }

                return $this->table['totals'][$c['key']] ?? '';
            }, $cols, array_keys($cols));
        }

        return $out;
    }

    private function cell($v)
    {
        return is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v;
    }

    public function title(): string
    {
        $t = preg_replace('/[\\\\\/\?\*\[\]:]/', '', $this->table['title'] ?? 'Hoja');

        return mb_substr($t ?: 'Hoja', 0, 31);
    }

    public function styles(Worksheet $sheet): array
    {
        $lastCol = $sheet->getHighestColumn();
        $sheet->getStyle("A{$this->headerRow}:{$lastCol}{$this->headerRow}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '1E3A5F']],
        ]);
        if (! empty($this->table['totals'])) {
            $last = $sheet->getHighestRow();
            $sheet->getStyle("A{$last}:{$lastCol}{$last}")->getFont()->setBold(true);
        }

        return [1 => ['font' => ['bold' => true, 'size' => 14]], 3 => ['font' => ['bold' => true]]];
    }
}
