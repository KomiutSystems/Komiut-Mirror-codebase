<?php

namespace App\Http\Controllers\APIs\Dashboard\Routes;

use App\Http\Controllers\Controller;
use App\Models\Place;
use App\Models\SaccoTerminus;
use App\Models\Terminus;
use App\Models\TerminusUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TerminusAPIController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function getTermini(Request $request)
    {
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $termini = Terminus::with('place')->when(filled($request->search), fn ($q) => $q->where('name', 'LIKE', '%'.$request->search.'%'));
        $terminiIds = TerminusUser::where('user_id', auth()->user()->id)->pluck('terminus_id');
        if (count($terminiIds) > 0) {
            $termini = $termini->whereIn('id', $terminiIds);
        }
        if(auth()->user()->sacco_id > 0){
            $termini = $termini->whereIn('id', SaccoTerminus::where('sacco_id', auth()->user()->sacco_id)->pluck('terminus_id'));
        }
        $termini = $termini->skip($offset)->take(20)
            ->orderBy('name', 'ASC')->get();
        return response()->json(['termini' => $termini]);
    }
    public function addTerminus(Request $request)
    {
        if (auth()->user()->can('Edit Termini') || auth()->user()->can('Add Termini')) {
            $validator = Validator::make($request->all(), [
                'id' => 'required|integer|min:0',
                'name' => 'required|string',
                'place' => 'required|string',
                'status' => 'required|integer|min:0|max:1',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            $place = Place::where('name', $request->place)->first();
            if ($place == null) {
                return response()->json(['error' => 'Invalid place name provided!'], 401);
            }
            if (
                Terminus::where('name', $request->name)->where('place_id', $place->id)
                    ->where('id', '<>', $request->id)->count() > 0
            ) {
                return response()->json(['error' => 'Terminus already exists'], 401);
            }
            $terminus = new Terminus();
            if ($request->id > 0) {
                $terminus = Terminus::findOrFail($request->id);
            }
            $terminus->name = $request->name;
            $terminus->place_id = $place->id;
            $terminus->status = $request->status;
            if ($terminus->save()) {
                return response()->json(['success' => "Terminus updated successfully!"]);
            } else {
                return response()->json(['error' => 'Unable to update terminus'], 401);
            }
        } else {
            return response()->json(['error' => 'You do not have permissions for this action'], 401);
        }
    }
}
