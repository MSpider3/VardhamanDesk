<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class InvoicePdfService
{
    /**
     * Generate the Dompdf instance for the given invoice.
     */
    public function generate(Invoice $invoice): \Barryvdh\DomPDF\PDF
    {
        $invoice->loadMissing([
            'client',
            'items.gstRate',
            'payments.recordedBy',
        ]);

        $company = CompanySetting::current();

        /** @var \Barryvdh\DomPDF\PDF $pdf */
        $pdf = Pdf::loadView('invoices.pdf', [
            'invoice' => $invoice,
            'company' => $company,
        ]);

        $pdf->setPaper('a4', 'portrait');

        return $pdf;
    }

    /**
     * Get safe formatted filename for the invoice PDF download.
     */
    public function getFilename(Invoice $invoice): string
    {
        if ($invoice->invoice_number) {
            $cleanedNumber = str_replace(['/', '\\'], '-', $invoice->invoice_number);

            return "Invoice-{$cleanedNumber}.pdf";
        }

        return "Invoice-DRAFT-{$invoice->id}.pdf";
    }

    /**
     * Download the invoice PDF.
     */
    public function download(Invoice $invoice): Response
    {
        $pdf = $this->generate($invoice);

        return $pdf->download($this->getFilename($invoice));
    }

    /**
     * Stream the invoice PDF in the browser.
     */
    public function stream(Invoice $invoice): Response
    {
        $pdf = $this->generate($invoice);

        return $pdf->stream($this->getFilename($invoice));
    }
}
