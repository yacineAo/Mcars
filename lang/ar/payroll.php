<?php

declare(strict_types=1);

return [
    'fields' => [
        'period_month' => 'الفترة',
        'branch' => 'الفرع',
        'status' => 'الحالة',
        'total' => 'الصافي الإجمالي',
        'employees' => 'الموظفون',
        'approved_by' => 'اعتمده',
        'approved_at' => 'تاريخ الاعتماد',
        'paid_at' => 'تاريخ الدفع',
        'notes' => 'ملاحظات',
        'gross' => 'الأجور الإجمالية',
        'commissions' => 'العمولات',
        'advances' => 'السلف المسترجعة',
    ],
    'filters' => [
        'status' => 'الحالة',
        'period' => 'الفترة',
        'branch' => 'الفرع',
    ],
    'sections' => [
        'run' => 'كشف الأجور',
        'approval_trail' => 'مسار الاعتماد',
        'totals' => 'المجاميع المشتقة',
        'items' => 'البنود',
    ],
    'items' => [
        'employee' => 'الموظف',
        'employee_number' => 'الرقم',
        'base' => 'الأجر الأساسي',
        'bonuses' => 'المنح',
        'overtime' => 'الساعات الإضافية',
        'commissions' => 'العمولات',
        'advances' => 'السلف',
        'absences' => 'الغياب',
        'social' => 'الاشتراكات الاجتماعية',
        'other' => 'خصومات أخرى',
        'net' => 'الصافي',
        'edit' => 'تعديل',
        'remove' => 'إزالة',
    ],
    'transactions' => [
        'reference' => 'المرجع',
        'occurred_on' => 'التاريخ',
        'description' => 'الوصف',
        'debit' => 'مدين',
        'credit' => 'دائن',
        'amount' => 'المبلغ',
    ],
    'actions' => [
        'approve' => 'اعتماد',
        'approve_description' => 'يسجّل الأجور واشتراكات المستخدِم والعمولات كمبالغ مستحقة للعمال.',
        'pay' => 'تعليمها كمدفوعة',
        'pay_description' => 'يسجّل خروج الأموال ويصفّي المبالغ المستحقة.',
    ],
    'notifications' => [
        'approved' => 'تم اعتماد كشف الأجور وتسجيله كمستحق.',
        'paid' => 'تم دفع كشف الأجور.',
        'item_removed' => 'تمت إزالة البند؛ عادت عمولته وسلفته إلى قائمتَي الانتظار.',
    ],
];
