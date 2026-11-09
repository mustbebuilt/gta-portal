<x-portal-layout>
    <div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: start; flex-wrap: wrap; gap: 16px;">
        <div>
            <span class="badge badge-purple" style="margin-bottom: 6px;">Apprentice Setup &bull; Administrator</span>
            <h2 style="font-size: 22px; font-weight: 700; color: var(--gta-text);">Add Apprentice &amp; Setup Programme</h2>
            <p style="font-size: 13px; color: var(--gta-muted); margin-top: 4px;">Enroll an apprentice, set up their tutor group, employer, and initial 10-block curriculum.</p>
        </div>
        <a href="{{ route('admin.apprentices.index') }}" class="gta-btn gta-btn-secondary" style="font-size: 13px;">&larr; Back to Apprentices</a>
    </div>

    @if($errors->any())
        <div style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 14px; border-radius: 6px; font-size: 13px; margin-bottom: 20px;">
            <strong style="display: block; margin-bottom: 4px;">Please correct the errors below:</strong>
            <ul style="margin-left: 20px;">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.apprentices.store') }}">
        @csrf

        <!-- 1. Apprentice Details -->
        <div class="gta-card" style="margin-bottom: 20px;">
            <h3 style="font-size: 16px; font-weight: 700; border-bottom: 1px solid var(--gta-border); padding-bottom: 10px; margin-bottom: 16px;">
                1. Apprentice Personal Details &amp; Account
            </h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px;">
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px;">First Name *</label>
                    <input type="text" name="first_name" value="{{ old('first_name') }}" required class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px;">Last Name *</label>
                    <input type="text" name="last_name" value="{{ old('last_name') }}" required class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px;">Email Address *</label>
                    <input type="email" name="email" value="{{ old('email') }}" required class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px;">Username *</label>
                    <input type="text" name="username" value="{{ old('username') }}" required class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px;">Date of Birth</label>
                    <input type="date" name="user_dob" value="{{ old('user_dob') }}" class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;">
                </div>
            </div>
        </div>

        <!-- 2. Tutor Group & Teaching Assignment -->
        <div class="gta-card" style="margin-bottom: 20px;">
            <h3 style="font-size: 16px; font-weight: 700; border-bottom: 1px solid var(--gta-border); padding-bottom: 10px; margin-bottom: 16px;">
                2. Teaching Group &amp; Main Tutor
            </h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px;">
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px;">Tutor Group</label>
                    <select name="tutor_group_id" id="tutorGroupSelect" class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;">
                        <option value="">No group assigned yet</option>
                        @foreach($tutorGroups as $group)
                            <option value="{{ $group->tutor_group_id }}" data-tutor-id="{{ $group->tutor_id }}" {{ old('tutor_group_id') == $group->tutor_group_id ? 'selected' : '' }}>
                                {{ $group->group_name }} ({{ $group->group_code }}) &bull; Tutor: {{ $group->tutor?->full_name ?? 'Unassigned' }}
                            </option>
                        @endforeach
                    </select>
                    <p style="font-size: 11px; color: var(--gta-muted); margin-top: 4px;">Selecting a group sets their cohort and workshop schedule.</p>
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px;">Assigned GTA Main Tutor *</label>
                    <select name="trainer_id" id="trainerSelect" required class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;">
                        @foreach($trainers as $trainer)
                            <option value="{{ $trainer->id }}" {{ old('trainer_id') == $trainer->id ? 'selected' : '' }}>{{ $trainer->full_name }} ({{ $trainer->email }})</option>
                        @endforeach
                    </select>
                    <p style="font-size: 11px; color: var(--gta-muted); margin-top: 4px;">Responsible for whole-task marking approvals under 7-day SLA.</p>
                </div>
            </div>
        </div>

        <!-- 3. Employer & Workplace Mentor -->
        <div class="gta-card" style="margin-bottom: 20px;">
            <h3 style="font-size: 16px; font-weight: 700; border-bottom: 1px solid var(--gta-border); padding-bottom: 10px; margin-bottom: 16px;">
                3. Employer &amp; Workplace Mentor
            </h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px;">
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px;">Existing Partner Employer</label>
                    <select name="employer_id" class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;">
                        <option value="">-- Or enter new employer below --</option>
                        @foreach($employers as $emp)
                            <option value="{{ $emp->employer_id }}" {{ old('employer_id') == $emp->employer_id ? 'selected' : '' }}>{{ $emp->employer_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px;">Or New Employer Company Name</label>
                    <input type="text" name="new_employer_name" value="{{ old('new_employer_name') }}" placeholder="e.g. Apex Autocare &amp; MOT Centre" class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px;">Workplace Mentor Name</label>
                    <input type="text" name="workplace_mentor_name" value="{{ old('workplace_mentor_name') }}" placeholder="e.g. Dave Miller (Master Tech)" class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px;">Workplace Mentor Email</label>
                    <input type="email" name="workplace_mentor_email" value="{{ old('workplace_mentor_email') }}" placeholder="dave@apexautocare.test" class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;">
                </div>
            </div>
        </div>

        <!-- 4. Apprenticeship Standard & Delivery Dates -->
        <div class="gta-card" style="margin-bottom: 24px;">
            <h3 style="font-size: 16px; font-weight: 700; border-bottom: 1px solid var(--gta-border); padding-bottom: 10px; margin-bottom: 16px;">
                4. Apprenticeship Standard &amp; Training Target
            </h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px;">
                <div style="grid-column: span 2;">
                    <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px;">Apprenticeship Standard *</label>
                    <select name="standard_id" required class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;">
                        @foreach($standards as $std)
                            <option value="{{ $std->standard_id }}" {{ old('standard_id') == $std->standard_id ? 'selected' : '' }}>
                                {{ $std->title }} ({{ $std->standard_code }}) &bull; Level {{ $std->level }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px;">Apprenticeship Start Date *</label>
                    <input type="date" name="start_date" value="{{ old('start_date', now()->format('Y-m-d')) }}" required class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px;">Planned Gateway / End Date *</label>
                    <input type="date" name="planned_end_date" value="{{ old('planned_end_date', now()->addMonths(24)->format('Y-m-d')) }}" required class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px;">Target OTJ Hours</label>
                    <input type="number" step="0.5" name="otj_target_hours" value="{{ old('otj_target_hours', 663.00) }}" class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;">
                    <p style="font-size: 11px; color: var(--gta-muted); margin-top: 4px;">Standard baseline: 663 hours over 24-month programme.</p>
                </div>
            </div>
        </div>

        <div style="display: flex; gap: 12px;">
            <button type="submit" class="gta-btn gta-btn-primary" style="padding: 12px 28px; font-size: 14px; font-weight: 700;">
                Save Programme &amp; Initialize Curriculum
            </button>
            <a href="{{ route('admin.apprentices.index') }}" class="gta-btn gta-btn-secondary" style="padding: 12px 20px; font-size: 14px;">Cancel</a>
        </div>
    </form>
</x-portal-layout>

