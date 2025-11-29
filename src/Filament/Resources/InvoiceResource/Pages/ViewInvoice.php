<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Filament\Resources\InvoiceResource\Pages;

use AichaDigital\Larabill\Filament\Resources\InvoiceResource;
use AichaDigital\Larabill\Models\Invoice;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * @property Invoice $record
 */
class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->hidden(fn () => $this->getRecord()->is_immutable),
        ];
    }

    /**
     * Get the record being viewed.
     */
    public function getRecord(): Invoice
    {
        /** @var Invoice $record */
        $record = $this->record;

        return $record;
    }
}
