<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveDirectoryRequest;
use App\Models\User;
use App\Models\Apprentice;
use App\Models\LearningTask;
use App\Support\DirectoryCatalog;
use App\Support\AccountAccess;
use App\Support\SupportAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DirectoryController extends Controller
{
    public function index(Request $request, string $kind): View
    {
        Gate::authorize('manage-directories');
        $query = DirectoryCatalog::query($kind);
        $search = trim((string) $request->query('search', ''));
        $state = $request->query('state', 'all');
        if (in_array($state, ['active', 'inactive'], true)) {
            $query->where('active', $state === 'active');
        }
        $records = $query->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))->orderBy('name')->paginate(20)->withQueryString();

        return view('directories.index', ['kind' => $kind, 'title' => DirectoryCatalog::title($kind), 'records' => $records, 'search' => $search, 'state' => $state]);
    }

    public function create(string $kind): View
    {
        Gate::authorize('manage-directories');

        return view('directories.form', ['kind' => $kind, 'title' => DirectoryCatalog::title($kind), 'record' => DirectoryCatalog::blank($kind), 'fields' => DirectoryCatalog::fields($kind)]);
    }

    public function store(SaveDirectoryRequest $request, string $kind): RedirectResponse
    {
        $record = DB::transaction(function () use ($request, $kind) {
            if ($kind === 'staff') {
                User::orderBy('id')->lockForUpdate()->get(['id']);
            }
            $record = DirectoryCatalog::blank($kind);
            $this->fillRecord($record, $kind, $request->safe()->except('record_version'));
            if ($kind === 'staff') {
                $record->password = Str::password(32);
            }
            if ($kind === 'staff') {
                SupportAccess::assertSingleLead($record);
            }
            $record->save();
            $this->audit($request, $kind, $record->id, 'created');

            return $record;
        });

        return to_route('directories.show', [$kind, $record->id])->with('status', 'Record saved. No invitation or message was sent.');
    }

    public function show(string $kind, int $record): View
    {
        Gate::authorize('manage-directories');
        $record = DirectoryCatalog::query($kind)->findOrFail($record);

        return view('directories.show', ['kind' => $kind, 'title' => DirectoryCatalog::title($kind), 'record' => $record, 'fields' => DirectoryCatalog::fields($kind, $record)]);
    }

    public function edit(string $kind, int $record): View
    {
        Gate::authorize('manage-directories');
        $record = DirectoryCatalog::query($kind)->findOrFail($record);

        return view('directories.form', ['kind' => $kind, 'title' => DirectoryCatalog::title($kind), 'record' => $record, 'fields' => DirectoryCatalog::fields($kind, $record)]);
    }

    public function update(SaveDirectoryRequest $request, string $kind, int $record): RedirectResponse
    {
        DB::transaction(function () use ($request, $kind, $record) {
            if ($kind === 'staff') {
                User::orderBy('id')->lockForUpdate()->get(['id']);
            }
            $row = DirectoryCatalog::query($kind)->whereKey($record)->lockForUpdate()->firstOrFail();
            if ($row->record_version !== (int) $request->validated('record_version')) {
                throw ValidationException::withMessages(['record_version' => 'This record changed while you were editing. Reopen it before saving.']);
            }$this->fillRecord($row, $kind, $request->safe()->except('record_version'));
            if ($kind === 'programmes' && $row->isDirty(['standard_code', 'version']) && LearningTask::whereIn('apprentice_id', Apprentice::where('programme_id', $row->id)->select('id'))->exists()) {
                throw ValidationException::withMessages(['standard_code' => 'This programme has assigned task history. Create a separate programme/version instead of relabelling its source.']);
            }
            if ($kind === 'staff') {
                SupportAccess::assertSingleLead($row);
            }
            if ($kind === 'staff' && $row->isDirty(['email', 'active', 'role', 'subject_scope'])) {
                AccountAccess::invalidate($row, $request->user()->id, 'staff_access_changed', 'administrator');
            } else {
                $row->record_version++;
                $row->save();
            }
            $this->audit($request, $kind, $row->id, 'updated');
        });

        return to_route('directories.show', [$kind, $record])->with('status', 'Changes saved. Existing apprentice assignments were retained.');
    }

    private function fillRecord(Model $record, string $kind, array $data): void
    {
        if ($kind === 'staff') {
            $record->fill(['name' => $data['name'], 'email' => $data['email']]);
            $record->role = $data['role'];
            $record->active = $data['active'];
            $record->subject_scope = $data['role'] === 'functional_skills_tutor' ? ($data['subject_scope'] ?? null) : null;
        } else {
            $record->fill($data);
        }
    }

    private function audit(Request $request, string $kind, int $id, string $event): void
    {
        DB::table('directory_events')->insert(['user_id' => $request->user()->id, 'kind' => $kind, 'record_id' => $id, 'event' => $event, 'created_at' => now()]);
    }
}
