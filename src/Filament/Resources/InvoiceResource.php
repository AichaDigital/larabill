<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Filament\Resources;

use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\Invoice;
use BackedEnum;
use Filament\{Actions, Forms, Tables};
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * Invoice Resource for Filament 4
 *
 * Provides CRUD interface for invoices with base100 monetary support.
 *
 * @note Updated for Filament 4 API (ADR-001)
 */
class InvoiceResource extends LarabillResource
{
    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return __('larabill::filament.invoice.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('larabill::filament.invoice.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('larabill::filament.invoice.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Header section
                Forms\Components\TextInput::make('fiscal_number')
                    ->label(__('larabill::filament.invoice.fiscal_number'))
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText(__('larabill::filament.invoice.fiscal_number_help')),

                Forms\Components\Select::make('status')
                    ->label(__('larabill::filament.invoice.status'))
                    ->options(InvoiceStatus::toArray())
                    ->required()
                    ->default(InvoiceStatus::DRAFT->value),

                Forms\Components\Toggle::make('is_roi_taxed')
                    ->label(__('larabill::filament.invoice.reverse_charge'))
                    ->helperText(__('larabill::filament.invoice.reverse_charge_help')),

                // Customer section
                Forms\Components\Select::make('customer_id')
                    ->label(__('larabill::filament.invoice.customer'))
                    ->relationship('customer', 'display_name')
                    ->searchable()
                    ->preload()
                    ->required(),

                Forms\Components\DatePicker::make('invoice_date')
                    ->label(__('larabill::filament.invoice.invoice_date'))
                    ->required()
                    ->default(now()),

                Forms\Components\DatePicker::make('service_date')
                    ->label(__('larabill::filament.invoice.service_date')),

                Forms\Components\DatePicker::make('due_date')
                    ->label(__('larabill::filament.invoice.due_date'))
                    ->default(fn () => now()->addDays(15)),

                // Totals section (read-only)
                Forms\Components\TextInput::make('taxable_amount_display')
                    ->label(__('larabill::filament.invoice.taxable_amount'))
                    ->disabled()
                    ->dehydrated(false)
                    ->formatStateUsing(fn (?Invoice $record) => $record ? self::formatMoney($record->taxable_amount ?? 0) : '-'),

                Forms\Components\TextInput::make('total_tax_display')
                    ->label(__('larabill::filament.invoice.total_tax_amount'))
                    ->disabled()
                    ->dehydrated(false)
                    ->formatStateUsing(fn (?Invoice $record) => $record ? self::formatMoney($record->total_tax_amount ?? 0) : '-'),

                Forms\Components\TextInput::make('total_amount_display')
                    ->label(__('larabill::filament.invoice.total'))
                    ->disabled()
                    ->dehydrated(false)
                    ->formatStateUsing(fn (?Invoice $record) => $record ? self::formatMoney($record->total_amount ?? 0) : '-'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('fiscal_number')
                    ->label(__('larabill::filament.invoice.fiscal_number'))
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('customer.display_name')
                    ->label(__('larabill::filament.invoice.customer'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('invoice_date')
                    ->label(__('larabill::filament.invoice.invoice_date'))
                    ->date('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_amount')
                    ->label(__('larabill::filament.invoice.total'))
                    ->formatStateUsing(fn (int $state) => self::formatMoney($state))
                    ->sortable()
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('larabill::filament.invoice.status'))
                    ->badge()
                    ->formatStateUsing(fn (int $state) => InvoiceStatus::from($state)->label())
                    ->color(fn (int $state): string => match ($state) {
                        InvoiceStatus::DRAFT->value   => 'gray',
                        InvoiceStatus::SENT->value    => 'warning',
                        InvoiceStatus::PAID->value    => 'success',
                        InvoiceStatus::OVERDUE->value => 'danger',
                        default                       => 'gray',
                    }),

                Tables\Columns\IconColumn::make('is_roi_taxed')
                    ->label(__('larabill::filament.invoice.roi'))
                    ->boolean()
                    ->trueIcon('heroicon-o-arrow-path')
                    ->falseIcon('heroicon-o-banknotes')
                    ->trueColor('info')
                    ->falseColor('success'),

                Tables\Columns\IconColumn::make('is_immutable')
                    ->label(__('larabill::filament.invoice.immutable'))
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->falseIcon('heroicon-o-lock-open')
                    ->trueColor('warning')
                    ->falseColor('gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(InvoiceStatus::toArray()),

                Tables\Filters\TernaryFilter::make('is_roi_taxed')
                    ->label(__('larabill::filament.invoice.reverse_charge')),

                Tables\Filters\TernaryFilter::make('is_immutable')
                    ->label(__('larabill::filament.invoice.immutable')),

                Tables\Filters\Filter::make('invoice_date')
                    ->schema([
                        Forms\Components\DatePicker::make('from')
                            ->label(__('larabill::filament.invoice.filter_date_from')),
                        Forms\Components\DatePicker::make('until')
                            ->label(__('larabill::filament.invoice.filter_date_until')),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'], fn ($q, $date) => $q->whereDate('invoice_date', '>=', $date))
                            ->when($data['until'], fn ($q, $date) => $q->whereDate('invoice_date', '<=', $date));
                    }),
            ])
            ->recordActions([
                Actions\ViewAction::make(),
                Actions\EditAction::make()
                    ->hidden(fn (Invoice $record) => $record->is_immutable),
                Actions\DeleteAction::make()
                    ->hidden(fn (Invoice $record) => $record->is_immutable),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('invoice_date', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => \AichaDigital\Larabill\Filament\Resources\InvoiceResource\Pages\ListInvoices::route('/'),
            'create' => \AichaDigital\Larabill\Filament\Resources\InvoiceResource\Pages\CreateInvoice::route('/create'),
            'edit'   => \AichaDigital\Larabill\Filament\Resources\InvoiceResource\Pages\EditInvoice::route('/{record}/edit'),
            'view'   => \AichaDigital\Larabill\Filament\Resources\InvoiceResource\Pages\ViewInvoice::route('/{record}'),
        ];
    }

    /**
     * Check if resource should be registered.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return self::isResourceEnabled('invoice');
    }
}
