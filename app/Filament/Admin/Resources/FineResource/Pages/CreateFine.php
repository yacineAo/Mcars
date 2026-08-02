<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FineResource\Pages;

use App\Enums\FineLiability;
use App\Enums\FineStatus;
use App\Filament\Admin\Resources\FineResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFine extends CreateRecord
{
    protected static string $resource = FineResource::class;

    /**
     * The decision is the assign action's, never the form's: assigning posts
     * E49/E50, and a row that claims a decision the ledger never saw is a lie.
     * The disabled fields already keep the clerk honest; this re-asserts it
     * against a crafted payload, which can bypass `disabled()` (the Filament
     * vendor note says exactly that).
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['liability'] = FineLiability::PendingReview->value;
        $data['status'] = FineStatus::PendingReview->value;

        return $data;
    }
}
