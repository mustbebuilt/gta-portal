<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>GTA Quality &amp; Inspection Evidence Dossier</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Inter, -apple-system, Arial, sans-serif; color: #1e293b; background: #fff; padding: 30px; font-size: 11pt; line-height: 1.5; }
        
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #254c6a; padding-bottom: 16px; margin-bottom: 24px; }
        .brand { display: flex; align-items: center; gap: 14px; }
        .logo { width: 50px; height: 50px; object-fit: contain; }
        .brand h1 { font-size: 16pt; font-weight: 700; color: #0f172a; }
        .brand p { font-size: 9pt; color: #64748b; }
        
        .meta { text-align: right; font-size: 9pt; color: #64748b; }
        
        h2 { font-size: 13pt; font-weight: 700; color: #254c6a; margin: 20px 0 10px; border-bottom: 1px solid #cbd5e1; padding-bottom: 4px; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 20px; }
        .stat-box { border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px; background: #f8fafc; }
        .stat-box small { display: block; font-size: 8pt; text-transform: uppercase; color: #64748b; font-weight: 600; }
        .stat-box strong { font-size: 14pt; color: #0f172a; }

        table { width: 100%; border-collapse: collapse; font-size: 9pt; margin-bottom: 24px; }
        th, td { border: 1px solid #cbd5e1; padding: 8px 10px; text-align: left; }
        th { background: #f1f5f9; font-weight: 700; color: #334155; }
        
        .signoff-box { margin-top: 30px; border: 1px solid #cbd5e1; padding: 18px; border-radius: 6px; page-break-inside: avoid; }
        .signoff-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 14px; }
        .sign-line { border-bottom: 1px solid #94a3b8; height: 35px; margin-top: 8px; }

        @media print {
            body { padding: 0; font-size: 10pt; }
            .no-print { display: none; }
            .page-break { page-break-before: always; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom: 20px; padding: 12px; background: #e0f2fe; border-radius: 6px; display: flex; justify-content: space-between; align-items: center;">
        <span>This is the print-ready inspection evidence dossier. Click the button or press <strong>Cmd + P</strong> to print or save as PDF.</span>
        <button onclick="window.print()" style="padding: 8px 18px; background: #254c6a; color: #fff; border: none; border-radius: 4px; font-weight: 600; cursor: pointer;">
            Print / Save as PDF
        </button>
    </div>

    <div class="header">
        <div class="brand">
            <img src="{{ asset('images/gta-logo.jpg') }}" alt="GTA Logo" class="logo">
            <div>
                <h1>Doncaster, Rotherham and District Motor Trades GTA Ltd</h1>
                <p>Quality Inspection &amp; Statutory Compliance Dossier &bull; Build 2026.11.08.6</p>
            </div>
        </div>
        <div class="meta">
            <div>Report Date: <strong>{{ date('d F Y') }}</strong></div>
            <div>Authority: <strong>Quality Administrator / CEO</strong></div>
        </div>
    </div>

    <h2>1. Executive Summary &amp; Compliance Indicators</h2>
    <div class="stats-grid">
        <div class="stat-box">
            <small>Active Learners</small>
            <strong>{{ $totalLearners }}</strong>
        </div>
        <div class="stat-box">
            <small>Tripartite Review Rate</small>
            <strong>{{ $reviewSigningRate }}%</strong>
        </div>
        <div class="stat-box">
            <small>Total OTJ Delivered</small>
            <strong>{{ number_format($totalVerifiedOtjHours, 1) }}h</strong>
        </div>
        <div class="stat-box">
            <small>Curriculum Completion</small>
            <strong>{{ $curriculumCompletionRate }}%</strong>
        </div>
    </div>

    <h2>2. Active Learner Milestones &amp; Standard Progress</h2>
    <table>
        <thead>
            <tr>
                <th>Apprentice Name</th>
                <th>Standard</th>
                <th>Employer Garage</th>
                <th>Assigned Tutor</th>
                <th>Completed Tasks</th>
                <th>OTJ Verified</th>
                <th>3-Party Reviews</th>
            </tr>
        </thead>
        <tbody>
            @foreach($apprentices as $app)
                @php
                    $tasksCount = $app->tasks->where('status', 'Completed')->count();
                    $signedRevs = $app->progressReviews->where('status', 'Signed')->count();
                    $totalRevs = $app->progressReviews->count();
                @endphp
                <tr>
                    <td><strong>{{ $app->user->full_name }}</strong></td>
                    <td>{{ $app->standard?->title ?? 'Autocare Technician' }}</td>
                    <td>{{ $app->employer?->employer_name ?? 'GTA Partner' }}</td>
                    <td>{{ $app->trainer?->full_name ?? 'Alex Taylor' }}</td>
                    <td>{{ $tasksCount }} / 50 Tasks</td>
                    <td>{{ number_format($app->otj_hours_completed, 1) }} / {{ number_format($app->otj_target_hours, 0) }}h</td>
                    <td>{{ $signedRevs }} / {{ $totalRevs }} Signed</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="page-break"></div>

    <h2>3. Peer-Approved Qualitative Case Studies</h2>
    <table>
        <thead>
            <tr>
                <th style="width: 140px;">Category</th>
                <th>Case Title &amp; Intervention</th>
                <th>Measured Progress (Before &rarr; After)</th>
                <th>Peer Approver</th>
            </tr>
        </thead>
        <tbody>
            @forelse($approvedCaseStudies as $case)
                <tr>
                    <td><strong>{{ $case->category }}</strong></td>
                    <td>
                        <strong>{{ $case->title }}</strong>
                        <p style="font-size: 8pt; color: #475569; margin-top: 2px;">{{ Str::limit($case->response, 120) }}</p>
                    </td>
                    <td>
                        <span style="color: #b91c1c;">{{ $case->before_state }}</span> &rarr;
                        <strong style="color: #15803d;">{{ $case->after_state }}</strong>
                    </td>
                    <td>{{ $case->approver?->full_name ?? 'Administrator' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" style="text-align: center; color: #64748b;">No published case studies recorded.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="signoff-box">
        <strong>Provider Sign-off &amp; Internal Quality Assurance</strong>
        <p style="font-size: 8pt; color: #64748b; margin-top: 4px;">
            I confirm that the metrics and apprentice records set out in this dossier are drawn directly from the live canonical registers.
        </p>
        <div class="signoff-grid">
            <div>
                <small style="font-size: 8pt; color: #475569; font-weight: 600;">GTA Quality Manager / Lead Reviewer</small>
                <div class="sign-line"></div>
            </div>
            <div>
                <small style="font-size: 8pt; color: #475569; font-weight: 600;">Date of Inspection Verification</small>
                <div class="sign-line"></div>
            </div>
        </div>
    </div>
</body>
</html>

