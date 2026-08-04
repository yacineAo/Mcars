<?php

declare(strict_types=1);

return [
    'actions' => [
        'render_pdf' => 'Générer le PDF',
        'download_pdf' => 'Télécharger le PDF',
        'send' => 'Envoyer au client',
        'send_description' => 'Passe le contrat en « en attente de signature » et enregistre le canal d\'envoi.',
        'sign' => 'Marquer comme signé',
        'sign_heading' => 'Marquer ce contrat comme signé',
        'sign_description' => 'Enregistre la signature en personne et fige le document — les conditions qu\'il embarque ne pourront plus être modifiées.',
        'close' => 'Clôturer le contrat',
    ],
    'fields' => [
        'contract_number' => 'Numéro',
        'signer_role' => 'Rôle du signataire',
        'signer_name' => 'Nom du signataire',
        'signature_method' => 'Méthode',
        'signed_at' => 'Signé le',
        'ip_address' => 'Adresse IP',
        'checkin_report' => 'Procès-verbal de retour',
        'terms_version' => 'Version des conditions',
        'document_hash' => 'Empreinte du document',
        'witness' => 'Témoin',
    ],
    'sections' => [
        'document' => 'Le contrat',
        'identity' => 'Identité',
        'signatures' => 'Signatures',
        'amendments' => 'Avenants',
        'condition_reports' => 'Procès-verbaux d\'état',
    ],
    'document' => [
        'customer' => 'Client',
        'vehicle' => 'Véhicule',
        'period' => 'Période de location',
        'pickup' => 'Prise en charge',
        'return' => 'Retour prévu',
        'pricing' => 'Tarification',
        'item' => 'Poste',
        'amount' => 'Montant',
        'rental' => 'Location',
        'days' => 'jours',
        'extras' => 'Options',
        'discount' => 'Remise',
        'total' => 'Total',
        'drivers' => 'Conducteurs additionnels',
        'license' => 'Permis de conduire',
    ],
    'notifications' => [
        'pdf_generated' => 'PDF généré.',
        'sent' => 'Contrat envoyé.',
        'signed' => 'Contrat marqué comme signé.',
        'closed' => 'Contrat clôturé.',
    ],
];
