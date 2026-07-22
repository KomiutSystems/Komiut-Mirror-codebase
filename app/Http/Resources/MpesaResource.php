<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contract-capture of the current Mpesa JSON shape.
 *
 * Keys derived from the mpesas table migration (2023_07_12_090342), id +
 * timestamps. Field names are Safaricom Daraja PascalCase verbatim and MUST
 * NOT be snake_cased.
 */
class MpesaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'TransID' => $this->TransID,
            'MSISDN' => $this->MSISDN,
            'TransAmount' => $this->TransAmount,
            'TransTime' => $this->TransTime,
            'FirstName' => $this->FirstName,
            'MiddleName' => $this->MiddleName,
            'LastName' => $this->LastName,
            'ThirdPartyTransID' => $this->ThirdPartyTransID,
            'InvoiceNumber' => $this->InvoiceNumber,
            'BillRefNumber' => $this->BillRefNumber,
            'BusinessShortCode' => $this->BusinessShortCode,
            'TransactionType' => $this->TransactionType,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'transaction' => $this->whenLoaded('transaction'),
        ];
    }
}
