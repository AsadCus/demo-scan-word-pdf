<?php

namespace App\Http\Controllers;

use App\Models\Demo;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use Smalot\PdfParser\Parser as PdfParser;

class FileImportController extends Controller
{
    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,csv,docx,pdf',
        ]);

        $file = $request->file('file');
        $extension = $file->getClientOriginalExtension();

        $data = [];

        if ($extension === 'xlsx' || $extension === 'csv') {
            // Excel reader
            $spreadsheet = IOFactory::load($file->getPathname());
            $sheet = $spreadsheet->getActiveSheet()->toArray();

            foreach ($sheet as $row) {
                $data[] = $row;
            }
        } elseif ($extension === 'docx') {
            // Word reader
            $phpWord = WordIOFactory::load($file->getPathname(), 'Word2007');
            $text = '';
            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    if (method_exists($element, 'getText')) {
                        $text .= $element->getText() . ' ';
                    }
                }
            }
            $data[] = ['text' => $text];
        } elseif ($extension === 'pdf') {
            // PDF reader
            $parser = new PdfParser();
            $pdf = $parser->parseFile($file->getPathname());
            $text = $pdf->getText();

            $data[] = ['text' => $text];
        }

        // Example: Store in DB
        foreach ($data as $row) {
            Demo::create([
                'content' => is_array($row) ? json_encode($row) : $row['text'],
            ]);
        }

        return redirect('/demo')->with('success', $data);
    }
}
