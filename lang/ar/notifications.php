<?php

declare(strict_types=1);

return [

    'actions' => [
        'view' => 'عرض',
    ],

    'mail' => [
        'signature' => ':app — تنبيه آلي',
    ],

    'fields' => [
        'reference' => 'المرجع',
        'customer' => 'الزبون',
        'owner' => 'المالك',
        'car' => 'المركبة',
        'due_at' => 'الاستحقاق',
        'due_date' => 'تاريخ الاستحقاق',
        'hours_late' => 'ساعات التأخير',
        'days_late' => 'أيام التأخير',
        'days_remaining' => 'الأيام المتبقية',
        'amount' => 'المبلغ (دج)',
        'sequence' => 'رقم القسط',
        'document_type' => 'الوثيقة',
        'number' => 'الرقم',
        'expiry_date' => 'تنتهي في',
        'licence_number' => 'رقم الرخصة',
        'task' => 'العملية',
        'due_odometer' => 'الاستحقاق (كم)',
        'current_odometer' => 'الحالي (كم)',
        'km_remaining' => 'الكيلومترات المتبقية',
        'category' => 'الصنف',
        'description' => 'الوصف',
        'account' => 'الحساب',
        'closed_at' => 'أُغلقت في',
        'closed_by' => 'أُغلقت من طرف',
        'counted' => 'المبلغ المحصى (دج)',
        'url' => 'الرابط',
    ],

    'alerts' => [
        'booking_return_due' => [
            'subject' => 'موعد الإرجاع: :reference',
            'body' => 'يجب إرجاع الكراء :reference الخاص بـ :customer (:car) في :due_at.',
        ],
        'booking_overdue' => [
            'subject' => 'كراء متأخر: :reference',
            'body' => 'لم يُرجع :customer المركبة :car. كان موعد الإرجاع :due_at، منذ :hours_late ساعة.',
        ],
        'customer_payment_overdue' => [
            'subject' => 'دفعة متأخرة: :customer',
            'body' => 'دفعة بقيمة :amount دج من :customer كانت مستحقة في :due_date، منذ :days_late يوم.',
        ],
        'owner_installment_due' => [
            'subject' => 'قسط المالك: :owner',
            'body' => 'القسط :sequence بقيمة :amount دج للمركبة :car (المالك :owner) مستحق في :due_date.',
        ],
        'car_document_expiring' => [
            'subject' => ':document_type على وشك الانتهاء: :car',
            'body' => 'وثيقة :document_type للمركبة :car (رقم :number) تنتهي في :expiry_date — تبقّى :days_remaining يوم.',
        ],
        'driving_licence_expiring' => [
            'subject' => 'رخصة السياقة على وشك الانتهاء: :customer',
            'body' => 'رخصة سياقة :customer (رقم :licence_number) تنتهي في :expiry_date — تبقّى :days_remaining يوم.',
        ],
        'maintenance_due' => [
            'subject' => 'صيانة مستحقة: :car',
            'body' => 'العملية :task مستحقة للمركبة :car في :due_date أو عند :due_odometer كم (حاليا :current_odometer كم).',
        ],
        'recurring_expense_due' => [
            'subject' => 'مصروف دوري مستحق: :category',
            'body' => ':description (:category)، :amount دج، مستحق في :due_date.',
        ],
        'cash_variance' => [
            'subject' => 'فرق في الصندوق: :account',
            'body' => 'جلسة الصندوق :account التي أغلقها :closed_by في :closed_at لا تطابق دفتر الأستاذ. المبلغ المحصى: :counted دج.',
        ],
        'backup_failed' => [
            'subject' => 'فشل النسخ الاحتياطي',
            'body' => 'لم تكتمل عملية النسخ الاحتياطي المجدولة. راجع سجل النسخ الاحتياطي فورا.',
        ],
        'scheduled_report_failed' => [
            'subject' => 'فشل التقرير المجدول: :name',
            'body' => 'تعذّر إنشاء التقرير المجدول :name. آخر فشل: :failed_at. راجع سجل تشغيله.',
        ],
    ],

    'digest' => [
        'subject' => 'ملخصك اليومي — :count تنبيه، :date',
        'heading' => 'الملخص اليومي للتنبيهات',
        'intro' => 'لديك :count تنبيه خلال الـ 24 ساعة الماضية.',
        'footer' => 'تتلقى ملخصا يوميا واحدا بدل الرسائل الفردية. يمكنك تغيير ذلك من ملفك الشخصي.',
    ],

    'resources' => [
        'alert_rule' => [
            'label' => 'قاعدة تنبيه',
            'plural_label' => 'قواعد التنبيه',
            'global' => 'كل الوكالات',
            'once' => 'مرة واحدة فقط',
            'sections' => [
                'what' => 'ماذا نراقب',
                'when' => 'متى ننبّه',
                'who' => 'من نُعلم',
            ],
            'fields' => [
                'type' => 'نوع التنبيه',
                'branch' => 'الوكالة',
                'template_key' => 'مفتاح القالب',
                'days_before' => 'المهلة المسبقة (أيام)',
                'repeat_every_days' => 'التكرار كل (أيام)',
                'max_repeats' => 'أقصى عدد تكرارات',
                'channels' => 'القنوات',
                'recipient_roles' => 'المستلمون',
                'is_active' => 'مفعّلة',
                'updated_by' => 'عدّلها',
                'updated_at' => 'تاريخ التعديل',
            ],
            'actions' => [
                'deactivate' => 'إيقاف',
                'deactivate_confirm' => 'يتوقف هذا التنبيه عن الانطلاق حتى إعادة تفعيله. يبقى سجل إرساله محفوظا.',
                'reactivate' => 'إعادة تفعيل',
                'reactivate_confirm' => 'يعود هذا التنبيه إلى الانطلاق.',
                'view_deliveries' => 'عرض الإرسالات',
                'delete_confirm' => 'حذف هذه القاعدة يوقف تنبيه « :type » نهائيا — لجميع الوكالات إذا كانت قاعدة عامة. فضّل الإيقاف: فهو يعلّق التنبيه نفسه بنقرة واحدة ويبقي القاعدة للرجوع إليها.',
            ],
            'notifications' => [
                'deactivated' => 'تم إيقاف التنبيه.',
                'reactivated' => 'تمت إعادة تفعيل التنبيه.',
            ],
            'validation' => [
                'duplicate' => 'توجد قاعدة نشطة من هذا النوع لهذه الوكالة بالفعل. أوقفها أو عدّلها بدل ذلك.',
            ],
            'help' => [
                'branch' => 'اتركه فارغا ليشمل كل الوكالات. قاعدة الوكالة تَجُبّ القاعدة العامة.',
                'template_key' => 'مفتاح الترجمة تحت notifications.*. تغييره يبدأ سجل تكرار جديدا.',
                'timing' => 'إعدادات التكرار تمنع إرهاق التنبيهات: تأمين ينتهي بعد 30 يوما يجب أن يُنتج بضعة تنبيهات لا ثلاثين.',
                'days_before' => 'ينبّه قبل الاستحقاق بهذا العدد من الأيام. 0 يعني التنبيه في اليوم نفسه.',
                'repeat_every_days' => 'اتركه فارغا للتنبيه مرة واحدة لكل موضوع.',
                'max_repeats' => 'اتركه فارغا لعدم وضع سقف.',
                'recipient_roles' => 'المالك والزبون يُحصران في الشخص المعني وحده — لا كل من يحمل الدور.',
            ],
        ],
        'notification_log' => [
            'label' => 'سجل الإرسال',
            'plural_label' => 'سجل الإرسال',
            'sections' => [
                'delivery' => 'الإرسال',
                'content' => 'المحتوى',
            ],
            'fields' => [
                'created_at' => 'أُنشئ في',
                'type' => 'التنبيه',
                'channel' => 'القناة',
                'recipient' => 'المستلم',
                'status' => 'الحالة',
                'attempts' => 'المحاولات',
                'subject' => 'يخص',
                'branch' => 'الوكالة',
                'payload' => 'البيانات',
                'error' => 'الخطأ',
            ],
            'filters' => [
                'alert_rule' => 'قاعدة التنبيه',
                'failed_only' => 'الفاشلة فقط',
            ],
        ],
    ],

    'preferences' => [
        'title' => 'تفضيلات التنبيهات',
        'digest' => 'ملخص يومي بدل الرسائل الفردية',
        'digest_help' => 'رسالة تلخيص واحدة يوميا. جرس التطبيق لا يتأثر.',
        'digest_at' => 'إرسال الملخص على الساعة',
        'saved' => 'تم حفظ التفضيلات.',
    ],

];
