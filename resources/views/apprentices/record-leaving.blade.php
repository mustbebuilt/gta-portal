<section class="panel" aria-labelledby="record-leaving">
    <h2 id="record-leaving">Withdrawal or transfer</h2>
    <form method="post" action="{{ route('retention.save', $apprentice) }}">
        @csrf
        <input type="hidden" name="workflow" value="exit">
        <input type="hidden" name="version" value="{{ $outcome?->version ?? 0 }}">
        <div class="form-grid">
            <div>
                <label for="leaving-status">Outcome</label>
                <select id="leaving-status" name="context[status]" required>
                    @foreach(['Withdrawn', 'Transferred'] as $status)
                        <option @selected(old('context.status') === $status)>{{ $status }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="leaving-end">Actual programme end date</label>
                <input id="leaving-end" type="date" name="context[endDate]" max="{{ today()->toDateString() }}" value="{{ old('context.endDate') }}" required>
            </div>
            <div>
                <label for="leaving-source">Source reference</label>
                <input id="leaving-source" name="context[sourceRef]" maxlength="1000" value="{{ old('context.sourceRef') }}" required>
            </div>
            <div>
                <label for="leaving-employment">Employment at exit</label>
                <select id="leaving-employment" name="context[exitEmployment]">
                    @foreach($employmentChoices as $choice)
                        <option @selected(old('context.exitEmployment', 'Unknown') === $choice)>{{ $choice }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <details @if(old('context.reason', 'Not established') !== 'Not established') open @endif>
            <summary>Add a reason for withdrawal</summary>
            <div class="form-grid">
                <div>
                    <label for="leaving-reason">Withdrawal reason</label>
                    <select id="leaving-reason" name="context[reason]">
                        @foreach($exitChoices['reason'] as $choice)
                            <option @selected(old('context.reason') === $choice)>{{ $choice }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="leaving-basis">Who confirmed the reason</label>
                    <select id="leaving-basis" name="context[reasonBasis]">
                        @foreach($exitChoices['reasonBasis'] as $choice)
                            <option @selected(old('context.reasonBasis') === $choice)>{{ $choice }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </details>
        <div class="form-actions"><button class="primary">Save leaving outcome</button></div>
    </form>
</section>
