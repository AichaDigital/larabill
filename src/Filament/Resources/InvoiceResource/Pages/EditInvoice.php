<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Filament\Resources\InvoiceResource\Pages;

use AichaDigital\Larabill\Filament\Resources\InvoiceResource;
use AichaDigital\Larabill\Models\Invoice;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

/**
 * @property Invoice $record
 */
class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make()
                ->hidden(fn () => $this->getRecord()->is_immutable),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->getRecord()->is_immutable) {
            $this->halt();
        }

        return $data;
    }

    /**
     * Get the record being edited.
     */
    public function getRecord(): Invoice
    {
        /** @var Invoice $record */
        $record = $this->record;

        return $record;
    }
}
