<x-portal-layout>
    <div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: start; flex-wrap: wrap; gap: 16px;">
        <div>
            @if($apprentice)
                <span class="badge badge-purple" style="margin-bottom: 6px;">{{ $apprentice->user->full_name }} &bull; Training Action Plan</span>
            @endif
            <h2 style="font-size: 22px; font-weight: 700; color: var(--gta-text);">Action Plan</h2>
            <p style="font-size: 13px; color: var(--gta-muted); margin-top: 4px;">Complete your agreed actions, upload evidence, and verify tutor checking milestones.</p>
        </div>
        <div style="display: flex; gap: 10px;">
            <button type="button" onclick="document.getElementById('addActionForm').scrollIntoView({behavior:'smooth'})" class="gta-btn gta-btn-primary" style="font-size: 13px; font-weight: 700;">
                + Add Action
            </button>
            <button type="button" onclick="window.print()" class="gta-btn gta-btn-secondary" style="font-size: 13px;">Download PDF</button>
        </div>
    </div>

    @if(session('success'))
        <div style="background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 14px; border-radius: 6px; font-size: 13px; margin-bottom: 20px;">
            {{ session('success') }}
        </div>
    @endif

    <!-- Staff Apprentice Selector -->
    @if(Auth::user()->isStaff() && $allApprentices->isNotEmpty())
        <div class="gta-card" style="margin-bottom: 20px; padding: 14px;">
            <form method="GET" action="{{ route('action-plan.index') }}" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
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

    <!-- Action Counts -->
    <div class="gta-stat-grid" style="margin-bottom: 20px;">
        <div class="gta-stat-card">
            <small>Active Tasks (To Do)</small>
            <strong style="color: #254c6a;">{{ $todoItems->count() }}</strong>
        </div>
        <div class="gta-stat-card">
            <small>Completed Milestones</small>
            <strong style="color: #15803d;">{{ $completedItems->count() }}</strong>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px; align-items: start;">
        <!-- Left Column: Tasks Lists -->
        <div>
            <!-- To Do Section -->
            <div class="gta-card" style="margin-bottom: 24px;">
                <h3 style="font-size: 16px; font-weight: 700; border-bottom: 1px solid var(--gta-border); padding-bottom: 10px; margin-bottom: 16px;">
                    To Do ({{ $todoItems->count() }})
                </h3>

                @if($todoItems->isEmpty())
                    <p style="font-size: 13px; color: var(--gta-muted); padding: 12px 0;">No pending actions to complete.</p>
                @else
                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        @foreach($todoItems as $item)
                            @php
                                $isOverdue = $item->target_date && $item->target_date->isPast();
                            @endphp
                            <div style="padding: 14px; background: var(--gta-bg); border-radius: 6px; border-left: 4px solid {{ $isOverdue ? '#e52b45' : '#254c6a' }};">
                                <div style="display: flex; justify-content: space-between; align-items: start; gap: 10px;">
                                    <div>
                                        <span class="badge badge-gray" style="font-size: 10px;">{{ $item->owner_role }}</span>
                                        <h4 style="font-size: 14px; font-weight: 600; margin-top: 4px;">{{ $item->action_description }}</h4>
                                    </div>
                                    <form method="POST" action="{{ route('action-plan.complete', $item->action_item_id) }}">
                                        @csrf
                                        <button type="submit" class="gta-btn gta-btn-secondary" style="font-size: 11px; padding: 4px 10px;">
                                            ✓ Mark Done
                                        </button>
                                    </form>
                                </div>
                                <div style="display: flex; justify-content: space-between; font-size: 11px; color: var(--gta-muted); margin-top: 8px;">
                                    <span>Target Deadline: <strong style="color: {{ $isOverdue ? '#e52b45' : 'inherit' }};">{{ $item->target_date ? $item->target_date->format('d M Y') : 'N/A' }}</strong></span>
                                    @if($isOverdue)
                                        <span class="badge badge-red" style="font-size: 9px;">OVERDUE</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <!-- Completed Section -->
            <div class="gta-card">
                <h3 style="font-size: 16px; font-weight: 700; border-bottom: 1px solid var(--gta-border); padding-bottom: 10px; margin-bottom: 16px;">
                    Completed History ({{ $completedItems->count() }})
                </h3>

                @if($completedItems->isEmpty())
                    <p style="font-size: 12px; color: var(--gta-muted);">No completed actions recorded yet.</p>
                @else
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        @foreach($completedItems as $item)
                            <div style="padding: 10px 14px; background: var(--gta-bg); border-radius: 4px; border-left: 3px solid #16a34a; font-size: 12px; display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <strong style="text-decoration: line-through; color: var(--gta-muted);">{{ $item->action_description }}</strong>
                                    <div style="font-size: 11px; color: var(--gta-muted);">Owner: {{ $item->owner_role }}</div>
                                </div>
                                <span class="badge badge-green" style="font-size: 10px;">Completed {{ $item->completed_at ? $item->completed_at->format('d M Y') : '' }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <!-- Right Column: Add SMART Action Form -->
        <div class="gta-card" id="addActionForm">
            <h3 style="font-size: 16px; font-weight: 700; border-bottom: 1px solid var(--gta-border); padding-bottom: 10px; margin-bottom: 16px;">
                Add Agreed SMART Target
            </h3>

            @if($apprentice)
                <form method="POST" action="{{ route('action-plan.store', $apprentice->apprentice_id) }}">
                    @csrf
                    <div style="margin-bottom: 14px;">
                        <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px;">Specific Action / Work Needed *</label>
                        <textarea name="action_description" rows="3" required placeholder="e.g. Complete the brake-system practice worksheet questions..." class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;"></textarea>
                    </div>

                    <div style="margin-bottom: 14px;">
                        <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px;">Responsible Person *</label>
                        <select name="owner_role" required class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;">
                            <option value="Apprentice">Apprentice</option>
                            <option value="Tutor">Tutor</option>
                            <option value="Employer / workplace mentor">Employer / workplace mentor</option>
                            <option value="Learning &amp; Progress Mentor">Learning &amp; Progress Mentor</option>
                        </select>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px;">Agreed Target Deadline *</label>
                        <input type="date" name="target_date" value="{{ now()->addWeeks(2)->format('Y-m-d') }}" required class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;">
                    </div>

                    <button type="submit" class="gta-btn gta-btn-primary" style="width: 100%; padding: 10px; font-size: 13px; font-weight: 700;">
                        Save SMART Target
                    </button>
                </form>
            @endif
        </div>
    </div>
</x-portal-layout>

