<?php

return [
    'igv_rate' => 0.18,

    // Tolerancia de redondeo para validar base + IGV = total
    'tolerance' => 0.05,

    // Cuentas PCGE usadas en los asientos automáticos
    'accounts' => [
        'purchases' => env('ACC_PURCHASES', '6011'),      // Compras de mercaderías
        'igv' => env('ACC_IGV', '40111'),                 // IGV - cuenta propia
        'payables' => env('ACC_PAYABLES', '4212'),        // Facturas emitidas por pagar
        'receivables' => env('ACC_RECEIVABLES', '1212'),  // Facturas emitidas por cobrar
        'sales' => env('ACC_SALES', '7011'),              // Ventas de mercaderías
        'other_taxes' => env('ACC_OTHER_TAXES', '6419'),  // Otros tributos (ISC, otros cargos)
    ],

    'ai' => [
        'enabled' => (bool) env('AI_EXTRACTION_ENABLED', false),
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5-5'),
    ],
];
