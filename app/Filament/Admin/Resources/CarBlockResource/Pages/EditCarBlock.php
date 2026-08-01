<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarBlockResource\Pages;

use App\Filament\Admin\Resources\CarBlockResource;
use App\Models\CarBlock;
use App\Services\Booking\CarBlockService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class EditCarBlock extends EditRecord
{
    protected static string $resource = CarBlockResource::class;

    /**
     * Saves go through CarBlockService, the same way creates do. The form
     * freezes the car and the service re-checks the window: an extension can
     * overlap a booking made in the meantime, and that clash is a field error,
     * not a database error.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var CarBlock $record */
        try {
            return app(CarBlockService::class)->update($record, $data);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages([
                'data.car_id' => $e->getMessage(),
            ]);
        }
    }
}
