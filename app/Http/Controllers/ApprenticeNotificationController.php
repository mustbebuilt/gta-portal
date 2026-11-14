<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\ApprenticeAlertDelivery as Delivery;
use App\Models\User;
use App\Support\ApprenticeExperience;
use App\Support\ApprenticeNotificationDelivery as Alerts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ApprenticeNotificationController extends Controller
{
    private function admin(Request $r): void
    {
        abort_unless(Alerts::admin($r->user()), 403);
    }

    public function index(Request $r)
    {
        $this->admin($r);
        $data = $r->validate(['state' => ['nullable', Rule::in(['all', ...Alerts::STATES])], 'channel' => ['nullable', Rule::in(['all', 'email', 'sms'])], 'page' => ['nullable', 'integer', 'min:1', 'max:1000000']]);
        $state = $data['state'] ?? 'all';
        $channel = $data['channel'] ?? 'all';
        $counts = Delivery::select('state', DB::raw('COUNT(*) as total'))->groupBy('state')->pluck('total', 'state')->all();
        $q = Delivery::when($state !== 'all', fn ($q) => $q->where('state', $state))->when($channel !== 'all', fn ($q) => $q->where('channel', $channel))->orderByDesc('created_at')->orderByDesc('id');

        $rows = $q->paginate(20)->withQueryString();
        $people = Apprentice::whereIn('id', $rows->getCollection()->pluck('apprentice_id'))->get(['id', 'reference', 'first_name', 'last_name'])->keyBy('id');

        return response()->view('apprentice-notifications.management', ['control' => Alerts::control(), 'people' => $people, 'rows' => $rows, 'counts' => $counts, 'configuration' => Alerts::inspect(), 'state' => $state, 'channel' => $channel, 'preview' => $r->boolean('preview') ? Alerts::preview() : null])->header('Cache-Control', 'no-store, private');
    }

    public function pause(Request $r)
    {
        $this->admin($r);
        $data = $r->validate(['version' => ['required', 'integer'], 'paused' => ['required', 'boolean'], 'note' => ['required', 'string', 'max:2000']]);
        Alerts::pause($r->user(), (int) $r->session()->get('auth_version'), (int) $data['version'], (bool) $data['paused'], $data['note']);

        return back()->with('status', 'Sending control saved. In-flight attempts cannot be recalled.');
    }

    public function prepare(Request $r)
    {
        $this->admin($r);
        $r->validate(['confirmed' => ['accepted']]);
        $counts = Alerts::prepare(250, $r->user(), (int) $r->session()->get('auth_version'));

        return to_route('apprentice-notifications.index')->with('status', 'Preparation finished: '.json_encode($counts).'. Nothing was sent by this action.');
    }

    public function dispatch(Request $r, Delivery $delivery)
    {
        $this->admin($r);
        $r->validate(['confirmed' => ['accepted']]);
        $result = Alerts::dispatch($delivery, $r->user(), (int) $r->session()->get('auth_version'));

        return back()->with('status', 'Reminder attempt: '.$result['state'].' — '.$result['reason'].'. Accepted is not proof of delivery.');
    }

    public function cancel(Request $r, Delivery $delivery)
    {
        $this->admin($r);
        $data = $r->validate(['note' => ['required', 'string', 'max:2000']]);
        Alerts::cancel($r->user(), (int) $r->session()->get('auth_version'), $delivery, $data['note']);

        return back()->with('status', 'Unreserved reminder cancelled.');
    }

    public function reconcile(Request $r, Delivery $delivery)
    {
        $this->admin($r);
        $data = $r->validate(['note' => ['required', 'string', 'max:2000']]);
        Alerts::reconcile($r->user(), (int) $r->session()->get('auth_version'), $delivery, $data['note']);

        return back()->with('status', 'Reconciliation note saved. The attempt state and no-retry rule remain.');
    }

    public function inbox(Request $r)
    {
        $a = ApprenticeExperience::profile($r->user());
        abort_unless($a, 409);
        $records = Delivery::where('recipient_id', $r->user()->id)->where('apprentice_id', $a->id)->whereDate('cycle_date', '>=', today()->subDays(60))->orderByDesc('cycle_date')->orderByDesc('updated_at')->limit(122)->get();
        $groups = $records->groupBy(fn ($d) => $d->cycle_date->toDateString());

        return response()->view('apprentice-notifications.inbox', ['apprentice' => $a, 'groups' => $groups])->header('Cache-Control', 'no-store, private');
    }

    public function seen(Request $r, Delivery $delivery)
    {
        DB::transaction(function () use ($r, $delivery): void {
            $a = ApprenticeExperience::profile($r->user(), true);
            abort_unless($a, 409);
            $u = User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($u->active && $u->role === 'apprentice' && $u->auth_version === $r->session()->get('auth_version') && $delivery->recipient_id === $u->id && $delivery->apprentice_id === $a->id, 403);
            foreach (Delivery::where('recipient_id', $u->id)->where('apprentice_id', $a->id)->whereDate('cycle_date', $delivery->cycle_date)->lockForUpdate()->get() as $d) {
                if (! $d->seen_at) {
                    $d->seen_at = now();
                    $d->save();
                }
            }
        });

        return to_route('apprentice-reminders.index')->with('status', 'This saved digest is marked read. Tasks, attendance, reviews and delivery settings are unchanged.');
    }
}
