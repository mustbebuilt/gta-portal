<x-portal-layout>
    <div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: start; flex-wrap: wrap; gap: 16px;">
        <div>
            @if($apprentice)
                <span class="badge badge-purple" style="margin-bottom: 6px;">{{ $apprentice->user->full_name }} &bull; Careers Guidance</span>
            @endif
            <h2 style="font-size: 22px; font-weight: 700; color: var(--gta-text);">Careers, Advice &amp; Progression</h2>
            <p style="font-size: 13px; color: var(--gta-muted); margin-top: 4px;">Goals, post-apprenticeship progression routes, specialist referrals, and career resources.</p>
        </div>
        @if(Auth::user()->isStaff() && $apprentice)
            <button type="button" onclick="document.getElementById('recordDiscussionForm').scrollIntoView({behavior:'smooth'})" class="gta-btn gta-btn-primary" style="font-size: 13px; font-weight: 700;">
                + Record Discussion
            </button>
        @endif
    </div>

    @if(session('success'))
        <div style="background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 14px; border-radius: 6px; font-size: 13px; margin-bottom: 20px;">
            {{ session('success') }}
        </div>
    @endif

    <div style="background: #f0edf9; border-left: 4px solid #7059d3; padding: 12px 16px; border-radius: 4px; margin-bottom: 20px; font-size: 12px; color: #4e426b;">
        <strong>Confidentiality Notice:</strong> Careers records are visible to the apprentice, assigned careers advisers, and administrators. Employers and workplace mentors are strictly barred from this area.
    </div>

    <!-- Staff Apprentice Selector -->
    @if(Auth::user()->isStaff() && $allApprentices->isNotEmpty())
        <div class="gta-card" style="margin-bottom: 20px; padding: 14px;">
            <form method="GET" action="{{ route('careers.index') }}" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                <label style="font-size: 13px; font-weight: 600;">Select Apprentice:</label>
                <select name="apprentice" onchange="this.form.submit()" class="form-control" style="padding: 7px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px; min-width: 250px;">
                    @foreach($allApprentices as $learner)
                        <option value="{{ $learner->apprentice_id }}" {{ $apprentice?->apprentice_id == $learner->apprentice_id ? 'selected' : '' }}>
                            {{ $learner->user->full_name }} ({{ $learner->employer?->employer_name ?? 'GTA Partner' }})
                        </option>
                    @endforeach
                </select>
            </form>
        </div>
    @endif

    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px; align-items: start;">
        <!-- Left Column: Discussions History -->
        <div>
            <div class="gta-card" style="margin-bottom: 24px;">
                <h3 style="font-size: 16px; font-weight: 700; border-bottom: 1px solid var(--gta-border); padding-bottom: 10px; margin-bottom: 16px;">
                    Career Discussion History
                </h3>

                @if($discussions->isEmpty())
                    <p style="font-size: 13px; color: var(--gta-muted); padding: 16px 0;">No career guidance discussions recorded yet.</p>
                @else
                    <div style="display: flex; flex-direction: column; gap: 16px;">
                        @foreach($discussions as $disc)
                            <div style="padding: 16px; background: var(--gta-bg); border-radius: 8px; border-left: 4px solid var(--gta-accent);">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                    <div>
                                        <span class="badge badge-purple" style="font-size: 11px;">{{ ucfirst($disc->discussion_type) }}</span>
                                        <strong style="margin-left: 8px; font-size: 13px;">{{ $disc->discussion_date->format('d M Y') }}</strong>
                                    </div>
                                    <span class="badge badge-green">{{ $disc->outcome }}</span>
                                </div>

                                <div style="font-size: 12px; margin-bottom: 8px;">
                                    <strong>Apprentice Goal:</strong>
                                    <p style="color: var(--gta-muted); margin-top: 2px;">{{ $disc->goals }}</p>
                                </div>

                                <div style="font-size: 12px; margin-bottom: 8px;">
                                    <strong>Options Explored:</strong>
                                    <p style="color: var(--gta-muted); margin-top: 2px;">{{ $disc->options_discussed }}</p>
                                </div>

                                <div style="font-size: 12px; margin-bottom: 8px;">
                                    <strong>Advice Given:</strong>
                                    <p style="color: var(--gta-muted); margin-top: 2px;">{{ $disc->advice_given }}</p>
                                </div>

                                @if($disc->referral_to)
                                    <div style="font-size: 12px; margin-bottom: 8px;">
                                        <strong>Specialist Referral:</strong>
                                        <p style="color: #254c6a; margin-top: 2px;">{{ $disc->referral_to }}</p>
                                    </div>
                                @endif

                                <div style="font-size: 12px; margin-top: 10px; padding-top: 8px; border-top: 1px solid var(--gta-border); color: var(--gta-muted);">
                                    Adviser: <strong>{{ $disc->adviser?->full_name ?? 'GTA Adviser' }}</strong> &bull; Next Steps: <em>{{ $disc->next_steps }}</em>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <!-- Record Discussion Form for Staff -->
            @if(Auth::user()->isStaff() && $apprentice)
                <div class="gta-card" id="recordDiscussionForm">
                    <h3 style="font-size: 16px; font-weight: 700; border-bottom: 1px solid var(--gta-border); padding-bottom: 10px; margin-bottom: 16px;">
                        Record Careers Discussion for {{ $apprentice->user->full_name }}
                    </h3>

                    <form method="POST" action="{{ route('careers.store', $apprentice->apprentice_id) }}">
                        @csrf

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                            <div>
                                <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px;">Discussion Type *</label>
                                <select name="discussion_type" required class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;">
                                    <option value="progression">Career Progression (Level 3 / Diagnostic / MOT)</option>
                                    <option value="employment">Employment Support — At-Risk / Unemployed Apprentice</option>
                                </select>
                            </div>
                            <div>
                                <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px;">Discussion Date *</label>
                                <input type="date" name="discussion_date" value="{{ now()->format('Y-m-d') }}" required class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;">
                            </div>
                        </div>

                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px;">Apprentice's Stated Goals *</label>
                            <textarea name="goals" rows="2" required placeholder="What does the learner want to do next in the automotive industry?" class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;"></textarea>
                        </div>

                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px;">Options Explored &amp; Entry Requirements *</label>
                            <textarea name="options_discussed" rows="2" required placeholder="Realistic routes explored (e.g. Level 3 Light Vehicle, IMI EV/Hybrid certification)..." class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;"></textarea>
                        </div>

                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px;">Advice Given *</label>
                            <textarea name="advice_given" rows="2" required placeholder="Practical guidance provided..." class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;"></textarea>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                            <div>
                                <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px;">Discussion Outcome *</label>
                                <select name="outcome" required class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;">
                                    <option value="Next step agreed">Next step agreed</option>
                                    <option value="Exploring options">Exploring options</option>
                                    <option value="Specialist referral">Specialist referral</option>
                                </select>
                            </div>
                            <div>
                                <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px;">Referral Contact (if applicable)</label>
                                <input type="text" name="referral_to" placeholder="e.g. GTA Level 3 Lead" class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;">
                            </div>
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px;">What Happens Next / SMART Actions *</label>
                            <textarea name="next_steps" rows="2" required placeholder="Agreed learner and adviser actions..." class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;"></textarea>
                        </div>

                        <button type="submit" class="gta-btn gta-btn-primary" style="padding: 10px 24px; font-size: 13px; font-weight: 700;">
                            Save Careers Discussion
                        </button>
                    </form>
                </div>
            @endif
        </div>

        <!-- Right Column: Career Resources Catalogue -->
        <div>
            <div class="gta-card">
                <h3 style="font-size: 16px; font-weight: 700; border-bottom: 1px solid var(--gta-border); padding-bottom: 10px; margin-bottom: 16px;">
                    Automotive Career Resources
                </h3>
                <div style="display: flex; flex-direction: column; gap: 14px;">
                    @forelse($resources as $res)
                        <div style="padding: 12px; background: var(--gta-bg); border-radius: 6px; border: 1px solid var(--gta-border);">
                            <div style="font-weight: 700; font-size: 13px; color: var(--gta-text);">{{ $res->title }}</div>
                            <span class="badge badge-blue" style="font-size: 10px; margin-top: 4px; display: inline-block;">{{ $res->target_stage }}</span>
                            <p style="font-size: 12px; color: var(--gta-muted); margin-top: 6px; line-height: 1.5;">{{ $res->description }}</p>
                            @if($res->link_url)
                                <a href="{{ $res->link_url }}" target="_blank" rel="noopener noreferrer" style="font-size: 12px; color: var(--gta-steel-blue); font-weight: 600; text-decoration: underline; margin-top: 8px; display: inline-block;">
                                    Open Resource &rarr;
                                </a>
                            @endif
                        </div>
                    @empty
                        <p style="font-size: 12px; color: var(--gta-muted);">No resources found.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</x-portal-layout>

