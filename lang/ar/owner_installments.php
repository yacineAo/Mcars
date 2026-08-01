<?php

declare(strict_types=1);

return [
    'actions' => [
        'accrue' => 'تسجيل كمستحق',
        'accrue_description' => 'يسجّل كراء الشهر كتكلفة للمركبة وكمبلغ مستحق للمالك. يُنفَّذ مرة واحدة لكل قسط.',
    ],
    'fields' => [
        'agreement' => 'الاتفاقية',
        'owner' => 'المالك',
        'car' => 'المركبة',
        'period_month' => 'الفترة',
        'due_date' => 'تاريخ الاستحقاق',
        'amount_due' => 'المبلغ المستحق',
        'status' => 'الحالة',
        'waived_reason' => 'سبب التنازل',
        'paid' => 'المدفوع',
        'accrued' => 'مُثبَت',
        'not_accrued' => 'غير مُثبَت',
    ],
    'filters' => [
        'unaccrued' => 'غير مُثبَتة',
        'overdue' => 'متأخرة',
        'period_month' => 'الفترة',
        'owner' => 'المالك',
        'car' => 'المركبة',
        'status' => 'الحالة',
    ],
    'relations' => [
        'payments' => 'المدفوعات مقابل هذا القسط',
    ],
    'notifications' => [
        'accrued' => 'تم تسجيل القسط كمستحق للمالك.',
    ],
];
