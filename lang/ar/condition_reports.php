<?php

declare(strict_types=1);

return [
    'fields' => [
        'booking' => 'الحجز',
        'car' => 'السيارة',
        'type' => 'الوجهة',
        'performed_at' => 'تاريخ الفحص',
        'performed_by' => 'أُنجز بواسطة',
        'odometer' => 'العداد',
        'fuel_level' => 'مستوى الوقود',
        'clean' => 'نظيفة',
        'damage_points' => 'نقاط الأضرار',
        'notes' => 'ملاحظات',
        'photos' => 'الصور',
    ],
    'filters' => [
        'type' => 'الوجهة',
        'booking' => 'الحجز',
        'car' => 'السيارة',
        'damages' => 'الأضرار',
        'damages_options' => [
            'damaged' => 'بأضرار',
            'clean' => 'نظيفة',
        ],
    ],
    'sections' => [
        'report' => 'التقرير',
        'readings' => 'القراءات',
        'readings_description' => 'قراءة التسليم وقراءة الاستلام جنباً إلى جنب — تُبنى تسعيرة الإقفال على الفارق بينهما.',
        'photos' => 'الصور',
        'this_report' => 'هذا التقرير',
        'paired_report' => 'التقرير المقابل',
    ],
    'placeholders' => [
        'no_damage' => 'لا توجد أضرار مسجلة',
        'no_photos' => 'لا توجد صور',
    ],
    'errors' => [
        'duplicate_type' => 'تحتوي هذه الحجز بالفعل على تقرير بهذا الاتجاه.',
    ],
];
