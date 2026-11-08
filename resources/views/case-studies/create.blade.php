<x-portal-layout>
    <div style="margin-bottom: 20px;">
        <a href="{{ route('case-studies.index') }}" style="font-size: 13px; color: #64748b; text-decoration: none;">&larr; Back to Case Studies Bank</a>
        <h2 style="font-size: 22px; font-weight: 700; color: #0f172a; margin-top: 10px;">Author Anonymous Case Study</h2>
        <p style="font-size: 13px; color: #64748b; margin-top: 4px;">
            Document qualitative evidence of learning support, retention intervention, or teaching adaptation.
            <strong>Note:</strong> Case studies must remain strictly anonymous. Do not include learner names, contact details, or sensitive medical diagnoses.
        </p>
    </div>

    @if ($errors->any())
        <div style="background: #fee2e2; border: 1px solid #f87171; color: #991b1b; padding: 14px; border-radius: 6px; font-size: 13px; margin-bottom: 20px;">
            <ul style="margin-left: 20px;">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="gta-card">
        <form method="POST" action="{{ route('case-studies.store') }}">
            @csrf

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 18px; margin-bottom: 18px;">
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 6px;">Case Study Title</label>
                    <input type="text" name="title" value="{{ old('title') }}" required placeholder="e.g. Overcoming workshop anxiety through structured practical mentoring" class="form-control" style="width: 100%; padding: 9px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 6px;">Category (11 Standard Areas)</label>
                    <select name="category" required class="form-control" style="width: 100%; padding: 9px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px;">
                        @foreach($categories as $cat)
                            <option value="{{ $cat }}" {{ old('category') === $cat ? 'selected' : '' }}>{{ $cat }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-bottom: 18px;">
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 6px;">Identified Barrier / Challenge</label>
                    <textarea name="challenge" rows="3" required class="form-control" style="width: 100%; padding: 8px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px;" placeholder="Describe the practical, educational, or attendance barrier...">{{ old('challenge') }}</textarea>
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 6px;">Starting Point / Baseline</label>
                    <textarea name="starting_point" rows="3" required class="form-control" style="width: 100%; padding: 8px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px;" placeholder="What was the initial engagement, attendance, or assessment score...">{{ old('starting_point') }}</textarea>
                </div>
            </div>

            <div style="margin-bottom: 18px;">
                <label style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 6px;">GTA Pedagogical / Mentorship Response</label>
                <textarea name="response" rows="3" required class="form-control" style="width: 100%; padding: 8px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px;" placeholder="What specific technical adjustment, 1:1 coaching, or employer collaboration was delivered...">{{ old('response') }}</textarea>
            </div>

            <!-- Before & After Metrics Comparison -->
            <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 18px; margin-bottom: 18px;">
                <h4 style="font-size: 13px; font-weight: 700; color: #0f172a; margin-bottom: 12px;">Before vs. After Measure Comparison</h4>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 18px;">
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">Before State Measure</label>
                        <input type="text" name="before_state" value="{{ old('before_state') }}" required placeholder="e.g. 78% attendance, 0 tasks completed" style="width: 100%; padding: 8px; font-size: 12px; border: 1px solid #cbd5e1; border-radius: 4px; margin-bottom: 8px;">
                        <label style="display: block; font-size: 11px; color: #64748b; margin-bottom: 4px;">As-of Date</label>
                        <input type="date" name="before_date" value="{{ old('before_date', now()->subMonths(3)->toDateString()) }}" required style="width: 100%; padding: 6px; font-size: 12px; border: 1px solid #cbd5e1; border-radius: 4px;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">After State Measure</label>
                        <input type="text" name="after_state" value="{{ old('after_state') }}" required placeholder="e.g. 96% attendance, 5/5 Block tasks completed" style="width: 100%; padding: 8px; font-size: 12px; border: 1px solid #cbd5e1; border-radius: 4px; margin-bottom: 8px;">
                        <label style="display: block; font-size: 11px; color: #64748b; margin-bottom: 4px;">As-of Date</label>
                        <input type="date" name="after_date" value="{{ old('after_date', now()->toDateString()) }}" required style="width: 100%; padding: 6px; font-size: 12px; border: 1px solid #cbd5e1; border-radius: 4px;">
                    </div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 18px; margin-bottom: 24px;">
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 6px;">Verifiable Evidence Basis</label>
                    <input type="text" name="evidence" value="{{ old('evidence') }}" required placeholder="e.g. Electronic session registers, tutor marking log, signed review #2" style="width: 100%; padding: 9px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 6px;">Primary Outcome</label>
                    <select name="outcome" required class="form-control" style="width: 100%; padding: 9px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px;">
                        <option value="Progress improved">Progress improved</option>
                        <option value="Retained in learning">Retained in learning</option>
                        <option value="Apprenticeship achieved">Apprenticeship achieved</option>
                        <option value="Employment secured">Employment secured</option>
                        <option value="Wider development">Wider development</option>
                        <option value="Mixed outcome">Mixed outcome</option>
                    </select>
                </div>
            </div>

            <div style="display: flex; gap: 12px;">
                <button type="submit" name="submit_for_approval" value="1" style="padding: 11px 24px; background: #254c6a; color: #fff; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer;">
                    Submit for Peer Administrator Approval &rarr;
                </button>
                <button type="submit" name="submit_for_approval" value="0" style="padding: 11px 20px; background: #cbd5e1; color: #1e293b; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer;">
                    Save as Draft
                </button>
            </div>
        </form>
    </div>
</x-portal-layout>

