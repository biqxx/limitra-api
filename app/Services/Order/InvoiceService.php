<?php

namespace App\Services\Order;

use App\Models\Order\Order;

class InvoiceService
{
    public function data(Order $order): array
    {
        $order->loadMissing(['items', 'latestPayment', 'shipment']);

        return [
            'invoice_number' => 'INV-'.$order->number,
            'order_number' => $order->number,
            'issued_at' => $order->created_at,
            'seller' => ['name' => config('app.name')],
            'customer' => [
                'email' => $order->contact_email,
                'shipping_address' => $order->shipping_address,
            ],
            'currency' => $order->currency,
            'items' => $order->items->map(fn ($item) => [
                'name' => $item->product_name,
                'sku' => $item->sku,
                'selected_options' => $item->selected_options ?? [],
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'line_total' => $item->line_total,
            ])->all(),
            'totals' => [
                'subtotal' => $order->subtotal,
                'discount' => $order->discount_total,
                'credit' => $order->credit_total,
                'shipping' => $order->shipping_total,
                'grand_total' => $order->grand_total,
            ],
            'payment' => $order->latestPayment ? [
                'method' => $order->latestPayment->method,
                'reference' => $order->latestPayment->reference,
                'status' => $order->latestPayment->status,
                'paid_at' => $order->latestPayment->paid_at,
            ] : [
                'method' => $order->payment_method,
                'reference' => null,
                'status' => $order->payment_status,
                'paid_at' => null,
            ],
        ];
    }

    public function pdf(array $invoice): string
    {
        $lines = [
            config('app.name').' Invoice',
            $invoice['invoice_number'],
            'Order: '.$invoice['order_number'],
            'Issued: '.$invoice['issued_at']->toDateTimeString(),
            'Customer: '.$invoice['customer']['email'],
            'Ship to: '.$this->addressLine($invoice['customer']['shipping_address']),
            '',
            'Items',
        ];
        foreach ($invoice['items'] as $item) {
            $options = $item['selected_options'] ? ' ('.collect($item['selected_options'])->map(
                fn ($value, $key) => "{$key}: {$value}"
            )->implode(', ').')' : '';
            $lines[] = sprintf(
                '%d x %s%s [%s] @ %s %s = %s %s',
                $item['quantity'],
                $item['name'],
                $options,
                $item['sku'] ?: 'no SKU',
                $invoice['currency'],
                $item['unit_price'],
                $invoice['currency'],
                $item['line_total'],
            );
        }
        $lines = [...$lines, '',
            'Subtotal: '.$invoice['currency'].' '.$invoice['totals']['subtotal'],
            'Discount: '.$invoice['currency'].' '.$invoice['totals']['discount'],
            'Credit: '.$invoice['currency'].' '.$invoice['totals']['credit'],
            'Shipping: '.$invoice['currency'].' '.$invoice['totals']['shipping'],
            'Total: '.$invoice['currency'].' '.$invoice['totals']['grand_total'],
            'Payment: '.$invoice['payment']['method'].' / '.$invoice['payment']['status'],
            'Reference: '.($invoice['payment']['reference'] ?? 'Not available'),
        ];

        return $this->renderPdf($lines);
    }

    private function renderPdf(array $lines): string
    {
        $pages = array_chunk($lines, 48);
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pageIds = [];
        foreach ($pages as $index => $pageLines) {
            $pageId = 4 + ($index * 2);
            $contentId = $pageId + 1;
            $pageIds[] = "{$pageId} 0 R";
            $content = "BT\n/F1 9 Tf\n48 790 Td\n12 TL\n";
            foreach ($pageLines as $line) {
                $content .= '('.$this->escapePdfText((string) $line).") Tj\nT*\n";
            }
            $content .= "ET\n";
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Resources << /Font << /F1 3 0 R >> >> /Contents {$contentId} 0 R >>";
            $objects[$contentId] = '<< /Length '.strlen($content).">>\nstream\n{$content}endstream";
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $pageIds).'] /Count '.count($pageIds).' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        for ($id = 1; $id <= count($objects); $id++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$id])."\n";
        }
        $pdf .= 'trailer'."\n<< /Size ".(count($objects) + 1).' /Root 1 0 R >>'."\nstartxref\n{$xref}\n%%EOF";

        return $pdf;
    }

    private function escapePdfText(string $text): string
    {
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text) ?: $text;

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded);
    }

    private function addressLine(array $address): string
    {
        return implode(', ', array_filter([
            $address['recipient_name'] ?? null,
            $address['line1'] ?? null,
            $address['line2'] ?? null,
            $address['city'] ?? null,
            $address['state'] ?? null,
            $address['country'] ?? null,
        ]));
    }
}
