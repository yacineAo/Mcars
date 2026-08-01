<?php

declare(strict_types=1);

return [
    'actions' => [
        'post' => 'Post to ledger',
    ],
    'fields' => [
        'posted' => 'Posted',
        'not_posted' => 'Not posted',
        'customer' => 'Customer',
        'branch' => 'Branch',
        'paid_for' => 'Paid for',
        'external_reference' => 'External reference',
        'rib' => 'RIB / transfer reference',
        'ccp_account' => 'CCP account',
        'baridimob_number' => 'BaridiMob number',
        'cheque_number' => 'Cheque number',
        'card_reference' => 'Card transaction reference',
    ],
    'filters' => [
        'unposted' => 'Not yet posted',
        'paid_at' => 'Date paid',
        'from' => 'From',
        'to' => 'To',
    ],
    'notifications' => [
        'posted' => 'Payment recorded and posted to the accounts.',
        'post_failed' => 'The payment was saved but could NOT be posted to the accounts',
        'post_failed_body' => 'Use the "Post to ledger" action on this payment to retry. If it keeps failing, tell the accountant — the details have been logged.',
    ],
];
