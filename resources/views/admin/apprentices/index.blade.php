<x-portal-layout>
    <div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: start; flex-wrap: wrap; gap: 16px;">
        <div>
            <h2 style="font-size: 22px; font-weight: 700; color: var(--gta-text);">Apprentices Directory</h2>
            <p style="font-size: 13px; color: var(--gta-muted); margin-top: 4px;">Manage enrolled learners, view training progress, attendance RAG, and staff assignments.</p>
        </div>
        <div>
            <a href="{{ route('admin.apprentices.create') }}" class="gta-btn gta-btn-primary" style="padding: 10px 18px; font-size: 13px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 8px;">
                <span>+</span> Add Apprentice
            </a>
        </div>
    </div>

    @if(session('success'))
        <div style="background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 14px; border-radius: 6px; font-size: 13px; margin-bottom: 20px;">
            {{ session('success') }}
        </div>
    @endif

    <!-- Metrics Strip -->
    <div class="gta-stat-grid" style="margin-bottom: 20px;">
        <div class="gta-stat-card">
            <small>Total Apprentices</small>
            <strong>{{ $totalCount }}</strong>
        </div>
        <div class="gta-stat-card">
            <small>Active in Learning</small>
            <strong style="color: #15803d;">{{ $liveCount }}</strong>
        </div>
        <div class="gta-stat-card">
            <small>Registered Employers</small>
            <strong style="color: #254c6a;">{{ $employers->count() }}</strong>
        </div>
    </div>

    <!-- Search & Filter Bar -->
    <div class="gta-card" style="margin-bottom: 20px; padding: 16px;">
        <form method="GET" action="{{ route('admin.apprentices.index') }}" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
            <div style="flex: 2; min-width: 220px;">
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Search by name, email, or username..." class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;">
            </div>
            <div style="flex: 1; min-width: 160px;">
                <select name="status" class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;">
                    <option value="">All Statuses</option>
                    <option value="Live" {{ request('status') === 'Live' ? 'selected' : '' }}>Live</option>
                    <option value="Completed" {{ request('status') === 'Completed' ? 'selected' : '' }}>Completed</option>
                    <option value="Withdrawn" {{ request('status') === 'Withdrawn' ? 'selected' : '' }}>Withdrawn</option>
                </select>
            </div>
            <div style="flex: 1; min-width: 180px;">
                <select name="employer_id" class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;">
                    <option value="">All Employers</option>
                    @foreach($employers as $emp)
                        <option value="{{ $emp->employer_id }}" {{ request('employer_id') == $emp->employer_id ? 'selected' : '' }}>{{ $emp->employer_name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <button type="submit" class="gta-btn gta-btn-secondary" style="padding: 8px 16px; font-size: 13px;">Filter</button>
                @if(request()->hasAny(['search', 'status', 'employer_id']))
                    <a href="{{ route('admin.apprentices.index') }}" style="font-size: 12px; color: var(--gta-muted); margin-left: 8px; text-decoration: underline;">Clear</a>
                @endif
            </div>
        </form>
    </div>

    <!-- Apprentice Table -->
    <div class="gta-card">
        <table class="gta-table">
            <thead>
                <tr>
                    <th>Learner</th>
                    <th>Employer</th>
                    <th>Standard / Level</th>
                    <th>Assigned Tutor</th>
                    <th>OTJ Progress</th>
                    <th>Attendance RAG</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($apprenticesWithRag as $row)
                    @php
                        $app = $row['model'];
                        $rag = $row['rag'];
                    @endphp
                    <tr>
                        <td>
                            <div style="font-weight: 700; color: var(--gta-text);">{{ $app->user->full_name }}</div>
                            <div style="font-size: 11px; color: var(--gta-muted);">{{ $app->user->email }}</div>
                        </td>
                        <td>{{ $app->employer?->employer_name ?? 'GTA Partner Garage' }}</td>
                        <td>
                            <div>{{ $app->standard?->title ?? 'Autocare Technician' }}</div>
                            <span class="badge badge-gray" style="font-size: 10px;">Level {{ $app->standard?->level ?? 2 }}</span>
                        </td>
                        <td>{{ $app->trainer?->full_name ?? 'Alex Taylor' }}</td>
                        <td>
                            <strong>{{ number_format($app->otj_hours_completed, 1) }}</strong> / {{ number_format($app->otj_target_hours, 0) }}h
                            <div style="width: 80px; height: 5px; background: #e2e8f0; border-radius: 3px; margin-top: 4px; overflow: hidden;">
                                @php
                                    $pct = $app->otj_target_hours > 0 ? min(100, round(($app->otj_hours_completed / $app->otj_target_hours) * 100)) : 0;
                                @endphp
                                <div style="width: {{ $pct }}%; height: 100%; background: #254c6a;"></div>
                            </div>
                        </td>
                        <td>
                            <span class="badge {{ $rag['badge_class'] }}" style="{{ $rag['badge_style'] }}">
                                {{ $rag['rating'] }} ({{ $rag['percentage'] ?? 0 }}%)
                            </span>
                        </td>
                        <td>
                            <span class="badge {{ $app->status === 'Live' ? 'badge-green' : 'badge-gray' }}">{{ $app->status }}</span>
                        </td>
                        <td>
                            <div style="display: flex; gap: 8px;">
                                <a href="{{ route('journey.show', $app->apprentice_id) }}" style="font-size: 12px; color: var(--gta-steel-blue); font-weight: 600; text-decoration: underline;" title="View visual journey">Journey</a>
                                <a href="{{ route('apprentice.tasks', $app->apprentice_id) }}" style="font-size: 12px; color: var(--gta-accent); font-weight: 600; text-decoration: underline;" title="View 10-block curriculum tasks">Tasks</a>
                                <a href="{{ route('apprentice.reviews', $app->apprentice_id) }}" style="font-size: 12px; color: #ea580c; font-weight: 600; text-decoration: underline;" title="View progress reviews">Reviews</a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 30px; color: var(--gta-muted);">
                            No apprentices matching the criteria found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if($apprentices->hasPages())
            <div style="padding: 16px; border-top: 1px solid var(--gta-border);">
                {{ $apprentices->links() }}
            </div>
        @endif
    </div>
</x-portal-layout>

