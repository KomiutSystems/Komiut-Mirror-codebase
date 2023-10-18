<?php

namespace App\Http\Controllers\APIs\Dashboard\Routes;

use App\Http\Controllers\Controller;
use App\Models\Place;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PlaceAPIController extends Controller
{
    public function __construct(){
        $this->middleware('auth:api');
    }
    
    public function getPlaces(Request $request){
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $places = Place::where('name', 'LIKE', '%'.$request->search.'%')->skip($offset)->take(20)
        ->orderBy('name', 'ASC')->get();
        return response()->json(['places'=>$places]);
    }
    
    public function addPlace(Request $request){
        if(auth()->user()->can('Add Places') || auth()->user()->can('Add Places')){
            $validator = Validator::make($request->all(), [
                'id'=>'required|min:0|integer',
                'name'=>'required|string',
                'county_name'=>'nullable|string',
                'longitude'=>'nullable|numeric',
                'latitude'=>'nullable|numeric',
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
            $place->longitude = $request->longitude;
            $place->latitude = $request->latitude;
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
