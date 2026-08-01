<?php

declare(strict_types=1);

return [
    'actions' => [
        'post' => 'ترحيل إلى الدفاتر',
    ],
    'fields' => [
        'posted' => 'مرحّل',
        'not_posted' => 'غير مرحّل',
        'customer' => 'العميل',
        'branch' => 'الفرع',
        'paid_for' => 'مقابل',
        'external_reference' => 'مرجع خارجي',
        'rib' => 'RIB / مرجع التحويل',
        'ccp_account' => 'حساب CCP',
        'baridimob_number' => 'رقم بريدي موب',
        'cheque_number' => 'رقم الشيك',
        'card_reference' => 'مرجع عملية البطاقة',
    ],
    'filters' => [
        'unposted' => 'غير مرحلة بعد',
        'paid_at' => 'تاريخ الدفع',
        'from' => 'من',
        'to' => 'إلى',
    ],
    'notifications' => [
        'posted' => 'تم تسجيل الدفعة وترحيلها إلى الحسابات.',
        'post_failed' => 'تم حفظ الدفعة لكن تعذّر ترحيلها إلى الحسابات',
        'post_failed_body' => 'استخدم إجراء «ترحيل إلى الدفاتر» على هذه الدفعة لإعادة المحاولة. إذا تكرّر الخطأ فأبلغ المحاسب — فقد سُجّلت التفاصيل في السجلات.',
    ],
];
