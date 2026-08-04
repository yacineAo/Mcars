<?php

declare(strict_types=1);

return [
    'actions' => [
        'render_pdf' => 'Generate PDF',
        'download_pdf' => 'Download PDF',
        'send' => 'Send to customer',
        'send_description' => 'This puts the contract into "awaiting signature" and records the channel it was sent on.',
        'sign' => 'Mark signed',
        'sign_heading' => 'Mark this contract as signed',
        'sign_description' => 'This records the in-person signature and freezes the document — the embedded terms cannot be edited afterwards.',
        'close' => 'Close contract',
    ],
    'fields' => [
        'contract_number' => 'Number',
        'signer_role' => 'Signer role',
        'signer_name' => 'Signer name',
        'signature_method' => 'Method',
        'signed_at' => 'Signed at',
        'ip_address' => 'IP address',
        'checkin_report' => 'Check-in report',
        'terms_version' => 'Terms version',
        'document_hash' => 'Document hash',
        'witness' => 'Witness',
    ],
    'sections' => [
        'document' => 'The contract',
        'identity' => 'Identity',
        'signatures' => 'Signatures',
        'amendments' => 'Amendments',
        'condition_reports' => 'Condition reports',
    ],
    'document' => [
        'customer' => 'Customer',
        'vehicle' => 'Vehicle',
        'period' => 'Rental period',
        'pickup' => 'Pickup',
        'return' => 'Expected return',
        'pricing' => 'Pricing',
        'item' => 'Item',
        'amount' => 'Amount',
        'rental' => 'Rental',
        'days' => 'days',
        'extras' => 'Extras',
        'discount' => 'Discount',
        'total' => 'Total',
        'drivers' => 'Additional drivers',
        'license' => 'Driving licence',
    ],
    'notifications' => [
        'pdf_generated' => 'PDF generated.',
        'sent' => 'Contract sent.',
        'signed' => 'Contract marked as signed.',
        'closed' => 'Contract closed.',
    ],
];
