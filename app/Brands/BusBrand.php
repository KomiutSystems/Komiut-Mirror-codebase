<?php

declare(strict_types=1);

namespace App\Brands;

use App\Models\Vehicle;
use Illuminate\Support\Facades\Context;

/**
 * Run the rest of a fare payment under the brand of the BUS being paid.
 *
 * A passenger pays whatever bus they are standing in, from whichever of our
 * apps is on their phone: they do not know or care that MetroTrans is a
 * 2Safiri SACCO. A sticker on the bus is proof enough of which bus it is. So
 * once a payment has resolved its bus, the request takes on that bus's brand
 * -- exactly as if the passenger had opened the bus's own app. Everything the
 * payment then writes lands where the SACCO looks for it: the QR payment and
 * its transaction carry the bus's brand, Daraja calls back on the bus's brand
 * route, and loyalty points accrue in the bus's SACCO programme.
 *
 * The status poll (StkStatusController) reads its records unscoped, so the
 * passenger's own app still sees the outcome.
 */
final class BusBrand
{
    public static function adopt(Vehicle $vehicle): void
    {
        $key = (string) $vehicle->brand;
        if ($key === '' || Context::get('brand') === $key) {
            return;
        }

        $brand = app(BrandRegistry::class)->get($key);
        if ($brand !== null) {
            app(BrandContext::class)->apply($brand);
        }
    }
}
