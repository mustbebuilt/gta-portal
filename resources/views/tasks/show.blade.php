<x-portal-layout>
    <div style="margin-bottom: 20px;">
        <a href="{{ route('tasks.index') }}" style="font-size: 13px; color: #64748b; text-decoration: none;">&larr; Back to 10-Block Curriculum</a>
        <div style="display: flex; justify-content: space-between; align-items: start; margin-top: 10px; flex-wrap: wrap; gap: 14px;">
            <div>
                <span style="font-size: 12px; font-weight: 700; color: #254c6a; text-transform: uppercase; letter-spacing: 0.5px;">
                    Block {{ $task->ablk_id }}: {{ $block->title ?? 'Training Block' }} &bull; Task {{ $task->task_num }}
                </span>
                <h2 style="font-size: 22px; font-weight: 700; color: #0f172a; margin-top: 4px;">{{ $task->task_type }}</h2>
                <p style="font-size: 13px; color: #64748b; margin-top: 4px;">
                    Apprentice: <strong>{{ $task->apprentice->user->full_name }}</strong> &bull; Employer: <strong>{{ $task->apprentice->employer?->employer_name ?? 'GTA Partner' }}</strong>
                </p>
            </div>
            <div>
                @php
                    $badgeClass = match($task->status) {
                        'Completed' => 'badge-green',
                        'Submitted' => 'badge-amber',
                        'In Progress' => 'badge-blue',
                        'Referred' => 'badge-red',
                        default => '',
                    };
                @endphp
                <span class="badge {{ $badgeClass }}" style="font-size: 13px; padding: 6px 14px; {{ $task->status === 'Not Started' ? 'background: #f1f5f9; color: #64748b;' : '' }}">
                    {{ $task->status }}
                </span>
            </div>
        </div>
    </div>

    <!-- Alert Notifications -->
    @if(session('success'))
        <div style="background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 14px; border-radius: 6px; font-size: 13px; margin-bottom: 20px;">
            {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div style="background: #fee2e2; border: 1px solid #f87171; color: #991b1b; padding: 14px; border-radius: 6px; font-size: 13px; margin-bottom: 20px;">
            {{ session('error') }}
        </div>
    @endif

    <div style="display: grid; grid-template-columns: minmax(0, 1.8fr) minmax(320px, 1fr); gap: 24px;">
        <!-- Left Column: Evidence Uploads & Files -->
        <div>
            <div class="gta-card">
                <header>
                    <h3 style="font-size: 16px; font-weight: 700;">Task Evidence &amp; Submissions</h3>
                    <span style="font-size: 12px; color: #64748b;">{{ $task->uploads->count() }} {{ Str::plural('file', $task->uploads->count()) }}</span>
                </header>

                @if($task->uploads->isEmpty())
                    <div style="padding: 24px; text-align: center; color: #64748b; font-size: 13px; border: 1px dashed #cbd5e1; border-radius: 6px;">
                        No evidence files uploaded yet. Upload your completed job card, photo evidence, or knowledge test results below.
                    </div>
                @else
                    <table class="gta-table" style="margin-bottom: 16px;">
                        <thead>
                            <tr>
                                <th>Filename</th>
                                <th>Size</th>
                                <th>Uploaded</th>
                                <th>Status</th>
                                <th>Download</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($task->uploads as $upload)
                                <tr>
                                    <td><strong>{{ $upload->original_filename }}</strong></td>
                                    <td>{{ number_format($upload->file_size_bytes / 1024, 1) }} KB</td>
                                    <td>{{ $upload->created_at->format('d M Y, H:i') }}</td>
                                    <td>
                                        <span class="badge" style="background: #f1f5f9; color: #475569;">
                                            {{ $upload->status }}
                                        </span>
                                    </td>
                                    <td>
                                        <a href="{{ route('tasks.download', [$task->at_id, $upload->atu_id]) }}" style="font-size: 12px; color: #254c6a; font-weight: 600; text-decoration: underline;">
                                            Download
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                <!-- Upload Form for Apprentice -->
                @can('submit', $task)
                    <div style="margin-top: 24px; padding-top: 20px; border-top: 1px solid #e2e8f0;">
                        <h4 style="font-size: 14px; font-weight: 600; margin-bottom: 10px;">Upload Evidence Document</h4>
                        <form method="POST" action="{{ route('tasks.upload', $task->at_id) }}" enctype="multipart/form-data">
                            @csrf
                            <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                                <input type="file" name="evidence_file" required style="font-size: 13px; padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; background: #fff;">
                                <button type="submit" style="padding: 9px 18px; background: #254c6a; color: #fff; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer;">
                                    Upload File
                                </button>
                            </div>
                            <small style="display: block; color: #64748b; font-size: 11px; margin-top: 6px;">Supported files: PDF, JPEG, PNG, DOCX (Max 15MB)</small>
                        </form>
                    </div>

                    <!-- Submit for Tutor Marking CTA -->
                    @if($task->uploads->isNotEmpty())
                        <div style="margin-top: 20px; padding: 18px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <strong style="font-size: 14px; color: #0f172a; display: block;">Ready to submit this task?</strong>
                                <span style="font-size: 12px; color: #64748b;">Submitting flags this task for GTA tutor review. (7-day turnaround SLA applies).</span>
                            </div>
                            <form method="POST" action="{{ route('tasks.submit', $task->at_id) }}">
                                @csrf
                                <button type="submit" style="padding: 10px 20px; background: #15803d; color: #fff; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer;">
                                    Submit for Marking &rarr;
                                </button>
                            </form>
                        </div>
                    @endif
                @endcan
            </div>
        </div>

        <!-- Right Column: Tutor Decision & Feedback -->
        <div>
            <!-- Tutor Action Card -->
            <div class="gta-card">
                <header>
                    <h3 style="font-size: 16px; font-weight: 700;">Tutor Decision &amp; Feedback</h3>
                </header>

                @if($task->feedback_date)
                    <div style="padding: 14px; background: #f8fafc; border-radius: 6px; margin-bottom: 16px;">
                        <small style="color: #64748b; font-size: 11px; text-transform: uppercase; font-weight: 600;">Recorded Feedback ({{ $task->feedback_date->format('d M Y') }})</small>
                        <p style="font-size: 13px; color: #1e293b; margin-top: 6px; line-height: 1.5;">{{ $task->feedback }}</p>
                        @if($task->knowledge_score !== null)
                            <div style="margin-top: 10px; font-size: 13px; color: #15803d; font-weight: 600;">
                                Knowledge Score: {{ $task->knowledge_score }}%
                            </div>
                        @endif
                    </div>
                @endif

                @can('mark', $task)
                    <form method="POST" action="{{ route('marking.approve', $task->at_id) }}">
                        @csrf
                        <div style="margin-bottom: 14px;">
                            <label style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 6px;">Tutor Feedback &amp; Observations</label>
                            <textarea name="feedback" rows="4" class="form-control" style="width: 100%; padding: 10px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px;" placeholder="Provide feedback on the practical execution and job card details...">{{ $task->feedback }}</textarea>
                        </div>

                        @if($task->task_num === 1)
                            <div style="margin-bottom: 14px;">
                                <label style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 6px;">Paper Knowledge Mark (%)</label>
                                <input type="number" name="knowledge_score" value="{{ $task->knowledge_score }}" min="0" max="100" style="width: 100%; padding: 8px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px;">
                            </div>
                        @endif

                        <div style="display: flex; gap: 8px;">
                            <button type="submit" style="flex: 1; padding: 10px; background: #15803d; color: #fff; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer;">
                                Approve Task
                            </button>
                            <button type="submit" formaction="{{ route('marking.refer', $task->at_id) }}" style="padding: 10px 16px; background: #cbd5e1; color: #1e293b; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer;">
                                Refer
                            </button>
                        </div>
                    </form>
                @else
                    @if(!$task->feedback_date)
                        <div style="color: #64748b; font-size: 13px;">
                            No feedback recorded yet. Task is awaiting tutor review.
                        </div>
                    @endif
                @endcan
            </div>

            <!-- Task Metadata Card -->
            <div class="gta-card">
                <header>
                    <h3 style="font-size: 15px; font-weight: 700;">Task Information</h3>
                </header>
                <div style="font-size: 12px; line-height: 1.8; color: #475569;">
                    <div>Standard: <strong>{{ $task->apprentice->standard?->title ?? 'Autocare Technician' }}</strong></div>
                    <div>Block: <strong>Block {{ $task->ablk_id }} (30 hrs)</strong></div>
                    <div>Due Date: <strong>{{ $task->due_date ? $task->due_date->format('d M Y') : 'Aligned with Block' }}</strong></div>
                    <div>Submitted Date: <strong>{{ $task->submitted_at ? $task->submitted_at->format('d M Y, H:i') : 'Not yet submitted' }}</strong></div>
                    <div>Decision Date: <strong>{{ $task->completion_date ? $task->completion_date->format('d M Y') : 'Pending' }}</strong></div>
                </div>
            </div>
        </div>
    </div>
</x-portal-layout>

