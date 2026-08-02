<?php

declare(strict_types=1);

return [
    'fields' => [
        'employee' => 'Employé',
        'amount' => 'Montant',
        'advanced_on' => 'Avance le',
        'reason' => 'Motif',
        'status' => 'Statut',
        'status_help' => 'Géré par le workflow : l’approbation poste le versement dans le grand livre (E61), le refus le décline, la récupération par la paie le clôt.',
        'notes' => 'Notes',
    ],
    'columns' => [
        'employee' => 'Employé',
        'amount' => 'Montant',
        'advanced_on' => 'Avance le',
        'status' => 'Statut',
        'recovered_in' => 'Récupérée dans',
    ],
    'filters' => [
        'open' => 'Avances en cours',
        'settled' => 'Soldée',
        'employee' => 'Employé',
    ],
    'actions' => [
        'approve' => 'Approuver & verser',
        'approve_description' => 'Le versement est enregistré dans le grand livre (avance sur 1130, sortie de caisse 1010). Cette action est irréversible.',
        'reject' => 'Refuser',
        'reject_description' => 'La demande est refusée et clôturée. Aucune écriture au grand livre.',
    ],
    'notifications' => [
        'approved' => 'Avance approuvée et versée.',
        'rejected' => 'Avance refusée.',
    ],
    'validation' => [
        'self_granted' => 'Un employé ne peut pas demander une avance sur son propre dossier.',
    ],
];
