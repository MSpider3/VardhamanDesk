<?php

namespace App\Services;

use App\Enums\IndianState;
use App\Models\CompanySetting;
use App\Models\GstRate;
use DomainException;

class InvoiceCalculationService
{
    /**
     * Calculate line items and totals for an invoice.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{
     *     is_intra_state: bool,
     *     supplier_state_code: string,
     *     place_of_supply_code: string,
     *     items: array<int, array<string, mixed>>,
     *     subtotal: string,
     *     cgst_amount: string,
     *     sgst_amount: string,
     *     igst_amount: string,
     *     total: string
     * }
     */
    public function calculate(string|IndianState $placeOfSupply, array $items): array
    {
        $company = CompanySetting::current();
        $supplierStateCode = $company?->state_code
            ?? ($company ? IndianState::fromCodeOrName($company->state)?->code() : null)
            ?? '08'; // Default Rajasthan if setting row missing

        $posState = IndianState::fromCodeOrName($placeOfSupply);
        $posCode = $posState?->code() ?? str_pad((string) $placeOfSupply, 2, '0', STR_PAD_LEFT);

        $isIntraState = ($posCode === $supplierStateCode);

        $calculatedItems = [];
        $subtotal = '0.00';
        $totalCgst = '0.00';
        $totalSgst = '0.00';
        $totalIgst = '0.00';

        foreach ($items as $item) {
            $qty = (float) ($item['quantity'] ?? 0);
            $rate = (float) ($item['rate'] ?? 0);

            if ($qty <= 0) {
                throw new DomainException('Line item quantity must be greater than zero.');
            }

            if ($rate < 0) {
                throw new DomainException('Line item unit rate cannot be negative.');
            }

            $amount = round($qty * $rate, 2);

            $gstRateId = $item['gst_rate_id'] ?? null;
            $gstRateModel = $gstRateId ? GstRate::find($gstRateId) : null;
            $ratePercent = $gstRateModel ? (float) $gstRateModel->rate : (float) ($item['gst_rate'] ?? 0);

            if ($isIntraState) {
                $halfRate = $ratePercent / 2.0;
                $cgst = round($amount * ($halfRate / 100.0), 2);
                $sgst = round($amount * ($halfRate / 100.0), 2);
                $igst = 0.00;
            } else {
                $cgst = 0.00;
                $sgst = 0.00;
                $igst = round($amount * ($ratePercent / 100.0), 2);
            }

            $calculatedItems[] = array_merge($item, [
                'quantity' => number_format($qty, 2, '.', ''),
                'rate' => number_format($rate, 2, '.', ''),
                'amount' => number_format($amount, 2, '.', ''),
                'cgst_amount' => number_format($cgst, 2, '.', ''),
                'sgst_amount' => number_format($sgst, 2, '.', ''),
                'igst_amount' => number_format($igst, 2, '.', ''),
            ]);

            $subtotal = bcadd($subtotal, number_format($amount, 2, '.', ''), 2);
            $totalCgst = bcadd($totalCgst, number_format($cgst, 2, '.', ''), 2);
            $totalSgst = bcadd($totalSgst, number_format($sgst, 2, '.', ''), 2);
            $totalIgst = bcadd($totalIgst, number_format($igst, 2, '.', ''), 2);
        }

        $taxSum = bcadd(bcadd($totalCgst, $totalSgst, 2), $totalIgst, 2);
        $total = bcadd($subtotal, $taxSum, 2);

        return [
            'is_intra_state' => $isIntraState,
            'supplier_state_code' => $supplierStateCode,
            'place_of_supply_code' => $posCode,
            'items' => $calculatedItems,
            'subtotal' => $subtotal,
            'cgst_amount' => $totalCgst,
            'sgst_amount' => $totalSgst,
            'igst_amount' => $totalIgst,
            'total' => $total,
        ];
    }
}
