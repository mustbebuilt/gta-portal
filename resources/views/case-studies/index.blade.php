<x-portal-layout>
    <div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: start; flex-wrap: wrap; gap: 16px;">
        <div>
            <h2 style="font-size: 22px; font-weight: 700; color: #0f172a;">Anonymous Case Studies Bank</h2>
            <p style="font-size: 13px; color: #64748b; margin-top: 4px;">
                Peer-reviewed qualitative evidence bank across 11 standard apprenticeship categories.
                Preserved rule: Requires internal approval by a <strong>different administrator</strong> before publication.
            </p>
        </div>
        <div>
            <a href="{{ route('case-studies.create') }}" style="display: inline-block; padding: 10px 18px; background: #254c6a; color: #fff; text-decoration: none; border-radius: 6px; font-size: 13px; font-weight: 600;">
                + Author New Case Study
            </a>
        </div>
    </div>

    @if(session('success'))
        <div style="background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 14px; border-radius: 6px; font-size: 13px; margin-bottom: 20px;">
            {{ session('success') }}
        </div>
    @endif

    <!-- Category Filter Pills -->
    <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px;">
        <a href="{{ route('case-studies.index') }}" style="padding: 6px 12px; border-radius: 9999px; font-size: 12px; font-weight: 600; text-decoration: none; {{ !$selectedCategory ? 'background: #254c6a; color: #fff;' : 'background: #e2e8f0; color: #334155;' }}">
            All Categories
        </a>
        @foreach($categories as $cat)
            <a href="{{ route('case-studies.index', ['category' => $cat]) }}" style="padding: 6px 12px; border-radius: 9999px; font-size: 12px; font-weight: 600; text-decoration: none; {{ $selectedCategory === $cat ? 'background: #254c6a; color: #fff;' : 'background: #e2e8f0; color: #334155;' }}">
                {{ $cat }}
            </a>
        @endforeach
    </div>

    <!-- Case Studies Table -->
    <div class="gta-card">
        <header>
            <h3 style="font-size: 16px; font-weight: 700;">
                {{ $selectedCategory ? $selectedCategory . ' Cases' : 'All Approved & Draft Case Studies' }}
            </h3>
            <span style="font-size: 12px; color: #64748b;">{{ $caseStudies->count() }} Records</span>
        </header>

        @if($caseStudies->isEmpty())
            <div style="padding: 32px; text-align: center; color: #64748b; font-size: 13px;">
                No case studies found matching this filter. Click <strong>"+ Author New Case Study"</strong> to draft qualitative evidence.
            </div>
        @else
            <table class="gta-table">
                <thead>
                    <tr>
                        <th>Title &amp; Challenge</th>
                        <th>Category</th>
                        <th>Outcome</th>
                        <th>Author</th>
                        <th>Status</th>
                        <th>Approval</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($caseStudies as $case)
                        @php
                            $badgeClass = match($case->status) {
                                'Approved' => 'badge-green',
                                'Pending Approval' => 'badge-amber',
                                default => 'badge-blue',
                            };
                        @endphp
                        <tr>
                            <td>
                                <a href="{{ route('case-studies.show', $case->id) }}" style="color: #254c6a; font-weight: 600; text-decoration: none;">
                                    {{ $case->title }}
                                </a>
                                <small style="display: block; color: #64748b; font-size: 11px; margin-top: 2px;">{{ Str::limit($case->challenge, 60) }}</small>
                            </td>
                            <td><span style="font-size: 12px; color: #475569;">{{ $case->category }}</span></td>
                            <td><strong>{{ $case->outcome }}</strong></td>
                            <td>{{ $case->author->full_name }}</td>
                            <td>
                                <span class="badge {{ $badgeClass }}">
                                    {{ $case->status }}
                                </span>
                            </td>
                            <td>
                                @if($case->status === 'Approved')
                                    <small style="color: #15803d; font-weight: 600;">Approved by {{ $case->approver?->full_name ?? 'Admin' }}</small>
                                @elseif($case->status === 'Pending Approval')
                                    <small style="color: #b45309;">Awaiting Peer Admin</small>
                                @else
                                    <small style="color: #64748b;">Draft</small>
                                @endif
                            </td>
                            <td>
                                <a href="{{ route('case-studies.show', $case->id) }}" style="font-size: 12px; color: #254c6a; font-weight: 600; text-decoration: underline;">
                                    View &rarr;
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</x-portal-layout>
