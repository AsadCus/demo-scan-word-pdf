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
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;
use thiagoalessio\TesseractOCR\TesseractOCR;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;

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
            $photoFilename = null;

            $zip = new \ZipArchive;
            if ($zip->open($file->getPathname()) === true) {
                $outputDir = storage_path('app/temp_word');
                if (!is_dir($outputDir)) mkdir($outputDir, 0777, true);

                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = $zip->getNameIndex($i);

                    if (preg_match('/word\/media\/.*\.(jpe?g|png|bmp)$/i', $entry)) {
                        $imgContent = $zip->getFromIndex($i);

                        // Generate filename
                        $photoFilename = 'fdw_photo_' . pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) . '.png';

                        // Convert with Imagick
                        $img = new \Imagick();
                        $img->readImageBlob($imgContent);
                        $img->setImageFormat('png');

                        // Save inside storage/app/public/fdw_photos/
                        $relativePath = 'fdw_photos/' . $photoFilename;
                        Storage::disk('public')->put($relativePath, $img->getImageBlob());

                        // Save URL into your data
                        $photoUrl = Storage::url($relativePath);

                        break;
                    }
                }
                $zip->close();
            }

            // dd([
            //     'filename'       => $file->getClientOriginalName(),
            //     'photo_saved_as' => $photoFilename,
            //     'extracted_text' => $text,
            // ]);
            $data[] = $text;
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

                $cmd = "$gsPath -sDEVICE=pngalpha -o \"$imagePattern\" -r300 \"$pdfPath\"";
                exec($cmd, $out, $ret);

                if ($ret !== 0) {
                    throw new \Exception("Ghostscript failed: " . implode("\n", $out));
                }

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
                $text = $this->normalizePdf($file->getPathname());

                // $details = $pdf->getDetails();
                // $producer = $details['Producer'] ?? '';
                // $creator  = $details['Creator'] ?? '';

                // $isWpsPdf = stripos($producer, 'wps') !== false
                //     || stripos($creator, 'wps') !== false
                //     || preg_match('/[a-z]{2,}[A-Z]{2,}/', $text);

                // if ($isWpsPdf) {
                //     $text = str_replace(
                //         ["", "", ""],
                //         ["☐", "☒", "☑"],
                //         $text
                //     );

                //     $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
                //     $text = str_replace(["\xC2\xA0", "\xE2\x80\x8B", "\xEF\xBB\xBF"], ' ', $text);

                //     $text = preg_replace('/([:;,.])([A-Za-z0-9])/', '$1 $2', $text);

                //     $patterns = [
                //         '/([a-z])([A-Z])/'      => '$1 $2',
                //         '/([A-Z])([A-Z][a-z])/' => '$1 $2',
                //         '/([a-zA-Z])([0-9])/'   => '$1 $2',
                //         '/([0-9])([a-zA-Z])/'   => '$1 $2',
                //     ];
                //     foreach ($patterns as $p => $r) {
                //         $text = preg_replace($p, $r, $text);
                //     }

                //     $text = preg_replace('/\b(\d{1,2})\s*YO\b/i', '$1 YEARS OLD', $text);

                //     $text = preg_replace('/(\([A-E]\))/', "\n$1", $text); // sections
                //     $text = preg_replace('/(\d{1,2}\.)/', "\n$1", $text); // numbered Qs
                //     $text = preg_replace('/A-\d/', "\n$0", $text);

                //     $text = preg_replace('/\s+/', ' ', $text);
                //     $text = preg_replace('/\n\s+/', "\n", $text);
                // }
            }

            $data[] = $text;
        }

        foreach ($data as $row) {
            Demo::create([
                'content' => is_array($row) ? json_encode($row) : $row,
            ]);
        }

        // Profile info
        preg_match('/Name:\s*([^\n]+?)\s*Date of birth:/i', $text, $nameMatch);
        preg_match('/Date of birth:\s*([0-9\/-]+)\s*Place of birth:/i', $text, $dobMatch);
        preg_match('/Age:\s*([0-9]+)/i', $text, $ageMatch);
        preg_match('/Place of birth:\s*([^\n]+?)\s*Height/i', $text, $pobMatch);
        preg_match('/Height.*?:\s*([0-9]+)\s*cm\s*&\s*weight:\s*([0-9]+)\s*kg/i', $text, $hwMatch);
        preg_match('/Nationality:\s*([^\n]+?)\s*Address:/i', $text, $nationalityMatch);
        preg_match('/Address:\s*([^\n]+?)\s*Name of port/i', $text, $addressMatch);
        preg_match('/Name of port \/ airport to be repatriated to:\s*([^\n]+?)\s*Contact number/i', $text, $repatriationMatch);
        preg_match('/Contact number in home country:\s*([^\n]+?)\s*Religion:/i', $text, $contactMatch);
        preg_match('/Religion:\s*([^\n]+?)\s*Education/i', $text, $religionMatch);
        preg_match('/Education level:\s*([^\n]+?)\s*Number of siblings:/i', $text, $educationMatch);
        preg_match('/Number of siblings:\s*([0-9]+)/i', $text, $siblingsMatch);
        preg_match('/Marital status:\s*([^\n]+?)\s*Number of children:/i', $text, $maritalMatch);
        preg_match('/Number of children:\s*([0-9]+)/i', $text, $childrenCountMatch);
        preg_match('/Age\(s\) of children.*?:\s*(.*?)\s*Photo Profile/i', $text, $childrenMatch);

        // Normalize children ages
        $childrenAgesRaw = trim($childrenMatch[1] ?? '');
        $childrenAgesNormalized = preg_replace('/\s*AND\s*/i', ', ', $childrenAgesRaw);
        $childrenAgesNormalized = str_ireplace(
            ['YO', 'Y.O.', 'YRS', 'YEARS OLD', 'YEAR OLD', 'YRS OLD'],
            '',
            $childrenAgesNormalized
        );
        preg_match_all('/\d{1,2}/', $childrenAgesNormalized, $ageMatches);
        $childrenAges = array_map('intval', $ageMatches[0] ?? []);
        $childrenCount = count($childrenAges);

        $profile = [
            'name'            => $nameMatch[1] ?? null,
            'photo_profile'   => $photoUrl ?? null,
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

        // Medical history
        preg_match('/Allergies\s*\(if any\)\s*:\s*([^\n]+?)(\s*Past|15\.|Physical|$)/i', $text, $allergyMatch);
        $allergies = trim($allergyMatch[1] ?? '');

        preg_match('/Physical disabilities\s*:\s*([^\n]+?)(\s*Dietary|17\.|Food|$)/i', $text, $disabilitiesMatch);
        $physicalDisabilities = trim($disabilitiesMatch[1] ?? '');

        preg_match('/Dietary restrictions\s*:\s*([^\n]+?)(\s*Food|18\.|A3|$)/i', $text, $dietaryMatch);
        $dietaryRestrictions = trim($dietaryMatch[1] ?? '');

        // Food preferences
        $foodSelections = [];
        $foodOptions = ['No pork', 'No beef', 'Others'];

        foreach ($foodOptions as $option) {
            if ($option === 'Others') {
                if (preg_match('/(☒|X)\s*Others\s*:\s*([^\n]+)/i', $text, $match)) {
                    $foodSelections[] = trim($match[2]);
                }
            } else {
                $pattern = '/(☒|X)\s*' . preg_quote($option, '/') . '/i';
                if (preg_match($pattern, $text)) {
                    $foodSelections[] = $option;
                }
            }
        }

        $foodPreferences = implode(', ', $foodSelections);

        // Save medical history
        $medicalHistories = [
            'fdw_id'               => $fdw->id,
            'allergies'            => $allergies,
            'physical_disabilities' => $physicalDisabilities,
            'dietary_restrictions' => $dietaryRestrictions,
            'food_preferences'     => $foodPreferences,
        ];

        // Illnesses
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
            $pattern = '/' . preg_quote($illness, '/') . '\s*(☒|X|☐)?/i';
            if (preg_match($pattern, $text, $match)) {
                if (($match[1] ?? '☐') === '☒' || strtoupper($match[1] ?? '') === 'X') {
                    if (strtolower($illness) === 'others') {
                        if (preg_match('/Others\s*:\s*([^\n]+)/i', $text, $othersMatch)) {
                            $illnessesFound[] = trim($othersMatch[1]);
                        }
                    } else {
                        $illnessesFound[] = $illness;
                    }
                }
            }
        }

        FdwMedicalHistory::create($medicalHistories);

        foreach ($illnessesFound as $illness) {
            FdwMedicalIllness::create([
                'fdw_id'  => $fdw->id,
                'illness' => $illness,
            ]);
        }

        // --- Sections ---
        preg_match('/\(A\).*?\(B\)/s', $text, $sectionA);
        preg_match('/\(B\).*?\(C\)/s', $text, $skillsSection);
        preg_match('/\(C\).*?\(D\)/s', $text, $employmentSection);
        preg_match('/\(D\).*?\(E\)/s', $text, $availabilitySection);
        preg_match('/\(E\).*?(FDW Name|I have gone)/s', $text, $remarksSection);

        // --- Inside (A): split Profile & Medical ---
        preg_match('/A1.*?(A2|A3|Photo Profile)/s', $sectionA[0] ?? '', $profileSection);
        preg_match('/A2.*?(A3|19\.|Preference)/s', $sectionA[0] ?? '', $medicalSection);
        preg_match('/A3.*?$/s', $sectionA[0] ?? '', $otherSection);

        // --- Clean up ---
        $profileText   = trim($profileSection[0] ?? '');
        $medicalText   = trim($medicalSection[0] ?? '');
        $otherText     = trim($otherSection[0] ?? '');
        $skillsText    = trim($skillsSection[0] ?? '');
        $employmentText = trim($employmentSection[0] ?? '');
        $availabilityText = trim($availabilitySection[0] ?? '');
        $remarksText   = trim($remarksSection[0] ?? '');

        $data = [
            'profile'     => $profileText,
            'medical'     => $medicalText,
            'other'       => $otherText,
            'skills'      => $skillsText,
            'employment'  => $employmentText,
            'availability' => $availabilityText,
            'remarks'     => $remarksText,
        ];

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

        // --- Extract paragraphs ---
        $paragraphs = $xpath->query('//w:p');
        foreach ($paragraphs as $p) {
            $pText = '';
            $runs = $p->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 't');
            foreach ($runs as $r) {
                $pText .= $r->nodeValue;
            }
            if (trim($pText) !== '') {
                $textParts[] = trim($pText);
            }
        }

        // --- Extract checkboxes ---
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

        // --- Join with newlines ---
        $text = implode("\n", $textParts);

        // --- Normalize like PDF ---
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\s*\n\s*/', "\n", $text);
        $text = preg_replace('/(\d{1,2})\.\s*/', '$1. ', $text);
        $text = preg_replace('/(\([A-E]\))/', "\n$1", $text);
        $text = preg_replace('/:{2,}/', ':', $text);

        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');

        return trim($text);
    }

    private function normalizePdf(string $path): string
    {
        $parser = new PdfParser();
        $pdf = $parser->parseFile($path);
        $text = $pdf->getText();

        // Collapse whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\s*\n\s*/', "\n", $text);
        $text = trim($text);

        // Remove page markers like "A-1"
        $text = preg_replace('/A-\d+\s*/', '', $text);

        // Fix label variations
        $replacements = [
            'Height:'            => 'Height & weight:',
            '& Weight:'          => '& weight:',
            'Education Level'    => 'Education level:',
            'Marital status'     => 'Marital status:',
            'Number of children' => 'Number of children:',
            'Age(s) of children (if any)' => 'Age(s) of children (if any):',
            'Allergies (if any) :' => 'Allergies (if any):',
            'Employment  HISTORY' => 'Employment history',
            'Hearth disease'     => 'Heart disease',
        ];
        $text = str_ireplace(array_keys($replacements), array_values($replacements), $text);

        // Normalize checkboxes
        $text = str_replace(["", "", "", "✓"], ["☐", "☒", "☑", "☑"], $text);

        // Standardize numbering & sections
        $text = preg_replace('/^\d+\.\s*/m', '', $text);
        $text = preg_replace('/(\d{1,2})\.\s*/', '$1. ', $text);
        $text = preg_replace('/(\([A-E]\))/', "\n$1", $text);
        $text = preg_replace('/:{2,}/', ':', $text);

        return trim($text);
    }
}
