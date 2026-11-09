<x-portal-layout>
    <div class="ap-head" style="margin-bottom: 20px;">
        <div>
            <span class="sim-badge">{{ $apprentice->user->full_name }} &bull; Plan of training</span>
            <h1 style="font-size: 26px; font-weight: 700; margin-top: 4px; color: var(--gta-text);">Block plan</h1>
            <p style="font-size: 13px; color: var(--gta-muted); margin-top: 4px;">Blocks open on their agreed start dates. Earlier unfinished work stays available.</p>
        </div>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="{{ route('dashboard') }}" class="gta-btn gta-btn-secondary" style="text-decoration: none; font-size: 13px;">
                &larr; Back to apprentice
            </a>
            @if(Auth::user()->isStaff() && $allLearners = \App\Models\Apprentice::with('user')->where('status', 'Live')->get())
                <form method="GET" action="{{ route('tasks.index') }}" style="display: inline-flex; align-items: center; gap: 6px;">
                    <select name="apprentice" onchange="window.location.href='/apprentice/'+this.value+'/tasks'" style="padding: 6px 12px; font-size: 12px; border-radius: 6px; border: 1px solid var(--gta-border); background: var(--gta-card);">
                        @foreach($allLearners as $l)
                            <option value="{{ $l->apprentice_id }}" {{ $apprentice->apprentice_id === $l->apprentice_id ? 'selected' : '' }}>
                                {{ $l->user->full_name }}
                            </option>
                        @endforeach
                    </select>
                </form>
            @endif
        </div>
    </div>

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

    <p class="sim-note" style="margin-bottom: 22px;">
        Block time is based on the individual KSB training allocation and agreed delivery calendar. These captured example dates are reused from the supplied portal view. Opening a block is not evidence of training delivery or achievement.
    </p>

    <!-- SECTION 1: Block calendar and task records (Matching bp-work-table in preview/index.html) -->
    <div class="panel" style="padding: 20px; margin-bottom: 28px;">
        <h2 style="font-size: 18px; font-weight: 700; color: #07366f; margin-bottom: 6px;">Block calendar and task records</h2>
        <p class="sim-caption" style="margin-bottom: 16px;">
            Task approval comes from the original task records. KSB confirmation is a separate tutor judgement; neither is a whole-apprenticeship achievement percentage. Planned dates are unchanged.
        </p>

        <div class="sim-table" style="overflow-x: auto;">
            <table class="bp-work-table gta-table">
                <thead>
                    <tr>
                        <th style="min-width: 220px;">Block</th>
                        <th style="min-width: 170px;">Agreed dates / access</th>
                        <th style="min-width: 260px;">Task progress</th>
                        <th style="min-width: 220px;">KSB position / source</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($blockPlanRows as $row)
                        <tr>
                            <th scope="row" style="font-size: 13px; text-align: left;">
                                <strong style="color: #07366f;">Block {{ $row['block'] }}</strong>
                                <small class="ap-meta" style="display: block; font-weight: normal; color: var(--gta-muted); margin-top: 2px;">
                                    {{ $row['title'] }}
                                </small>
                            </th>
                            <td>
                                @if($row['start'] && $row['end'])
                                    <div><strong>{{ $row['start'] }}</strong></div>
                                    <div style="color: var(--gta-muted);">{{ $row['end'] }}</div>
                                    <small class="ap-meta" style="display: inline-block; margin-top: 4px;">
                                        <span class="badge {{ $row['access'] === 'Open' ? 'badge-green' : 'badge-blue' }}" style="font-size: 10px;">
                                            {{ $row['access'] }}
                                        </span>
                                    </small>
                                @else
                                    <span style="color: var(--gta-muted); font-size: 12px;">Dates not recorded</span>
                                @endif
                            </td>
                            <td>
                                @if($row['configured'])
                                    <b>{{ $row['approved'] }} / {{ $row['loaded'] }} loaded tasks approved</b><br>
                                    @if($row['percent'] !== null)
                                        <span style="font-size: 12px; font-weight: 700; color: {{ $row['percent'] === 100 ? '#15803d' : '#254c6a' }};">
                                            {{ $row['percent'] }}% task approval
                                        </span>
                                    @else
                                        <span style="font-size: 11px; color: var(--gta-muted);">Percentage not rated</span>
                                    @endif
                                    <small class="ap-meta" style="display: block; color: var(--gta-muted); margin-top: 3px;">
                                        {{ $row['waiting'] }} with tutor &bull; {{ $row['changes'] }} requiring corrections / reassessment
                                    </small>
                                @else
                                    <span style="color: var(--gta-muted); font-size: 12px;">Task content pending &bull; no completion percentage</span>
                                @endif
                            </td>
                            <td>
                                <div style="font-size: 12px; color: var(--gta-text); line-height: 1.4;">
                                    {{ $row['confirmation'] }}
                                </div>
                                @if($row['canOpen'])
                                    <div class="sim-actions" style="margin-top: 8px;">
                                        <a href="#block-workspace-{{ $row['block'] }}" class="gta-btn gta-btn-secondary" style="font-size: 11px; padding: 4px 10px; text-decoration: none;">
                                            Open block {{ $row['block'] }} &darr;
                                        </a>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <!-- SECTION 2: Request a Change (Matching block-request-panel in preview/index.html) -->
    <div class="ap-panel panel" id="block-request-panel" style="padding: 20px; margin-bottom: 28px;">
        <h2 style="font-size: 18px; font-weight: 700; color: #07366f; margin-bottom: 4px;">Request a change</h2>
        <p style="font-size: 13px; color: var(--gta-muted); margin-bottom: 16px;">
            Tutors request a change; an administrator acting as the authorised manager must approve it with a reason.
        </p>

        <form method="POST" action="{{ route('tasks.block-request', $apprentice->apprentice_id) }}" id="block-request-form">
            @csrf
            <div class="sim-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 14px;">
                <label style="font-size: 12px; font-weight: 600; display: block;">
                    Block
                    <select name="block_num" required style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px; margin-top: 4px;">
                        @for($b = 1; $b <= 10; $b++)
                            <option value="{{ $b }}">Block {{ $b }}</option>
                        @endfor
                    </select>
                </label>
                <label style="font-size: 12px; font-weight: 600; display: block;">
                    Change Type
                    <select name="request_type" id="requestTypeSelect" onchange="toggleDateInputs(this.value)" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px; margin-top: 4px;">
                        <option value="early">Open early</option>
                        <option value="dates">Change planned dates</option>
                    </select>
                </label>
            </div>

            <div id="blockRequestDatesWrapper" class="sim-grid" style="display: none; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 14px;">
                <label style="font-size: 12px; font-weight: 600; display: block;">
                    Proposed start date
                    <input type="date" name="start_date" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px; margin-top: 4px;">
                </label>
                <label style="font-size: 12px; font-weight: 600; display: block;">
                    Proposed finish date
                    <input type="date" name="end_date" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px; margin-top: 4px;">
                </label>
            </div>

            <label style="font-size: 12px; font-weight: 600; display: block; margin-bottom: 14px;">
                Why is a change needed? *
                <textarea name="reason" rows="2" required placeholder="Explain the operational or learning requirement for this block adjustment..." style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px; margin-top: 4px; box-sizing: border-box;"></textarea>
            </label>

            <div class="sim-actions" style="display: flex; gap: 10px;">
                <button type="submit" class="gta-btn gta-btn-primary" style="font-size: 13px; font-weight: 700; padding: 9px 18px;">
                    Submit request
                </button>
            </div>
        </form>
    </div>

    <!-- SECTION 3: Requests and decisions -->
    <div class="panel" style="padding: 20px; margin-bottom: 28px;">
        <h2 style="font-size: 18px; font-weight: 700; color: #07366f; margin-bottom: 12px;">Requests and decisions</h2>
        @if($changeRequests->isEmpty())
            <p style="font-size: 13px; color: var(--gta-muted);">No block change requests submitted yet.</p>
        @else
            <div style="display: flex; flex-direction: column; gap: 12px;">
                @foreach($changeRequests as $req)
                    <article class="ap-panel" style="padding: 14px; background: var(--gta-bg); border-radius: 6px; border: 1px solid var(--gta-border);">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 6px; flex-wrap: wrap; gap: 8px;">
                            <strong>
                                Block {{ $req->block_num }} &bull; {{ $req->request_type === 'early' ? 'Open early' : 'Change dates' }} &bull;
                                <span class="badge {{ $req->status === 'Approved' ? 'badge-green' : ($req->status === 'Rejected' ? 'badge-red' : 'badge-amber') }}">
                                    {{ $req->status }}
                                </span>
                            </strong>
                            <small class="ap-meta" style="color: var(--gta-muted);">
                                {{ \Carbon\Carbon::parse($req->created_at)->format('d M Y') }}
                            </small>
                        </div>
                        <p style="font-size: 13px; color: var(--gta-text); margin-bottom: 6px;">{{ $req->reason }}</p>

                        @if($req->status === 'Requested' && Auth::user()->role === \App\Enums\UserRole::Admin)
                            <form method="POST" action="{{ route('tasks.block-decide', $req->id) }}" style="margin-top: 10px; padding: 10px; background: #fff; border-radius: 4px; border: 1px solid var(--gta-border);">
                                @csrf
                                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                    <label style="font-size: 12px; font-weight: 600;">Decision:</label>
                                    <select name="decision" required style="padding: 5px 10px; font-size: 12px; border-radius: 4px; border: 1px solid var(--gta-border);">
                                        <option value="Approve">Approve</option>
                                        <option value="Reject">Reject</option>
                                    </select>
                                    <input type="text" name="decision_reason" required placeholder="Manager justification reason..." style="flex: 1; padding: 5px 10px; font-size: 12px; border-radius: 4px; border: 1px solid var(--gta-border); min-width: 200px;">
                                    <button type="submit" class="gta-btn gta-btn-secondary" style="font-size: 12px; padding: 5px 12px;">
                                        Record Decision
                                    </button>
                                </div>
                            </form>
                        @elseif($req->decision_reason)
                            <p class="sim-caption" style="margin-top: 6px; font-size: 12px; color: var(--gta-muted);">
                                <strong>Manager Decision:</strong> {{ $req->decision_reason }}
                            </p>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </div>

    <!-- SECTION 4: 10-Block Training Tasks Workspace (Detailed curriculum) -->
    <h2 style="font-size: 20px; font-weight: 700; color: #07366f; margin-bottom: 16px;">10-Block Training Curriculum Workspace</h2>

    @foreach($blocks as $block)
        @php
            $blockTasks = $tasks->get($block->block_num, collect());
            $completedInBlock = $blockTasks->where('status', 'Completed')->count();
        @endphp
        <div class="gta-card" id="block-workspace-{{ $block->block_num }}" style="margin-bottom: 20px;">
            <header style="padding-bottom: 12px; margin-bottom: 14px; border-bottom: 1px solid var(--gta-border); display: flex; justify-content: space-between; align-items: start; flex-wrap: wrap; gap: 12px;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <span style="background: #254c6a; color: #fff; width: 34px; height: 34px; border-radius: 6px; display: grid; place-items: center; font-weight: 700; font-size: 14px;">
                        {{ $block->block_num }}
                    </span>
                    <div>
                        <h3 style="font-size: 16px; font-weight: 700; color: #07366f; margin: 0;">Block {{ $block->block_num }}: {{ $block->title }}</h3>
                        <p style="font-size: 12px; color: var(--gta-muted); margin-top: 3px;">
                            {{ $block->primary_themes }} &bull; {{ $block->nominal_hours }} nominal training hours
                            @if($block->start_date && $block->end_date)
                                &bull; <span style="color: #254c6a; font-weight: 600;">{{ $block->start_date->format('d M Y') }} &rarr; {{ $block->end_date->format('d M Y') }}</span>
                            @endif
                        </p>
                    </div>
                </div>
                <div>
                    <span class="badge {{ $completedInBlock === 5 ? 'badge-green' : ($completedInBlock > 0 ? 'badge-amber' : '') }}" style="font-size: 11px;">
                        {{ $completedInBlock }} / 5 Tasks Done
                    </span>
                </div>
            </header>

            @if($blockTasks->isEmpty())
                <p style="font-size: 13px; color: var(--gta-muted); padding: 12px 0;">No tasks configured for this block.</p>
            @else
                <table class="gta-table" style="width: 100%;">
                    <thead>
                        <tr>
                            <th style="width: 70px;">Task #</th>
                            <th>Task Title &amp; Technical Scope</th>
                            <th style="width: 140px;">Status</th>
                            <th style="width: 120px;">Evidence Files</th>
                            <th style="width: 130px;">Action</th>
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
                                        <div style="font-size: 11px; color: var(--gta-muted); margin-top: 2px;">{{ $task->theme }}</div>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $badgeClass }}">
                                        {{ $task->status }}
                                    </span>
                                </td>
                                <td>
                                    {{ $task->uploads->count() }} {{ Str::plural('file', $task->uploads->count()) }}
                                </td>
                                <td>
                                    <a href="{{ route('tasks.show', $task->at_id) }}" class="gta-btn gta-btn-secondary" style="font-size: 11px; padding: 4px 10px; text-decoration: none;">
                                        Open Workspace &rarr;
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endforeach

    <!-- SECTION 5: Gateway Readiness (Matching #gateway-readiness in preview/index.html) -->
    <section id="gateway-readiness" class="rm-panel panel" style="padding: 20px; margin-top: 30px; border-left: 4px solid #075dcc;">
        <h2 style="font-size: 18px; font-weight: 700; color: #07366f; margin-bottom: 6px;">Gateway readiness &bull; Blocks 11&ndash;12</h2>
        <p class="sim-caption" style="margin-bottom: 12px;">
            Assessment-plan version and source check. Minimum 12-month delivery, required functional skills, and tripartite gateway declaration.
        </p>
        <p class="sim-note" style="margin-bottom: 16px;">
            GTA&rsquo;s gateway signer is the assigned main tutor. An ordinary administrator, Learning &amp; Progress Mentor or subject tutor does not replace them. The apprentice, employer and GTA readiness sign-off is separate from progress-review signatures, training-plan agreement and EPA results.
        </p>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px;">
            <div style="padding: 12px; background: var(--gta-bg); border-radius: 6px; border: 1px solid var(--gta-border);">
                <strong>Block 11: Gateway Readiness Declaration</strong>
                <p style="font-size: 12px; color: var(--gta-muted); margin-top: 4px;">
                    Scheduled: <strong>10-Dec-2027 &rarr; 26-Dec-2027</strong><br>
                    Requires all 10 technical blocks completed, full 663 OTJ hours verified, and Level 2 Functional Skills English &amp; Maths secured.
                </p>
            </div>
            <div style="padding: 12px; background: var(--gta-bg); border-radius: 6px; border: 1px solid var(--gta-border);">
                <strong>Block 12: End-Point Assessment (EPA)</strong>
                <p style="font-size: 12px; color: var(--gta-muted); margin-top: 4px;">
                    Scheduled: <strong>03-Jan-2028</strong><br>
                    External synoptic assessment with independent EPAO assessor. Practical observation, professional discussion, and showcase portfolio check.
                </p>
            </div>
        </div>
    </section>

    <script>
        function toggleDateInputs(value) {
            const wrap = document.getElementById('blockRequestDatesWrapper');
            if (wrap) {
                wrap.style.display = (value === 'dates') ? 'grid' : 'none';
            }
        }
    </script>
</x-portal-layout>
