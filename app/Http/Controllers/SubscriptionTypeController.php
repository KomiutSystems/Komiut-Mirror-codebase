<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionType;
use Illuminate\Http\Request;

class SubscriptionTypeController extends Controller
{
    public function createSubscriptionType(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|min:0|integer',
            'name' => 'required|string|unique:saccos,name,' . $request->id,
            'description' => 'required',
            'status' => 'required|min:0|max:1|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $subscriptionType = new SubscriptionType;
        if ($request->id > 0) {
            $subscriptionType = SubscriptionType::findOrFail($request->id);
        }

        $subscriptionType->name = $request->name;
        $subscriptionType->description = $request->description;
        $subscriptionType->status_id = $request->status;
        $subscriptionType->created_by = Auth::user()->id;
        $subscriptionType->updated_by = Auth::user()->id;

        if ($subscriptionType->save()) {
            return response()->json(['success' => 'Subscription Type updated successfully']);
        } else {
            return response()->json(['error' => 'Unable to update slogan'], 401);
        }
    }

    public function show($id)
    {
        return SubscriptionType::find($id);
    }

    public function getSubcriptionTypes(Request $request)
    {
        $page = $request->has('page') ? intval($request->page) - 1 : 0;
        $records = $request->has('records') ? $request->records : 10;
        $search = $request->has('records') ? $request->search : "";
        $subscription_types = SubscriptionType::with([])
            ->where('name', 'LIKE', '%' . $search . '%')
            ->skip($records * $page)->take($records)->get();
        return response()->json(['subscription_types' => $subscription_types, 'records' => $records,
            'recordsTotal' => $subscription_types->count(), 'recordsFiltered' => $subscription_types->count(), 'page' => $page + 1]);
    }

    public function searchSubscriptionTypes(Request $request)
    {
        $subscriptionTypes = SubscriptionType::where('name', 'LIKE', '%' . $request->search . '%')->skip(0)->take(3)->get();
        return response()->json(['subscription_types' => $subscriptionTypes]);
    }



}
