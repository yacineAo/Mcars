<?php

declare(strict_types=1);

return [
    'fields' => [
        'employee' => 'الموظف',
        'amount' => 'المبلغ',
        'advanced_on' => 'السلفة بتاريخ',
        'reason' => 'السبب',
        'status' => 'الحالة',
        'status_help' => 'تديرها سيرورة العمل: الموافقة تسجل الدفع في دفتر القيود (E61)، والرفض يرفضها، والاسترجاع عبر الرواتب يقفلها.',
        'notes' => 'ملاحظات',
    ],
    'columns' => [
        'employee' => 'الموظف',
        'amount' => 'المبلغ',
        'advanced_on' => 'السلفة بتاريخ',
        'status' => 'الحالة',
        'recovered_in' => 'استرجعت في',
    ],
    'filters' => [
        'open' => 'السلف الجارية',
        'settled' => 'المسددة',
        'employee' => 'الموظف',
    ],
    'actions' => [
        'approve' => 'اعتماد ودفع',
        'approve_description' => 'يُسجل الدفع في دفتر القيود (سلفة على 1130، خروج نقدي من 1010). لا يمكن التراجع عن هذه العملية.',
        'reject' => 'رفض',
        'reject_description' => 'يُرفض الطلب ويُغلق. لا يُسجل أي قيد محاسبي.',
    ],
    'notifications' => [
        'approved' => 'تم اعتماد السلفة وتسجيلها.',
        'rejected' => 'تم رفض السلفة.',
    ],
    'validation' => [
        'self_granted' => 'لا يمكن لموظف طلب سلفة على ملفه الخاص.',
    ],
];
