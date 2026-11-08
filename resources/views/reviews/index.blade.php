<x-portal-layout>
    <div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: start; flex-wrap: wrap; gap: 16px;">
        <div>
            <h2 style="font-size: 22px; font-weight: 700; color: #0f172a;">Progress Reviews &amp; Tripartite Signatures</h2>
            <p style="font-size: 13px; color: #64748b; margin-top: 4px;">
                Apprentice: <strong>{{ $apprentice->user->full_name }}</strong> &bull; Employer: <strong>{{ $apprentice->employer?->employer_name ?? 'GTA Partner Garage' }}</strong>
            </p>
        </div>
        @if(Auth::user()->isStaff())
            <form method="POST" action="{{ route('reviews.schedule', $apprentice->apprentice_id) }}">
                @csrf
                <button type="submit" style="padding: 10px 18px; background: #254c6a; color: #fff; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer;">
                    + Schedule Next Review (Due: {{ $nextDueDate->format('d M Y') }})
                </button>
            </form>
        @endif
    </div>

    @if(session('success'))
        <div style="background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 14px; border-radius: 6px; font-size: 13px; margin-bottom: 20px;">
            {{ session('success') }}
        </div>
    @endif

    <!-- Reviews Schedule Cadence Note -->
    <div class="gta-card" style="padding: 16px 20px; background: #f8fafc; margin-bottom: 20px;">
        <strong style="font-size: 13px; color: #0f172a; display: block; margin-bottom: 4px;">Statutory Cadence Preserved:</strong>
        <p style="font-size: 12px; color: #64748b; line-height: 1.5;">
            Initial review due at <strong>10 weeks</strong> from learning start date. Subsequent periodic reviews are due <strong>70 calendar days</strong> after the actual meeting date.
            Full sign-off requires independent digital signatures from the <strong>Apprentice</strong>, <strong>Employer</strong>, and <strong>Learning &amp; Progress Mentor</strong>.
        </p>
    </div>

    <!-- Reviews Table -->
    <div class="gta-card">
        <header>
            <h3 style="font-size: 16px; font-weight: 700;">Progress Review Schedule</h3>
            <span style="font-size: 12px; color: #64748b;">{{ $reviews->count() }} Reviews Logged</span>
        </header>

        @if($reviews->isEmpty())
            <div style="padding: 32px; text-align: center; color: #64748b; font-size: 13px;">
                No reviews scheduled yet. Click <strong>"+ Schedule Next Review"</strong> to generate Review #1 (10-Week Review).
            </div>
        @else
            <table class="gta-table">
                <thead>
                    <tr>
                        <th>Sequence</th>
                        <th>Cadence Interval</th>
                        <th>Due Date</th>
                        <th>Meeting Date</th>
                        <th>Status</th>
                        <th>Tripartite Signatures</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($reviews as $review)
                        @php
                            $signedCount = $review->signatures->count();
                            $statusBadge = match($review->status) {
                                'Signed' => 'badge-green',
                                'Conducted' => 'badge-amber',
                                default => 'badge-blue',
                            };
                        @endphp
                        <tr>
                            <td><strong>Review #{{ $review->review_sequence }}</strong></td>
                            <td>{{ $review->review_sequence === 1 ? '10-Week Initial Review' : '70-Day Periodic Review' }}</td>
                            <td>{{ $review->due_date->format('d M Y') }}</td>
                            <td>{{ $review->meeting_date ? $review->meeting_date->format('d M Y') : 'Pending Meeting' }}</td>
                            <td>
                                <span class="badge {{ $statusBadge }}">
                                    {{ $review->status }}
                                </span>
                            </td>
                            <td>
                                <strong style="color: {{ $signedCount === 3 ? '#15803d' : '#b45309' }};">{{ $signedCount }} / 3 Signed</strong>
                            </td>
                            <td>
                                <a href="{{ route('reviews.show', $review->id) }}" style="font-size: 12px; color: #254c6a; font-weight: 600; text-decoration: underline;">
                                    Open Document &rarr;
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</x-portal-layout>

