<?php

declare(strict_types=1);

return [
    'resource' => [
        'label' => 'سجل النشاطات',
        'plural_label' => 'سجل النشاطات',
        'sections' => [
            'details' => 'التفاصيل',
            'changes' => 'التغييرات',
        ],
        'fields' => [
            'created_at' => 'التاريخ',
            'log_name' => 'السجل',
            'description' => 'الوصف',
            'event' => 'الحدث',
            'causer' => 'المستخدم',
            'subject' => 'الموضوع',
            'subject_id' => 'رقم الموضوع',
            'branch' => 'الفرع',
            'changes' => 'تغييرات الحقول',
        ],
        'filters' => [
            'date_range' => 'نطاق التاريخ',
        ],
    ],
];
