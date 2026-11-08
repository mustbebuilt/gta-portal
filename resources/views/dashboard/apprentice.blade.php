<x-portal-layout>
    <div style="margin-bottom: 24px;">
        <h2 style="font-size: 22px; font-weight: 700; color: var(--gta-text);">My Apprenticeship Workspace</h2>
        <p style="font-size: 13px; color: var(--gta-muted); margin-top: 4px;">Welcome, {{ Auth::user()->full_name }}. Here is an overview of your training standard, evidence tasks, and progress.</p>
    </div>

    @if(!$apprentice)
        <div class="gta-card">
            <p>No active apprenticeship record found linked to your account.</p>
        </div>
    @else
        <!-- Apprentice Overview Banner -->
        <div class="gta-card" style="background: linear-gradient(135deg, #17212b 0%, #254c6a 100%); color: #fff; border: none; margin-bottom: 24px;">
            <div style="display: flex; justify-content: space-between; align-items: start; flex-wrap: wrap; gap: 16px;">
                <div>
                    <span class="badge badge-blue" style="background: rgba(255,255,255,0.2); color: #fff; margin-bottom: 8px;">{{ $apprentice->status }}</span>
                    <h3 style="font-size: 20px; font-weight: 700;">{{ $apprentice->standard?->title ?? 'Autocare Technician' }} (Level {{ $apprentice->standard?->level ?? 2 }})</h3>
                    <p style="font-size: 13px; color: #cbd5e1; margin-top: 6px;">
                        Employer: <strong>{{ $apprentice->employer?->employer_name ?? 'GTA Partner Garage' }}</strong> &bull; Assigned Tutor: <strong>{{ $apprentice->trainer?->full_name ?? 'Alex Taylor' }}</strong>
                    </p>
                </div>
                <div style="text-align: right; font-size: 12px; color: #cbd5e1;">
                    <div>Start Date: <strong>{{ $apprentice->start_date ? $apprentice->start_date->format('d M Y') : 'N/A' }}</strong></div>
                    <div style="margin-top: 4px;">Planned EPA Gateway: <strong>{{ $apprentice->planned_end_date ? $apprentice->planned_end_date->format('d M Y') : 'N/A' }}</strong></div>
                </div>
            </div>
        </div>

        <!-- Progress Metrics -->
        <div class="gta-stat-grid" style="margin-bottom: 24px;">
            <div class="gta-stat-card">
                <small>Completed Tasks</small>
                <strong>{{ $completedTasksCount }} <span style="font-size: 14px; font-weight: 500; color: var(--gta-muted);">/ 50 Tasks</span></strong>
            </div>
            <div class="gta-stat-card">
                <small>Submissions Under Review</small>
                <strong style="color: #b45309;">{{ $submittedTasksCount }}</strong>
            </div>
            <div class="gta-stat-card">
                <small>Off-The-Job (OTJ) Completed</small>
                <strong style="color: #15803d;">{{ number_format($apprentice->otj_hours_completed, 1) }} <span style="font-size: 14px; font-weight: 500; color: var(--gta-muted);">/ {{ number_format($apprentice->otj_target_hours, 0) }} hrs</span></strong>
            </div>
        </div>

        <!-- Quick Access Navigation Grid -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;">
            <a href="{{ route('tasks.index') }}" class="gta-card" style="text-decoration: none; color: inherit; display: block; border-left: 4px solid var(--gta-accent); transition: transform 0.15s ease;">
                <div style="font-size: 13px; font-weight: 700; color: var(--gta-accent); margin-bottom: 4px;">10-BLOCK CURRICULUM</div>
                <div style="font-weight: 600; font-size: 15px;">Tasks & Evidence &rarr;</div>
                <p style="font-size: 12px; color: var(--gta-muted); margin-top: 6px;">Upload evidence, view tutor feedback and criteria.</p>
            </a>

            <a href="{{ route('journey.show') }}" class="gta-card" style="text-decoration: none; color: inherit; display: block; border-left: 4px solid var(--gta-steel-blue); transition: transform 0.15s ease;">
                <div style="font-size: 13px; font-weight: 700; color: var(--gta-steel-blue); margin-bottom: 4px;">ROADMAP</div>
                <div style="font-weight: 600; font-size: 15px;">Apprentice Journey &rarr;</div>
                <p style="font-size: 12px; color: var(--gta-muted); margin-top: 6px;">Visual block milestones, timeline and progress bar.</p>
            </a>

            <a href="{{ route('attendance.index') }}" class="gta-card" style="text-decoration: none; color: inherit; display: block; border-left: 4px solid #16a34a; transition: transform 0.15s ease;">
                <div style="font-size: 13px; font-weight: 700; color: #16a34a; margin-bottom: 4px;">REGISTERS</div>
                <div style="font-weight: 600; font-size: 15px;">Attendance & RAG &rarr;</div>
                <p style="font-size: 12px; color: var(--gta-muted); margin-top: 6px;">Workshop and classroom registers with percentage breakdown.</p>
            </a>

            <a href="{{ route('otj.index') }}" class="gta-card" style="text-decoration: none; color: inherit; display: block; border-left: 4px solid #0284c7; transition: transform 0.15s ease;">
                <div style="font-size: 13px; font-weight: 700; color: #0284c7; margin-bottom: 4px;">TRAINING HOURS</div>
                <div style="font-weight: 600; font-size: 15px;">Off-The-Job (OTJ) &rarr;</div>
                <p style="font-size: 12px; color: var(--gta-muted); margin-top: 6px;">Logged training entries and tutor verifications.</p>
            </a>

            <a href="{{ route('reviews.index') }}" class="gta-card" style="text-decoration: none; color: inherit; display: block; border-left: 4px solid #ea580c; transition: transform 0.15s ease;">
                <div style="font-size: 13px; font-weight: 700; color: #ea580c; margin-bottom: 4px;">TRI-PARTITE REVIEWS</div>
                <div style="font-weight: 600; font-size: 15px;">Progress Reviews &rarr;</div>
                <p style="font-size: 12px; color: var(--gta-muted); margin-top: 6px;">10-week and 70-day review meetings and signatures.</p>
            </a>
        </div>

        <!-- 2-Column Split: Functional Skills & Qualifications / Action Plan -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 20px;">
            <!-- Functional Skills & Qualifications Card -->
            <div class="gta-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid var(--gta-border); padding-bottom: 10px;">
                    <h3 style="font-size: 16px; font-weight: 700;">Functional Skills & Qualifications</h3>
                    <span class="badge badge-gray" style="font-size: 11px;">Maths &bull; English &bull; Regulated</span>
                </div>

                @if($apprentice->mandatoryQualifications->isNotEmpty())
                    <div style="margin-bottom: 16px;">
                        <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; color: var(--gta-muted); margin-bottom: 8px;">Mandatory Qualifications</div>
                        @foreach($apprentice->mandatoryQualifications as $qual)
                            <div style="padding: 10px 12px; background: var(--gta-bg); border-radius: 6px; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <div style="font-weight: 600; font-size: 13px;">{{ $qual->title }}</div>
                                    <div style="font-size: 11px; color: var(--gta-muted);">{{ $qual->awarding_body }} &bull; Level {{ $qual->level }}</div>
                                </div>
                                <span class="badge {{ $qual->status === 'Achieved' ? 'badge-green' : 'badge-amber' }}">{{ $qual->status }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if($apprentice->functionalSkills->isNotEmpty())
                    <div>
                        <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; color: var(--gta-muted); margin-bottom: 8px;">Functional Skills Topic Measures</div>
                        <div style="max-height: 260px; overflow-y: auto;">
                            @foreach($apprentice->functionalSkills as $fs)
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-bottom: 1px solid var(--gta-border); font-size: 12px;">
                                    <div>
                                        <span class="badge {{ $fs->subject === 'Maths' ? 'badge-blue' : 'badge-purple' }}" style="font-size: 10px; padding: 2px 6px;">{{ $fs->subject }}</span>
                                        <span style="font-weight: 500; margin-left: 6px;">{{ $fs->topic }}</span>
                                    </div>
                                    <div>
                                        <span class="badge {{ $fs->status === 'Secure' ? 'badge-green' : ($fs->status === 'In Progress' ? 'badge-amber' : 'badge-gray') }}" style="font-size: 10px;">
                                            {{ $fs->status }} ({{ $fs->hours_spent }}h)
                                        </span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            <!-- SMART Action Plan Card -->
            <div class="gta-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid var(--gta-border); padding-bottom: 10px;">
                    <h3 style="font-size: 16px; font-weight: 700;">Active SMART Action Plan</h3>
                    <span class="badge badge-blue" style="font-size: 11px;">Agreed Targets</span>
                </div>

                @php
                    $allActionItems = $apprentice->actionPlans->flatMap->items;
                @endphp

                @if($allActionItems->isEmpty())
                    <p style="font-size: 13px; color: var(--gta-muted);">No active action plan items recorded yet.</p>
                @else
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        @foreach($allActionItems as $item)
                            <div style="padding: 12px; background: var(--gta-bg); border-left: 3px solid {{ $item->status === 'Completed' ? '#16a34a' : ($item->status === 'In Progress' ? '#254c6a' : '#94a3b8') }}; border-radius: 4px;">
                                <div style="display: flex; justify-content: space-between; align-items: start; gap: 8px;">
                                    <div style="font-weight: 600; font-size: 13px;">{{ $item->target }}</div>
                                    <span class="badge {{ $item->status === 'Completed' ? 'badge-green' : ($item->status === 'In Progress' ? 'badge-blue' : 'badge-gray') }}" style="font-size: 10px;">
                                        {{ $item->status }}
                                    </span>
                                </div>
                                <div style="font-size: 12px; color: var(--gta-muted); margin-top: 4px;">{{ $item->action }}</div>
                                <div style="display: flex; justify-content: space-between; font-size: 11px; color: var(--gta-muted); margin-top: 8px;">
                                    <span>Target Date: <strong>{{ $item->target_date ? $item->target_date->format('d M Y') : 'N/A' }}</strong></span>
                                    <span>Success: {{ $item->success_criteria }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif
</x-portal-layout>
