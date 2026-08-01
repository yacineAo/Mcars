<?php

declare(strict_types=1);

return [
    'actions' => [
        'generate' => 'إنشاء جدول الدفع',
        'record_payment' => 'تسجيل الدفعة',
        'reschedule' => 'تعديل تاريخ الاستحقاق',
        'waive' => 'إلغاء القسط',
        'waive_confirm' => 'سيتم إلغاء القسط دون دفع. هذا القرار نهائي — يتم تسجيل السبب.',
    ],
    'fields' => [
        'customer' => 'الزبون',
        'sequence' => 'القسط',
        'sequence_of' => ':sequence من :total',
        'due_date' => 'تاريخ الاستحقاق',
        'amount' => 'المبلغ',
        'status' => 'الحالة',
        'reminder_sent' => 'تم إرسال التذكير',
        'method' => 'طريقة الدفع',
        'financial_account' => 'الحساب المالي',
        'plan_for' => 'الجدول لـ',
        'booking' => 'الحجز',
        'contract' => 'العقد',
        'total' => 'إجمالي الجدول',
        'installments' => 'عدد الأقساط',
        'first_due_date' => 'تاريخ أول استحقاق',
        'notes' => 'ملاحظات',
        'waived_reason' => 'سبب الإلغاء',
    ],
    'filters' => [
        'overdue' => 'متأخر',
        'due_this_month' => 'مستحق هذا الشهر',
        'status' => 'الحالة',
        'customer' => 'الزبون',
    ],
    'groups' => [
        'plan' => 'الجدول',
        'plan_title' => ':type رقم :id',
        'unassigned' => 'غير مرتبط',
    ],
    'notifications' => [
        'generated' => 'تم إنشاء جدول الدفع.',
        'generated_body' => ':count قسطا بإجمالي :total دج.',
        'payment_recorded' => 'تم تسجيل الدفعة على القسط.',
        'rescheduled' => 'تم تعديل تاريخ استحقاق القسط.',
        'waived' => 'تم إلغاء القسط.',
    ],
];
