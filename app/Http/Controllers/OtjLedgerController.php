<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Support\LearningAccess;
use App\Support\OtjPosition;
use App\Support\PortalPdf;
use Illuminate\Http\Request;

class OtjLedgerController extends Controller
{
    public function show(Request $r, Apprentice $apprentice)
    {
        abort_unless(LearningAccess::training($r->user(),$apprentice),403);

        return view('attendance.otj-ledger',['apprentice'=>$apprentice,'importedRecords'=>\App\Models\HistoricalOtjRecord::where('apprentice_id',$apprentice->id)->get(),'manage'=>LearningAccess::manager($r->user()),...OtjPosition::forApprentice($apprentice)]);
    }

    public function pdf(Request $r, Apprentice $apprentice)
    {
        abort_unless(LearningAccess::training($r->user(),$apprentice),403);

        return PortalPdf::response('pdf.otj-ledger',['apprentice'=>$apprentice,...OtjPosition::forApprentice($apprentice)],'GTA-Recorded-OTJ-'.$apprentice->reference);
    }
}
