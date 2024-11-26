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

        // ID
        $sheet1->setCellValue('A1', 'ID');
        $sheet1->setCellValue('B1', $data['id']);

        // UUID
        $sheet1->setCellValue('A2', 'UUID');
        $sheet1->setCellValue('B2', $data['uuid']);

        // IssueDate
        $sheet1->setCellValue('A3', 'IssueDate');
        $sheet1->setCellValue('B3', $data['issueDate']);

        // IssueTime
        $sheet1->setCellValue('A4', 'IssueTime');
        $sheet1->setCellValue('B4', $data['issueTime']);

        // Supplier info
        $sheet1->setCellValue('A5', 'Supplier');
        $sheet1->setCellValue('A6', 'Name');
        $sheet1->setCellValue('B6', $data['supplier']['companyName']);


        $sheet1->setCellValue('A7', 'City');
        $sheet1->setCellValue('B7', $data['supplier']['city']);

        $sheet1->setCellValue('A8', 'Street');
        $sheet1->setCellValue('B8', $data['supplier']['street']);

        // Supplier info
        $sheet1->setCellValue('A9', 'Customer');
        $sheet1->setCellValue('A10', 'Name');
        $sheet1->setCellValue('B10', $data['customer']['companyName']);


        $sheet1->setCellValue('A11', 'City');
        $sheet1->setCellValue('B11', $data['customer']['city']);

        $sheet1->setCellValue('A12', 'Street');
        $sheet1->setCellValue('B12', $data['customer']['street']);

        // Next sheet
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('Items');

        $row = 1;
        $sheet2->setCellValue("A$row", 'ID');
        $sheet2->setCellValue("B$row", 'Name');
        $sheet2->setCellValue("C$row", 'Quantity');
        $sheet2->setCellValue("D$row", 'Price');
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
