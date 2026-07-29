<?php

declare(strict_types=1);

return [
    'resources' => [
        'report_definition' => [
            'label' => 'Saved Report',
            'plural_label' => 'Saved Reports',
            'sections' => [
                'scope' => 'Scope',
                'schedule' => 'Schedule',
            ],
            'fields' => [
                'name' => 'Name',
                'report_type' => 'Report Type',
                'format' => 'Format',
                'branch' => 'Branch',
                'customer' => 'Customer',
                'car' => 'Car',
                'car_owner' => 'Car Owner',
                'schedule_cron' => 'Cron Expression',
                'schedule_email' => 'Email Recipient',
                'schedule_enabled' => 'Schedule Enabled',
                'last_sent_at' => 'Last Sent',
            ],
            'help' => [
                'cron' => 'e.g. "0 8 * * 1" for every Monday at 8 AM. Uses standard 5-field cron format.',
                'email' => 'The report PDF/Excel/CSV will be emailed to this address when generated.',
            ],
            'all_branches' => 'All branches',
            'placeholder_customer' => 'All customers (top list)',
            'placeholder_car' => 'All cars (fleet overview)',
            'never' => 'Never',
        ],
    ],
    'scheduled_mail' => [
        'subject' => 'Scheduled Report: :name',
        'heading' => 'Your Scheduled Report: :name',
        'body' => 'Please find your scheduled report attached.',
        'generated_at' => 'Generated on :date',
        'regards' => 'Regards',
    ],
];
