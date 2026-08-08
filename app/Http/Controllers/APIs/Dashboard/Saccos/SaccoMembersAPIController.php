<?php

namespace App\Http\Controllers\APIs\Dashboard\Saccos;

use App\Auth\Roles;
use App\Http\Controllers\Controller;
use App\Models\SaccoUser;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;

class SaccoMembersAPIController extends Controller
{
    public function __construct(){
        $this->middleware('auth:sanctum');
    }

    public function getMembers(Request $request){
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $saccoUsers = SaccoUser::with(['user.roles', 'sacco', 'user.gender']);
        if($request->sacco > 0){
            $saccoUsers = $saccoUsers->where('sacco_id', $request->sacco);
        }
        // Only filter when a term was actually typed. An empty box turns this
        // into LIKE '%%' on every column in the group, OR'd with any whereHas
        // below — none of it indexable. The guard wraps the WHOLE group on
        // purpose: guarding one column leaves the orWhere siblings matching
        // unconditionally, which is worse than no guard.
        if (filled($request->search)) {
            $saccoUsers = $saccoUsers->whereHas('user',function($query) use($request){
                $query->where('firstname', 'LIKE', '%'.$request->search.'%')
                ->orWhere('lastname', 'LIKE', '%'.$request->search.'%')
                ->orWhere('email', 'LIKE', '%'.$request->search.'%')
                ->orWhere('phone', 'LIKE', '%'.$request->search.'%');
            });
        }
        $saccoUsers = $saccoUsers->skip($offset)->take(20)
        ->orderBy('created_at', 'DESC')->get();

        // `users` is an ADDITIVE alias so the members screen can standardise on
        // this endpoint instead of GET users, which is the whole-platform user
        // list and is not membership. Existing callers of `sacco_users` keep
        // working, so this is not a breaking rename; it carries the underlying
        // User rows in the shape those callers already expect from GET users.
        return response()->json([
            'sacco_users' => $saccoUsers,
            'users' => $saccoUsers->pluck('user')->filter()->values(),
        ]);
    }public function addMember(Request $request)
    {
        if(auth()->user()->can('Edit Sacco Members') || auth()->user()->can('Add Sacco Members')){
            $validator = Validator::make($request->all(), [
                'id' => 'required|integer|min:0',
                'sacco' => 'required|exists:saccos,id',
                'member' => 'required|exists:users,id',
                'status' => 'required|integer|min:0|max:1'
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }

            // TENANT BOUNDARY. `sacco` was validated only as exists:saccos,id and
            // `member` only as exists:users,id, with no check that either belongs
            // to the caller — so any SACCO Admin could move ANY user on the
            // platform into ANY SACCO, and the last line of this method writes
            // users.sacco_id directly. Superadmins may still act across SACCOs.
            $caller = auth()->user();
            if (! $caller->isSuperAdmin()) {
                if ((int) $request->sacco !== (int) $caller->currentSaccoId()) {
                    return response()->json(['error' => 'You can only manage members of your own SACCO.'], 403);
                }
                // The member must be unattached, or already ours — otherwise this
                // is a transfer out of somebody else's SACCO.
                $memberSaccoId = User::whereKey($request->member)->value('sacco_id');
                if ($memberSaccoId !== null && (int) $memberSaccoId !== (int) $caller->currentSaccoId()) {
                    return response()->json(['error' => 'That user belongs to another SACCO.'], 403);
                }
            }

            $saccoUser = SaccoUser::where('user_id', $request->member)->where('sacco_id', $request->sacco)
            ->where('end_date', null)->first();
            if($saccoUser == null){
                SaccoUser::where('user_id', $request->member)->where('id', '<>', $request->id)->where('end_date', null)
                ->update(['end_date'=>Carbon::now()]);

                $saccoUser = new SaccoUser;
                if ($request->id > 0) {
                    $saccoUser = SaccoUser::findOrFail($request->id);
                }else{
                    $saccoUser->start_date = Carbon::now();
                }
            }
            $saccoUser->sacco_id = $request->sacco;
            $saccoUser->user_id = $request->member;
            $saccoUser->status = $request->status;
            $saccoUser->created_by = Auth::user()->id;

            if ($saccoUser->save()) {
                SaccoUser::where('user_id', $request->member)->where('id', '<>',$saccoUser->id)
                ->where('end_date', null)->update(['end_date'=>Carbon::now(), 'status'=>0]);
                User::where('id', $request->member)->update(['sacco_id'=>$request->sacco]);
                return response()->json(['success' => 'Member updated successfully!']);
            } else {
                return response()->json(['error' => 'Unable to update member'], 401);
            }
        }else {
            return response()->json(['error' => 'Permissions to Add/Edit Member Denied'], 401);
        }

    }

    /**
     * Create a NEW user inside the caller's own SACCO.
     *
     * addMember() above only LINKS an account that already exists — it validates
     * `member` as exists:users,id. Creating an account needed 'Add Users', which
     * is PLATFORM_ONLY, so a SACCO Admin could not add their own driver or
     * clerk without the platform team doing it for them. That is the gap this
     * closes.
     *
     * Three things are forced rather than accepted from the client, because each
     * is an escalation route:
     *   - sacco_id  comes from the caller, never the payload
     *   - type      is restricted to the field/staff tiers (never superadmin)
     *   - roles     go through Roles::saccoAssignable() plus the caller's own
     *               permission ceiling, the same rules RolesController::
     *               assignMemberRoles enforces
     */
    public function createMember(Request $request)
    {
        $caller = auth()->user();

        if (! $caller->can('Add Sacco Members')) {
            return response()->json(['error' => 'You do not have permission to add members.'], 403);
        }

        $saccoId = $caller->isSuperAdmin() && $request->filled('sacco')
            ? (int) $request->input('sacco')
            : (int) $caller->currentSaccoId();

        if ($saccoId <= 0) {
            return response()->json(['error' => 'You are not attached to a SACCO.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'firstname' => 'required|string|max:255',
            'lastname' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'phone' => 'required|string|max:255|unique:users,phone',
            'password' => 'required|string|min:8',
            // 'admin' covers office staff; superadmin is deliberately absent.
            'type' => 'required|in:driver,conductor,admin,passenger',
            'gender_id' => 'nullable|integer|exists:genders,id',
            'dob' => 'nullable|date',
            'roles' => 'nullable|array',
            'roles.*' => 'string|exists:roles,name',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $roles = (array) $request->input('roles', []);

        if (! $caller->isSuperAdmin() && $roles !== []) {
            $illegal = array_values(array_diff($roles, Roles::saccoAssignable()));
            if ($illegal !== []) {
                return response()->json(['error' => 'You cannot assign these roles: '.implode(', ', $illegal)], 403);
            }
            // Permission ceiling — never grant beyond what the caller holds.
            $granting = Role::where('guard_name', 'web')->whereIn('name', $roles)
                ->with('permissions:id,name')->get()
                ->flatMap(fn (Role $r) => $r->permissions->pluck('name'))->unique();
            $exceeding = $granting->diff($caller->getAllPermissions()->pluck('name'));
            if ($exceeding->isNotEmpty()) {
                return response()->json(['error' => 'These roles exceed your own permissions: '.$exceeding->implode(', ')], 403);
            }
        }

        $user = DB::transaction(function () use ($request, $saccoId, $roles) {
            $user = User::create([
                'firstname' => $request->firstname,
                'lastname' => $request->lastname,
                'email' => $request->email,
                'phone' => $request->phone,
                'password' => Hash::make($request->password),
                'type' => $request->type,
                'gender_id' => $request->gender_id,
                'dob' => $request->dob,
                'sacco_id' => $saccoId,
                'status' => true,
            ]);

            // Mirror addMember()'s membership record so the member shows up on
            // the members screen, which reads sacco_users rather than users.
            SaccoUser::create([
                'sacco_id' => $saccoId,
                'user_id' => $user->id,
                'start_date' => Carbon::now(),
                'status' => 1,
                'created_by' => auth()->id(),
            ]);

            if ($roles !== []) {
                $user->syncRoles(Role::where('guard_name', 'web')->whereIn('name', $roles)->get());
            }

            return $user;
        });

        return response()->json([
            'success' => 'Member created successfully!',
            'user' => [
                'id' => $user->id,
                'name' => $user->firstname.' '.$user->lastname,
                'email' => $user->email,
                'type' => $user->type,
                'sacco_id' => $user->sacco_id,
                'roles' => $user->getRoleNames(),
            ],
        ], 201);
    }
}
