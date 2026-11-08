<x-portal-layout>
    <div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: start; flex-wrap: wrap; gap: 16px;">
        <div>
            <h2 style="font-size: 22px; font-weight: 700; color: #0f172a;">Apprentice Journey &amp; Visual Roadmap</h2>
            <p style="font-size: 13px; color: #64748b; margin-top: 4px;">
                Learner: <strong>{{ $apprentice->user->full_name }}</strong> &bull; Standard: <strong>{{ $apprentice->standard?->title ?? 'Autocare Technician' }}</strong> &bull; Employer: <strong>{{ $apprentice->employer?->employer_name ?? 'GTA Partner' }}</strong>
            </p>
        </div>
        <div>
            <a href="{{ route('tasks.index') }}" style="display: inline-block; padding: 9px 16px; background: #254c6a; color: #fff; text-decoration: none; border-radius: 6px; font-size: 13px; font-weight: 600;">
                Go to Tasks &rarr;
            </a>
        </div>
    </div>

    <!-- Quick Status Pills Bar -->
    <div class="gta-card" style="padding: 16px 20px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
        <div style="display: flex; gap: 20px; align-items: center; font-size: 13px;">
            <span>Attendance: <strong class="badge {{ $attendanceRag['badge_class'] }}">{{ $attendanceRag['label'] }}</strong></span>
            <span>OTJ Delivery: <strong class="badge {{ $otjMetrics['rag_badge_class'] }}">{{ $otjMetrics['rag_label'] }}</strong></span>
            <span>Completed Tasks: <strong>{{ $apprentice->tasks->where('status', 'Completed')->count() }} / 50</strong></span>
        </div>
        <div style="font-size: 12px; color: #64748b;">
            Start: <strong>{{ $apprentice->start_date ? $apprentice->start_date->format('M Y') : 'N/A' }}</strong> &bull; Target Gateway: <strong>{{ $apprentice->planned_end_date ? $apprentice->planned_end_date->format('M Y') : 'N/A' }}</strong>
        </div>
    </div>

    <!-- Visual Chronological Timeline -->
    <div style="position: relative; padding-left: 36px; border-left: 3px solid #254c6a; margin-left: 20px; display: flex; flex-direction: column; gap: 24px;">
        
        <!-- Milestone 1: Onboarding & Start -->
        <div style="position: relative;">
            <div style="position: absolute; left: -49px; top: 0; width: 22px; height: 22px; background: #15803d; border: 4px solid #fff; border-radius: 50%; box-shadow: 0 0 0 2px #15803d;"></div>
            <div class="gta-card" style="margin-bottom: 0;">
                <span class="badge badge-green" style="margin-bottom: 6px;">Commenced</span>
                <h4 style="font-size: 15px; font-weight: 700; color: #0f172a;">Induction &amp; Training Onboarding</h4>
                <p style="font-size: 12px; color: #64748b; margin-top: 4px;">Registered on Standard ST0482 Autocare Technician with {{ $apprentice->employer?->employer_name ?? 'workplace garage' }}.</p>
                <small style="color: #64748b; font-size: 11px;">Recorded Start: {{ $apprentice->start_date ? $apprentice->start_date->format('d M Y') : 'Active' }}</small>
            </div>
        </div>

        <!-- Milestone 2: 10 Training Blocks Grid -->
        <div style="position: relative;">
            <div style="position: absolute; left: -49px; top: 0; width: 22px; height: 22px; background: #254c6a; border: 4px solid #fff; border-radius: 50%; box-shadow: 0 0 0 2px #254c6a;"></div>
            <div class="gta-card" style="margin-bottom: 0;">
                <span class="badge badge-blue" style="margin-bottom: 6px;">10 Core Technical Blocks</span>
                <h4 style="font-size: 15px; font-weight: 700; color: #0f172a;">Autocare Practical Curriculum (50 Tasks)</h4>
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; margin-top: 14px;">
                    @foreach($blockProgress as $num => $info)
                        @php
                            $bStatus = $info['status'];
                            $bg = match($bStatus) {
                                'Completed' => '#dcfce7',
                                'In Progress' => '#fef3c7',
                                default => '#f1f5f9',
                            };
                            $txt = match($bStatus) {
                                'Completed' => '#15803d',
                                'In Progress' => '#b45309',
                                default => '#64748b',
                            };
                        @endphp
                        <div style="background: {{ $bg }}; padding: 10px; border-radius: 6px; font-size: 12px;">
                            <div style="display: flex; justify-content: space-between; font-weight: 700; color: {{ $txt }};">
                                <span>Block {{ $num }}</span>
                                <span>{{ $info['completed'] }}/{{ $info['total'] }}</span>
                            </div>
                            <small style="display: block; color: #475569; margin-top: 4px;">{{ Str::limit($info['block']->title, 24) }}</small>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <!-- Milestone 3: Progress Reviews -->
        <div style="position: relative;">
            <div style="position: absolute; left: -49px; top: 0; width: 22px; height: 22px; background: #b45309; border: 4px solid #fff; border-radius: 50%; box-shadow: 0 0 0 2px #b45309;"></div>
            <div class="gta-card" style="margin-bottom: 0;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <span class="badge badge-amber">Statutory Reviews</span>
                    <a href="{{ route('reviews.index', $apprentice->apprentice_id) }}" style="font-size: 12px; color: #254c6a; font-weight: 600; text-decoration: underline;">Open Reviews &rarr;</a>
                </div>
                <h4 style="font-size: 15px; font-weight: 700; color: #0f172a;">10-Week &amp; 70-Day Periodic Reviews</h4>
                <p style="font-size: 12px; color: #64748b; margin-top: 4px;">Regular 3-party progress checks between Apprentice, Employer, and Mentor.</p>

                <div style="display: flex; gap: 12px; margin-top: 12px; flex-wrap: wrap;">
                    @forelse($reviews as $rev)
                        <div style="padding: 8px 14px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px;">
                            <strong>Review #{{ $rev->review_sequence }}</strong>: {{ $rev->due_date->format('d M Y') }}
                            <span class="badge {{ $rev->status === 'Signed' ? 'badge-green' : 'badge-amber' }}" style="margin-left: 6px;">{{ $rev->status }}</span>
                        </div>
                    @empty
                        <span style="font-size: 12px; color: #64748b;">Initial 10-week review scheduled upon commencement.</span>
                    @endforelse
                </div>
            </div>
        </div>

        <!-- Milestone 4: EPA Gateway & End-Point Assessment -->
        <div style="position: relative;">
            <div style="position: absolute; left: -49px; top: 0; width: 22px; height: 22px; background: #64748b; border: 4px solid #fff; border-radius: 50%; box-shadow: 0 0 0 2px #64748b;"></div>
            <div class="gta-card" style="margin-bottom: 0;">
                <span class="badge" style="background: #f1f5f9; color: #64748b; margin-bottom: 6px;">Final Gateway</span>
                <h4 style="font-size: 15px; font-weight: 700; color: #0f172a;">End-Point Assessment (EPA) Gateway</h4>
                <p style="font-size: 12px; color: #64748b; margin-top: 4px;">
                    Requires 100% completion of 50 tasks across 10 blocks, full 663 OTJ hours, signed-off tripartite reviews, and Functional Skills Level 2.
                </p>
                <small style="color: #64748b; font-size: 11px;">Target EPA Window: {{ $apprentice->planned_end_date ? $apprentice->planned_end_date->format('M Y') : 'Month 30' }}</small>
            </div>
        </div>

    </div>
</x-portal-layout>

