<?php

namespace App\Http\Controllers;

use App\Models\Demo;
use App\Models\FdwMedicalHistory;
use App\Models\FdwMedicalIllness;
use App\Models\FdwProfile;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;

class DemoController extends Controller
{
    public function index()
    {
        return view('demo.index');
    }

    public function getData(Request $request)
    {
        if ($request->ajax()) {
            $data = FdwProfile::all();

            return DataTables::of($data)
                ->addIndexColumn()
                ->addColumn('details', function ($row) {
                    $childrenAges = json_decode($row->children_ages, true);

                    if (!empty($childrenAges)) {
                        $childrenAges = collect($childrenAges)
                            ->map(fn($age) => $age . ' Years Old')
                            ->implode(', ');
                    }

                    $medicalHistories = FdwMedicalHistory::where('fdw_id', $row->id)->first();
                    $medicalIllness = FdwMedicalIllness::where('fdw_id', $row->id)->pluck('illness')->toArray();
                    $illnessesFormatted = !empty($medicalIllness)
                        ? implode(', ', $medicalIllness)
                        : '-';

                    return collect([
                        'Name'            => $row->name,
                        'Date of Birth'   => $row->dob,
                        'Age'             => $row->age,
                        'Birth Place'     => $row->birth_place,
                        'Height'          => $row->height ? $row->height . ' cm' : null,
                        'Weight'          => $row->weight ? $row->weight . ' kg' : null,
                        'Nationality'     => $row->nationality,
                        'Address'         => $row->address,
                        'Repatriation To' => $row->repatriation_to,
                        'Contact Number'  => $row->contact_number,
                        'Religion'        => $row->religion,
                        'Education'       => $row->education,
                        'Siblings'        => $row->siblings,
                        'Marital Status'  => $row->marital_status,
                        'Children'        => $row->children,
                        'Children Ages'   => $childrenAges,
                        'Allergies'       => $medicalHistories->allergies ?? null,
                        'Past and Existing Illness' => $illnessesFormatted,
                        'Physical Disabilities'     => $medicalHistories->physical_disabilities ?? null,
                        'Dietary Restrictions'      => $medicalHistories->dietary_restrictions ?? null,
                        'Food Preferances'          => $medicalHistories->food_preferences ?? null,
                    ]);
                })
                ->editColumn('created_at', function ($row) {
                    return Carbon::parse($row->created_at)->format('d F Y');
                })
                ->make(true);
        }
    }
}
