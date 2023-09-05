<?php

namespace App\Http\Controllers\Dashboard\Sacco;

use App\Http\Controllers\Controller;
use App\Models\RouteStage;
use App\Models\SaccoUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Yajra\DataTables\DataTables;

class SaccoUserController extends Controller
{
    public function __construct(){
        $this->middleware('auth');
    }
    public function addSaccoUser(Request $request): JsonResponse|RedirectResponse
    {
        $validator = $this->validateSaccoUser($request);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }
        $saccoUserExists = SaccoUser::where('user_id', '=', $request->input('member'))->exists();
        if ($saccoUserExists) {
            $saccoUser = SaccoUser::where('user_id', '=', $request->input('member'))->first();
        } else {
            $saccoUser = new SaccoUser();
        }
        $saccoUser->user_id = $request->input('member');
        $saccoUser->sacco_id = $request->input('sacco_id');
        $saccoUser->date_left = $request->input('date_left');
        $saccoUser->status = $request->input('status') ? $request->input('status') : 1;
        if ($saccoUser->save()) {
            return redirect()->back()->with('success', 'Sacco User added successfully');
        } else {
            return redirect()->back()->with('error', 'Failed to update Sacco User');
        }
    }

    public function getSaccoUsers($id): JsonResponse
    {
        $saccoRoutes = SaccoUser::with([
            'user_id',
            'sacco_id' => function ($query) use ($id) {
                $query->where('id','=', $id);
            },
        ]);

        return DataTables::of($saccoRoutes)
            ->addColumn('action', function ($row) {
                $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                    '<span class="d-none id">' . $row->id . '</span>' .
                    '<span class="d-none route">' . $row->user_id . '</span>' .
                    '<span class="d-none sacco">' . $row->sacco_id . '</span>' .
                    '<span class="d-none date_left">' . $row->date_left . '</span>' .
                    '<span class="d-none status">' . $row->status . '</span>' .
                    '<button class="btn-edit btn btn-danger btn-sm" data-toggle="modal" data-target="#vehicleModal"> Remove </button> '
                    . '</div>';
                return $actionBtn;
            })->addIndexColumn()->escapeColumns([])->make();
    }

    public function removeSaccoUser($id)
    {
        $saccoUser = SaccoUser::find($id)->update(['sacco_id' => 0]);
        if ($saccoUser) {
            return redirect()->back()->with('success', 'Sacco User removed successfully');
        }
        else {
            return redirect()->back()->with('error', 'Failed to remove Sacco User');
        }
    }

    private function validateSaccoUser(Request $request): \Illuminate\Validation\Validator
    {
        return Validator::make(
            $request->all(),
            [
                "sacco_id" => "required|integer|min:1",
                "member" => "required|integer|min:1",
            ]
        );
    }




}
