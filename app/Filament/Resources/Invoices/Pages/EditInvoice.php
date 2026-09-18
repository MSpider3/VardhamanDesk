<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Services\InvoiceDraftService;
use App\Services\InvoiceSendService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $draftService = app(InvoiceDraftService::class);
        $items = $data['items'] ?? [];
        unset($data['items']);

        return $draftService->updateDraft($record, $data, $items);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('send')
                ->label('Send Invoice')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Finalize and Send Invoice')
                ->modalDescription('Are you sure you want to send this invoice? An official sequential invoice number will be permanently allocated and all line items will be locked.')
                ->visible(fn () => $this->record->status === InvoiceStatus::DRAFT || $this->record->status === InvoiceStatus::DRAFT->value)
                ->action(function (InvoiceSendService $sendService) {
                    $sentInvoice = $sendService->send($this->record);
                    Notification::make()
                        ->title('Invoice Sent Successfully')
                        ->body("Allocated Number: {$sentInvoice->invoice_number}")
                        ->success()
                        ->send();

                    $this->redirect(InvoiceResource::getUrl('index'));
                }),

            Action::make('download_pdf')
                ->label('Download PDF')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->url(fn () => route('invoices.pdf', $this->record))
                ->openUrlInNewTab(),

            DeleteAction::make()
                ->visible(fn () => $this->record->status === InvoiceStatus::DRAFT || $this->record->status === InvoiceStatus::DRAFT->value),
        ];
    }
}
