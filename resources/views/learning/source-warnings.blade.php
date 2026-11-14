@php($warningKey=\App\Support\TaskCatalogue::keyFor($apprentice))
@php($sourceAudit=json_decode(file_get_contents(resource_path('data/task-pack-qa2-audit.json')),true))
@foreach($sourceAudit['open_items'] as $sourceIssue)
@if(($warningKey==='paint' && $sourceIssue['id']==='C1') || ($warningKey==='autocare' && $sourceIssue['id']==='C2'))
<section class="notice"><h2>{{ $sourceIssue['topic'] }}</h2><p>{{ $sourceIssue['detail'] }}</p><p>Record the applicable controlled document/version and curriculum decision in the individual plan references. Task availability and provider operational approval do not resolve this source decision.</p></section>
@endif
@endforeach
