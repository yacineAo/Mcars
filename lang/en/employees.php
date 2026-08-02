<?php

declare(strict_types=1);

return [
    'fields' => [
        'employee_number' => 'Employee No.',
        'employee_number_help' => 'Assigned by the system — the next number in the sequence, never typed.',
        'first_name' => 'First Name',
        'last_name' => 'Last Name',
        'job_title' => 'Job Title',
        'department' => 'Department',
        'base_salary' => 'Base Salary',
        'hire_date' => 'Hire Date',
        'contract_type' => 'Contract Type',
        'status' => 'Status',
        'phone' => 'Phone',
        'bank_rib' => 'Bank RIB',
        'ccp_account' => 'CCP Account',
        'national_id' => 'National ID',
        'termination_date' => 'Termination Date',
        'termination_reason' => 'Termination Reason',
        'salary_type' => 'Salary Type',
        'notes' => 'Notes',
    ],
    'sections' => [
        'identity' => 'Identity',
        'employment' => 'Employment',
        'contact' => 'Contact',
        'salary' => 'Salary',
        'pay_history' => 'Pay history',
    ],
    'relations' => [
        'payroll_period' => 'Run Period',
        'base' => 'Base',
        'commissions' => 'Commissions',
        'advances_recovered' => 'Advances Recovered',
        'net' => 'Net',
        'advanced_on' => 'Advanced On',
        'amount' => 'Amount',
        'status' => 'Status',
        'recovered_in' => 'Recovered In',
        'earned_on' => 'Earned On',
        'booking' => 'Booking',
        'basis' => 'Basis',
        'rate' => 'Rate',
    ],
];
