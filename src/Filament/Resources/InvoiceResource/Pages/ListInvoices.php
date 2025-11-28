<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Filament\Resources\InvoiceResource\Pages;

use AichaDigital\Larabill\Filament\Resources\InvoiceResource;
use Filament\Resources\Pages\ListRecords;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\CreateAction::make(),
        ];
    }
}

