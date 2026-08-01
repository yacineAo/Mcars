<?php

declare(strict_types=1);

return [
    'actions' => [
        'render_pdf' => 'توليد ملف PDF',
        'send' => 'إرسال إلى العميل',
        'send_description' => 'ينقل العقد إلى حالة «بانتظار التوقيع» ويسجل قناة الإرسال.',
        'sign' => 'وضع علامة التوقيع',
        'sign_heading' => 'وضع علامة التوقيع على هذا العقد',
        'sign_description' => 'يسجل التوقيع الحضوري ويجمّد العقد — لا يمكن تعديل الشروط المضمنة بعد ذلك.',
        'close' => 'إغلاق العقد',
    ],
    'fields' => [
        'contract_number' => 'الرقم',
        'signer_role' => 'دور الموقّع',
        'signer_name' => 'اسم الموقّع',
        'signature_method' => 'الطريقة',
        'signed_at' => 'تاريخ التوقيع',
        'ip_address' => 'عنوان IP',
        'checkin_report' => 'محضر الإرجاع',
        'terms_version' => 'نسخة الشروط',
        'document_hash' => 'بصمة الوثيقة',
        'witness' => 'الشاهد',
    ],
    'sections' => [
        'document' => 'العقد',
        'identity' => 'الهوية',
        'signatures' => 'التوقيعات',
        'amendments' => 'الملاحق',
        'condition_reports' => 'محاضر الحالة',
    ],
    'document' => [
        'customer' => 'العميل',
        'vehicle' => 'المركبة',
        'period' => 'مدة الإيجار',
        'pickup' => 'التسليم',
        'return' => 'الإرجاع المتوقع',
        'pricing' => 'التسعيرة',
        'item' => 'البند',
        'amount' => 'المبلغ',
        'rental' => 'الإيجار',
        'days' => 'أيام',
        'extras' => 'الخيارات',
        'discount' => 'الخصم',
        'total' => 'المجموع',
        'drivers' => 'السائقون الإضافيون',
        'license' => 'رخصة القيادة',
    ],
    'notifications' => [
        'pdf_generated' => 'تم توليد ملف PDF.',
        'sent' => 'تم إرسال العقد.',
        'signed' => 'تم وضع علامة التوقيع على العقد.',
        'closed' => 'تم إغلاق العقد.',
    ],
];
