<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BranchResource\Pages;

use App\Filament\Admin\Resources\BranchResource;
use App\Models\Branch;
use App\Services\BranchService;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditBranch extends EditRecord
{
    protected static string $resource = BranchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // The guarded replacement for the plain DeleteAction: never the
            // default branch, never while rows still point at it — see
            // BranchService::delete(). Hiding on the default branch is UX; the
            // service is the authority.
            Action::make('delete')
                ->label(__('branches.actions.delete'))
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(__('branches.actions.delete_heading'))
                ->modalDescription(__('branches.actions.delete_confirm'))
                ->action(function (BranchService $service): void {
                    $record = $this->getRecord();
                    assert($record instanceof Branch);

                    try {
                        $service->delete($record, Auth::user());
                    } catch (DomainException $e) {
                        Notification::make()
                            ->danger()
                            ->title($e->getMessage())
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title(__('branches.notifications.deleted'))
                        ->send();

                    $this->redirect(BranchResource::getUrl('index'));
                })
                ->visible(function (): bool {
                    $record = $this->getRecord();
                    assert($record instanceof Branch);

                    return ! $record->is_default;
                }),
        ];
    }

    /**
     * The staff list moved to ViewBranch — it is read-only history, not
     * something maintained in place on this form.
     *
     * @return array<int, mixed>
     */
    protected function getAllRelationManagers(): array
    {
        return [];
    }
}
