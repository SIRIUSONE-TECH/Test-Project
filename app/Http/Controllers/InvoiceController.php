<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

use Smalot\PdfParser\Parser;
use Smalot\PdfParser\Element\ElementName;
use Smalot\PdfParser\Element\ElementString;
use Smalot\PdfParser\Header;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class InvoiceController extends Controller
{
    public function upload(Request $request) {
        $request->validate([
            'file' => 'required|mimes:pdf|max:1000000'
        ]);

        $path = $request->file('file')->store('uploads', 'public');
        $filePath = storage_path("app/public/{$path}");

        $xml = InvoiceController::findXmlFromPdf($filePath);
        
        if (!$xml) {
            return response()->json([
                'message' => 'The PDF file doesn\'t contain a XML invoice'
            ], 400);
        }

        // Parse XML
        $xml = simplexml_load_string((string)$xml);

        if ($xml === false) {
            return response()->json([
                'message' => 'Cannot read XML data'
            ], 400);
        }

        // register all namespaces
        foreach ($xml->getNamespaces(true) as $prefix => $url) {
            $xml->registerXPathNamespace($prefix, $url);
        } 

        $data = InvoiceController::extractHeaderFromXML($xml);

        // Create XLSX
        $spreadsheet = new Spreadsheet();

        $sheet1 = $spreadsheet->getActiveSheet();
        $sheet1->setTitle('Header Info');
        $sheet1->getColumnDimension('A')->setWidth(40);
        $sheet1->getColumnDimension('B')->setWidth(100);
        $row = 1;

        InvoiceController::fillSheet($sheet1, $row, $data);

        // Supplier info
        $sheet1->setCellValue('A5', 'Supplier');
        $row++;
        InvoiceController::fillSheet($sheet1, $row, $data['supplier']);
    

        // Customer info
        $sheet1->setCellValue('A9', 'Customer');
        $row++;
        InvoiceController::fillSheet($sheet1, $row, $data['customer']);

        // Next sheet
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('Items');

        $row = 1;
        $sheet2->setCellValue("A$row", 'ID');
        $sheet2->setCellValue("B$row", 'Name');
        $sheet2->setCellValue("C$row", 'Quantity');
        $sheet2->setCellValue("D$row", 'Price');
        $sheet2->getColumnDimension('A')->setWidth(30);
        $sheet2->getColumnDimension('B')->setWidth(100);
        $sheet2->getColumnDimension('C')->setWidth(40);
        $sheet2->getColumnDimension('D')->setWidth(40);
        $sheet2->freezePane('A2');
        $row++;

        foreach ($data['lines'] as $line) {
            $sheet2->setCellValue("A$row", $line['id']);
            $sheet2->setCellValue("B$row", $line['name']);
            $sheet2->setCellValue("C$row", $line['quantity']);
            $sheet2->setCellValue("D$row", $line['price']);
            $row++;
        }

        $writer = new Xlsx($spreadsheet);
        $xlsxPath = storage_path('app/public/' . pathinfo(basename($filePath), PATHINFO_FILENAME) . '.xlsx');
        $writer->save($xlsxPath);

        $xlsxUrl = Storage::url(basename($xlsxPath));

        return response()->json([
            'message' => 'File successfully uploaded',
            'xlsxUrl' => $xlsxUrl,
        ]);
    }

    public static function fillSheet($sheet, &$row, $map) {
        foreach ($map as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            $sheet->setCellValue("A$row", ucfirst($key));
            $sheet->setCellValue("B$row", $value);
            ++$row;
        }
    }

    /**
     * Try to find XML in PDF file
     * 
     * @return the XML body, null if not found
     */
    public static function findXmlFromPdf($filePath) {
        // 0 - Filespec
        // 2 - 300187978810003_20241110T133818_12600003996.xml
        // 4 - Header -> pdfObject

        $parser = new Parser();
        $pdf = $parser->parseFile($filePath);

        foreach ($pdf->getObjects() as $object) {
            $header = $object->getHeader();

            if (!$header) {
                continue;
            }

            $elements = [];
            foreach ($header->getElements() as $element) {
                $elements[] = $element;
            }

            if (count($elements) < 5) {
                continue;
            }

            if (!($elements[0] instanceof ElementName) || !($elements[2] instanceof ElementString) || !($elements[4] instanceof Header)) {
                continue;
            }

            $fileSpec = (string)$elements[0];
            $fileName = (string)$elements[2];
            $pdfObject = array_values($elements[4]->getElements())[0];
            
            if (!preg_match("~\\.xml$~", $fileName)) {
                continue;
            }

            return $pdfObject->getContent();
        }

        return null;
    }

    public static function extractHeaderFromXML($xml) {
        $lines = [];

        foreach ($xml->xpath('cac:InvoiceLine') as $line) {
            $lines[] = [
                'id' => (string)$line->xpath('cbc:ID')[0] ?? '',
                'name' => (string)$line->xpath('cac:Item/cbc:Name')[0] ?? '',
                'quantity' => (string)$line->xpath('cbc:InvoicedQuantity')[0] ?? '',
                'price' => (string)$line->xpath('cac:Price/cbc:PriceAmount')[0] ?? '',
            ];
        }

        return [
            'id' => (string)$xml->xpath('cbc:ID')[0] ?? '',
            'uuid' => (string)$xml->xpath('cbc:UUID')[0] ?? '',
            'issueDate' => (string)$xml->xpath('cbc:IssueDate')[0] ?? '',
            'issueTime' => (string)$xml->xpath('cbc:IssueTime')[0] ?? '',
            'supplier'  => [
                'street' => (string)$xml->xpath('cac:AccountingSupplierParty/cac:Party/cac:PostalAddress/cbc:StreetName')[0] ?? '',
                'city' => (string)$xml->xpath('cac:AccountingSupplierParty/cac:Party/cac:PostalAddress/cbc:CityName')[0] ?? '',
                'companyName' => (string)$xml->xpath('cac:AccountingSupplierParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName')[0] ?? '',
            ],
            'customer' => [
                'street' => (string)$xml->xpath('cac:AccountingCustomerParty/cac:Party/cac:PostalAddress/cbc:StreetName')[0] ?? '',
                'city' => (string)$xml->xpath('cac:AccountingCustomerParty/cac:Party/cac:PostalAddress/cbc:CityName')[0] ?? '',
                'companyName' => (string)$xml->xpath('cac:AccountingCustomerParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName')[0] ?? ''
            ],
            'lines' => $lines
        ];
    }
}
