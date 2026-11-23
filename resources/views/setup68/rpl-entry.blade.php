@if(!$review['template'])
    <p class="warn">Choose an approved programme before recording prior learning.</p>
    <a class="button" href="{{ route('setup68.show', [$draft, 3]) }}">Choose programme</a>
@else
    <input type="hidden" name="action" value="record_rpl">
    @php($mode = old('data.mode', $p['rpl']['mode'] ?? ''))
    <label for="rpl-mode">Prior learning decision</label>
    <select id="rpl-mode" name="data[mode]" required>
        <option value="">Choose an option</option>
        <option value="none" @selected($mode === 'none')>No relevant prior learning</option>
        <option value="applies" @selected($mode === 'applies')>Apply assessed prior learning</option>
    </select>
    @if(!empty($p['rpl']['adjustments']))
        <p id="rpl-clear-note">Saving No relevant prior learning clears the current reductions.</p>
    @endif

    <details id="rpl-adjustments" @if($mode === 'applies') open @endif>
        <summary>Record assessed prior learning</summary>
        <label for="assessment">Completed assessment reference</label>
        <input id="assessment" name="data[assessment_ref]" value="{{ old('data.assessment_ref', $p['rpl']['assessment_ref'] ?? '') }}" maxlength="1000">
        @if($ksbs)
            <div data-rpl-tools hidden>
                <label for="rpl-ksb">Choose a KSB</label>
                <select id="rpl-ksb">
                    <option value="">Choose a Knowledge, Skill or Behaviour</option>
                    @foreach(collect($ksbs)->groupBy(fn ($ksb) => $ksb['code'][0]) as $type => $items)
                        <optgroup label="{{ $ksbTypes[$type] }}">
                            @foreach($items as $ksb)
                                <option value="{{ $ksb['code'] }}">{{ $ksb['code'] }} · {{ \Illuminate\Support\Str::limit($ksb['description'], 85) }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                <p>Choose each KSB that has an approved reduction, then set its percentage.</p>
            </div>
            <noscript><p>Set the approved reduction for each affected KSB. Leave the rest at 0%.</p></noscript>
        @endif
        @if($ksbFilters)
            <p><a href="{{ route('setup68.show', [$draft, 4]) }}">Show all KSBs</a></p>
        @endif
        <div class="table-wrap" data-rpl-rows>
            <table>
                <thead><tr><th scope="col">KSB</th><th scope="col">Requirement</th><th scope="col">Reduction</th></tr></thead>
                <tbody>
                @forelse($ksbs as $ksb)
                    @php($code = $ksb['code'])
                    <tr data-rpl-code="{{ $code }}" @if($errors->has('reductions.'.$code)) data-rpl-error @endif>
                        <th scope="row">{{ $code }}</th><td>{{ $ksb['description'] }}</td>
                        <td><select name="reductions[{{ $code }}]" aria-label="Reduction for {{ $code }}" data-rpl-reduction>
                            @for($percent = 0; $percent <= 100; $percent += 10)
                                <option value="{{ $percent }}" @selected((string) old('reductions.'.$code, $p['rpl']['adjustments'][$code] ?? 0) === (string) $percent)>{{ $percent }}%</option>
                            @endfor
                        </select>
                        @error('reductions.'.$code)<p class="warn">{{ $message }}</p>@enderror
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3">No KSBs match. Clear the filters or check the programme template.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <p data-rpl-summary data-other-adjusted="{{ count(array_diff_key($p['rpl']['adjustments'] ?? [], array_flip(array_column($ksbs, 'code')))) }}" aria-live="polite"></p>
        @if($review['calculation']['unresolved'] ?? [])
            <p class="warn">Block allocation needs checking before the plan can be confirmed. Ask the curriculum team to check the programme template.</p>
        @endif
    </details>
@endif
