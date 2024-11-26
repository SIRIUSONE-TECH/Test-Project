<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use Smalot\PdfParser\Parser;
use Smalot\PdfParser\Element\ElementName;
use Smalot\PdfParser\Element\ElementString;
use Smalot\PdfParser\Header;

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

        file_put_contents('dump.xml', $xml);

        // Parse XML
        $xml = simplexml_load_string($xml);

        if ($xml === false) {
            return response()->json([
                'message' => 'Cannot read XML data'
            ], 400);
        }

        return $xml;
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
}
