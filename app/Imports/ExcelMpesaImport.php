<?php

namespace App\Imports;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use App\Models\Mpesa;
use App\Models\Vehicle;
use App\Models\Transaction;


class ExcelMpesaImport implements WithChunkReading, ToCollection
{
    protected $vehicleId;
    protected $vehicle;

    public function __construct($vehicleId)
    {
        $this->vehicleId = $vehicleId;
        $this->vehicle = Vehicle::find($vehicleId);// Create an instance of the custom value binder
    }
    public function chunkSize(): int
    {
        return 100; //number of rows to read per chunk
    }

    public function collection(Collection $collection)
    {
        $count = 0;
        $merchant = $this->vehicle->merchant_short_code;
        foreach ($collection as $row) {
            //\Log::info($merchant);
            /*if ($count == 1) {
                $merchant = $row[1];
            }
            if ($count >= 7) {*/
            if (strlen($row[0]) == 10) {
                //\Log::info($row[0]);
                $msisdn = 0;

                $str = $row[10];

                if ($str != "") {
                    $details = explode("-", $str);

                    $msisdn = $details[0];
                    //\Log::info($details[1]);
                    $name = explode(" ", $details[1]);
                    $firstname = !empty($name[1]) ? $name[1] : '';
                    $middlename = !empty($name[2]) ? $name[2] : '';
                    $lastname = !empty($name[3]) ? $name[3] : '';
                    $transtype = "";
                    $transtime = Carbon::parse((string) $row[1]);

                    $transamount = doubleval($row[5]);
                    //\Log::info('Amount:' . $transamount);

                    if (($transamount > 0)) {
                        $mpesa = Mpesa::where("TransID", $row[0])->first();
                        if($mpesa == null){
                            $mpesa = new Mpesa;
                            $mpesa->TransID = $row[0];
                            $mpesa->MSISDN = $msisdn;
                            $mpesa->FirstName = $firstname;
                            $mpesa->LastName = $lastname;
                            $mpesa->MiddleName = $middlename;
                            $mpesa->BusinessShortCode = $merchant;
                            $mpesa->TransactionType = $transtype;
                            $mpesa->ThirdPartyTransID = "";
                            $mpesa->InvoiceNumber = "";
                            $mpesa->BillRefNumber = "";
                        }
                        $mpesa->TransAmount = $transamount;
                        $mpesa->TransTime = Carbon::parse($row[1]);
                        if ($mpesa->save()) {
                            //$vehicle = Vehicle::where("merchant_short_code", $merchant)->first();
                            $transaction = new Transaction;
                            $transaction->mpesa_id = $mpesa->id;
                            if ($this->vehicle != null) {
                                $transaction->vehicle_id = $this->vehicle->id;
                            }
                            $transaction->amount = $transamount;
                            $transaction->trans_date = $transtime;
                            $transaction->save();
                        }
                    }
                }
            }
            //}

            $count++;
        }

    }
}
