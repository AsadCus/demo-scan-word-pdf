<?php

namespace App\Http\Controllers;

use App\Models\Demo;
use App\Models\FdwSkill;
use App\Models\FdwProfile;
use Illuminate\Http\Request;
use App\Models\FdwMedicalHistory;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;
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
            $data[] = $text;
            // dd($text);
        } elseif ($extension === 'pdf') {
            // PDF reader
            $parser = new PdfParser();
            $pdf = $parser->parseFile($file->getPathname());
            $text = $pdf->getText();

            $data[] = $text;
            // dd($text);
        }

        // Example: Store in DB
        foreach ($data as $row) {
            Demo::create([
                'content' => is_array($row) ? json_encode($row) : $row,
            ]);
        }

        // Normalize whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');

        // Extract profile info
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

        // // Example: extract medical illnesses block
        // preg_match('/Past and existing illnesses.*?16\./', $text, $illnessBlock);
        // $medical = [
        //     'mental_illness' => str_contains($illnessBlock[0] ?? '', 'Mental illness'),
        //     'epilepsy' => str_contains($illnessBlock[0] ?? '', 'Epilepsy'),
        //     'tuberculosis' => str_contains($illnessBlock[0] ?? '', 'Tuberculosis'),
        //     'heart_disease' => str_contains($illnessBlock[0] ?? '', 'Heart disease'),
        //     // etc...
        // ];

        // // Extract skills (you could regex table rows like "1. Care of elderly YES Yes, Tanggerang ...")
        // preg_match('/1\. Care of infants\/children.*?C\)/', $text, $skillsBlock);
        // $skills = [];
        // if (!empty($skillsBlock)) {
        //     preg_match_all('/([0-9]+)\.\s*(.*?)\s+YES|NO/i', $skillsBlock[0], $rows, PREG_SET_ORDER);
        //     foreach ($rows as $row) {
        //         $skills[] = [
        //             'area' => trim($row[2]),
        //             'willingness' => str_contains($row[0], 'YES') ? 1 : 0,
        //             // add parsing for experience, years, observations
        //         ];
        //     }
        // }

        // Example: Save FDW profile
        $fdw = FdwProfile::create($profile);

        // // Save medical history
        // foreach ($medical as $illness => $val) {
        //     FdwMedicalHistory::create([
        //         'fdw_id' => $fdw->id,
        //         'illness' => $illness,
        //         'has_condition' => $val,
        //     ]);
        // }

        // // Save skills
        // foreach ($skills as $s) {
        //     FdwSkill::create([
        //         'fdw_id' => $fdw->id,
        //         'area' => $s['area'],
        //         'willingness' => $s['willingness'],
        //     ]);
        // }

        return redirect('/demo')->with('success', $profile);
    }
}
