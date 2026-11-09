<x-portal-layout>
    <div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: start; flex-wrap: wrap; gap: 16px;">
        <div>
            <span class="badge badge-blue" style="margin-bottom: 6px;">Groups &bull; Administrator Management</span>
            <h2 style="font-size: 22px; font-weight: 700; color: var(--gta-text);">Manage Tutor Groups &amp; Allocations</h2>
            <p style="font-size: 13px; color: var(--gta-muted); margin-top: 4px;">Create cohorts, assign GTA lead tutors, and allocate apprentices to workshop schedules.</p>
        </div>
        <button type="button" onclick="document.getElementById('newGroupPanel').scrollIntoView({behavior: 'smooth'})" class="gta-btn gta-btn-primary" style="font-size: 13px; font-weight: 700;">
            + New Tutor Group
        </button>
    </div>

    @if(session('success'))
        <div style="background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 14px; border-radius: 6px; font-size: 13px; margin-bottom: 20px;">
            {{ session('success') }}
        </div>
    @endif

    <!-- Active Groups List -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 20px; margin-bottom: 30px;">
        @forelse($groups as $group)
            <div class="gta-card">
                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px; border-bottom: 1px solid var(--gta-border); padding-bottom: 12px;">
                    <div>
                        <span class="badge badge-purple" style="font-size: 11px;">{{ $group->group_code }}</span>
                        <h3 style="font-size: 17px; font-weight: 700; margin-top: 4px;">{{ $group->group_name }}</h3>
                        <p style="font-size: 12px; color: var(--gta-muted); margin-top: 2px;">
                            Assigned Lead Tutor: <strong style="color: var(--gta-text);">{{ $group->tutor?->full_name ?? 'Unassigned' }}</strong>
                        </p>
                    </div>
                    <span class="badge badge-gray" style="font-size: 12px; font-weight: 700;">
                        {{ $group->apprentices->count() }} {{ Str::plural('Learner', $group->apprentices->count()) }}
                    </span>
                </div>

                <!-- Learners in group -->
                <div style="margin-bottom: 16px;">
                    <h4 style="font-size: 12px; font-weight: 700; text-transform: uppercase; color: var(--gta-muted); margin-bottom: 8px;">Enrolled Apprentices</h4>
                    @if($group->apprentices->isEmpty())
                        <p style="font-size: 12px; color: var(--gta-muted); font-style: italic;">No apprentices allocated to this group yet.</p>
                    @else
                        <div style="display: flex; flex-direction: column; gap: 6px; max-height: 180px; overflow-y: auto;">
                            @foreach($group->apprentices as $app)
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 6px 10px; background: var(--gta-bg); border-radius: 4px; font-size: 12px;">
                                    <div>
                                        <strong>{{ $app->user->full_name }}</strong>
                                        <span style="color: var(--gta-muted); font-size: 11px;">({{ $app->employer?->employer_name ?? 'GTA Partner' }})</span>
                                    </div>
                                    <form method="POST" action="{{ route('admin.groups.remove', [$group->tutor_group_id, $app->apprentice_id]) }}" onsubmit="return confirm('Remove learner from this group?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" style="background: none; border: none; color: #ef4444; font-size: 11px; cursor: pointer; text-decoration: underline;">Remove</button>
                                    </form>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <!-- Add apprentice to this group -->
                <form method="POST" action="{{ route('admin.groups.assign', $group->tutor_group_id) }}" style="display: flex; gap: 8px;">
                    @csrf
                    <select name="apprentice_id" required class="form-control" style="flex: 1; padding: 6px 10px; font-size: 12px; border: 1px solid var(--gta-border); border-radius: 4px;">
                        <option value="">Allocate an apprentice...</option>
                        @foreach(\App\Models\Apprentice::with('user')->where('status', 'Live')->get() as $learner)
                            <option value="{{ $learner->apprentice_id }}">{{ $learner->user->full_name }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="gta-btn gta-btn-secondary" style="padding: 6px 12px; font-size: 12px;">Add</button>
                </form>
            </div>
        @empty
            <div class="gta-card" style="grid-column: 1 / -1; text-align: center; padding: 40px; color: var(--gta-muted);">
                No tutor groups established yet. Create your first group below.
            </div>
        @endforelse
    </div>

    <!-- Create Group Panel -->
    <div class="gta-card" id="newGroupPanel">
        <h3 style="font-size: 16px; font-weight: 700; border-bottom: 1px solid var(--gta-border); padding-bottom: 10px; margin-bottom: 16px;">
            Create New Tutor Group
        </h3>

        <form method="POST" action="{{ route('admin.groups.store') }}">
            @csrf
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; margin-bottom: 20px;">
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px;">Group Name *</label>
                    <input type="text" name="group_name" placeholder="e.g. Autocare Cohort 2026-A" required class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px;">Group Code *</label>
                    <input type="text" name="group_code" placeholder="e.g. AC-26-A" required class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px;">Assigned GTA Lead Tutor *</label>
                    <select name="tutor_id" required class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;">
                        @foreach($trainers as $trainer)
                            <option value="{{ $trainer->id }}">{{ $trainer->full_name }} ({{ $trainer->email }})</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div style="margin-bottom: 20px;">
                <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px;">Operational Reason / Scheduling Notes</label>
                <textarea name="notes" rows="2" placeholder="e.g. Wednesday workshop release cycle at Sheffield road campus..." class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;"></textarea>
            </div>

            <button type="submit" class="gta-btn gta-btn-primary" style="padding: 10px 24px; font-size: 13px; font-weight: 700;">
                Save Tutor Group
            </button>
        </form>
    </div>
</x-portal-layout>

