<?php

declare(strict_types=1);

return [
    'actions' => [
        'assign_roles' => 'منح الأدوار',
        'assign_branch' => 'تعيين فرع',
        'reset_password' => 'إعادة تعيين كلمة المرور',
        'reset_password_heading' => 'إعادة تعيين كلمة مرور الحساب',
        'new_password' => 'كلمة المرور الجديدة',
        'new_password_confirmation' => 'تأكيد كلمة المرور الجديدة',
        'deactivate' => 'تعطيل',
        'deactivate_confirm' => 'سيفقد الحساب الوصول إلى اللوحة فورًا، حتى عبر تبويب مفتوح.',
        'reactivate' => 'إعادة تفعيل',
        'reactivate_confirm' => 'سيستعيد الحساب الوصول إلى اللوحة.',
    ],
    'notifications' => [
        'roles_updated' => 'تم تحديث الأدوار.',
        'password_reset' => 'تمت إعادة تعيين كلمة المرور. يجب على المستخدم تغييرها قبل المتابعة.',
        'deactivated' => 'تم تعطيل الحساب.',
        'reactivated' => 'تمت إعادة تفعيل الحساب.',
    ],
    'errors' => [
        'action_requires_staff' => 'لا يمكن تنفيذ هذا الإجراء إلا من طرف موظف مسجّل الدخول.',
        'role_not_assignable' => 'لا يمكنك منح دور لا تملكه أنت نفسك.',
        'cannot_change_own_roles' => 'لا يمكنك تعديل أدوارك الخاصة.',
        'cannot_modify_super_admin' => 'لا يمكنك تعديل أدوار حساب super_admin.',
        'cannot_deactivate_self' => 'لا يمكنك تعطيل حسابك الخاص.',
    ],
    'activity' => [
        'roles_updated' => 'تم تحديث الأدوار',
        'password_reset' => 'تمت إعادة تعيين كلمة المرور من طرف مسؤول',
    ],
    'resources' => [
        'branch' => 'الفرع',
        'branch_assignments' => 'تعيينات الفروع',
    ],
];
