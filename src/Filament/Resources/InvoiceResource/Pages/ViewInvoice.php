<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Filament\Resources\InvoiceResource\Pages;

use AichaDigital\Larabill\Filament\Resources\InvoiceResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->hidden(fn () => $this->record->is_immutable),
        ];
    }
}
