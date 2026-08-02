<?php

declare(strict_types=1);

return [
    'fields' => [
        'employee' => 'الموظف',
        'booking' => 'الحجز',
        'basis_amount' => 'مبلغ الأساس',
        'basis_amount_help' => 'يُحسب المبلغ (الأساس × النسبة / 100) عبر الخدمة — ولا يُدخل يدوياً أبداً.',
        'rate' => 'النسبة',
        'earned_on' => 'تاريخ الاستحقاق',
        'notes' => 'ملاحظات',
    ],
    'columns' => [
        'employee' => 'الموظف',
        'booking' => 'الحجز',
        'earned_on' => 'تاريخ الاستحقاق',
        'basis' => 'الأساس',
        'rate' => 'النسبة',
        'amount' => 'المبلغ',
        'status' => 'الحالة',
        'swept_in' => 'دفعت في',
    ],
    'filters' => [
        'unpaid' => 'غير المدفوعة',
        'swept' => 'المدفوعة بالفعل',
        'employee' => 'الموظف',
        'earned_on' => 'تاريخ الاستحقاق',
        'earned_from' => 'من',
        'earned_until' => 'إلى',
    ],
    'validation' => [
        'self_granted' => 'لا يمكن إنشاء عمولة على ملفك الخاص كموظف.',
    ],
];
