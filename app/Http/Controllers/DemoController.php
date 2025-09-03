<?php

namespace App\Http\Controllers;

use App\Models\Demo;
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
            $data = Demo::select(['id', 'content', 'created_at']);
            return DataTables::of($data)
                ->addIndexColumn()
                ->editColumn('content', function ($row) {
                    return strlen($row->content) > 100
                        ? substr($row->content, 0, 100) . '...'
                        : $row->content;
                })
                ->make(true);
        }
    }
}
