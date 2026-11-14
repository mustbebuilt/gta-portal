<div data-help-recording class="help-recording">
<button type="button" data-play-recording data-recording-url="{{ $audioUrl }}" aria-pressed="false" hidden>{{ $audioLabel??'Play GTA Yorkshire recording' }}</button>
<button type="button" data-stop-recording hidden>Stop recording</button>
<audio data-help-audio controls preload="none" hidden aria-label="{{ $audioTitle }}"></audio>
<p class="muted" data-recording-status role="status" aria-live="polite">{{ $audioNote??'Approved recording available. Nothing plays automatically.' }}</p>
<noscript><p>Read the transcript below. The optional recording player needs JavaScript.</p></noscript>
</div>
