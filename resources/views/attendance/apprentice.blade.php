<x-portal-layout>
    <div style="margin-bottom: 20px;">
        <a href="{{ route('attendance.index') }}" style="font-size: 13px; color: #64748b; text-decoration: none;">&larr; Back to Attendance Overview</a>
        <div style="display: flex; justify-content: space-between; align-items: start; margin-top: 10px; flex-wrap: wrap; gap: 14px;">
            <div>
                <h2 style="font-size: 22px; font-weight: 700; color: #0f172a;">Attendance Ledger: {{ $apprentice->user->full_name }}</h2>
                <p style="font-size: 13px; color: #64748b; margin-top: 4px;">
                    Employer: <strong>{{ $apprentice->employer?->employer_name ?? 'GTA Partner' }}</strong> &bull; Total Scheduled Sessions: <strong>{{ $rag['total_sessions'] }}</strong>
                </p>
            </div>
            <div>
                <span class="badge {{ $rag['badge_class'] }}" style="font-size: 14px; padding: 6px 16px; {{ $rag['badge_style'] }}">
                    {{ $rag['label'] }}
                </span>
            </div>
        </div>
    </div>

    <!-- Attendance Breakdown Stats -->
    <div class="gta-stat-grid">
        <div class="gta-stat-card">
            <small>Total Sessions</small>
            <strong>{{ $rag['total_sessions'] }}</strong>
        </div>
        <div class="gta-stat-card">
            <small>Attended Sessions</small>
            <strong style="color: #15803d;">{{ $rag['attended_sessions'] }}</strong>
        </div>
        <div class="gta-stat-card">
            <small>Attendance Percentage</small>
            <strong style="color: #254c6a;">{{ $rag['percentage'] !== null ? $rag['percentage'] . '%' : 'N/A' }}</strong>
        </div>
    </div>

    <!-- Attendance History Table -->
    <div class="gta-card">
        <header>
            <h3 style="font-size: 16px; font-weight: 700;">Recorded Session History</h3>
        </header>

        @if($records->isEmpty())
            <div style="padding: 24px; text-align: center; color: #64748b; font-size: 13px;">
                No attendance sessions recorded yet for this learner.
            </div>
        @else
            <table class="gta-table">
                <thead>
                    <tr>
                        <th>Date &amp; Time</th>
                        <th>Training Topic</th>
                        <th>Attendance Status</th>
                        <th>Authorised Code</th>
                        <th>Arrival Time</th>
                        <th>Marked By</th>
                        <th>Comments</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($records as $rec)
                        @php
                            $statusBadge = match($rec->status) {
                                'Present' => 'badge-green',
                                'Late' => 'badge-amber',
                                'Authorised Absence' => 'badge-blue',
                                default => 'badge-red',
                            };
                        @endphp
                        <tr>
                            <td><strong>{{ $rec->session->session_datetime->format('d M Y, H:i') }}</strong></td>
                            <td>{{ $rec->session->topic }}</td>
                            <td>
                                <span class="badge {{ $statusBadge }}">
                                    {{ $rec->status }}
                                </span>
                            </td>
                            <td>
                                {{ $rec->authorised_code ? $rec->authorised_code : '-' }}
                            </td>
                            <td>
                                {{ $rec->arrival_datetime ? $rec->arrival_datetime->format('H:i') : '-' }}
                            </td>
                            <td>{{ $rec->marker?->full_name ?? 'GTA Tutor' }}</td>
                            <td>{{ $rec->comment ?? '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</x-portal-layout>

