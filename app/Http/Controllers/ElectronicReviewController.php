<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\ElectronicReviewPacket as Packet;
use App\Models\EmployerApprenticeGrant as Grant;
use App\Models\ProgressReviewDraft as Review;
use App\Models\ReviewSigningAuthority as Authority;
use App\Models\SignedReviewRecord;
use App\Support\ElectronicReviews as E;
use App\Support\EmployerActions;
use App\Support\LearningAccess;
use App\Support\PrivateEvidence;
use App\Support\SignedReviews;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ElectronicReviewController extends Controller
{
    private function auth(Request $r): int
    {
        return (int) $r->session()->get('auth_version', 0);
    }

    private function page(string $v, array $data)
    {
        return response()->view('electronic-reviews.'.$v, $data)->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function index(Request $r)
    {
        $filter = $r->validate(['filter' => ['nullable', Rule::in(['open', 'all', 'mine', 'Draft', 'Released', 'All signed', 'Checked', 'Declined', 'Withdrawn'])]])['filter'] ?? 'open';
        $q = E::queue($r->user());
        if ($filter === 'open') {
            $q->whereIn('state', ['Draft', 'Released', 'All signed', 'Declined']);
        } elseif ($filter === 'mine') {
            $q->where('state', 'Released')->whereHas('signers', fn ($q) => $q->where('user_id', $r->user()->id)->where('state', 'Pending'));
        } elseif ($filter !== 'all') {
            $q->where('state', $filter);
        }
        $rows = $q->with('apprentice:id,reference,first_name,last_name')->orderByDesc('created_at')->orderByDesc('id')->get()->filter(fn ($p) => E::visible($r->user(), $p))->values();
        $page = Paginator::resolveCurrentPage();

        return $this->page('index', ['rows' => new LengthAwarePaginator($rows->forPage($page, 25), $rows->count(), 25, $page, ['path' => $r->url(), 'query' => $r->except('page')]), 'filter' => $filter]);
    }

    public function authorities(Request $r, Apprentice $apprentice)
    {
        abort_unless(LearningAccess::manager($r->user()), 403);

        return $this->page('authorities', ['apprentice' => $apprentice, 'grants' => Grant::where('apprentice_id', $apprentice->id)->get()->filter(fn ($g) => EmployerActions::valid($g, $apprentice)), 'authorities' => Authority::where('apprentice_id', $apprentice->id)->get()->keyBy('employer_apprentice_grant_id')]);
    }

    public function saveAuthority(Request $r, Apprentice $apprentice)
    {
        abort_unless(LearningAccess::manager($r->user()), 403);
        $d = $r->validate(['grant_id' => ['required', 'integer'], 'version' => ['required', 'integer', 'min:0'], 'authority_reference' => ['required', 'string', 'max:4000'], 'expires_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:'.today()->addYear()->toDateString()], 'authority_checked' => ['accepted']]);
        E::saveAuthority($r->user(), $this->auth($r), $apprentice, Grant::findOrFail($d['grant_id']), $d);

        return back()->with('status', 'Named employer review-signing authority verified. Existing packets are not silently rebound.');
    }

    public function revokeAuthority(Request $r, Authority $authority)
    {
        $d = $r->validate(['version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000']]);
        E::revokeAuthority($r->user(), $this->auth($r), $authority, $d['version'], $d['reason']);

        return back()->with('status', 'Signing authority revoked. Pending packets require reconciliation.');
    }

    public function prepareForm(Request $r, Review $review)
    {
        $a = Apprentice::findOrFail($review->apprentice_id);
        abort_unless(E::staff($r->user(), $a), 403);

        $activePacket = Packet::where('active_review_id', $review->id)->first();
        if ($activePacket) {
            abort_unless($activePacket->apprentice_id === $a->id, 409);

            return to_route('electronic-reviews.show', $activePacket);
        }

        $hasSignedCopy = SignedReviewRecord::where('progress_review_draft_id', $review->id)
            ->whereNull('withdrawn_at')->exists();
        $authorities = $review->state === 'Ready for signatures' && ! $hasSignedCopy
            ? Authority::where('apprentice_id', $a->id)->get()
                ->filter(fn ($authority) => E::authorityValid($authority, $a))
            : collect();

        return $this->page('prepare', ['review' => $review, 'apprentice' => $a, 'authorities' => $authorities, 'hasSignedCopy' => $hasSignedCopy]);
    }

    public function prepare(Request $r, Review $review)
    {
        abort_unless(E::staff($r->user(), Apprentice::findOrFail($review->apprentice_id)), 403);
        $d = $r->validate(['version' => ['required', 'integer', 'min:1'], 'authority_id' => ['required', 'uuid'], 'expires_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:'.today()->addDays(30)->toDateString()], 'action_ids' => ['nullable', 'array', 'max:100'], 'action_ids.*' => ['required', 'integer', 'distinct']]);
        $p = E::prepare($r->user(), $this->auth($r), $review, $d);

        return to_route('electronic-reviews.show', $p)->with('status', 'Private participant record prepared. Check every shared field before releasing it to the named participants.');
    }

    public function show(Request $r, Packet $packet)
    {
        abort_unless(E::visible($r->user(), $packet), 403);
        abort_unless(E::intact($packet), 409, 'The participant record needs checking by GTA.');
        $a = Apprentice::findOrFail($packet->apprentice_id);
        $staff = E::staff($r->user(), $a);
        $signers = $packet->signers()->orderBy('role')->get();
        abort_unless($signers->count() === 3 && $signers->every(fn ($s) => E::signerIntact($s, $packet)), 409, 'The participant signing history needs checking by GTA.');
        $events = collect();
        if ($staff) {
            $events = DB::table('electronic_review_events as e')->join('users as u', 'u.id', '=', 'e.actor_id')->where('packet_id', $packet->id)->orderBy('e.id')->select('e.event', 'e.created_at', 'e.record_version', 'e.private_note', 'u.name as actor_name')->get();
            foreach ($events as $event) {
                try {
                    $event->private_note = $event->private_note === null ? null : decrypt($event->private_note);
                } catch (\Throwable) {
                    abort(409, 'The private event history needs checking by GTA.');
                }
            }
        }

        return $this->page('show', ['packet' => $packet, 'document' => $packet->snapshot['document'], 'signers' => $signers, 'own' => $signers->firstWhere('user_id', $r->user()->id), 'staff' => $staff, 'manager' => LearningAccess::manager($r->user()), 'current' => in_array($packet->state, ['Draft', 'Released', 'All signed'], true) ? E::current($packet) : null, 'events' => $events]);
    }

    public function release(Request $r, Packet $packet)
    {
        $d = $r->validate(['version' => ['required', 'integer', 'min:1'], 'sharing_checked' => ['accepted']]);
        E::release($r->user(), $this->auth($r), $packet, $d['version']);

        return back()->with('status', 'The exact participant record is released in the three named accounts. No email or text was sent.');
    }

    public function respond(Request $r, Packet $packet)
    {
        $d = $r->validate(['version' => ['required', 'integer', 'min:1'], 'document_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'], 'typed_name' => ['required', 'string', 'max:255'], 'current_password' => ['required', 'string', 'max:1000'], 'confirmed' => ['accepted'], 'decision' => ['required', Rule::in(['Signed', 'Declined'])], 'reason' => ['nullable', 'required_if:decision,Declined', 'string', 'max:2000']]);
        E::respond($r->user(), $this->auth($r), $packet, $d);

        return back()->with('status', 'Your decision is recorded against this exact participant review record. A staff check is still required before it becomes a checked review.');
    }

    public function confirm(Request $r, Packet $packet)
    {
        $d = $r->validate(['version' => ['required', 'integer', 'min:1'], 'check_reference' => ['required', 'string', 'max:4000'], 'content_checked' => ['accepted'], 'identity_checked' => ['accepted'], 'signatures_checked' => ['accepted']]);
        E::confirm($r->user(), $this->auth($r), $packet, $d['version'], $d['check_reference']);

        return back()->with('status', 'Electronic review independently checked. The tracked next review is seventy days after the actual meeting date.');
    }

    public function withdraw(Request $r, Packet $packet)
    {
        $d = $r->validate(['version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000']]);
        E::withdraw($r->user(), $this->auth($r), $packet, $d['version'], $d['reason']);

        return back()->with('status', 'Participant record withdrawn; decisions and evidence retained. Reopen the original review separately if corrections are needed.');
    }

    public function download(Request $r, Packet $packet)
    {
        abort_unless(E::visible($r->user(), $packet), 403);
        abort_unless(E::completedIntact($packet),409,'A checked intact record is required.');
        $record = SignedReviewRecord::findOrFail($packet->signed_review_record_id);
        abort_unless(SignedReviews::intact($record) && PrivateEvidence::matchesHash($record->file),409,'This signed review evidence needs checking.');
        $file = $record->file;
        $file['name'] = ($packet->state === 'Withdrawn' ? 'Withdrawn-original-' : 'GTA-checked-').'electronic-review-'.$packet->id.'.pdf';

        return PrivateEvidence::download($file);
    }
}
