<?php

declare(strict_types=1);

return [
    'resources' => [
        'report_definition' => [
            'label' => 'تقرير محفوظ',
            'plural_label' => 'التقارير المحفوظة',
            'sections' => [
                'scope' => 'النطاق',
                'schedule' => 'الجدولة',
            ],
            'fields' => [
                'name' => 'الاسم',
                'report_type' => 'نوع التقرير',
                'format' => 'الصيغة',
                'branch' => 'الفرع',
                'customer' => 'الزبون',
                'car' => 'المركبة',
                'car_owner' => 'المالك',
                'schedule_cron' => 'تعبير Cron',
                'schedule_email' => 'مستلم البريد',
                'schedule_enabled' => 'الجدولة مفعلة',
                'last_sent_at' => 'آخر إرسال',
            ],
            'help' => [
                'cron' => 'مثال: "0 8 * * 1" لكل إثنين على الساعة 8 صباحاً. صيغة cron القياسية ذات 5 حقول.',
                'email' => 'سيتم إرسال التقرير (PDF/Excel/CSV) إلى هذا البريد عند الإنشاء.',
            ],
            'all_branches' => 'جميع الفروع',
            'placeholder_customer' => 'جميع الزبائن (القائمة العلوية)',
            'placeholder_car' => 'جميع المركبات (نظرة عامة)',
            'never' => 'أبداً',
        ],
    ],
    'scheduled_mail' => [
        'subject' => 'تقرير مجدول: :name',
        'heading' => 'تقريرك المجدول: :name',
        'body' => 'يرجى إيجاد تقريرك المجدول مرفقاً.',
        'generated_at' => 'أُنشئ في :date',
        'regards' => 'مع التحية',
    ],
];
