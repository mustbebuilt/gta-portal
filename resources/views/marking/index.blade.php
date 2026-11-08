<x-portal-layout>
    <div class="gta-card">
        <header>
            <div>
                <h2 style="font-size: 20px; font-weight: 700;">Task Marking SLA Queue</h2>
                <p style="font-size: 13px; color: #64748b; margin-top: 4px;">
                    SLA Rule: Submissions waiting over 7 calendar days are marked <strong style="color: #b91c1c;">Overdue (Red)</strong>. Due today is not overdue.
                </p>
            </div>
            <div>
                <span class="badge badge-amber">{{ $tasks->count() }} Waiting Decisions</span>
            </div>
        </header>

        @if(session('success'))
            <div style="background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 12px; border-radius: 6px; font-size: 13px; margin-bottom: 16px;">
                {{ session('success') }}
            </div>
        @endif

        @if(session('info'))
            <div style="background: #e0f2fe; border: 1px solid #7dd3fc; color: #075985; padding: 12px; border-radius: 6px; font-size: 13px; margin-bottom: 16px;">
                {{ session('info') }}
            </div>
        @endif

        @if($tasks->isEmpty())
            <div style="padding: 36px; text-align: center; color: #64748b; font-size: 14px;">
                &#10004; Marking queue is clear. No apprentice submissions are waiting review.
            </div>
        @else
            <table class="gta-table">
                <thead>
                    <tr>
                        <th>Apprentice</th>
                        <th>Block &amp; Task</th>
                        <th>Submitted Date</th>
                        <th>Waiting Time</th>
                        <th>SLA Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($tasks as $task)
                        @php
                            $daysWaiting = $task->submitted_at ? $task->submitted_at->diffInDays(now()) : 0;
                            $isOverdue = $daysWaiting > 7;
                        @endphp
                        <tr>
                            <td><strong>{{ $task->apprentice->user->full_name }}</strong></td>
                            <td>Block {{ $task->ablk_id }} &bull; {{ $task->task_type }}</td>
                            <td>{{ $task->submitted_at ? $task->submitted_at->format('d M Y') : 'N/A' }}</td>
                            <td>{{ $daysWaiting }} {{ Str::plural('day', $daysWaiting) }}</td>
                            <td>
                                @if($isOverdue)
                                    <span class="badge badge-red">Overdue ({{ $daysWaiting }}d)</span>
                                @else
                                    <span class="badge badge-green">Within SLA</span>
                                @endif
                            </td>
                            <td>
                                <form method="POST" action="{{ route('marking.approve', $task->at_id) }}" style="display: inline;">
                                    @csrf
                                    <input type="hidden" name="feedback" value="Tutor approved following online evidence review.">
                                    <button type="submit" style="padding: 6px 14px; background: #15803d; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 600;">Approve</button>
                                </form>

                                <form method="POST" action="{{ route('marking.refer', $task->at_id) }}" style="display: inline; margin-left: 6px;">
                                    @csrf
                                    <input type="hidden" name="feedback" value="Please re-check the reflection notes and re-upload job card.">
                                    <button type="submit" style="padding: 6px 12px; background: #cbd5e1; color: #1e293b; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 600;">Refer</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</x-portal-layout>

