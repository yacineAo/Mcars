<?php

declare(strict_types=1);

return [
    'actions' => [
        'propose' => 'اقتراح المسؤول',
        'assign' => 'تحديد المسؤولية',
        'assign_description' => 'تحميلها على الزبون يُنشئ دينا عليه (E49). تحميلها على الشركة يسجّلها كتكلفة تتحملها المؤسسة (E50).',
    ],
    'fields' => [
        'reference' => 'المرجع',
        'notice_number' => 'رقم الإشعار',
        'authority' => 'الجهة',
        'type' => 'النوع',
        'violation_at' => 'وقت المخالفة',
        'location' => 'المكان',
        'received_at' => 'تاريخ الاستلام',
        'due_date' => 'الاستحقاق',
        'amount' => 'الغرامة',
        'late_penalty_amount' => 'غرامة التأخير',
        'total_amount' => 'المجموع',
        'liability' => 'من يدفع',
        'status' => 'الحالة',
        'liability_note' => 'ملاحظة القرار',
        'determined_at' => 'تاريخ القرار',
        'determined_by' => 'من قرّر',
        'customer' => 'الزبون',
        'booking' => 'الحجز',
        'contract' => 'العقد',
        'car' => 'المركبة',
        'liability_help' => 'يُحدَّد عبر إجراء «تحديد المسؤولية» الذي يسجّل الدين أو التكلفة في الدفتر.',
        'status_help' => 'تتبع الحالة الدفتر؛ لا يمكن ضبطها يدويًا.',
    ],
    'filters' => [
        'pending_liability' => 'غير محسومة',
        'violation_range' => 'تاريخ المخالفة',
        'violated_from' => 'من',
        'violated_until' => 'إلى',
    ],
    'sections' => [
        'notice' => 'الإشعار',
        'money' => 'المبالغ',
        'liability' => 'قرار المسؤولية',
        'related' => 'السجلات المرتبطة',
        'history' => 'السجل',
    ],
    'notifications' => [
        'proposed' => 'تم وضع اقتراح بناء على من كان بحوزته المركبة في ذلك الوقت وحُفظ على الغرامة. راجعه قبل التحديد.',
        'assigned' => 'تم تحديد المسؤولية وتسجيلها في الدفتر.',
    ],
];
