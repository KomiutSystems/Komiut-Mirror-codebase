<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{

    public function index(Request $request)
    {
        return Subscription::all();
    }

    public function addSubscription(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|min:0|integer',
            'sacco_id' => 'required|min:0|integer',
            'subscription_type_id' => 'required|min:0|integer',
            'amount' => 'required|numeric',
            'previous_balance' => 'required|numeric',
            'status' => 'required|min:0|max:1|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $subscription = new Subscription;
        $subscription->sacco_id = $request->sacco_id;
        $subscription->subscription_type_id = $request->subscription_type_id;
        $subscription->amount = $request->amount;
        $subscription->previous_balance = $request->previous_balance;
        $subscription->status_id = $request->status;
        $subscription->created_by = Auth::user()->id;
        $subscription->updated_by = Auth::user()->id;

        if ($subscription->save()) {
            return response()->json(['success' => 'Subscription updated successfully']);
        } else {
            return response()->json(['error' => 'Unable to update subscription'], 401);
        }
    }

    public function show($id)
    {
        return Subscription::find($id);
    }

    public function getSubcriptions(Request $request)
    {
        $page = $request->has('page') ? intval($request->page) - 1 : 0;
        $records = $request->has('records') ? $request->records : 10;
        $search = $request->has('records') ? $request->search : "";
        $subscriptions = Subscription::with([
            'sacco_id',
            'subscription_type_id'
        ])
            ->where('amount', 'LIKE', '%' . $search . '%')
            ->skip($records * $page)->take($records)->get();
        return response()->json(['subscriptions' => $subscriptions, 'records' => $records,
            'recordsTotal' => $subscriptions->count(), 'recordsFiltered' => $subscriptions->count(), 'page' => $page + 1]);
    }

    public function updateSubscription(Request $request, $subscriptionId)
    {
        $subscription = Subscription::find($subscriptionId);
        $subscription->sacco_id = $request->sacco_id;
        $subscription->subscription_type_id = $request->subscription_type_id;
        $subscription->amount = $request->amount;
        $subscription->previous_balance = $request->previous_balance;
        $subscription->status_id = $request->status;
        $subscription->created_by = Auth::user()->id;
        $subscription->updated_by = Auth::user()->id;

        if ($subscription->save()) {
            return response()->json(['success' => 'Subscription updated successfully']);
        } else {
            return response()->json(['error' => 'Unable to update subscription'], 401);
        }
    }

}
