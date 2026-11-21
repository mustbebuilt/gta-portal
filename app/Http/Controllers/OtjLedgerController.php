<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\TrainingSession;
use App\Support\LearningAccess;
use App\Support\OtjPosition;
use App\Support\PortalPdf;
use App\Support\RegisterFollowup;
use Illuminate\Http\Request;

class OtjLedgerController extends Controller
{
    public function show(Request $r, Apprentice $apprentice)
    {
        abort_unless(LearningAccess::training($r->user(),$apprentice),403);

        $ledger = OtjPosition::forApprentice($apprentice);
        $staff = LearningAccess::manager($r->user()) || LearningAccess::tutor($r->user(), $apprentice);
        $needsAttention = $staff ? collect($ledger['rows'])->filter(static fn (array $row): bool => match ($row['kind']) {
            'session' => in_array($row['state'], ['Register not marked', 'OTJ not confirmed', 'Minutes need checking'], true),
            'extra' => $row['state'] === 'Awaiting approval / check',
            default => $row['data_check'] ?? false,
        })->values() : collect();
        $openable = $needsAttention->where('kind', 'session')->pluck('id');
        $registers = $openable->isEmpty() ? [] : TrainingSession::with('group')->whereKey($openable->all())->get()
            ->filter(fn (TrainingSession $s): bool => RegisterFollowup::roster($s)['shape'] && RegisterFollowup::canOpen($r->user(), $s))
            ->pluck('id')->all();

        return view('attendance.otj-ledger', [
            'apprentice' => $apprentice,
            'importedRecords' => \App\Models\HistoricalOtjRecord::where('apprentice_id', $apprentice->id)->get(),
            'manage' => LearningAccess::manager($r->user()),
            'needsAttention' => $needsAttention,
            'registers' => $registers,
            ...$ledger,
        ]);
    }

    public function pdf(Request $r, Apprentice $apprentice)
    {
        abort_unless(LearningAccess::training($r->user(),$apprentice),403);

        return PortalPdf::response('pdf.otj-ledger',['apprentice'=>$apprentice,...OtjPosition::forApprentice($apprentice)],'GTA-Recorded-OTJ-'.$apprentice->reference);
    }
}
