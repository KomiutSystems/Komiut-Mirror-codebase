<?php

namespace App\Http\Controllers\APIs\Dashboard\Routes;

use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Models\Place;
use App\Services\Sql\LikeSql;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PlaceAPIController extends Controller
{
    use PaginatesResults;

    public function __construct(){
        $this->middleware('auth:sanctum');
    }

    public function getPlaces(Request $request){
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $places = Place::when(filled($request->search), fn ($q) => $q->where('name', LikeSql::op(), '%'.$request->search.'%'));
        $__meta = $this->pageMeta($places, $request, 20);
        $places = $places->skip($offset)->take(20)
        ->orderBy('name', 'ASC')->get();
        return response()->json(array_merge(['places'=>$places], $__meta));
    }

    public function addPlace(Request $request){
        if(auth()->user()->can('Add Places') || auth()->user()->can('Edit Places')){
            // Coordinates are REQUIRED when creating (id == 0), optional when
            // editing an existing row.
            //
            // The columns always existed and were always accepted, but callers
            // sent name only, so every place in the database has NULL lat/lng.
            // A place without coordinates cannot be drawn: no route line, no
            // stage marker, no terminus pin. Requiring them at creation stops
            // the gap growing while the existing rows are backfilled, and keeps
            // edits of those rows possible in the meantime.
            //
            // Ranges are the real world's, not Kenya's, so a mistyped sign is
            // caught but nothing legitimate is refused.
            $isNew = (int) $request->input('id', 0) === 0;
            $coordinateRule = $isNew ? 'required' : 'nullable';

            $validator = Validator::make($request->all(), [
                'id'=>'required|min:0|integer',
                'name'=>'required|string',
                'county_name'=>'nullable|string',
                'longitude'=>$coordinateRule.'|numeric|between:-180,180',
                'latitude'=>$coordinateRule.'|numeric|between:-90,90',
                'status'=>'required|min:0|max:1|integer',
            ]);
            if($validator->fails()){
                return response()->json(['errors'=>$validator->messages()], 400);
            }

            if(Place::where('name', $request->name)->where('county_name', $request->county_name)->where('id','<>', $request->id)->count() > 0){
                return response()->json(['error'=>'Place already exists'], 401);
            }
            $place = new Place;
            if($request->id > 0){
                $place = Place::findOrFail($request->id);
            }
            $place->name = $request->name;
            $place->county_name = $request->county_name;
            // Only overwrite coordinates when they are actually supplied.
            // Assigning unconditionally meant an edit that omitted them (a
            // rename, a status toggle) silently wiped the position of a place
            // that had one.
            if ($request->filled('longitude')) {
                $place->longitude = $request->longitude;
            }
            if ($request->filled('latitude')) {
                $place->latitude = $request->latitude;
            }
            $place->status = $request->status;
            if($place->save()){
                return response()->json(['success'=>'Place updated successfully']);
            }else{
                return response()->json(['error'=>'Unable to update place'], 401);
            }
        }else{
            return response()->json(['error'=>'You do not have permissions to Add/Edit Places'], 401);
        }
    }
    public function getPlace(Request $request){
        $place = Place::find($request->id);
        return response()->json(['place'=>$place]);
    }
}
