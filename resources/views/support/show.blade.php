<x-portal-layout>
    <div style="margin-bottom: 20px;">
        <a href="{{ route('support.index') }}" style="font-size: 13px; color: #64748b; text-decoration: none;">&larr; Back to Support Plans Roster</a>
        <div style="display: flex; justify-content: space-between; align-items: start; margin-top: 10px; flex-wrap: wrap; gap: 14px;">
            <div>
                <span class="badge badge-blue" style="margin-bottom: 6px;">Support Plan #{{ $supportPlan->plan_number }}</span>
                <h2 style="font-size: 22px; font-weight: 700; color: #0f172a; margin-top: 2px;">{{ $supportPlan->apprentice->user->full_name }}</h2>
                <p style="font-size: 13px; color: #64748b; margin-top: 4px;">
                    Designated Lead: <strong>{{ $supportPlan->planLead?->full_name ?? 'GTA ALS Lead' }}</strong> &bull; Referral: <strong>{{ $supportPlan->referral_date ? $supportPlan->referral_date->format('d M Y') : 'Active' }}</strong>
                </p>
            </div>
            <div>
                <span class="badge badge-green" style="font-size: 13px; padding: 6px 14px;">
                    {{ $supportPlan->status }}
                </span>
            </div>
        </div>
    </div>

    <!-- Authorized Teaching Adjustments (Visible to GTA Tutors) -->
    <div class="gta-card" style="border-left: 4px solid #15803d;">
        <header>
            <div>
                <h3 style="font-size: 16px; font-weight: 700; color: #15803d;">Authorized Teaching Instructions &amp; Accommodations</h3>
                <p style="font-size: 12px; color: #64748b; margin-top: 2px;">Instructions for GTA Main Tutors and Functional Skills specialists to adapt classroom &amp; workshop delivery.</p>
            </div>
            <span class="badge badge-green">Tutor Actionable</span>
        </header>

        <div style="font-size: 14px; line-height: 1.7; color: #1e293b; white-space: pre-line;">
            {{ $supportPlan->authorized_teaching_adjustments }}
        </div>
    </div>

    <!-- Confidential Case Notes (Restricted: ALS Lead & Admin Only) -->
    <div class="gta-card" style="border-left: 4px solid #b45309;">
        <header>
            <div>
                <h3 style="font-size: 16px; font-weight: 700; color: #b45309;">Confidential Support &amp; Clinical Background</h3>
                <p style="font-size: 12px; color: #64748b; margin-top: 2px;">Restricted to the designated ALS Lead and Administrator. Excluded from routine tutors and employers.</p>
            </div>
            <span class="badge badge-amber">&#128274; Restricted Access</span>
        </header>

        @if($isLeadOrAdmin)
            <div style="font-size: 13px; line-height: 1.6; color: #334155; white-space: pre-line;">
                {{ $supportPlan->confidential_case_notes ?: 'No confidential medical background notes recorded.' }}
            </div>
        @else
            <div style="padding: 16px; background: #fffbeb; border: 1px dashed #fde68a; border-radius: 6px; font-size: 13px; color: #92400e;">
                &#128274; Confidential case history and medical markers are restricted to the designated ALS Lead and Quality Administrator under GTA privacy boundaries.
            </div>
        @endif
    </div>
</x-portal-layout>

