<?php

declare(strict_types=1);

return [
    'fields' => [
        'employee_number' => 'N° employé',
        'employee_number_help' => 'Attribué par le système — le prochain numéro de la séquence, jamais saisi à la main.',
        'first_name' => 'Prénom',
        'last_name' => 'Nom',
        'job_title' => 'Poste',
        'department' => 'Département',
        'base_salary' => 'Salaire de base',
        'hire_date' => 'Date d’embauche',
        'contract_type' => 'Type de contrat',
        'status' => 'Statut',
        'phone' => 'Téléphone',
        'bank_rib' => 'RIB bancaire',
        'ccp_account' => 'Compte CCP',
        'national_id' => 'NIN',
        'termination_date' => 'Date de départ',
        'termination_reason' => 'Motif de départ',
        'salary_type' => 'Type de salaire',
        'notes' => 'Notes',
    ],
    'sections' => [
        'identity' => 'Identité',
        'employment' => 'Emploi',
        'contact' => 'Contact',
        'salary' => 'Salaire',
        'pay_history' => 'Historique de paie',
    ],
    'relations' => [
        'payroll_period' => 'Période',
        'base' => 'Base',
        'commissions' => 'Commissions',
        'advances_recovered' => 'Avances récupérées',
        'net' => 'Net',
        'advanced_on' => 'Avance le',
        'amount' => 'Montant',
        'status' => 'Statut',
        'recovered_in' => 'Récupérée dans',
        'earned_on' => 'Gagnée le',
        'booking' => 'Réservation',
        'basis' => 'Assiette',
        'rate' => 'Taux',
    ],
];
