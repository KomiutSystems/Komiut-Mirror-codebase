<?php

namespace App\Http\Controllers\Dashboard\Vehicles;

use App\Http\Controllers\Controller;
use App\Models\DirectLineClaim;
use App\Models\Sacco;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Yajra\DataTables\DataTables;

class DirectLineClaimsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(['permission:View Direct Line Claims']);
    }
    public function index()
    {
        $sacco = Sacco::find(Auth::user()->sacco_id);
        return view('dashboard.vehicles.direct_line_claims', @compact('sacco'));
    }
    public function getDirectLineClaims(Request $request)
    {

        $claims = DirectLineClaim::with(['vehicle.sacco']);
        if ($request->sacco > 0) {
            $claims = $claims->whereHas('vehicle', function ($query) use ($request) {
                $query->where('sacco_id', $request->sacco);
            });
        }
        if ($request->status != "") {
            $claims = $claims->where('status', $request->status);
        }
        if ($request->search != "") {
            $claims = $claims->where(function ($query) use ($request) {
                $query->where('passenger_phone', 'LIKE', '%' . $request->search . '%')->orWhere('passenger_phone', 'LIKE', '%' . $request->search . '%')
                    ->orWhereHas('vehicle', function ($query) use ($request) {
                        $query->where('plate', 'LIKE', '%' . $request->search . '%');
                    });
            });
        }

        return DataTables::of($claims)
            ->editColumn('created_at', function ($row) {
                return Carbon::parse($row->created_at)->diffForHumans();
            })->editColumn('travel_date', function ($row) {
                return Carbon::parse($row->travel_date)->format('d M, Y h:i A');
            })->addColumn('action', function ($row) {
                $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                    '<span class="d-none id">' . $row->id . '</span>' .
                    '<span class="d-none name">' . $row->passenger_name . '</span>' .
                    '<span class="d-none phone">' . '0' . substr($row->passenger_phone, 3) . '</span>' .
                    '<span class="d-none vehicle_id">' . $row->vehicle_id . '</span>' .
                    '<span class="d-none vehicle">' . ($row->vehicle != null ? $row->vehicle->plate . '( ' . $row->vehicle->till_number . ' | ' . $row->vehicle->merchant_short_code . ')' : '') . '</span>' .
                    '<span class="d-none sacco">' . ($row->vehicle != null ? ($row->vehicle->sacco != null?$row->vehicle->sacco->name:'-') : '-') . '</span>' .
                    '<span class="d-none travel_date">' . $row->travel_date . '</span>' .
                    '<span class="d-none travel_date_1">' . Carbon::parse($row->travel_date)->format('d M, Y h:i A') . '</span>' .
                    '<span class="d-none claim_response">' . $row->claim_response . '</span>' .
                    '<span class="d-none source">' . $row->source . '</span>' .
                    '<span class="d-none status">' . $row->status . '</span>';
                if (auth()->user()->can('Edit Vehicles'))
                    $actionBtn .= '<button class="btn-edit btn btn-primary btn-sm" data-toggle="modal" data-target="#vehicleModal"><i class="fas fa-edit"></i> Edit</button> ';
                $actionBtn .= '<button class="btn-view btn btn-outline-primary btn-sm" data-toggle="modal" data-target="#viewClaimModal"><i class="fas fa-eye"></i> View</button>'
                    . '</div>';
                return $actionBtn;
            })->addIndexColumn()->escapeColumns([])->make();
    }

    public function addDirectLineClaim(Request $request)
    {
        if (auth()->user()->can('Add Direct Line Claims') || auth()->user()->can('Edit Direct Line Claims')) {
            $validator = Validator::make($request->all(), [
                'id' => 'required|min:0|integer',
                'transaction_id'=>'nullable|integer',
                'vehicle' => 'required|exists:vehicles,id',
                'phone' => 'required|digits:10',
                'name' => 'required|string',
                'travel_date' => 'string|required',
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            $directLineClaim = new DirectLineClaim;
            if ($request->id > 0) {
                $directLineClaim = DirectLineClaim::findOrFail($request->id);
            }
            $directLineClaim->vehicle_id = $request->vehicle;
            $directLineClaim->transaction_id = $request->transaction_id;
            $directLineClaim->passenger_name = $request->name;
            $directLineClaim->passenger_phone = '254' . intval($request->phone);
            $directLineClaim->travel_date = Carbon::parse($request->travel_date);
            $directLineClaim->source = "Komiut";
            if ($directLineClaim->save()) {
                $this->sendClaimToDirectLine($directLineClaim->id);
                return response()->json(['success' => 'Claim saved successfully']);
            } else {
                return response()->json(['error' => 'Unable to update claim'], 401);
            }
        } else {
            return response()->json(['error' => 'Permissions to Add/Edit Direct Line Claims Denied'], 401);
        }
    }
    public function sendClaim(Request $request)
    {
        return $this->sendClaimToDirectLine($request->id);
    }
    public function sendClaimToDirectLine($id)
    {
        $directLineClaim = DirectLineClaim::with('vehicle.sacco')->find($id);
        if ($directLineClaim != null) {
            $curl = curl_init();

            curl_setopt_array(
                $curl,
                array(
                    CURLOPT_URL => 'https://dacapps.co.ke/cashless/v1/postPassenger.php',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'GET',
                    CURLOPT_POSTFIELDS => '{
    "reg_marks": "' . $directLineClaim->vehicle->plate . '",
    "mobileno": "' . $directLineClaim->passenger_phone . '",
    "names": "' . $directLineClaim->passenger_name . '",
    "traveldate": "' . Carbon::parse($directLineClaim->travel_date)->format('d/m/Y') . '",
    "traveltime": "' . Carbon::parse($directLineClaim->travel_date)->format('Hi') . '",
    "source": "' . $directLineClaim->source . '" ,
    "clientname": "' . ($directLineClaim->vehicle->sacco != null ? $directLineClaim->vehicle->sacco->name : "No Sacco Name") . '"
}',
                    CURLOPT_HTTPHEADER => array(
                        'Content-Type: application/json'
                    ),
                )
            );

            $curl_response = curl_exec($curl);

            curl_close($curl);

            $response = json_decode($curl_response, true);

            if ($curl_response === false) {
                // Check for cURL errors
                $error = curl_error($curl);

                $directLineClaim->claim_response = "cURL Error: $error";
                $directLineClaim->status = false;
            } else {
                $directLineClaim->claim_response = $curl_response;
                $directLineClaim->status = true;
            }
            $directLineClaim->save();
            //\Log::info($curl_response);
            return $response;
        } else {
            return response()->json(['error' => 'Claim not found!'], 401);
        }
    }
}
