<x-portal-layout>
    <div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: start; flex-wrap: wrap; gap: 16px;">
        <div>
            <h2 style="font-size: 22px; font-weight: 700; color: #0f172a;">Additional Learning Support (ALS) &amp; Support Plans</h2>
            <p style="font-size: 13px; color: #64748b; margin-top: 4px;">
                Authorized teaching adjustments and learning accommodations.
                <strong>Confidentiality Notice:</strong> Employers are strictly excluded from private support plans.
            </p>
        </div>
    </div>

    <!-- Active Support Plans Roster -->
    <div class="gta-card">
        <header>
            <h3 style="font-size: 16px; font-weight: 700;">Active Learner Support Plans</h3>
            <span style="font-size: 12px; color: #64748b;">{{ $plans->count() }} Support Plans</span>
        </header>

        @if($plans->isEmpty())
            <div style="padding: 32px; text-align: center; color: #64748b; font-size: 13px;">
                No active ALS support plans currently recorded.
            </div>
        @else
            <table class="gta-table">
                <thead>
                    <tr>
                        <th>Apprentice</th>
                        <th>Plan Type</th>
                        <th>Primary Support Need</th>
                        <th>Designated Lead</th>
                        <th>Referral Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($plans as $plan)
                        <tr>
                            <td><strong>{{ $plan->apprentice->user->full_name }}</strong></td>
                            <td><span style="text-transform: capitalize;">{{ $plan->plan_type }} Touch</span></td>
                            <td>{{ $plan->primary_support_need }}</td>
                            <td>{{ $plan->planLead?->full_name ?? 'GTA ALS Lead' }}</td>
                            <td>{{ $plan->referral_date ? $plan->referral_date->format('d M Y') : 'N/A' }}</td>
                            <td>
                                <span class="badge badge-green">{{ $plan->status }}</span>
                            </td>
                            <td>
                                <a href="{{ route('support.show', $plan->id) }}" style="font-size: 12px; color: #254c6a; font-weight: 600; text-decoration: underline;">
                                    View Teaching Instructions &rarr;
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</x-portal-layout>

