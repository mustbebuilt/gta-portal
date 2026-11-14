<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\LearningBlockControl;
use App\Support\LearningAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BlockControlController extends Controller
{
    public function save(Request $r, Apprentice $apprentice): RedirectResponse
    {
        abort_unless(LearningAccess::manager($r->user()), 403);
        $d = $r->validate(['version' => ['required', 'integer'], 'enabled' => ['required', 'boolean'], 'operation' => ['required', Rule::in(['settings', 'release', 'withdraw'])], 'block' => ['nullable', 'required_unless:operation,settings', 'integer', 'min:2', 'max:10'], 'reason' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($r, $apprentice, $d): void {
            Apprentice::whereKey($apprentice->id)->lockForUpdate()->firstOrFail();
            $c = LearningBlockControl::where('apprentice_id', $apprentice->id)->lockForUpdate()->first();
            if (($c?->version ?? 0) !== $r->integer('version')) {
                throw ValidationException::withMessages(['version' => 'Block controls changed. Reopen the task list.']);
            }
            $c ??= new LearningBlockControl(['apprentice_id' => $apprentice->id, 'exceptions' => [], 'history' => []]);
            $before = $c->only('enabled', 'exceptions');
            $e = $c->exceptions ?? [];
            if ($d['operation'] === 'release') {
                $e[(string) $d['block']] = ['reason' => $d['reason'], 'by' => $r->user()->id, 'at' => now()->toIso8601String()];
            } elseif ($d['operation'] === 'withdraw') {
                unset($e[(string) $d['block']]);
            }
            $c->enabled = $d['enabled'];
            $c->exceptions = $e;
            $h = $c->history ?? [];
            $h[] = ['event' => $d['operation'], 'before' => $before, 'block' => $d['block'] ?? null, 'reason' => $d['reason'], 'by' => $r->user()->id, 'at' => now()->toIso8601String()];
            $c->history = $h;
            if ($c->exists) {
                $c->version++;
            }$c->save();
            DB::table('learning_events')->insert(['apprentice_id' => $apprentice->id, 'user_id' => $r->user()->id, 'kind' => 'block_control', 'record_id' => $c->id, 'event' => 'block_control_changed', 'created_at' => now()]);
        });

        return back()->with('status', 'Block controls saved with a reason. No tasks, KSBs or programme completion were signed off.');
    }
}
