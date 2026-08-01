<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarBlockResource\Pages;

use App\Filament\Admin\Resources\CarBlockResource;
use App\Models\CarBlock;
use App\Services\Booking\CarBlockService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CreateCarBlock extends CreateRecord
{
    protected static string $resource = CarBlockResource::class;

    /**
     * Creation goes through CarBlockService: it refuses a window that overlaps
     * another block or a live booking on the car, so the Postgres EXCLUDE
     * constraint and the cross-check trigger stay the backstop and the form
     * shows the clash as a field error instead of a database error.
     */
    protected function handleRecordCreation(array $data): CarBlock
    {
        try {
            return app(CarBlockService::class)->create($data, Auth::user());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages([
                'data.car_id' => $e->getMessage(),
            ]);
        }
    }
}
