<x-portal-layout>
    <div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: start; flex-wrap: wrap; gap: 16px;">
        <div>
            <h2 style="font-size: 22px; font-weight: 700; color: #0f172a;">Attendance &amp; Session Registers</h2>
            <p style="font-size: 13px; color: #64748b; margin-top: 4px;">
                Statutory attendance monitoring against preserved GTA RAG thresholds:
                <strong style="color: #15803d;">&ge;95% Green</strong> &bull;
                <strong style="color: #b45309;">90%&ndash;94.9% Amber</strong> &bull;
                <strong style="color: #b91c1c;">&lt;90% Red</strong>.
            </p>
        </div>
    </div>

    @if(session('success'))
        <div style="background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 14px; border-radius: 6px; font-size: 13px; margin-bottom: 20px;">
            {{ session('success') }}
        </div>
    @endif

    <!-- Cohort Attendance RAG Roster -->
    <div class="gta-card">
        <header>
            <h3 style="font-size: 16px; font-weight: 700;">Active Cohort Attendance RAG Breakdown</h3>
            <span style="font-size: 12px; color: #64748b;">{{ $apprentices->count() }} Active Learners</span>
        </header>

        <table class="gta-table">
            <thead>
                <tr>
                    <th>Apprentice</th>
                    <th>Employer</th>
                    <th>Recorded Sessions</th>
                    <th>Attended (Present / Late)</th>
                    <th>Attendance %</th>
                    <th>RAG Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @foreach($apprentices as $row)
                    @php
                        $app = $row['model'];
                        $rag = $row['rag'];
                    @endphp
                    <tr>
                        <td><strong>{{ $app->user->full_name }}</strong></td>
                        <td>{{ $app->employer?->employer_name ?? 'GTA Partner' }}</td>
                        <td>{{ $rag['total_sessions'] }}</td>
                        <td>{{ $rag['attended_sessions'] }}</td>
                        <td>
                            @if($rag['percentage'] !== null)
                                <strong>{{ $rag['percentage'] }}%</strong>
                            @else
                                <span style="color: #94a3b8;">N/A</span>
                            @endif
                        </td>
                        <td>
                            <span class="badge {{ $rag['badge_class'] }}" style="{{ $rag['badge_style'] }}">
                                {{ $rag['rating'] }}
                            </span>
                        </td>
                        <td>
                            <a href="{{ route('attendance.apprentice', $app->apprentice_id) }}" style="font-size: 12px; color: #254c6a; font-weight: 600; text-decoration: underline;">
                                View Ledger &rarr;
                            </a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <!-- Quick Register Form for Tutors -->
    @if(Auth::user()->isStaff())
        <div class="gta-card">
            <header>
                <h3 style="font-size: 16px; font-weight: 700;">Record New Session Register</h3>
            </header>

            <form method="POST" action="{{ route('attendance.store') }}">
                @csrf
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 20px;">
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 6px;">Session Date &amp; Time</label>
                        <input type="datetime-local" name="session_datetime" value="{{ now()->format('Y-m-d\TH:i') }}" required class="form-control" style="width: 100%; padding: 9px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 6px;">Training Topic / Module</label>
                        <input type="text" name="topic" placeholder="e.g. Block 1 Workshop Safety & Inspection" required class="form-control" style="width: 100%; padding: 9px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px;">
                    </div>
                </div>

                <h4 style="font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 10px;">Mark Learners</h4>
                <table class="gta-table" style="margin-bottom: 20px;">
                    <thead>
                        <tr>
                            <th>Learner</th>
                            <th>Status</th>
                            <th>Authorised Code (if absence)</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($apprentices as $index => $row)
                            @php $app = $row['model']; @endphp
                            <tr>
                                <td>
                                    <strong>{{ $app->user->full_name }}</strong>
                                    <input type="hidden" name="records[{{ $index }}][apprentice_id]" value="{{ $app->apprentice_id }}">
                                </td>
                                <td>
                                    <select name="records[{{ $index }}][status]" style="padding: 6px; font-size: 12px; border: 1px solid #cbd5e1; border-radius: 4px;">
                                        <option value="Present" selected>Present</option>
                                        <option value="Late">Late</option>
                                        <option value="Authorised Absence">Authorised Absence</option>
                                        <option value="Unauthorised Absence">Unauthorised Absence</option>
                                    </select>
                                </td>
                                <td>
                                    <select name="records[{{ $index }}][authorised_code]" style="padding: 6px; font-size: 12px; border: 1px solid #cbd5e1; border-radius: 4px;">
                                        <option value="">None</option>
                                        <option value="L">L - Late Transport</option>
                                        <option value="W">W - Workplace Overrun</option>
                                        <option value="S">S - Sickness</option>
                                        <option value="H">H - Approved Holiday</option>
                                        <option value="E">E - Emergency</option>
                                    </select>
                                </td>
                                <td>
                                    <input type="text" name="records[{{ $index }}][comment]" placeholder="Optional note..." style="width: 100%; padding: 6px; font-size: 12px; border: 1px solid #cbd5e1; border-radius: 4px;">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <button type="submit" style="padding: 10px 22px; background: #254c6a; color: #fff; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer;">
                    Save Session Register
                </button>
            </form>
        </div>
    @endif
</x-portal-layout>
