<x-portal-layout>
    <div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: start; flex-wrap: wrap; gap: 16px;">
        <div>
            <span class="badge badge-purple" style="margin-bottom: 6px;">Staff &bull; Professional Development</span>
            <h2 style="font-size: 22px; font-weight: 700; color: var(--gta-text);">Staff Development &amp; CPD</h2>
            <p style="font-size: 13px; color: var(--gta-muted); margin-top: 4px;">One staff record. One development plan. CPD activities, observations, and appraisals.</p>
        </div>
        <div style="display: flex; gap: 10px;">
            <button type="button" onclick="window.print()" class="gta-btn gta-btn-secondary" style="font-size: 13px;">Print / Save PDF</button>
        </div>
    </div>

    @if(session('success'))
        <div style="background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 14px; border-radius: 6px; font-size: 13px; margin-bottom: 20px;">
            {{ session('success') }}
        </div>
    @endif

    <!-- Staff Selector Toolbar -->
    @if(Auth::user()->role === \App\Enums\UserRole::Admin || Auth::user()->role === \App\Enums\UserRole::Moderator)
        <div class="gta-card" style="margin-bottom: 20px; padding: 14px;">
            <form method="GET" action="{{ route('staff-development.index') }}" style="display: flex; gap: 14px; align-items: center; flex-wrap: wrap;">
                <label style="font-size: 13px; font-weight: 600;">Staff Member:</label>
                <select name="user_id" onchange="this.form.submit()" class="form-control" style="padding: 7px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px; min-width: 250px;">
                    @foreach($staffMembers as $member)
                        <option value="{{ $member->id }}" {{ $selectedStaff->id == $member->id ? 'selected' : '' }}>
                            {{ $member->full_name }} ({{ ucfirst($member->role->value) }})
                        </option>
                    @endforeach
                </select>
            </form>
        </div>
    @endif

    <!-- Stat Highlights -->
    <div class="gta-stat-grid" style="margin-bottom: 20px;">
        <div class="gta-stat-card">
            <small>Selected Staff</small>
            <strong>{{ $selectedStaff->full_name }}</strong>
            <span class="badge badge-blue" style="font-size: 10px; margin-top: 4px;">{{ ucfirst($selectedStaff->role->value) }}</span>
        </div>
        <div class="gta-stat-card">
            <small>CPD Activities</small>
            <strong style="color: #254c6a;">{{ $totalCpdHours }}</strong>
        </div>
        <div class="gta-stat-card">
            <small>Teaching Observations</small>
            <strong style="color: #15803d;">{{ $totalObservations }}</strong>
        </div>
        <div class="gta-stat-card">
            <small>Active Targets</small>
            <strong style="color: #b45309;">{{ $pendingTargets }}</strong>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px; align-items: start;">
        <!-- Left: CPD & Observations History -->
        <div>
            <div class="gta-card" style="margin-bottom: 24px;">
                <h3 style="font-size: 16px; font-weight: 700; border-bottom: 1px solid var(--gta-border); padding-bottom: 10px; margin-bottom: 16px;">
                    Development &amp; Observation Records
                </h3>

                @if($records->isEmpty())
                    <p style="font-size: 13px; color: var(--gta-muted); padding: 16px 0;">No development or observation records logged yet.</p>
                @else
                    <div style="display: flex; flex-direction: column; gap: 14px;">
                        @foreach($records as $rec)
                            <div style="padding: 16px; background: var(--gta-bg); border-radius: 8px; border-left: 4px solid {{ $rec->category === 'observation' ? '#16a34a' : '#254c6a' }};">
                                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 8px;">
                                    <div>
                                        <span class="badge {{ $rec->category === 'observation' ? 'badge-green' : 'badge-blue' }}" style="font-size: 11px;">
                                            {{ strtoupper($rec->category) }}
                                        </span>
                                        <h4 style="font-size: 14px; font-weight: 700; margin-top: 4px;">{{ $rec->title }}</h4>
                                    </div>
                                    <span style="font-size: 12px; color: var(--gta-muted);">{{ $rec->record_date->format('d M Y') }}</span>
                                </div>

                                <p style="font-size: 13px; color: var(--gta-text); line-height: 1.5; margin-bottom: 8px;">{{ $rec->feedback }}</p>

                                @if($rec->evidence_ref)
                                    <div style="font-size: 11px; color: var(--gta-muted); margin-bottom: 6px;">
                                        Evidence Ref: <code style="background: #e2e8f0; padding: 2px 6px; border-radius: 4px;">{{ $rec->evidence_ref }}</code>
                                    </div>
                                @endif

                                @if($rec->private_notes && Auth::user()->role === \App\Enums\UserRole::Admin)
                                    <div style="margin-top: 10px; padding: 10px; background: #fff1f2; border-left: 3px solid #e11d48; border-radius: 4px; font-size: 11px; color: #881337;">
                                        <strong>Private Management Note (Restricted):</strong> {{ $rec->private_notes }}
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <!-- Log Activity Form for Admins & Managers -->
            @if(Auth::user()->role === \App\Enums\UserRole::Admin || Auth::user()->role === \App\Enums\UserRole::Moderator)
                <div class="gta-card">
                    <h3 style="font-size: 16px; font-weight: 700; border-bottom: 1px solid var(--gta-border); padding-bottom: 10px; margin-bottom: 16px;">
                        Log CPD / Observation for {{ $selectedStaff->full_name }}
                    </h3>

                    <form method="POST" action="{{ route('staff-development.record', $selectedStaff->id) }}">
                        @csrf
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                            <div>
                                <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px;">Record Category *</label>
                                <select name="category" required class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;">
                                    <option value="cpd">Training &amp; External CPD</option>
                                    <option value="observation">Teaching Observation &amp; Practice Check</option>
                                    <option value="meetings">One-to-One / Appraisal</option>
                                    <option value="performance">Monthly Performance Review</option>
                                </select>
                            </div>
                            <div>
                                <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px;">Date *</label>
                                <input type="date" name="record_date" value="{{ now()->format('Y-m-d') }}" required class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;">
                            </div>
                        </div>

                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px;">Activity Title *</label>
                            <input type="text" name="title" required placeholder="e.g. EV High-Voltage Isolation Certification" class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;">
                        </div>

                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px;">Feedback &amp; Demonstrated Learning</label>
                            <textarea name="feedback" rows="3" placeholder="Summary of impact, learning points, or teaching assessment..." class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;"></textarea>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                            <div>
                                <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px;">Evidence Reference</label>
                                <input type="text" name="evidence_ref" placeholder="e.g. CERT-2026-IMI-04" class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;">
                            </div>
                            <div style="display: flex; align-items: center; padding-top: 20px;">
                                <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; cursor: pointer;">
                                    <input type="checkbox" name="is_shared" value="1" checked>
                                    Share details with employee
                                </label>
                            </div>
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; color: #b91c1c;">Private Management Notes (Never shared with employee)</label>
                            <textarea name="private_notes" rows="2" placeholder="Restricted management notes..." class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;"></textarea>
                        </div>

                        <button type="submit" class="gta-btn gta-btn-primary" style="padding: 10px 24px; font-size: 13px; font-weight: 700;">
                            Save Development Record
                        </button>
                    </form>
                </div>
            @endif
        </div>

        <!-- Right: SMART Targets -->
        <div>
            <div class="gta-card" style="margin-bottom: 24px;">
                <h3 style="font-size: 16px; font-weight: 700; border-bottom: 1px solid var(--gta-border); padding-bottom: 10px; margin-bottom: 16px;">
                    Agreed SMART Targets
                </h3>

                @if($targets->isEmpty())
                    <p style="font-size: 12px; color: var(--gta-muted);">No active targets agreed yet.</p>
                @else
                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        @foreach($targets as $tgt)
                            <div style="padding: 12px; background: var(--gta-bg); border-radius: 6px; border-left: 3px solid {{ $tgt->status === 'Achieved' ? '#16a34a' : '#254c6a' }};">
                                <div style="display: flex; justify-content: space-between; align-items: start; gap: 6px;">
                                    <strong style="font-size: 13px;">{{ $tgt->target }}</strong>
                                    <span class="badge {{ $tgt->status === 'Achieved' ? 'badge-green' : 'badge-amber' }}" style="font-size: 10px;">{{ $tgt->status }}</span>
                                </div>
                                @if($tgt->measure)
                                    <p style="font-size: 11px; color: var(--gta-muted); margin-top: 4px;"><strong>Measure:</strong> {{ $tgt->measure }}</p>
                                @endif
                                <div style="font-size: 11px; color: var(--gta-muted); margin-top: 6px;">
                                    Target Date: <strong>{{ $tgt->deadline ? $tgt->deadline->format('d M Y') : 'Ongoing' }}</strong>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <!-- Add Target Form -->
            @if(Auth::user()->role === \App\Enums\UserRole::Admin)
                <div class="gta-card">
                    <h4 style="font-size: 14px; font-weight: 700; margin-bottom: 12px;">Add SMART Target</h4>
                    <form method="POST" action="{{ route('staff-development.target', $selectedStaff->id) }}">
                        @csrf
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-size: 11px; font-weight: 600; margin-bottom: 4px;">Target *</label>
                            <textarea name="target" rows="2" required placeholder="What will the employee achieve?" class="form-control" style="width: 100%; padding: 6px 10px; font-size: 12px; border: 1px solid var(--gta-border); border-radius: 4px;"></textarea>
                        </div>
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-size: 11px; font-weight: 600; margin-bottom: 4px;">Success Measure</label>
                            <textarea name="measure" rows="1" placeholder="How will success be demonstrated?" class="form-control" style="width: 100%; padding: 6px 10px; font-size: 12px; border: 1px solid var(--gta-border); border-radius: 4px;"></textarea>
                        </div>
                        <div style="margin-bottom: 14px;">
                            <label style="display: block; font-size: 11px; font-weight: 600; margin-bottom: 4px;">Agreed Deadline</label>
                            <input type="date" name="deadline" class="form-control" style="width: 100%; padding: 6px 10px; font-size: 12px; border: 1px solid var(--gta-border); border-radius: 4px;">
                        </div>
                        <button type="submit" class="gta-btn gta-btn-secondary" style="width: 100%; padding: 8px; font-size: 12px;">
                            Add Target
                        </button>
                    </form>
                </div>
            @endif
        </div>
    </div>
</x-portal-layout>

