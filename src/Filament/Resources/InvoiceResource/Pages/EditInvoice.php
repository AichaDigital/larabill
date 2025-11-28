<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Filament\Resources\InvoiceResource\Pages;

use AichaDigital\Larabill\Filament\Resources\InvoiceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make()
                ->hidden(fn () => $this->record->is_immutable),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Prevent editing if immutable
        if ($this->record->is_immutable) {
            $this->halt();
        }

        return $data;
    }
}

