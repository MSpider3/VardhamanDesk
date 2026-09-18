<?php

namespace App\Filament\Widgets;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Payment;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class FinancialOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 0;

    protected function getStats(): array
    {
        $invoicedThisMonth = (string) (Invoice::query()
            ->whereIn('status', [
                InvoiceStatus::SENT->value,
                InvoiceStatus::PARTIALLY_PAID->value,
                InvoiceStatus::PAID->value,
            ])
            ->whereYear('invoice_date', Carbon::now()->year)
            ->whereMonth('invoice_date', Carbon::now()->month)
            ->sum('total') ?? '0.00');

        $receivedThisMonth = (string) (Payment::query()
            ->whereYear('payment_date', Carbon::now()->year)
            ->whereMonth('payment_date', Carbon::now()->month)
            ->sum('amount') ?? '0.00');

        $totalInvoicedOpen = (string) (Invoice::query()
            ->whereIn('status', [
                InvoiceStatus::SENT->value,
                InvoiceStatus::PARTIALLY_PAID->value,
            ])
            ->sum('total') ?? '0.00');

        $totalPaidOpen = (string) (Payment::query()
            ->whereHas('invoice', function ($query) {
                $query->whereIn('status', [
                    InvoiceStatus::SENT->value,
                    InvoiceStatus::PARTIALLY_PAID->value,
                ]);
            })
            ->sum('amount') ?? '0.00');

        $outstanding = bcsub($totalInvoicedOpen, $totalPaidOpen, 2);
        if (bccomp($outstanding, '0.00', 2) < 0) {
            $outstanding = '0.00';
        }

        return [
            Stat::make('Invoiced This Month', '₹'.number_format((float) $invoicedThisMonth, 2))
                ->description('Invoices issued in '.Carbon::now()->format('F Y'))
                ->descriptionIcon('heroicon-m-document-currency-rupee')
                ->color('primary'),

            Stat::make('Received This Month', '₹'.number_format((float) $receivedThisMonth, 2))
                ->description('Collections received in '.Carbon::now()->format('F Y'))
                ->descriptionIcon('heroicon-m-arrow-down-left')
                ->color('success'),

            Stat::make('Outstanding Receivables', '₹'.number_format((float) $outstanding, 2))
                ->description('Pending from Sent & Partially Paid invoices')
                ->descriptionIcon('heroicon-m-clock')
                ->color(bccomp($outstanding, '0.00', 2) > 0 ? 'warning' : 'success'),
        ];
    }
}
