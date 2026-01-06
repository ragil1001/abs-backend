<?php

namespace App\Http\Controllers;

use App\Models\ShiftProject;
use Illuminate\Http\Request;

class ShiftProjectController extends Controller
{
    public function index()
    {
        $shiftProjects = ShiftProject::with('project')->get();
        return response()->json([
            'success' => true,
            'data' => $shiftProjects
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'project_id' => 'required|exists:projects,id',
            'kode' => 'required|string|max:50',
            'waktu_mulai' => 'required|date_format:H:i',
            'waktu_selesai' => 'required|date_format:H:i|after:waktu_mulai'
        ]);

        $shiftProject = ShiftProject::create($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Shift project created successfully',
            'data' => $shiftProject->load('project')
        ], 201);
    }

    public function show(ShiftProject $shiftProject)
    {
        return response()->json([
            'success' => true,
            'data' => $shiftProject->load('project')
        ]);
    }

    public function update(Request $request, ShiftProject $shiftProject)
    {
        $request->validate([
            'project_id' => 'required|exists:projects,id',
            'kode' => 'required|string|max:50',
            'waktu_mulai' => 'required|date_format:H:i',
            'waktu_selesai' => 'required|date_format:H:i|after:waktu_mulai'
        ]);

        $shiftProject->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Shift project updated successfully',
            'data' => $shiftProject->load('project')
        ]);
    }

    public function destroy(ShiftProject $shiftProject)
    {
        $shiftProject->delete();

        return response()->json([
            'success' => true,
            'message' => 'Shift project deleted successfully'
        ]);
    }

    // Method untuk mendapatkan shifts berdasarkan project
    public function getByProject($projectId)
    {
        $shifts = ShiftProject::where('project_id', $projectId)->get();
        
        return response()->json([
            'success' => true,
            'data' => $shifts
        ]);
    }
}