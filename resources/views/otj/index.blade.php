<x-portal-layout>
    <div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: start; flex-wrap: wrap; gap: 16px;">
        <div>
            <h2 style="font-size: 22px; font-weight: 700; color: #0f172a;">Off-The-Job (OTJ) Training Tracker</h2>
            <p style="font-size: 13px; color: #64748b; margin-top: 4px;">
                Apprentice: <strong>{{ $apprentice->user->full_name }}</strong> &bull; Standard: <strong>{{ $apprentice->standard?->title ?? 'Autocare Technician' }}</strong> (Target: {{ number_format($metrics['target_hours'], 0) }} Hours)
            </p>
        </div>
        <div>
            <span class="badge {{ $metrics['rag_badge_class'] }}" style="font-size: 13px; padding: 6px 14px;">
                {{ $metrics['rag_label'] }}
            </span>
        </div>
    </div>

    @if(session('success'))
        <div style="background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 14px; border-radius: 6px; font-size: 13px; margin-bottom: 20px;">
            {{ session('success') }}
        </div>
    @endif

    <!-- OTJ Progress Metric Grid -->
    <div class="gta-stat-grid">
        <div class="gta-stat-card">
            <small>Verified OTJ Hours</small>
            <strong style="color: #15803d;">{{ number_format($metrics['verified_hours'], 1) }} hrs</strong>
        </div>
        <div class="gta-stat-card">
            <small>Pending Verification</small>
            <strong style="color: #b45309;">{{ number_format($metrics['pending_hours'], 1) }} hrs</strong>
        </div>
        <div class="gta-stat-card">
            <small>Total Target Hours</small>
            <strong style="color: #254c6a;">{{ number_format($metrics['target_hours'], 0) }} hrs</strong>
        </div>
        <div class="gta-stat-card">
            <small>Overall Progress</small>
            <strong>{{ $metrics['completion_percentage'] }}%</strong>
        </div>
    </div>

    <!-- Visual Progress Bar -->
    <div class="gta-card" style="padding: 16px 20px; margin-bottom: 20px;">
        <div style="display: flex; justify-content: space-between; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 8px;">
            <span>Overall Statutory 20% Delivery Progress</span>
            <span>{{ number_format($metrics['verified_hours'], 1) }} / {{ number_format($metrics['target_hours'], 0) }} Hours ({{ $metrics['completion_percentage'] }}%)</span>
        </div>
        <div style="width: 100%; height: 12px; background: #e2e8f0; border-radius: 9999px; overflow: hidden;">
            <div style="width: {{ min($metrics['completion_percentage'], 100) }}%; height: 100%; background: #254c6a; border-radius: 9999px; transition: width 0.3s;"></div>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: minmax(0, 1.8fr) minmax(320px, 1fr); gap: 24px;">
        <!-- Left Column: OTJ Ledger Table -->
        <div>
            <div class="gta-card">
                <header>
                    <h3 style="font-size: 16px; font-weight: 700;">Recorded OTJ Activities</h3>
                    <span style="font-size: 12px; color: #64748b;">{{ $entries->count() }} {{ Str::plural('entry', $entries->count()) }}</span>
                </header>

                @if($entries->isEmpty())
                    <div style="padding: 24px; text-align: center; color: #64748b; font-size: 13px;">
                        No Off-The-Job training hours recorded yet. Use the form to log technical training, shadowing, or online learning.
                    </div>
                @else
                    <table class="gta-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Activity Type</th>
                                <th>Hours (Chunks)</th>
                                <th>Description</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($entries as $entry)
                                <tr>
                                    <td><strong>{{ $entry->date_of_otj->format('d M Y') }}</strong></td>
                                    <td>{{ $entry->otj_type }}</td>
                                    <td>
                                        <strong>{{ number_format($entry->hours, 2) }} hrs</strong>
                                        <small style="display: block; color: #64748b; font-size: 10px;">{{ $entry->time_chunks }} chunks</small>
                                    </td>
                                    <td style="max-width: 250px;">{{ $entry->otj_activity_description }}</td>
                                    <td>
                                        @if($entry->otj_trainer_sign_off)
                                            <span class="badge badge-green">Verified</span>
                                        @else
                                            <span class="badge badge-amber">Pending</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if(!$entry->otj_trainer_sign_off && Auth::user()->isStaff())
                                            <form method="POST" action="{{ route('otj.verify', $entry->otj_id) }}" style="display: inline;">
                                                @csrf
                                                <button type="submit" style="padding: 5px 10px; background: #15803d; color: #fff; border: none; border-radius: 4px; font-size: 11px; font-weight: 600; cursor: pointer;">
                                                    Sign Off
                                                </button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        <!-- Right Column: Log New Activity Form -->
        <div>
            <div class="gta-card">
                <header>
                    <h3 style="font-size: 16px; font-weight: 700;">Log OTJ Training Activity</h3>
                </header>

                <form method="POST" action="{{ route('otj.store', $apprentice->apprentice_id) }}">
                    @csrf
                    <div style="margin-bottom: 14px;">
                        <label style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 6px;">Date of Activity</label>
                        <input type="date" name="date_of_otj" value="{{ now()->toDateString() }}" required class="form-control" style="width: 100%; padding: 8px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px;">
                    </div>

                    <div style="margin-bottom: 14px;">
                        <label style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 6px;">Duration in Decimal Hours</label>
                        <input type="number" step="0.25" min="0.25" max="24.0" name="hours" value="6.0" required class="form-control" style="width: 100%; padding: 8px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px;">
                        <small style="color: #64748b; font-size: 11px; margin-top: 4px; display: block;">GTA standard specialist day reference = 6.0 hours (24 &times; 15-min chunks).</small>
                    </div>

                    <div style="margin-bottom: 14px;">
                        <label style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 6px;">Activity Type</label>
                        <select name="otj_type" required class="form-control" style="width: 100%; padding: 8px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px;">
                            <option value="Practical Training (Shadowing)">Practical Training (Shadowing)</option>
                            <option value="Online-Learning">Online-Learning</option>
                            <option value="Manufacture or Equipment Training">Manufacture or Equipment Training</option>
                            <option value="Simulation Exercise">Simulation Exercise</option>
                            <option value="Role Playing">Role Playing</option>
                            <option value="Other">Other Verified Learning</option>
                        </select>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px;">
                        <div>
                            <label style="display: block; font-size: 11px; font-weight: 600; color: #334155; margin-bottom: 4px;">Start Time (Optional)</label>
                            <input type="time" name="start_time" value="09:00" class="form-control" style="width: 100%; padding: 7px; font-size: 12px; border: 1px solid #cbd5e1; border-radius: 6px;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 11px; font-weight: 600; color: #334155; margin-bottom: 4px;">End Time (Optional)</label>
                            <input type="time" name="end_time" value="16:00" class="form-control" style="width: 100%; padding: 7px; font-size: 12px; border: 1px solid #cbd5e1; border-radius: 6px;">
                        </div>
                    </div>

                    <div style="margin-bottom: 18px;">
                        <label style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 6px;">Activity Details &amp; Learning Impact</label>
                        <textarea name="otj_activity_description" rows="4" required class="form-control" style="width: 100%; padding: 8px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px;" placeholder="Describe the practical skills, technical tools, or safety processes practiced..."></textarea>
                    </div>

                    <button type="submit" style="width: 100%; padding: 11px; background: #254c6a; color: #fff; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer;">
                        Log OTJ Hours &rarr;
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-portal-layout>

