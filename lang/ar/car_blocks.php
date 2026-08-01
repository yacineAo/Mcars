<?php

declare(strict_types=1);

return [
    'fields' => [
        'car' => 'السيارة',
        'reason' => 'السبب',
        'starts_at' => 'يبدأ في',
        'ends_at' => 'ينتهي في',
        'maintenance_log' => 'سجل الصيانة',
        'notes' => 'ملاحظات',
    ],
    'columns' => [
        'state' => 'الحالة',
        'state_active' => 'ساري الآن',
        'state_upcoming' => 'قادم',
        'state_ended' => 'منتهي',
    ],
    'filters' => [
        'state' => 'الحالة',
        'state_options' => [
            'active' => 'ساري الآن',
            'upcoming' => 'قادم',
            'ended' => 'منتهي',
        ],
        'car' => 'السيارة',
        'reason' => 'السبب',
        'window' => 'يتداخل مع الفترة',
        'window_from' => 'من',
        'window_to' => 'إلى',
    ],
    'actions' => [
        'unblock' => 'إلغاء الحجز الآن',
        'cancel' => 'إلغاء الحجز',
        'unblocked' => 'تم إلغاء حجز السيارة',
        'cancelled' => 'تم إلغاء الحجز',
    ],
    'errors' => [
        'block_clash' => 'السيارة محجوزة بالفعل خلال جزء من هذه الفترة.',
        'booking_clash' => 'السيارة محجوزة بموجب عقد بالفعل خلال جزء من هذه الفترة.',
        'not_active' => 'هذا الحجز غير ساري حالياً.',
        'not_upcoming' => 'يمكن فقط إلغاء حجز لم يبدأ بعد.',
    ],
];
