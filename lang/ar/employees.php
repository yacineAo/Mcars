<?php

declare(strict_types=1);

return [
    'fields' => [
        'employee_number' => 'رقم الموظف',
        'employee_number_help' => 'يُمنح تلقائيا من النظام — الرقم التالي في التسلسل، ولا يُكتب يدويا أبدا.',
        'first_name' => 'الاسم الأول',
        'last_name' => 'اللقب',
        'job_title' => 'المنصب',
        'department' => 'القسم',
        'base_salary' => 'الراتب الأساسي',
        'hire_date' => 'تاريخ التوظيف',
        'contract_type' => 'نوع العقد',
        'status' => 'الحالة',
        'phone' => 'الهاتف',
        'bank_rib' => 'ريب البنكي',
        'ccp_account' => 'حساب CCP',
        'national_id' => 'الرقم الوطني',
        'termination_date' => 'تاريخ إنهاء الخدمة',
        'termination_reason' => 'سبب إنهاء الخدمة',
        'salary_type' => 'نوع الراتب',
        'notes' => 'ملاحظات',
    ],
    'sections' => [
        'identity' => 'الهوية',
        'employment' => 'التوظيف',
        'contact' => 'الاتصال',
        'salary' => 'الراتب',
        'pay_history' => 'سجل الرواتب',
    ],
    'relations' => [
        'payroll_period' => 'الفترة',
        'base' => 'الأساسي',
        'commissions' => 'العمولات',
        'advances_recovered' => 'السلف المسترجعة',
        'net' => 'الصافي',
        'advanced_on' => 'السلفة بتاريخ',
        'amount' => 'المبلغ',
        'status' => 'الحالة',
        'recovered_in' => 'استرجعت في',
        'earned_on' => 'استحقت في',
        'booking' => 'الحجز',
        'basis' => 'الأساس',
        'rate' => 'النسبة',
    ],
];
