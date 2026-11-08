<?php

/*
| Super-admin wallet domain routes.
| Group prefix + middleware (brand, auth:sanctum, super) come from routes/api.php,
| which auto-loads every routes/super/*.php file.
|
| THE READ AND WRITE SIDES CARRY DIFFERENT PERMISSIONS ON PURPOSE. Looking at
| the float is something any platform money role needs; freezing a passenger's
| wallet, correcting a balance or marking a SACCO paid moves real money and is
| gated separately. A single permission over both would mean anyone who can read
| the dashboard can also adjust somebody's balance.
*/

use App\Http\Controllers\APIs\Super\Wallet\WalletAdminController;
use App\Http\Controllers\APIs\Super\Wallet\WalletFloatController;
use Illuminate\Support\Facades\Route;

// READ. The float, the payout queue, and one passenger's wallet.
Route::middleware('permission:View Platform Notifications')->group(function (): void {
    Route::get('wallet/float', [WalletFloatController::class, 'index']);
    Route::get('wallet/settlements', [WalletFloatController::class, 'settlements']);
    Route::get('wallet/settlements/{id}/fares', [WalletFloatController::class, 'settlementFares'])->whereNumber('id');

    // Money on our float shortcode that matches no wallet -- a mistyped account
    // number. Real money needing a human, so it needs a screen.
    Route::get('wallet/unmatched', [WalletFloatController::class, 'unmatched']);

    Route::get('wallet/lookup', [WalletAdminController::class, 'show']);
    Route::get('wallet/lookup/ledger', [WalletAdminController::class, 'ledger']);
});

// WRITE. Every one of these moves money or restricts a passenger, every one
// demands a reason, and every one writes an audit row (WalletOpsService /
// WalletSettlementService). Throttled because they are irreversible-ish and
// nothing legitimate needs them in bulk.
Route::middleware(['permission:Manage Wallet Float', 'throttle:30,1'])->group(function (): void {
    Route::post('wallet/freeze', [WalletAdminController::class, 'freeze']);
    Route::post('wallet/unfreeze', [WalletAdminController::class, 'unfreeze']);
    Route::post('wallet/adjust', [WalletAdminController::class, 'adjust']);
    Route::post('wallet/refund', [WalletAdminController::class, 'refund']);

    Route::post('wallet/settlements/{id}/approve', [WalletAdminController::class, 'approveSettlement'])->whereNumber('id');
    Route::post('wallet/settlements/{id}/pay', [WalletAdminController::class, 'paySettlement'])->whereNumber('id');
});
