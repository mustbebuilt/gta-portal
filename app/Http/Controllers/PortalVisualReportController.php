<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\TutorGroup;
use App\Support\PortalPdf;
use App\Support\PortalVisualReport;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PortalVisualReportController extends Controller
{
    public function index(Request $request)
    {
        return $this->respond($request, false);
    }

    public function pdf(Request $request)
    {
        return $this->respond($request, true);
    }

    private function respond(Request $request, bool $pdf)
    {
        try {
            $report = PortalVisualReport::capture($request);
        } catch (ValidationException|HttpException $error) {
            if ($request->expectsJson() || ($error instanceof HttpException && $error->getStatusCode() !== 422)) {
                throw $error;
            }

            return $this->recover($request, $error instanceof ValidationException ? $error->validator->errors()->first() : $error->getMessage());
        }

        return $pdf
            ? PortalPdf::response('pdf.visual-report', compact('report'), 'GTA-Portal-Report-'.today()->toDateString())
            : response()->view('visual-reports.index', compact('report'))->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    private function recover(Request $request, string $message)
    {
        $people = Apprentice::visibleTo($request->user());
        $groups = TutorGroup::whereIn('id', (clone $people)->whereNotNull('tutor_group_id')->select('tutor_group_id'))
            ->orderBy('name')->get(['id', 'name']);
        $selectedApprentice = filter_var($request->query('apprentice'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($selectedApprentice && !(clone $people)->whereKey($selectedApprentice)->exists()) {
            $selectedApprentice = false;
        }

        return response()->view('visual-reports.recover', compact('message', 'groups', 'selectedApprentice'), 422)
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
