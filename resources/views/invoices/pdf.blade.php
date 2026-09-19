<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $invoice->display_number }}</title>
    <style>
        @page {
            margin: 25px 30px;
        }
        body {
            font-family: 'DejaVu Sans', 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 11px;
            line-height: 1.4;
            color: #333333;
        }
        .header-table, .meta-table, .items-table, .totals-table, .bank-table {
            width: 100%;
            border-collapse: collapse;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .text-left {
            text-align: left;
        }
        .bold {
            font-weight: bold;
        }
        .muted {
            color: #666666;
        }
        .draft-watermark {
            position: absolute;
            top: 300px;
            left: 120px;
            font-size: 80px;
            color: rgba(220, 38, 38, 0.15);
            font-weight: bold;
            transform: rotate(-30deg);
            z-index: -1;
        }
        .badge-draft {
            background-color: #fee2e2;
            color: #dc2626;
            padding: 3px 8px;
            border-radius: 4px;
            font-weight: bold;
            font-size: 12px;
            display: inline-block;
        }
        .badge-sent {
            background-color: #dbeafe;
            color: #1d4ed8;
            padding: 3px 8px;
            border-radius: 4px;
            font-weight: bold;
            font-size: 12px;
            display: inline-block;
        }
        .badge-paid {
            background-color: #dcfce7;
            color: #15803d;
            padding: 3px 8px;
            border-radius: 4px;
            font-weight: bold;
            font-size: 12px;
            display: inline-block;
        }
        .items-table th {
            background-color: #f1f5f9;
            color: #0f172a;
            border: 1px solid #cbd5e1;
            padding: 6px 8px;
            font-size: 10px;
            text-transform: uppercase;
        }
        .items-table td {
            border: 1px solid #e2e8f0;
            padding: 6px 8px;
            font-size: 10px;
        }
        .section-title {
            font-size: 11px;
            font-weight: bold;
            background-color: #f8fafc;
            border-bottom: 2px solid #cbd5e1;
            padding: 4px 6px;
            margin-bottom: 6px;
            text-transform: uppercase;
            color: #1e293b;
        }
        .box {
            border: 1px solid #e2e8f0;
            padding: 8px;
            border-radius: 4px;
            background-color: #ffffff;
        }
        .signatory-line {
            margin-top: 50px;
            border-top: 1px solid #64748b;
            display: inline-block;
            width: 200px;
        }
    </style>
</head>
<body>
    @if($invoice->status->value === 'draft')
        <div class="draft-watermark">DRAFT INVOICE</div>
    @endif

    <!-- Header Section -->
    <table class="header-table" style="margin-bottom: 15px;">
        <tr>
            <td style="width: 55%; vertical-align: top;">
                @if(!empty($safeLogoPath) && file_exists($safeLogoPath))
                    <img src="{{ $safeLogoPath }}" style="max-height: 50px; margin-bottom: 5px;">
                @endif
                <div style="font-size: 16px; font-weight: bold; color: #0f172a;">
                    {{ $company?->company_name ?? 'Vardhaman Infotech Solutions' }}
                </div>
                <div class="muted" style="margin-top: 4px; font-size: 10px;">
                    {!! nl2br(e(strip_tags($company?->address ?? 'Jaipur, Rajasthan - 302017'))) !!}
                </div>
                <div style="margin-top: 4px; font-size: 10px;">
                    <span class="bold">GSTIN:</span> {{ $company?->gstin ?? '08AABCV1234F1Z9' }} | 
                    <span class="bold">PAN:</span> {{ $company?->pan ?? 'AABCV1234F' }}
                </div>
                <div style="font-size: 10px;">
                    <span class="bold">State:</span> {{ $company?->state ?? 'Rajasthan' }} (Code: {{ $company?->state_code ?? '08' }})
                </div>
            </td>
            <td style="width: 45%; vertical-align: top; text-align: right;">
                <div style="font-size: 20px; font-weight: bold; color: #1e293b; letter-spacing: 1px;">TAX INVOICE</div>
                <div style="margin-top: 5px;">
                    @if($invoice->status->value === 'draft')
                        <span class="badge-draft">DRAFT</span>
                    @elseif($invoice->status->value === 'paid')
                        <span class="badge-paid">PAID</span>
                    @else
                        <span class="badge-sent">{{ strtoupper($invoice->status->value) }}</span>
                    @endif
                </div>
                <table style="width: 100%; margin-top: 8px; font-size: 10px;">
                    <tr>
                        <td class="text-right bold">Invoice No:</td>
                        <td class="text-right bold" style="color: #0f172a;">{{ $invoice->display_number }}</td>
                    </tr>
                    <tr>
                        <td class="text-right bold">Invoice Date:</td>
                        <td class="text-right">{{ $invoice->invoice_date?->format('d/m/Y') }}</td>
                    </tr>
                    <tr>
                        <td class="text-right bold">Due Date:</td>
                        <td class="text-right">{{ $invoice->due_date?->format('d/m/Y') }}</td>
                    </tr>
                    <tr>
                        <td class="text-right bold">Place of Supply:</td>
                        <td class="text-right">{{ $invoice->place_of_supply?->stateName() ?? $invoice->place_of_supply }} (Code: {{ $invoice->place_of_supply?->code() ?? $invoice->place_of_supply }})</td>
                    </tr>
                    <tr>
                        <td class="text-right bold">Reverse Charge:</td>
                        <td class="text-right">No</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <!-- Billing Details Section -->
    <table class="meta-table" style="margin-bottom: 15px;">
        <tr>
            <td style="width: 100%;" class="box">
                <div class="section-title">Billed To (Client Details)</div>
                <table style="width: 100%; font-size: 10px;">
                    <tr>
                        <td style="width: 60%; vertical-align: top;">
                            <div class="bold" style="font-size: 12px; color: #0f172a;">{{ $invoice->client?->name }}</div>
                            @if($invoice->client?->company)
                                <div class="muted">{{ $invoice->client->company }}</div>
                            @endif
                            <div style="margin-top: 4px;">{!! nl2br(e(strip_tags($invoice->client?->billing_address ?? ''))) !!}</div>
                        </td>
                        <td style="width: 40%; vertical-align: top;">
                            <div><span class="bold">State:</span> {{ $invoice->client?->state?->stateName() ?? $invoice->client?->state }} (Code: {{ $invoice->client?->state?->code() ?? $invoice->client?->state }})</div>
                            <div style="margin-top: 3px;"><span class="bold">GSTIN:</span> {{ $invoice->client?->gstin ?: 'Unregistered' }}</div>
                            @if($invoice->client?->phone)
                                <div style="margin-top: 3px;"><span class="bold">Phone:</span> {{ $invoice->client->phone }}</div>
                            @endif
                            @if($invoice->client?->email)
                                <div style="margin-top: 3px;"><span class="bold">Email:</span> {{ $invoice->client->email }}</div>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <!-- Line Items Table -->
    @php
        $isIntraState = ($invoice->place_of_supply?->code() ?? (string)$invoice->place_of_supply) === ($company?->state_code ?? '08');
    @endphp
    <table class="items-table" style="margin-bottom: 15px;">
        <thead>
            <tr>
                <th style="width: 5%;" class="text-center">#</th>
                <th style="width: 35%;">Description</th>
                <th style="width: 10%;" class="text-center">SAC</th>
                <th style="width: 8%;" class="text-right">Qty</th>
                <th style="width: 12%;" class="text-right">Rate (₹)</th>
                <th style="width: 12%;" class="text-right">Amount (₹)</th>
                @if($isIntraState)
                    <th style="width: 9%;" class="text-right">CGST (₹)</th>
                    <th style="width: 9%;" class="text-right">SGST (₹)</th>
                @else
                    <th style="width: 18%;" class="text-right">IGST (₹)</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $index => $item)
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>
                        <span class="bold">{{ strip_tags($item->description ?? '') }}</span>
                    </td>
                    <td class="text-center">{{ $item->sac_code }}</td>
                    <td class="text-right">{{ number_format((float)$item->quantity, 2) }}</td>
                    <td class="text-right">{{ number_format((float)$item->rate, 2) }}</td>
                    <td class="text-right bold">{{ number_format((float)$item->amount, 2) }}</td>
                    @if($isIntraState)
                        <td class="text-right">
                            {{ number_format((float)$item->cgst_amount, 2) }}
                            <div class="muted" style="font-size: 8px;">({{ (float)($item->gstRate?->rate ?? 0) / 2 }}%)</div>
                        </td>
                        <td class="text-right">
                            {{ number_format((float)$item->sgst_amount, 2) }}
                            <div class="muted" style="font-size: 8px;">({{ (float)($item->gstRate?->rate ?? 0) / 2 }}%)</div>
                        </td>
                    @else
                        <td class="text-right">
                            {{ number_format((float)$item->igst_amount, 2) }}
                            <div class="muted" style="font-size: 8px;">({{ (float)($item->gstRate?->rate ?? 0) }}%)</div>
                        </td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>

    <!-- Totals and Bank Details -->
    <table class="totals-table" style="margin-bottom: 20px;">
        <tr>
            <!-- Bank Details Left Column -->
            <td style="width: 55%; vertical-align: top; padding-right: 15px;">
                <div class="box">
                    <div class="section-title">Payment Bank Details</div>
                    <table style="width: 100%; font-size: 10px;">
                        <tr>
                            <td class="bold" style="width: 35%;">Bank Name:</td>
                            <td>{{ $company?->bank_name ?? 'HDFC Bank' }}</td>
                        </tr>
                        <tr>
                            <td class="bold">Account Name:</td>
                            <td>{{ $company?->bank_account_name ?? ($company?->company_name ?? 'Vardhaman Infotech') }}</td>
                        </tr>
                        <tr>
                            <td class="bold">Account Number:</td>
                            <td class="bold">{{ $company?->bank_account_number ?? '987654321012' }}</td>
                        </tr>
                        <tr>
                            <td class="bold">IFSC Code:</td>
                            <td class="bold">{{ $company?->bank_ifsc ?? 'HDFC0001234' }}</td>
                        </tr>
                    </table>
                </div>

                @if($invoice->payments->isNotEmpty())
                    <div class="box" style="margin-top: 10px;">
                        <div class="section-title">Payments Received</div>
                        <table style="width: 100%; font-size: 9px;">
                            @foreach($invoice->payments as $payment)
                                <tr>
                                    <td>{{ $payment->payment_date?->format('d/m/Y') }}</td>
                                    <td>{{ $payment->method->label() }}</td>
                                    <td class="muted">{{ strip_tags($payment->reference_note ?? '') ?: '-' }}</td>
                                    <td class="text-right bold">₹{{ number_format((float)$payment->amount, 2) }}</td>
                                </tr>
                            @endforeach
                        </table>
                    </div>
                @endif
            </td>

            <!-- Totals Right Column -->
            <td style="width: 45%; vertical-align: top;">
                <table style="width: 100%; font-size: 10px; border: 1px solid #cbd5e1; border-collapse: collapse;">
                    <tr style="border-bottom: 1px solid #e2e8f0;">
                        <td style="padding: 5px 8px;" class="bold">Taxable Subtotal:</td>
                        <td style="padding: 5px 8px;" class="text-right bold">₹{{ number_format((float)$invoice->subtotal, 2) }}</td>
                    </tr>
                    @if($isIntraState)
                        <tr style="border-bottom: 1px solid #e2e8f0;">
                            <td style="padding: 5px 8px;">CGST Total:</td>
                            <td style="padding: 5px 8px;" class="text-right">₹{{ number_format((float)$invoice->cgst_amount, 2) }}</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #e2e8f0;">
                            <td style="padding: 5px 8px;">SGST Total:</td>
                            <td style="padding: 5px 8px;" class="text-right">₹{{ number_format((float)$invoice->sgst_amount, 2) }}</td>
                        </tr>
                    @else
                        <tr style="border-bottom: 1px solid #e2e8f0;">
                            <td style="padding: 5px 8px;">IGST Total:</td>
                            <td style="padding: 5px 8px;" class="text-right">₹{{ number_format((float)$invoice->igst_amount, 2) }}</td>
                        </tr>
                    @endif
                    <tr style="background-color: #0f172a; color: #ffffff; font-size: 12px;">
                        <td style="padding: 8px;" class="bold">Grand Total:</td>
                        <td style="padding: 8px;" class="text-right bold">₹{{ number_format((float)$invoice->total, 2) }}</td>
                    </tr>
                    @if($invoice->payments->isNotEmpty())
                        <tr style="border-top: 1px solid #cbd5e1;">
                            <td style="padding: 5px 8px; color: #15803d;" class="bold">Amount Paid:</td>
                            <td style="padding: 5px 8px; color: #15803d;" class="text-right bold">₹{{ number_format((float)$invoice->paid_amount, 2) }}</td>
                        </tr>
                        <tr style="background-color: #fef2f2; color: #991b1b;">
                            <td style="padding: 6px 8px;" class="bold">Balance Outstanding:</td>
                            <td style="padding: 6px 8px;" class="text-right bold">₹{{ number_format((float)$invoice->outstanding_amount, 2) }}</td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <!-- Signatory Footer -->
    <table style="width: 100%; margin-top: 20px;">
        <tr>
            <td style="width: 60%; vertical-align: bottom; font-size: 9px; color: #64748b;">
                This is a computer-generated tax invoice issued by {{ $company?->company_name ?? 'Vardhaman Infotech Solutions' }}.
            </td>
            <td style="width: 40%; text-align: right; vertical-align: bottom;">
                <div style="font-size: 10px; font-weight: bold; margin-bottom: 40px;">
                    For {{ $company?->company_name ?? 'Vardhaman Infotech Solutions' }}
                </div>
                <div class="signatory-line"></div>
                <div style="font-size: 9px; color: #475569; margin-top: 4px;">
                    {{ $company?->authorised_signatory_name ?? 'Authorised Signatory' }}
                </div>
            </td>
        </tr>
    </table>
</body>
</html>
