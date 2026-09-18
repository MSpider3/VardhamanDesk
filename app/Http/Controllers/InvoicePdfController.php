<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\InvoicePdfService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class InvoicePdfController extends Controller
{
    /**
     * Download or stream the authorized invoice PDF.
     */
    public function __invoke(Request $request, Invoice $invoice, InvoicePdfService $pdfService): Response
    {
        Gate::authorize('view', $invoice);

        if ($request->boolean('stream')) {
            return $pdfService->stream($invoice);
        }

        return $pdfService->download($invoice);
    }
}
