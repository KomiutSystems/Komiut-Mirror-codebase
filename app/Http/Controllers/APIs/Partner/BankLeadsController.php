<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Partner;

use App\Http\Controllers\Controller;
use App\Http\Middleware\BankPartnerAuth;
use App\Models\DriverBankLead;
use App\Services\Platform\AuditLogger;
use App\Services\Platform\PlatformEvent;
use App\Services\Platform\PlatformNotifier;
use App\Services\Sql\LikeSql;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @group Bank partner
 *
 * The list of drivers who asked for help opening an account, for the partner
 * bank to work through. Authenticated by the key on the shared link (see
 * BankPartnerAuth) — there is no user account behind it.
 *
 * Read-only by design. A partner can see who to call and export the list; it
 * cannot change a driver's record, and the endpoint exposes nothing beyond the
 * fields the bank needs to make contact and size the account.
 */
class BankLeadsController extends Controller
{
    /**
     * List driver leads
     *
     * Paginated, newest first, scoped to the partner's own brand. Optional
     * `status` filter and `q` search over driver name/phone.
     *
     * @unauthenticated
     *
     * @queryParam status string Filter by lead status. Example: new
     * @queryParam q string Search driver name or phone. Example: 0712
     * @queryParam page integer Page number. Example: 1
     *
     * @response 200 {"partner": "NCBA Bank", "total": 1, "leads": [{"name": "John Mwangi", "phone": "0712345678", "email": null, "sacco": "Nicco SACCO", "preferred_branch": "Westlands", "vehicle_seats": 14, "status": "new", "opted_in_at": "2026-07-27T10:00:00Z"}]}
     */
    public function index(Request $request): JsonResponse
    {
        $partner = $this->partner($request);

        $leads = $this->query($request, $partner['brand'])
            ->paginate((int) config('bank_portal.per_page', 50));

        $this->audit($request, $partner, $leads->total());

        return response()->json([
            'partner' => $partner['label'],
            'total' => $leads->total(),
            'per_page' => $leads->perPage(),
            'current_page' => $leads->currentPage(),
            'last_page' => $leads->lastPage(),
            'leads' => collect($leads->items())->map(fn (DriverBankLead $lead): array => $this->row($lead))->all(),
        ]);
    }

