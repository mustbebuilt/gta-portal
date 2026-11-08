<x-portal-layout>
    <div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: start; flex-wrap: wrap; gap: 16px;">
        <div>
            <h2 style="font-size: 22px; font-weight: 700; color: #0f172a;">10-Block Training Curriculum</h2>
            <p style="font-size: 13px; color: #64748b; margin-top: 4px;">
                Apprentice: <strong>{{ $apprentice->user->full_name }}</strong> &bull; Standard: <strong>{{ $apprentice->standard?->title ?? 'Autocare Technician' }}</strong> (50 Core Practical Tasks)
            </p>
        </div>
        <div style="font-size: 13px; color: #64748b;">
            Total Progress: <strong>{{ $apprentice->tasks->where('status', 'Completed')->count() }} / 50 Completed</strong>
        </div>
    </div>

    <!-- 10 Blocks Container -->
    @foreach($blocks as $block)
        @php
            $blockTasks = $tasks->get($block->block_num, collect());
            $completedInBlock = $blockTasks->where('status', 'Completed')->count();
        @endphp
        <div class="gta-card" style="margin-bottom: 18px;">
            <header style="padding-bottom: 12px; margin-bottom: 14px;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <span style="background: #254c6a; color: #fff; width: 32px; height: 32px; border-radius: 6px; display: grid; place-items: center; font-weight: 700; font-size: 14px;">
                        {{ $block->block_num }}
                    </span>
                    <div>
                        <h3 style="font-size: 16px; font-weight: 700; color: #1e293b;">Block {{ $block->block_num }}: {{ $block->title }}</h3>
                        <p style="font-size: 12px; color: #64748b; margin-top: 2px;">{{ $block->primary_themes ?? 'Core technical and safety competencies' }} &bull; {{ $block->nominal_hours }} nominal training hours</p>
                    </div>
                </div>
                <div>
                    <span class="badge {{ $completedInBlock === 5 ? 'badge-green' : ($completedInBlock > 0 ? 'badge-amber' : '') }}" style="background: {{ $completedInBlock === 5 ? '#dcfce7' : '#f1f5f9' }}; color: {{ $completedInBlock === 5 ? '#15803d' : '#475569' }};">
                        {{ $completedInBlock }} / 5 Tasks Done
                    </span>
                </div>
            </header>

            <table class="gta-table">
                <thead>
                    <tr>
                        <th style="width: 80px;">Task #</th>
                        <th>Task Title &amp; Evidence Mode</th>
                        <th style="width: 140px;">Status</th>
                        <th style="width: 130px;">Evidence Files</th>
                        <th style="width: 120px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($blockTasks as $task)
                        @php
                            $badgeClass = match($task->status) {
                                'Completed' => 'badge-green',
                                'Submitted' => 'badge-amber',
                                'In Progress' => 'badge-blue',
                                'Referred' => 'badge-red',
                                default => '',
                            };
                        @endphp
                        <tr>
                            <td><strong>T{{ $task->task_num }}</strong></td>
                            <td>
                                <a href="{{ route('tasks.show', $task->at_id) }}" style="color: #254c6a; font-weight: 600; text-decoration: none;">
                                    {{ $task->task_type }}
                                </a>
                                @if($task->theme)
                                    <div style="font-size: 11px; color: #64748b; margin-top: 2px;">{{ $task->theme }}</div>
                                @endif
                            </td>
                            <td>
                                <span class="badge {{ $badgeClass }}" style="{{ $task->status === 'Not Started' ? 'background: #f1f5f9; color: #64748b;' : '' }}">
                                    {{ $task->status }}
                                </span>
                            </td>
                            <td>
                                {{ $task->uploads->count() }} {{ Str::plural('file', $task->uploads->count()) }}
                            </td>
                            <td>
                                <a href="{{ route('tasks.show', $task->at_id) }}" style="font-size: 12px; color: #254c6a; font-weight: 600; text-decoration: underline;">
                                    Open Task &rarr;
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach
</x-portal-layout>

