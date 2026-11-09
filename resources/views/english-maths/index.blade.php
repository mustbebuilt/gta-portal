<x-portal-layout>
    <div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: start; flex-wrap: wrap; gap: 16px;">
        <div>
            @if($apprentice)
                <span class="badge badge-purple" style="margin-bottom: 6px;">{{ $apprentice->user->full_name }} &bull; Functional Skills</span>
            @endif
            <h2 style="font-size: 22px; font-weight: 700; color: var(--gta-text);">English &amp; Maths</h2>
            <p style="font-size: 13px; color: var(--gta-muted); margin-top: 4px;">Assessment results, required training topics, diagnostic purposing and regulated qualifications.</p>
        </div>
        <button type="button" onclick="window.print()" class="gta-btn gta-btn-secondary" style="font-size: 13px;">Print / Save PDF</button>
    </div>

    <!-- Staff Apprentice Selector -->
    @if(Auth::user()->isStaff() && $allApprentices->isNotEmpty())
        <div class="gta-card" style="margin-bottom: 20px; padding: 14px;">
            <form method="GET" action="{{ route('english-maths.index') }}" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                <label style="font-size: 13px; font-weight: 600;">Select Apprentice:</label>
                <select name="apprentice" onchange="this.form.submit()" class="form-control" style="padding: 7px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px; min-width: 250px;">
                    @foreach($allApprentices as $learner)
                        <option value="{{ $learner->apprentice_id }}" {{ $apprentice?->apprentice_id == $learner->apprentice_id ? 'selected' : '' }}>
                            {{ $learner->user->full_name }} ({{ $learner->employer?->employer_name ?? 'GTA Partner' }})
                        </option>
                    @endforeach
                </select>
            </form>
        </div>
    @endif

    <!-- Assessment Summary Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 18px; margin-bottom: 24px;">
        <div class="gta-card" style="margin-bottom: 0;">
            <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--gta-muted); margin-bottom: 6px;">1. Initial Assessment</div>
            <h3 style="font-size: 16px; font-weight: 700; margin-bottom: 8px;">Level 2 Entry Standard</h3>
            <p style="font-size: 12px; color: var(--gta-muted); line-height: 1.5;">Initial screener completed at induction. Exemption checks verified against prior GCSE attainment.</p>
            <span class="badge badge-green" style="margin-top: 10px;">Requirement Met</span>
        </div>

        <div class="gta-card" style="margin-bottom: 0;">
            <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--gta-muted); margin-bottom: 6px;">2. Diagnostic &amp; Purpose</div>
            <h3 style="font-size: 16px; font-weight: 700; margin-bottom: 8px;">Maintain &amp; Apply in Workshop</h3>
            <p style="font-size: 12px; color: var(--gta-muted); line-height: 1.5;">Diagnostic assessment informs individual topic allocation. Technical workshop calculations emphasized.</p>
            <span class="badge badge-blue" style="margin-top: 10px;">Diagnostic Confirmed</span>
        </div>

        <div class="gta-card" style="margin-bottom: 0;">
            <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--gta-muted); margin-bottom: 6px;">3. Mandatory Qualifications</div>
            <h3 style="font-size: 16px; font-weight: 700; margin-bottom: 8px;">{{ $mandatoryQuals->count() }} Regulated Awards</h3>
            <p style="font-size: 12px; color: var(--gta-muted); line-height: 1.5;">IMI Level 2 Routine EV Maintenance &amp; F-Gas refrigerant handling.</p>
            <span class="badge badge-purple" style="margin-top: 10px;">Working Towards</span>
        </div>
    </div>

    <!-- 2 Column Split: Maths Topics & English Topics -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; align-items: start;">
        <!-- Maths Topics -->
        <div class="gta-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; border-bottom: 1px solid var(--gta-border); padding-bottom: 10px;">
                <h3 style="font-size: 16px; font-weight: 700;">Functional Mathematics ({{ $mathsTopics->count() }} Topics)</h3>
                <span class="badge badge-blue">Maths</span>
            </div>

            @if($mathsTopics->isEmpty())
                <p style="font-size: 12px; color: var(--gta-muted);">No assigned maths topics recorded.</p>
            @else
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    @foreach($mathsTopics as $topic)
                        <div style="padding: 10px 12px; background: var(--gta-bg); border-radius: 6px; display: flex; justify-content: space-between; align-items: center; font-size: 12px;">
                            <div>
                                <div style="font-weight: 600;">{{ $topic->topic }}</div>
                                <div style="font-size: 11px; color: var(--gta-muted);">{{ $topic->area }}</div>
                            </div>
                            <span class="badge {{ $topic->status === 'Completed' ? 'badge-green' : ($topic->status === 'In Progress' ? 'badge-amber' : 'badge-gray') }}">
                                {{ $topic->status }} ({{ $topic->planned_hours }}h)
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <!-- English Topics -->
        <div class="gta-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; border-bottom: 1px solid var(--gta-border); padding-bottom: 10px;">
                <h3 style="font-size: 16px; font-weight: 700;">Functional English ({{ $englishTopics->count() }} Topics)</h3>
                <span class="badge badge-purple">English</span>
            </div>

            @if($englishTopics->isEmpty())
                <p style="font-size: 12px; color: var(--gta-muted);">No assigned English topics recorded.</p>
            @else
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    @foreach($englishTopics as $topic)
                        <div style="padding: 10px 12px; background: var(--gta-bg); border-radius: 6px; display: flex; justify-content: space-between; align-items: center; font-size: 12px;">
                            <div>
                                <div style="font-weight: 600;">{{ $topic->topic }}</div>
                                <div style="font-size: 11px; color: var(--gta-muted);">{{ $topic->area }}</div>
                            </div>
                            <span class="badge {{ $topic->status === 'Completed' ? 'badge-green' : ($topic->status === 'In Progress' ? 'badge-amber' : 'badge-gray') }}">
                                {{ $topic->status }} ({{ $topic->planned_hours }}h)
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-portal-layout>

