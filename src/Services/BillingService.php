<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

/**
 * Billing Service
 *
 * Handles invoice creation and management.
 */
class BillingService
{
    private int $invoiceCounter = 1;

    /**
     * Create a new invoice.
     *
     * @param  array  $invoiceData  Invoice data
     * @return array Created invoice data
     */
    public function createInvoice(array $invoiceData): array
    {
        $items = $invoiceData['items'] ?? [];
        $subtotal = 0;
        $totalTax = 0;

        // Calculate totals
        foreach ($items as $item) {
            $itemTotal = $item['quantity'] * $item['unit_price'];
            $itemTax = $itemTotal * ($item['tax_rate'] / 100);

            $subtotal += $itemTotal;
            $totalTax += $itemTax;
        }

        $totalAmount = $subtotal + $totalTax;

        return [
            'invoice_number' => $this->generateInvoiceNumber(),
            'user_id' => $invoiceData['user_id'],
            'items' => $items,
            'subtotal' => $subtotal,
            'total_tax' => $totalTax,
            'total_amount' => $totalAmount,
            'created_at' => now(),
        ];
    }

    /**
     * Generate a sequential invoice number.
     */
    private function generateInvoiceNumber(): string
    {
        $number = sprintf('FAC-%04d', $this->invoiceCounter++);

        return $number;
    }
}