    /**
     * Export leads as CSV
     *
     * The same rows as the list, streamed as a spreadsheet the bank can work
     * from directly. Streamed rather than built in memory so a long list does
     * not have to fit in one response buffer.
     *
     * @unauthenticated
     */
    public function export(Request $request): StreamedResponse
    {
        $partner = $this->partner($request);
        $query = $this->query($request, $partner['brand']);

        $count = $query->count();
        $this->audit($request, $partner, $count, 'export');

        // Personal data is leaving to a third party, so this is audit-FIRST: the
        // immutable row is written, then the critical alert references it. Never
        // throttled — every export must be individually accountable.
        $this->emitExport($request, $partner, $count);

        $filename = 'driver-leads-'.$partner['key'].'-'.now()->format('Y-m-d').'.csv';

        return response()->stream(function () use ($query): void {
            $out = fopen('php://output', 'wb');
            // The columns are listed EXPLICITLY and the rows below are built
            // field by field, never by splatting row(). That is deliberate:
            // row() is the screen payload and gains fields over time, and a
            // splat here would carry each new one — an account number, a
            // consent agent — out of the building in a file the moment it was
            // added to a screen. Widening this export is a decision, not a
            // side effect.
            fputcsv($out, ['Name', 'Phone', 'Email', 'ID Number', 'SACCO', 'Preferred Branch', 'Vehicle Seats', 'Status', 'Opted In']);

            $query->chunk(500, function ($leads) use ($out): void {
                foreach ($leads as $lead) {
                    $row = $this->row($lead);
                    fputcsv($out, [
                        $row['name'], $row['phone'], $row['email'], $row['id_number'],
                        $row['sacco'], $row['preferred_branch'], $row['vehicle_seats'],
                        $row['status'], $row['opted_in_at'],
                    ]);
                }
            });

            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Leads for this partner's brand.
     *
     * withoutGlobalScopes because BrandScope keys off the REQUEST's brand, and
     * a bank calls a bare partner URL with no X-App-Key. The brand here comes
     * from the partner's own configuration, so the scoping is explicit instead.
     */
    private function query(Request $request, string $brand): Builder
    {
        return DriverBankLead::withoutGlobalScopes()
            ->with(['user:id,firstname,lastname,phone,email,id_number,sacco_id', 'user.sacco:id,name'])
            ->where('brand', $brand)
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('q'), function (Builder $q) use ($request): void {
                $term = '%'.$request->string('q').'%';
                $q->whereHas('user', function (Builder $u) use ($term): void {
                    $u->where('firstname', LikeSql::op(), $term)
                        ->orWhere('lastname', LikeSql::op(), $term)
                        ->orWhere('phone', LikeSql::op(), $term);
                });
            })
            ->latest('opted_in_at');
    }

    /** @return array<string, mixed> */
    private function row(DriverBankLead $lead): array
    {
        $driver = $lead->user;

        return [
            'name' => trim(($driver->firstname ?? '').' '.($driver->lastname ?? '')),
            'phone' => $driver->phone ?? null,
            'email' => $driver->email ?? null,
            'id_number' => $driver->id_number ?? null,
            'sacco' => $driver->sacco->name ?? null,
            // The SACCO's id as well as its name: the portal addresses writes by
            // id, and a name is not an identifier — two SACCOs may share one.
            'sacco_id' => $driver->sacco_id === null ? null : (int) $driver->sacco_id,
            'preferred_branch' => $lead->preferred_branch,
            'vehicle_seats' => $lead->vehicle_capacity,
            'status' => $lead->status,
            'opted_in_at' => $lead->opted_in_at,

            // The driver's EXISTING account, where they gave one. Null and
            // absent are the same fact here and both mean "they did not give
            // one" — the portal must not render a blank as an account.
            'account_number' => $lead->account_number,

            // The consent record travels WITH the lead, because the lead is
            // personal data being handed to a third party and the consent is
            // the only thing that makes that lawful. A bank holding the row
            // must be able to see what the driver was told and when.
            //
            // consent_given_at is a TIMESTAMP, not a boolean: "did they
            // consent" and "when did they consent" are the same column, and a
            // boolean would have thrown away the half that matters in a
            // dispute. Null means no consent is recorded, which is not the same
            // as consent refused.
            'consent_given_at' => $lead->consent_given_at,

            // Which wording they actually agreed to. Old rows keep their old
            // version on purpose — the string exists so that changing the copy
            // never retroactively rewrites what someone already agreed to — so
            // consumers must accept every version they encounter, not just the
            // current one.
            'consent_text_version' => $lead->consent_text_version,

            // Who took the consent. NOT consent_ip, which is also on the row:
            // that is incident-response data for us, not something a partner
            // needs, and every field added here leaves the building.
            'consent_agent' => $lead->consent_agent,
        ];
    }

    /** @return array{key: string, brand: string, label: string} */
    private function partner(Request $request): array
    {
        return $request->attributes->get(BankPartnerAuth::PARTNER);
    }

    /**
     * Every read of the driver list is logged. This is personal data leaving to
     * a third party, so "who pulled what, and when" has to be answerable later.
     */
    private function audit(Request $request, array $partner, int $count, string $action = 'list'): void
    {
        Log::info('bank_partner.'.$action, [
            'partner' => $partner['key'],
            'brand' => $partner['brand'],
            'records' => $count,
            'ip' => $request->ip(),
        ]);
    }

    /**
     * Audit-first alert for a bulk lead export: the append-only AuditLog row is
     * written before the super-admin notification, and the notification carries
     * only its id, PII-free counts and request metadata — never a driver's data.
     *
     * @param  array{key: string, brand: string, label: string}  $partner
     */
    private function emitExport(Request $request, array $partner, int $count): void
    {
        $ip = $request->ip();
        $userAgent = substr((string) $request->userAgent(), 0, 512) ?: null;

        $audit = AuditLogger::record(
            'partner.leads.exported',
            ['partner' => $partner['key'], 'recordCount' => $count, 'ip' => $ip, 'userAgent' => $userAgent],
            ['type' => 'partner', 'id' => $partner['key'], 'label' => $partner['label']],
            null,
            $partner['brand'],
        );

        app(PlatformNotifier::class)->dispatch(new PlatformEvent(
            event: 'partner.leads.exported',
            severity: 'critical',
            class: 'alert',
            title: 'Partner '.$partner['label'].' exported '.$count.' driver leads',
            summary: $partner['label'].' exported '.$count.' driver leads as CSV.',
            brand: $partner['brand'],
            actor: ['type' => 'partner', 'id' => $partner['key'], 'label' => $partner['label']],
            data: [
                'partner' => $partner['label'],
                'recordCount' => $count,
                'ip' => $ip,
                'userAgent' => $userAgent,
            ],
            windowMinutes: 0, // NEVER throttle — every export is individually logged
            auditId: $audit->id,
        ));
    }
}
