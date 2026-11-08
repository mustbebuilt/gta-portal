<x-portal-layout>
    <div style="margin-bottom: 20px;">
        <a href="{{ route('reviews.index', $review->apprentice_id) }}" style="font-size: 13px; color: #64748b; text-decoration: none;">&larr; Back to Progress Reviews List</a>
        <div style="display: flex; justify-content: space-between; align-items: start; margin-top: 10px; flex-wrap: wrap; gap: 14px;">
            <div>
                <span style="font-size: 12px; font-weight: 700; color: #254c6a; text-transform: uppercase; letter-spacing: 0.5px;">
                    Progress Review #{{ $review->review_sequence }} &bull; {{ $review->review_sequence === 1 ? '10-Week Initial Review' : '70-Day Review' }}
                </span>
                <h2 style="font-size: 22px; font-weight: 700; color: #0f172a; margin-top: 4px;">{{ $review->apprentice->user->full_name }}</h2>
                <p style="font-size: 13px; color: #64748b; margin-top: 4px;">
                    Employer: <strong>{{ $review->apprentice->employer?->employer_name ?? 'GTA Partner' }}</strong> &bull; Due Date: <strong>{{ $review->due_date->format('d M Y') }}</strong>
                </p>
            </div>
            <div>
                @php
                    $badgeClass = match($review->status) {
                        'Signed' => 'badge-green',
                        'Conducted' => 'badge-amber',
                        default => 'badge-blue',
                    };
                @endphp
                <span class="badge {{ $badgeClass }}" style="font-size: 13px; padding: 6px 14px;">
                    {{ $review->status }} ({{ $review->signatures->count() }} / 3 Signatures)
                </span>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div style="background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 14px; border-radius: 6px; font-size: 13px; margin-bottom: 20px;">
            {{ session('success') }}
        </div>
    @endif

    <!-- Review Meeting Outcome Discussion Card -->
    <div class="gta-card">
        <header>
            <h3 style="font-size: 16px; font-weight: 700;">Meeting Discussion &amp; Progress Summary</h3>
            @if($review->meeting_date)
                <span style="font-size: 12px; color: #64748b;">Meeting Date: {{ $review->meeting_date->format('d M Y') }}</span>
            @endif
        </header>

        @if($review->status === 'Scheduled' && Auth::user()->isStaff())
            <form method="POST" action="{{ route('reviews.conduct', $review->id) }}">
                @csrf
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 6px;">Actual Meeting Date</label>
                    <input type="date" name="meeting_date" value="{{ now()->toDateString() }}" required class="form-control" style="width: 220px; padding: 8px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px;">
                    <small style="display: block; color: #64748b; font-size: 11px; margin-top: 4px;">Note: Subsequent 70-day review due date is calculated from this meeting date.</small>
                </div>

                <div style="margin-bottom: 18px;">
                    <label style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 6px;">Progress Summary &amp; Target Review Discussion</label>
                    <textarea name="progress_summary" rows="5" required class="form-control" style="width: 100%; padding: 10px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px;" placeholder="Document technical block milestones, workplace feedback from mentor, and agreed targets for the next 70 days..."></textarea>
                </div>

                <button type="submit" style="padding: 10px 22px; background: #254c6a; color: #fff; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer;">
                    Save Meeting Outcomes &rarr;
                </button>
            </form>
        @else
            @if($review->progress_summary)
                <p style="font-size: 14px; line-height: 1.6; color: #1e293b; white-space: pre-line;">{{ $review->progress_summary }}</p>
            @else
                <div style="padding: 24px; text-align: center; color: #64748b; font-size: 13px;">
                    Review meeting has not yet been conducted by GTA staff.
                </div>
            @endif
        @endif
    </div>

    <!-- Tripartite Digital Signatures -->
    <div class="gta-card">
        <header>
            <div>
                <h3 style="font-size: 16px; font-weight: 700;">Tripartite Digital Signatures</h3>
                <p style="font-size: 12px; color: #64748b; margin-top: 2px;">Preserved Rule: 3 independent signing parties required before final sign-off.</p>
            </div>
            <div>
                <strong style="color: {{ $review->signatures->count() === 3 ? '#15803d' : '#b45309' }};">{{ $review->signatures->count() }} of 3 Signed</strong>
            </div>
        </header>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 18px; margin-bottom: 24px;">
            <!-- Party 1: Apprentice -->
            @php $appSig = $signaturesByRole->get('apprentice'); @endphp
            <div style="background: #f8fafc; border: 1px solid {{ $appSig ? '#86efac' : '#cbd5e1' }}; border-radius: 8px; padding: 16px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <strong style="font-size: 13px; color: #0f172a;">1. Apprentice</strong>
                    @if($appSig)
                        <span class="badge badge-green">Signed</span>
                    @else
                        <span class="badge" style="background: #f1f5f9; color: #64748b;">Pending</span>
                    @endif
                </div>
                @if($appSig)
                    <div style="font-size: 12px; color: #334155;">
                        <div>Signatory: <strong>{{ $appSig->signatory_name }}</strong></div>
                        <div style="color: #64748b;">Signed: {{ $appSig->signed_at->format('d M Y, H:i') }}</div>
                        <small style="color: #94a3b8; display: block; margin-top: 4px; font-family: monospace;">Hash: {{ substr($appSig->document_snapshot_hash, 0, 16) }}...</small>
                    </div>
                @else
                    <p style="font-size: 12px; color: #64748b;">Awaiting digital signature from {{ $review->apprentice->user->full_name }}.</p>
                @endif
            </div>

            <!-- Party 2: Employer -->
            @php $empSig = $signaturesByRole->get('employer'); @endphp
            <div style="background: #f8fafc; border: 1px solid {{ $empSig ? '#86efac' : '#cbd5e1' }}; border-radius: 8px; padding: 16px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <strong style="font-size: 13px; color: #0f172a;">2. Workplace Mentor / Employer</strong>
                    @if($empSig)
                        <span class="badge badge-green">Signed</span>
                    @else
                        <span class="badge" style="background: #f1f5f9; color: #64748b;">Pending</span>
                    @endif
                </div>
                @if($empSig)
                    <div style="font-size: 12px; color: #334155;">
                        <div>Signatory: <strong>{{ $empSig->signatory_name }}</strong></div>
                        <div style="color: #64748b;">Signed: {{ $empSig->signed_at->format('d M Y, H:i') }}</div>
                        <small style="color: #94a3b8; display: block; margin-top: 4px; font-family: monospace;">Hash: {{ substr($empSig->document_snapshot_hash, 0, 16) }}...</small>
                    </div>
                @else
                    <p style="font-size: 12px; color: #64748b;">Awaiting signature from workplace mentor.</p>
                @endif
            </div>

            <!-- Party 3: Progress Mentor -->
            @php $menSig = $signaturesByRole->get('mentor'); @endphp
            <div style="background: #f8fafc; border: 1px solid {{ $menSig ? '#86efac' : '#cbd5e1' }}; border-radius: 8px; padding: 16px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <strong style="font-size: 13px; color: #0f172a;">3. Learning &amp; Progress Mentor</strong>
                    @if($menSig)
                        <span class="badge badge-green">Signed</span>
                    @else
                        <span class="badge" style="background: #f1f5f9; color: #64748b;">Pending</span>
                    @endif
                </div>
                @if($menSig)
                    <div style="font-size: 12px; color: #334155;">
                        <div>Signatory: <strong>{{ $menSig->signatory_name }}</strong></div>
                        <div style="color: #64748b;">Signed: {{ $menSig->signed_at->format('d M Y, H:i') }}</div>
                        <small style="color: #94a3b8; display: block; margin-top: 4px; font-family: monospace;">Hash: {{ substr($menSig->document_snapshot_hash, 0, 16) }}...</small>
                    </div>
                @else
                    <p style="font-size: 12px; color: #64748b;">Awaiting signature from GTA reviewer.</p>
                @endif
            </div>
        </div>

        <!-- Signing Form for Current User -->
        @php
            $currentPartyRole = match(Auth::user()->role) {
                \App\Enums\UserRole::Apprentice => 'apprentice',
                \App\Enums\UserRole::Employer => 'employer',
                default => 'mentor',
            };
            $alreadySigned = $signaturesByRole->has($currentPartyRole);
        @endphp

        @if($review->meeting_date && !$alreadySigned)
            <div style="padding: 20px; background: #e0f2fe; border: 1px solid #7dd3fc; border-radius: 8px;">
                <h4 style="font-size: 14px; font-weight: 700; color: #0369a1; margin-bottom: 6px;">Sign this Review as {{ Auth::user()->role->label() }}</h4>
                <p style="font-size: 12px; color: #0369a1; margin-bottom: 14px;">
                    I confirm that the progress discussed above accurately reflects current training achievements and agreed 70-day targets.
                </p>

                <form method="POST" action="{{ route('reviews.sign', $review->id) }}">
                    @csrf
                    <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                        <input type="text" name="signatory_name" value="{{ Auth::user()->full_name }}" required placeholder="Type full name to digitally sign..." class="form-control" style="max-width: 300px; padding: 9px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px;">
                        <button type="submit" style="padding: 10px 22px; background: #254c6a; color: #fff; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer;">
                            Sign Document &rarr;
                        </button>
                    </div>
                </form>
            </div>
        @endif
    </div>
</x-portal-layout>

