<?php
function generateSimpleInvoicePdf(int $bookingId, string $customerName, string $serviceType, float $total, string $date): string
{
    $safeName = 'invoice-' . $bookingId . '-' . date('YmdHis');
    $filename = $safeName . '.pdf';
    $uploadDir = __DIR__ . '/../uploads';

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $pdfPath = $uploadDir . DIRECTORY_SEPARATOR . $filename;

    $customer = preg_replace('/[^A-Za-z0-9 .,_-]/', '', $customerName);
    $service = preg_replace('/[^A-Za-z0-9 .,_-]/', '', $serviceType);
    $totalText = number_format($total, 2, '.', '');

    $content = "BT\n/F1 18 Tf\n50 790 Td\n(CleanManage Invoice) Tj\n0 -28 Td\n/F1 11 Tf\n(Invoice #: " . $bookingId . ") Tj\n0 -18 Td\n(Customer: " . $customer . ") Tj\n0 -18 Td\n(Service: " . $service . ") Tj\n0 -18 Td\n(Date: " . $date . ") Tj\n0 -18 Td\n(Total: R " . $totalText . ") Tj\nET\n";

    $pdf = "%PDF-1.4\n";
    $objects = [];
    $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
    $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>";
    $objects[] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
    $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";

    $offsets = [];
    $pdf .= "1 0 obj\n" . $objects[0] . "\nendobj\n";
    $offsets[] = strlen($pdf);
    $pdf .= "2 0 obj\n" . $objects[1] . "\nendobj\n";
    $offsets[] = strlen($pdf);
    $pdf .= "3 0 obj\n" . $objects[2] . "\nendobj\n";
    $offsets[] = strlen($pdf);
    $pdf .= "4 0 obj\n" . $objects[3] . "\nendobj\n";
    $offsets[] = strlen($pdf);
    $pdf .= "5 0 obj\n" . $objects[4] . "\nendobj\n";
    $offsets[] = strlen($pdf);

    $xrefStart = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf('%010d 00000 n \n', $offset);
    }
    $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" . $xrefStart . "\n%%EOF";

    file_put_contents($pdfPath, $pdf);
    return $filename;
}
