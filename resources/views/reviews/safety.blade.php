<section class="panel no-print" data-review-safety>
    <h3>Accidents and near misses</h3>
    @php($answer = old('fields.safetyAnswer', $review->fields['safetyAnswer'] ?? ''))
    <fieldset>
        <legend>Since your last review, have you had any accidents or near misses?</legend>
        <label class="check"><input type="radio" name="fields[safetyAnswer]" value="no" @checked($answer === 'no')> No</label>
        <label class="check"><input type="radio" name="fields[safetyAnswer]" value="yes" @checked($answer === 'yes')> Yes</label>
    </fieldset>
    <div data-safety-followup>
        <label for="safety-followup">If yes, safe follow-up or restricted incident reference</label>
        <input id="safety-followup" name="fields[safetyFollowup]" maxlength="250" value="{{ old('fields.safetyFollowup',$review->fields['safetyFollowup'] ?? '') }}">
        <p>Keep incident details in the restricted incident record.</p>
    </div>
</section>
<script src="{{ asset('assets/review-safety.js') }}" defer></script>
