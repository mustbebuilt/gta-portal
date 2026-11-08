<x-portal-layout>
    <div style="margin-bottom: 20px;">
        <a href="{{ route('case-studies.index') }}" style="font-size: 13px; color: #64748b; text-decoration: none;">&larr; Back to Case Studies Bank</a>
        <div style="display: flex; justify-content: space-between; align-items: start; margin-top: 10px; flex-wrap: wrap; gap: 14px;">
            <div>
                <span class="badge badge-blue" style="margin-bottom: 6px;">{{ $caseStudy->category }}</span>
                <h2 style="font-size: 22px; font-weight: 700; color: #0f172a; margin-top: 2px;">{{ $caseStudy->title }}</h2>
                <p style="font-size: 13px; color: #64748b; margin-top: 4px;">
                    Author: <strong>{{ $caseStudy->author->full_name }}</strong> &bull; Created: <strong>{{ $caseStudy->created_at->format('d M Y') }}</strong>
                </p>
            </div>
            <div>
                @php
                    $badgeClass = match($caseStudy->status) {
                        'Approved' => 'badge-green',
                        'Pending Approval' => 'badge-amber',
                        default => 'badge-blue',
                    };
                @endphp
                <span class="badge {{ $badgeClass }}" style="font-size: 13px; padding: 6px 14px;">
                    {{ $caseStudy->status }}
                </span>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div style="background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 14px; border-radius: 6px; font-size: 13px; margin-bottom: 20px;">
            {{ session('success') }}
        </div>
    @endif

    <!-- Peer Review & Approval Notice -->
    @if($caseStudy->status === 'Pending Approval')
        @if(Auth::user()->role === \App\Enums\UserRole::Admin)
            @if(Auth::id() === $caseStudy->author_user_id)
                <div style="background: #fffbeb; border: 1px solid #fde68a; color: #92400e; padding: 16px; border-radius: 8px; margin-bottom: 20px; font-size: 13px;">
                    <strong>&#9888; Different-Administrator Rule Enforced:</strong>
                    You authored this case study. Under GTA quality compliance rules, you cannot approve your own submission. Another administrator (or the CEO) must review and sign off on this record.
                </div>
            @else
                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; padding: 16px; border-radius: 8px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: gap; gap: 14px;">
                    <div>
                        <strong style="display: block; font-size: 14px;">Administrator Peer Review</strong>
                        <span style="font-size: 12px; color: #166534;">You are eligible to review and approve this case study authored by {{ $caseStudy->author->full_name }}.</span>
                    </div>
                    <form method="POST" action="{{ route('case-studies.approve', $caseStudy->id) }}">
                        @csrf
                        <button type="submit" style="padding: 10px 22px; background: #15803d; color: #fff; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer;">
                            Approve Case Study &rarr;
                        </button>
                    </form>
                </div>
            @endif
        @endif
    @elseif($caseStudy->status === 'Approved')
        <div style="background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; padding: 14px; border-radius: 6px; font-size: 13px; margin-bottom: 20px;">
            &#10004; Approved by <strong>{{ $caseStudy->approver?->full_name ?? 'Administrator' }}</strong> on {{ $caseStudy->approved_at?->format('d M Y, H:i') }}. Published for Ofsted inspection evidence.
        </div>
    @endif

    <!-- Narrative Cards -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
        <div class="gta-card" style="margin-bottom: 0;">
            <header><h3 style="font-size: 15px; font-weight: 700;">Identified Challenge</h3></header>
            <p style="font-size: 13px; line-height: 1.6; color: #334155;">{{ $caseStudy->challenge }}</p>
        </div>
        <div class="gta-card" style="margin-bottom: 0;">
            <header><h3 style="font-size: 15px; font-weight: 700;">Starting Point / Baseline</h3></header>
            <p style="font-size: 13px; line-height: 1.6; color: #334155;">{{ $caseStudy->starting_point }}</p>
        </div>
    </div>

    <div class="gta-card">
        <header><h3 style="font-size: 15px; font-weight: 700;">GTA Mentorship &amp; Technical Adaptation</h3></header>
        <p style="font-size: 13px; line-height: 1.6; color: #334155;">{{ $caseStudy->response }}</p>
    </div>

    <!-- Before & After Comparison -->
    <div class="gta-card" style="background: #f8fafc;">
        <header>
            <h3 style="font-size: 15px; font-weight: 700;">Measured Progress Impact</h3>
            <span class="badge badge-green">{{ $caseStudy->outcome }}</span>
        </header>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div style="background: #fff; padding: 16px; border: 1px solid #e2e8f0; border-radius: 6px;">
                <small style="color: #64748b; font-size: 11px; text-transform: uppercase; font-weight: 600;">Before ({{ $caseStudy->before_date->format('d M Y') }})</small>
                <strong style="display: block; font-size: 16px; color: #b91c1c; margin-top: 6px;">{{ $caseStudy->before_state }}</strong>
            </div>
            <div style="background: #fff; padding: 16px; border: 1px solid #e2e8f0; border-radius: 6px;">
                <small style="color: #64748b; font-size: 11px; text-transform: uppercase; font-weight: 600;">After ({{ $caseStudy->after_date->format('d M Y') }})</small>
                <strong style="display: block; font-size: 16px; color: #15803d; margin-top: 6px;">{{ $caseStudy->after_state }}</strong>
            </div>
        </div>

        <div style="margin-top: 16px; font-size: 12px; color: #64748b;">
            Evidence Basis: <strong>{{ $caseStudy->evidence }}</strong>
        </div>
    </div>
</x-portal-layout>

