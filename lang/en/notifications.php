<?php

declare(strict_types=1);

return [

    'actions' => [
        'view' => 'View',
    ],

    'mail' => [
        'signature' => ':app — automated alert',
    ],

    'fields' => [
        'reference' => 'Reference',
        'customer' => 'Customer',
        'owner' => 'Owner',
        'car' => 'Vehicle',
        'due_at' => 'Due',
        'due_date' => 'Due date',
        'hours_late' => 'Hours late',
        'days_late' => 'Days late',
        'days_remaining' => 'Days remaining',
        'amount' => 'Amount (DZD)',
        'sequence' => 'Instalment',
        'document_type' => 'Document',
        'number' => 'Number',
        'expiry_date' => 'Expires on',
        'licence_number' => 'Licence no.',
        'task' => 'Task',
        'due_odometer' => 'Due at (km)',
        'current_odometer' => 'Current (km)',
        'km_remaining' => 'Km remaining',
        'category' => 'Category',
        'description' => 'Description',
        'account' => 'Account',
        'closed_at' => 'Closed at',
        'closed_by' => 'Closed by',
        'counted' => 'Counted (DZD)',
        'url' => 'Link',
    ],

    'alerts' => [
        'booking_return_due' => [
            'subject' => 'Return due: :reference',
            'body' => 'The rental :reference for :customer (:car) is due back on :due_at.',
        ],
        'booking_overdue' => [
            'subject' => 'Overdue rental: :reference',
            'body' => ':customer has not returned :car. It was due on :due_at — :hours_late hour(s) ago.',
        ],
        'customer_payment_overdue' => [
            'subject' => 'Payment overdue: :customer',
            'body' => 'A scheduled payment of :amount DZD from :customer was due on :due_date, :days_late day(s) ago.',
        ],
        'owner_installment_due' => [
            'subject' => 'Owner instalment due: :owner',
            'body' => 'Instalment :sequence of :amount DZD for :car (owner :owner) is due on :due_date.',
        ],
        'car_document_expiring' => [
            'subject' => ':document_type expiring: :car',
            'body' => 'The :document_type for :car (no. :number) expires on :expiry_date — :days_remaining day(s) remaining.',
        ],
        'driving_licence_expiring' => [
            'subject' => 'Driving licence expiring: :customer',
            'body' => 'The driving licence of :customer (no. :licence_number) expires on :expiry_date — :days_remaining day(s) remaining.',
        ],
        'maintenance_due' => [
            'subject' => 'Maintenance due: :car',
            'body' => ':task is due for :car on :due_date or at :due_odometer km (currently :current_odometer km).',
        ],
        'recurring_expense_due' => [
            'subject' => 'Recurring expense due: :category',
            'body' => ':description (:category), :amount DZD, is due on :due_date.',
        ],
        'cash_variance' => [
            'subject' => 'Cash variance on :account',
            'body' => 'The cash session on :account closed by :closed_by at :closed_at did not match the ledger. Counted: :counted DZD.',
        ],
        'backup_failed' => [
            'subject' => 'Backup failed',
            'body' => 'The scheduled backup did not complete. Check the backup log immediately.',
        ],
    ],

    'digest' => [
        'subject' => 'Your daily summary — :count alert(s), :date',
        'heading' => 'Daily alert summary',
        'intro' => 'You have :count alert(s) from the last 24 hours.',
        'footer' => 'You are receiving one daily summary instead of individual emails. Change this in your profile.',
    ],

    'resources' => [
        'alert_rule' => [
            'label' => 'Alert rule',
            'plural_label' => 'Alert rules',
            'global' => 'All branches',
            'once' => 'Once only',
            'sections' => [
                'what' => 'What to watch',
                'when' => 'When to fire',
                'who' => 'Who to tell',
            ],
            'fields' => [
                'type' => 'Alert type',
                'branch' => 'Branch',
                'template_key' => 'Template key',
                'days_before' => 'Lead time (days)',
                'repeat_every_days' => 'Repeat every (days)',
                'max_repeats' => 'Max repeats',
                'channels' => 'Channels',
                'recipient_roles' => 'Recipients',
                'is_active' => 'Active',
            ],
            'help' => [
                'branch' => 'Leave empty to apply to all branches. A branch rule overrides the global one.',
                'template_key' => 'Translation key under notifications.*. Changing it starts a fresh deduplication history.',
                'timing' => 'Repeat settings prevent alert fatigue: an insurance policy expiring in 30 days should produce a handful of alerts, not thirty.',
                'days_before' => 'Fire this many days before the due date. 0 reacts on the day.',
                'repeat_every_days' => 'Leave empty to alert once per subject, ever.',
                'max_repeats' => 'Leave empty for no ceiling.',
                'recipient_roles' => 'Car owner and client resolve only to the person the alert is about — never to every holder of the role.',
            ],
        ],
        'notification_log' => [
            'label' => 'Delivery log',
            'plural_label' => 'Delivery log',
            'sections' => [
                'delivery' => 'Delivery',
                'content' => 'Content',
            ],
            'fields' => [
                'created_at' => 'Raised',
                'type' => 'Alert',
                'channel' => 'Channel',
                'recipient' => 'Recipient',
                'status' => 'Status',
                'attempts' => 'Attempts',
                'subject' => 'About',
                'branch' => 'Branch',
                'payload' => 'Payload',
                'error' => 'Error',
            ],
            'filters' => [
                'failed_only' => 'Failed only',
            ],
        ],
    ],

    'preferences' => [
        'title' => 'Notification preferences',
        'digest' => 'Daily digest instead of individual emails',
        'digest_help' => 'One summary email per day. The in-app bell is unaffected.',
        'digest_at' => 'Send the digest at',
        'saved' => 'Preferences saved.',
    ],

];
