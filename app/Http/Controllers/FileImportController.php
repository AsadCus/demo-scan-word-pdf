<?php

namespace App\Http\Controllers;

use Imagick;
use DOMXPath;
use ZipArchive;
use DOMDocument;
use App\Models\Demo;
use App\Models\FdwSkill;
use Spatie\PdfToText\Pdf;
use App\Models\FdwProfile;
use Illuminate\Http\Request;
use App\Models\FdwMedicalHistory;
use App\Models\FdwMedicalIllness;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use Smalot\PdfParser\Parser as PdfParser;
use thiagoalessio\TesseractOCR\TesseractOCR;

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
            $spreadsheet = IOFactory::load($file->getPathname());
            $sheet = $spreadsheet->getActiveSheet()->toArray();

            foreach ($sheet as $row) {
                $data[] = $row;
            }
        } elseif ($extension === 'docx') {
            $text = $this->normalizeDocx($file->getPathname());
            $data[] = $text;

            $photoFilename = null;

            // 2. Extract first image as profile photo
            $zip = new \ZipArchive;
            if ($zip->open($file->getPathname()) === true) {
                $outputDir = storage_path('app/temp_word');
                if (!is_dir($outputDir)) mkdir($outputDir, 0777, true);

                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = $zip->getNameIndex($i);

                    if (preg_match('/word\/media\/.*\.(jpe?g|png|bmp)$/i', $entry)) {
                        $imgContent = $zip->getFromIndex($i);

                        $photoFilename = 'profile_' . pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) . '.png';
                        $photoPath = public_path($photoFilename);

                        $img = new \Imagick();
                        $img->readImageBlob($imgContent);
                        $img->setImageFormat('png');
                        $img->writeImage($photoPath);

                        break;
                    }
                }
                $zip->close();
            }

            dd([
                'filename'       => $file->getClientOriginalName(),
                'photo_saved_as' => $photoFilename,
                'extracted_text' => $text,
            ]);
        } elseif ($extension === 'pdf') {
            $useOcr = $request->has('use_ocr');

            if ($useOcr) {
                $outputDir = storage_path('app/temp_ocr');
                if (!is_dir($outputDir)) {
                    mkdir($outputDir, 0777, true);
                }

                $pdfPath = $file->getPathname();
                $imagePattern = $outputDir . '/page_%03d.png';

                $gsPath = '"C:/Program Files/gs/gs10.05.1/bin/gswin64c.exe"';

                // Convert all PDF pages into PNGs
                $cmd = "$gsPath -sDEVICE=pngalpha -o \"$imagePattern\" -r300 \"$pdfPath\"";
                exec($cmd, $out, $ret);

                if ($ret !== 0) {
                    throw new \Exception("Ghostscript failed: " . implode("\n", $out));
                }

                // Collect OCR text
                $text = "";
                $pageFiles = glob($outputDir . '/page_*.png');
                sort($pageFiles);

                $photoFilename = null;
                // $photoFilenamePoppler = null;
                // $photoFilenameImagick = null;

                foreach ($pageFiles as $i => $pagePath) {
                    $ocr = (new TesseractOCR($pagePath))->lang('eng')->run();
                    $text .= "=== PAGE " . ($i + 1) . " ===\n" . $ocr . "\n\n";

                    // Handle profile photo only on first page
                    if ($i === 0) {
                        // // testing //
                        // $baseName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                        // // $photoFilename = 'profile_' . $baseName . '.png';
                        // // $photoPath = public_path($photoFilename);

                        // // Option 1: Use pdfimages (preferred, raw embedded image)
                        // $photoFilenamePoppler = 'profile_poppler_' . $baseName . '.png';
                        // $photoPathPoppler = public_path($photoFilenamePoppler);

                        // $pdfImagesCmd = "pdfimages -png \"$pdfPath\" \"$outputDir/pdfimage\"";
                        // exec($pdfImagesCmd, $imgOut, $imgRet);

                        // $extracted = glob($outputDir . '/pdfimage-*.png');
                        // copy($extracted[0], $photoPathPoppler);

                        // // Option 2: Imagick auto-crop fallback
                        // $photoFilenameImagick = 'profile_imagick_' . $baseName . '.png';
                        // $photoPathImagick = public_path($photoFilenameImagick);

                        // $img = new \Imagick($pagePath);
                        // $width = $img->getImageWidth();
                        // $height = $img->getImageHeight();

                        // $cropWidth = 640;
                        // $cropHeight = 980;
                        // $x = $width - $cropWidth - 152;
                        // $y = 345;
                        // $img->cropImage($cropWidth, $cropHeight, $x, $y);

                        // $img->writeImage($photoPathImagick);

                        $baseName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                        $photoFilename = 'profile_' . $baseName . '.png';
                        $photoPath = public_path($photoFilename);

                        // poppler first
                        $pdfImagesCmd = "pdfimages -png \"$pdfPath\" \"$outputDir/pdfimage\"";
                        exec($pdfImagesCmd, $imgOut, $imgRet);

                        $extracted = glob($outputDir . '/pdfimage-*.png');

                        if ($imgRet === 0 && !empty($extracted)) {
                            copy($extracted[0], $photoPath);
                        } else {
                            // fallback
                            $img = new \Imagick($pagePath);
                            $width = $img->getImageWidth();
                            $height = $img->getImageHeight();

                            $cropWidth = 640;
                            $cropHeight = 980;
                            $x = $width - $cropWidth - 152;
                            $y = 345;
                            $img->cropImage($cropWidth, $cropHeight, $x, $y);
                            $img->writeImage($photoPath);
                        }
                    }
                }

                dd([
                    'filename'      => $file->getClientOriginalName(),
                    'photo_saved_as' => $photoFilename,
                    'extracted_text' => $text,
                ]);
            } else {
                // Use normal parser

                $parser = new PdfParser();
                $pdf = $parser->parseFile($file->getPathname());
                $text = $pdf->getText();

                $details = $pdf->getDetails();
                $producer = $details['Producer'] ?? '';
                $creator  = $details['Creator'] ?? '';

                $isWpsPdf = stripos($producer, 'wps') !== false
                    || stripos($creator, 'wps') !== false
                    || preg_match('/[a-z]{2,}[A-Z]{2,}/', $text);

                if ($isWpsPdf) {
                    $text = str_replace(
                        ["", "", ""],
                        ["☐", "☒", "☑"],
                        $text
                    );

                    $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
                    $text = str_replace(["\xC2\xA0", "\xE2\x80\x8B", "\xEF\xBB\xBF"], ' ', $text);

                    $text = preg_replace('/([:;,.])([A-Za-z0-9])/', '$1 $2', $text);

                    $patterns = [
                        '/([a-z])([A-Z])/'      => '$1 $2',
                        '/([A-Z])([A-Z][a-z])/' => '$1 $2',
                        '/([a-zA-Z])([0-9])/'   => '$1 $2',
                        '/([0-9])([a-zA-Z])/'   => '$1 $2',
                    ];
                    foreach ($patterns as $p => $r) {
                        $text = preg_replace($p, $r, $text);
                    }

                    $text = preg_replace('/\b(\d{1,2})\s*YO\b/i', '$1 YEARS OLD', $text);

                    $text = preg_replace('/(\([A-E]\))/', "\n$1", $text); // sections
                    $text = preg_replace('/(\d{1,2}\.)/', "\n$1", $text); // numbered Qs
                    $text = preg_replace('/A-\d/', "\n$0", $text);

                    $text = preg_replace('/\s+/', ' ', $text);
                    $text = preg_replace('/\n\s+/', "\n", $text);
                }
            }

            $data[] = trim($text);
        }

        foreach ($data as $row) {
            Demo::create([
                'content' => is_array($row) ? json_encode($row) : $row,
            ]);
        }

        $text = preg_replace('/\s+/', ' ', $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');

        preg_match('/Name:\s*(.*?)\s*2\./', $text, $nameMatch);
        preg_match('/Date of birth:\s*(.*?)\s*Age:/', $text, $dobMatch);
        preg_match('/Age:\s*([0-9]+)\s*YEARS/i', $text, $ageMatch);
        preg_match('/Place of birth:\s*(.*?)\s*4\./', $text, $pobMatch);
        preg_match('/Height & weight:\s*([0-9]+)\s*cm\s*([0-9]+)\s*kg/i', $text, $hwMatch);
        preg_match('/Nationality:\s*(.*?)\s*6\./i', $text, $nationalityMatch);
        preg_match('/Residential address in home country:\s*(.*?)\s*7\./i', $text, $addressMatch);
        preg_match('/Name of port \/ airport to be repatriated to:\s*(.*?)\s*8\./i', $text, $repatriationMatch);
        preg_match('/Contact number in home country:\s*(.*?)\s*9\./i', $text, $contactMatch);
        preg_match('/Religion:\s*(.*?)\s*10\./i', $text, $religionMatch);
        preg_match('/Education level:\s*(.*?)\s*11\./i', $text, $educationMatch);
        preg_match('/Number of siblings:\s*(.*?)\s*12\./i', $text, $siblingsMatch);
        preg_match('/Marital status:\s*(.*?)\s*13\./i', $text, $maritalMatch);
        preg_match('/Age\(s\) of children \(if any\)\s*:\s*(.*?)\s*A2/i', $text, $childrenMatch);

        $childrenAgesRaw = trim($childrenMatch[1] ?? '');
        $childrenAgesNormalized = str_ireplace(['YO', 'Y.O.', 'YRS'], 'YEARS OLD', $childrenAgesRaw);
        $childrenAgesNormalized = preg_replace('/\s+AND\s+/i', ', ', $childrenAgesNormalized);

        preg_match_all('/(\d{1,2})\s*YEARS? OLD/i', $childrenAgesNormalized, $ageMatches);

        $childrenAges = $ageMatches[1] ?? [];
        $childrenCount = count($childrenAges);

        $profile = [
            'name'            => $nameMatch[1] ?? null,
            'dob'             => $dobMatch[1] ?? null,
            'age'             => $ageMatch[1] ?? null,
            'birth_place'     => $pobMatch[1] ?? null,
            'height'          => $hwMatch[1] ?? null,
            'weight'          => $hwMatch[2] ?? null,
            'nationality'     => $nationalityMatch[1] ?? null,
            'address'         => $addressMatch[1] ?? null,
            'repatriation_to' => $repatriationMatch[1] ?? null,
            'contact_number'  => $contactMatch[1] ?? null,
            'religion'        => $religionMatch[1] ?? null,
            'education'       => $educationMatch[1] ?? null,
            'siblings'        => $siblingsMatch[1] ?? null,
            'marital_status'  => $maritalMatch[1] ?? null,
            'children'        => $childrenCount,
            'children_ages'   => json_encode($childrenAges),
        ];

        $fdw = FdwProfile::create($profile);

        preg_match('/14\.\s*Allergies \(if any\):\s*(.*?)\s*15\./i', $text, $allergyMatch);
        $allergies = trim($allergyMatch[1] ?? '');

        preg_match('/16\.\s*Physical disabilities:\s*(.*?)\s*17\./i', $text, $disabilitiesMatch);
        $physicalDisabilities = trim($disabilitiesMatch[1] ?? '');

        preg_match('/17\.\s*Dietary restrictions:\s*(.*?)\s*18\./i', $text, $dietaryMatch);
        $dietaryRestrictions = trim($dietaryMatch[1] ?? '');

        $foodSelections = [];
        $foodOptions = [
            'No pork',
            'No beef',
            'Others',
        ];

        foreach ($foodOptions as $option) {
            if ($option === 'Others') {
                if (preg_match('/☒\s*Others:\s*(.*?)(\s|$)/i', $text, $match)) {
                    $foodSelections[] = "Others: " . trim($match[1]);
                }
            } else {
                $pattern = '/(☒|☐)\s*' . preg_quote($option, '/') . '/i';
                if (preg_match($pattern, $text, $match) && $match[1] === '☒') {
                    $foodSelections[] = $option;
                }
            }
        }

        $foodPreferences = implode(', ', $foodSelections);

        $medicalHistories = [
            'fdw_id'  => $fdw->id,
            'allergies' => $allergies,
            'physical_disabilities' => $physicalDisabilities,
            'dietary_restrictions' => $dietaryRestrictions,
            'food_preferences' => $foodPreferences,
        ];
        $illnessesFound = [];
        $illnessList = [
            'Mental illness',
            'Epilepsy',
            'Asthma',
            'Diabetes',
            'Hypertension',
            'Tuberculosis',
            'Heart disease',
            'Malaria',
            'Operations',
            'Others',
        ];

        foreach ($illnessList as $illness) {
            $pattern = '/' . preg_quote($illness, '/') . '\s*(☒|☐)?\s*(☒|☐)?/i';

            if (preg_match($pattern, $text, $match)) {
                $yesBox = $match[1] ?? '☐';

                if ($yesBox === '☒') {
                    $illnessesFound[] = $illness;
                }

                if (strtolower($illness) === 'others' && $yesBox === '☒') {
                    preg_match('/Others:\s*(.*?)\s*16\./i', $text, $othersMatch);
                    if (!empty($othersMatch[1])) {
                        $illnessesFound[] = trim($othersMatch[1]);
                    }
                }
            }
        }

        FdwMedicalHistory::create($medicalHistories);

        if ($illnessesFound) {
            foreach ($illnessesFound as $illness) {
                FdwMedicalIllness::create([
                    'fdw_id'  => $fdw->id,
                    'illness' => $illness,
                ]);
            }
        }

        return redirect('/demo')->with('success', $data);
    }

    private function normalizeDocx(string $path): string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== TRUE) {
            throw new \Exception("Unable to open DOCX file: $path");
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        $doc = new DOMDocument();
        $doc->loadXML($xml);

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $textParts = [];

        // --- 1. Paragraphs ---
        $paragraphs = $xpath->query('//w:p');
        foreach ($paragraphs as $p) {
            $pText = '';
            $runs = $p->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 't');
            foreach ($runs as $r) {
                $pText .= $r->nodeValue; // no extra spaces inside runs
            }
            if (trim($pText) !== '') {
                $textParts[] = $pText;
            }
        }

        // --- 2. Checkboxes ---
        $checkboxes = $xpath->query('//w:checkBox');
        foreach ($checkboxes as $cb) {
            $checked = $cb->getElementsByTagNameNS(
                'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
                'checked'
            );
            if ($checked->length > 0 && $checked->item(0)->getAttribute('w:val') === '1') {
                $textParts[] = '☒';
            } else {
                $textParts[] = '☐';
            }
        }

        // --- 3. Text content controls ---
        $sdts = $xpath->query('//w:sdt');
        foreach ($sdts as $sdt) {
            $alias = $sdt->getElementsByTagNameNS(
                'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
                'alias'
            );
            $textNode = $sdt->getElementsByTagNameNS(
                'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
                't'
            );

            $fieldName = $alias->length > 0 ? $alias->item(0)->getAttribute('w:val') : null;
            $fieldValue = $textNode->length > 0 ? $textNode->item(0)->nodeValue : null;

            if ($fieldName && $fieldValue) {
                $textParts[] = "$fieldName: $fieldValue";
            }
        }

        // --- 4. Join paragraphs ---
        $text = implode("\n", $textParts); // newline between paragraphs
        $text = preg_replace('/\s+/', ' ', $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');

        return trim($text);
    }
}
