<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ContractTemplateResource\Pages;

use App\Filament\Admin\Resources\ContractTemplateResource;
use App\Models\ContractTemplate;
use App\Services\Booking\ContractService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditContractTemplate extends EditRecord
{
    protected static string $resource = ContractTemplateResource::class;

    /**
     * `terms_version` is auto-managed: a body change is a new version of the terms,
     * so bump it instead of letting the author type one by hand. The field itself is
     * disabled and not dehydrated, so this is the only path that can set it.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->record;
        assert($record instanceof ContractTemplate);

        if (($data['body'] ?? null) !== $record->body) {
            $data['terms_version'] = $this->bumpTermsVersion((string) $record->terms_version);
        }

        return $data;
    }

    /**
     * Demoting the previous default runs *after* the record is saved, inside the same
     * transaction, so it is keyed on the locale that was submitted rather than the one
     * that was stored. Reading it from the record beforehand meant an edit that changed
     * the locale demoted the old locale's default and left the new locale with two.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Model {
            $record = parent::handleRecordUpdate($record, $data);
            assert($record instanceof ContractTemplate);

            if ($record->is_default) {
                app(ContractService::class)->setDefaultTemplate($record);
            }

            return $record;
        });
    }

    /**
     * `1.0` → `1.1`. Anything not in `major.minor` form starts a new major series
     * rather than growing a suffix: `terms_version` is varchar(16), and appending
     * would eventually overflow the column mid-save.
     */
    private function bumpTermsVersion(string $current): string
    {
        if (preg_match('/^(\d+)\.(\d+)$/', $current, $matches) === 1) {
            return $matches[1].'.'.((int) $matches[2] + 1);
        }

        if (preg_match('/^(\d+)/', $current, $matches) === 1) {
            return ((int) $matches[1] + 1).'.0';
        }

        return ContractTemplate::INITIAL_TERMS_VERSION;
    }
}
