<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ContractTemplateResource\Pages;

use App\Filament\Admin\Resources\ContractTemplateResource;
use App\Models\ContractTemplate;
use App\Services\Booking\ContractService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateContractTemplate extends CreateRecord
{
    protected static string $resource = ContractTemplateResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // `terms_version` is auto-managed and its field is not dehydrated, so it is
        // set here rather than trusted from the form.
        $data['terms_version'] = ContractTemplate::INITIAL_TERMS_VERSION;

        return $data;
    }

    /**
     * Creating a template that claims the default demotes the previous one, and the
     * two writes are a single unit: the panel does not run pages in a transaction, so
     * demoting first and then failing to insert would leave the locale with no default
     * at all.
     */
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): Model {
            $record = parent::handleRecordCreation($data);
            assert($record instanceof ContractTemplate);

            if ($record->is_default) {
                app(ContractService::class)->setDefaultTemplate($record);
            }

            return $record;
        });
    }
}
