<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\SupportCapability;
use App\Models\TutorGroup;
use App\Models\User;
use App\Support\DirectoryCatalog;
use App\Support\LearningAccess;
use App\Support\SupportAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SupportPermissionController extends Controller
{
    public function index(Request $r): View
    {
        abort_unless(LearningAccess::manager($r->user()), 403);
        $people = User::whereIn('role', [...DirectoryCatalog::STAFF_ROLES, 'admin', 'ceo'])->orderBy('name')->get();
        $target = $r->filled('person') ? $people->firstWhere('id', $r->integer('person')) : $people->first();
        abort_unless(! $r->filled('person') || $target, 404);

        return view('support.permissions', ['people' => $people, 'target' => $target, 'settings' => $target ? SupportAccess::settings($target) : null, 'apprentices' => Apprentice::orderBy('last_name')->get(['id', 'reference', 'first_name', 'last_name']), 'groups' => TutorGroup::orderBy('name')->get(['id', 'name']), 'events' => $target ? DB::table('support_permission_events')->where('user_id', $target->id)->orderByDesc('id')->get() : collect()]);
    }

    public function save(Request $r, User $target): RedirectResponse
    {
        abort_unless(LearningAccess::manager($r->user()), 403);
        $data = $r->validate(['version' => ['required', 'integer'], 'duty' => ['required', Rule::in(array_keys(SupportAccess::DUTIES))], 'scope' => ['required', Rule::in(array_keys(SupportAccess::SCOPES))], 'apprentice_ids' => ['nullable', 'array', 'max:1000'], 'apprentice_ids.*' => ['integer', Rule::exists('apprentices', 'id')], 'group_ids' => ['nullable', 'array', 'max:1000'], 'group_ids.*' => ['integer', Rule::exists('tutor_groups', 'id')], 'reason' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($r, $target, $data): void {
            User::orderBy('id')->lockForUpdate()->get(['id']);
            abort_unless(LearningAccess::manager(User::findOrFail($r->user()->id)), 403);
            $u = User::findOrFail($target->id);
            abort_unless(in_array($u->role, [...DirectoryCatalog::STAFF_ROLES, 'admin', 'ceo'], true), 403);
            if (in_array($u->role, ['admin', 'ceo'], true) && $data['scope'] !== 'all') {
                throw ValidationException::withMessages(['scope' => 'Administrators retain all-apprentice operational oversight.']);
            }
            $before = SupportAccess::settings($u);
            $row = SupportCapability::where('user_id', $u->id)->lockForUpdate()->first();
            if (($row?->version ?? 0) !== $r->integer('version')) {
                throw ValidationException::withMessages(['version' => 'These support permissions changed. Reopen before saving.']);
            }
            $row ??= new SupportCapability(['user_id' => $u->id]);
            $row->duty = $data['duty'];
            $row->scope = $data['scope'];
            $row->apprentice_ids = $data['scope'] === 'assigned' ? array_values(array_unique(array_map('intval', $data['apprentice_ids'] ?? []))) : [];
            $row->group_ids = $data['scope'] === 'assigned' ? array_values(array_unique(array_map('intval', $data['group_ids'] ?? []))) : [];
            if ($row->exists) {
                $row->version++;
            }$row->save();
            SupportAccess::assertSingleLead($u);
            $u->record_version++;
            $u->save();
            DB::table('support_permission_events')->insert(['user_id' => $u->id, 'actor_id' => $r->user()->id, 'before_state' => json_encode($before), 'after_state' => json_encode(SupportAccess::settings($u)), 'reason' => $data['reason'], 'created_at' => now()]);
        });

        return to_route('support.permissions', ['person' => $target->id])->with('status', 'Support duty and scope saved with a reason. Base role, Careers, staff HR and subject permissions have not changed.');
    }
}
