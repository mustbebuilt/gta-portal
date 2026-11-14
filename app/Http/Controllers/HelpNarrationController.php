<?php

namespace App\Http\Controllers;

use App\Models\HelpNarrationRecord as Record;
use App\Support\HelpCatalogue;
use App\Support\HelpNarration;
use App\Support\LearningAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HelpNarrationController extends Controller
{
    private function manager(Request $r): void
    {
        abort_unless(LearningAccess::manager($r->user()), 403);
    }

    private function auth(Request $r): int
    {
        return (int) $r->session()->get('auth_version', 0);
    }

    public function index(Request $r)
    {
        $this->manager($r);
        $d = $r->validate(['guide' => ['nullable', 'string', 'max:80']]);
        $catalogue = HelpCatalogue::all();
        $id = $d['guide'] ?? null;
        if ($id) {
            abort_unless(isset($catalogue[$id]), 404);
        }$q = Record::query();
        if ($id) {
            $q->where('guide_id', $id);
        }

return response()->view('help.narrations', ['catalogue' => $catalogue, 'selected' => $id, 'rows' => $q->orderByDesc('created_at')->orderByDesc('id')->paginate(20)->withQueryString(), 'inspection' => HelpNarration::inspect(true)])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function upload(Request $r)
    {
        $this->manager($r);
        $d = $r->validate(['guide_id' => ['required', 'string', 'max:80'], 'guide_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'], 'source_reference' => ['required', 'string', 'max:4000'], 'file' => ['required', 'file', 'extensions:mp3,wav', 'mimetypes:audio/mpeg,audio/wav,audio/x-wav,audio/wave,audio/vnd.wave', 'max:20480'], 'generic_content_checked' => ['accepted']]);
        HelpNarration::upload($r->user(), $this->auth($r), $d, $r->file('file'));

        return to_route('help-narrations.index', ['guide' => $d['guide_id']])->with('status', 'Recording uploaded privately. A different administrator or CEO must listen and approve it before learners can play it.');
    }

    public function change(Request $r, Record $record)
    {
        $this->manager($r);
        $d = $r->validate(['version' => ['required', 'integer', 'min:1'], 'action' => ['required', Rule::in(['approve', 'reject', 'withdraw'])], 'note' => ['required', 'string', 'max:4000'], 'yorkshire_listened' => ['required_if:action,approve', 'boolean'], 'transcript_checked' => ['required_if:action,approve', 'boolean'], 'permission_checked' => ['required_if:action,approve', 'boolean']]);
        HelpNarration::change($r->user(), $this->auth($r), $record, $d);

        return back()->with('status', 'Narration decision recorded. Withdrawn, stale or unapproved recordings are not offered to users.');
    }

    public function preview(Request $r, Record $record)
    {
        $this->manager($r);

        return HelpNarration::stream($record, $r);
    }

    public function audio(Request $r, string $guide, Record $record)
    {
        $g = HelpCatalogue::all()[$guide] ?? null;
        abort_unless($g && HelpCatalogue::allowed($r->user(), $g) && $record->guide_id === $guide, 404);
        abort_unless(HelpNarration::approved($record), 409, 'This recording is no longer current. Read the transcript.');

        return HelpNarration::stream($record,$r);
    }
}
