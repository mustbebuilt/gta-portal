<?php

namespace App\Http\Controllers;

use App\Support\ApprenticeList;
use App\Support\ProgressDashboard66 as Dash;
use App\Support\ProgressReturn68;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ProgressDashboard66Controller extends Controller
{
    private function report(Request $r, ?string $role = null): array
    {
        return $role === null ? Dash::live($r) : Dash::demo($r, $role);
    }

    private function show(Request $r, ?string $role = null)
    {
        try {
            $report = ProgressReturn68::decorate($r, $this->report($r, $role));
            if ($role === null && in_array($r->user()->role, ['admin', 'ceo', 'tutor', 'apprentice'], true)) {
                $report['groups'] = Dash::groups(Dash::actor($r));
                $report['apprentice_list_url'] = ApprenticeList::returnUrl($r);
                $selected = $report['selected'];
                if ($selected && $r->boolean('from_record') && (string) $selected['id'] === $report['filters']['apprentice']) {
                    $listOrigin = [...ApprenticeList::origin($r), ...\App\Support\Workday83::returnParameters($r)];
                    $report['record_origin'] = ['from_record'=>1, ...$listOrigin];
                    $report['apprentice_record_url'] = route('apprentices.show', ['apprentice'=>$selected['id'], ...$listOrigin]);
                }
            }

return response()->view('progress66.index', compact('report'))->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
        } catch (\JsonException|QueryException $e) {
            return response()->view('progress66.state', ['message' => 'The reporting source is unavailable or needs checking. No totals have been substituted. Try again or contact GTA.', 'demo' => $role !== null], 503)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
        } catch (HttpExceptionInterface $e) {
            if (! in_array($e->getStatusCode(), [409, 422, 503], true)) {
                throw $e;
            }

return response()->view('progress66.state', ['message' => $e->getMessage(), 'demo' => $role !== null, 'groups' => $role === null ? Dash::groups(Dash::actor($r)) : []], $e->getStatusCode())->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
        }
    }

    public function index(Request $r)
    {
        return $this->show($r);
    }

    public function demo(Request $r, string $role)
    {
        return $this->show($r, $role);
    }

    public function export(Request $r, string $format)
    {
        return $this->download($r, $format);
    }

    public function demoExport(Request $r, string $role, string $format)
    {
        return $this->download($r, $format, $role);
    }

    private function download(Request $r, string $format, ?string $role = null)
    {
        $r->validate(['section' => 'nullable|in:progress,journey']);
        $report = $this->report($r, $role);
        if ($r->query('section') === 'journey') {
            abort_unless(count($report['details']) === 1 && ! empty($report['details'][0]['roadmap']), 404);
            $person = $report['details'][0];
            $label = $report['demo'] ? 'Demonstration — fictional data' : 'GTA authorised journey report';
            if ($format === 'json') {
                $body = json_encode(['label' => $label, 'captured_at' => $report['captured_at'], 'reference' => $person['reference'], 'roadmap' => $person['roadmap']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $type = 'application/json';
            } else {
                $stream = fopen('php://temp', 'w+');
                $safeCell = fn ($v) => is_string($v) && preg_match('/^(?:\s*[=+@\-]|[\t\r\n])/u', $v) ? "'".$v : $v;
                fputcsv($stream, array_map($safeCell, [$label, $person['reference'], 'Captured '.$report['captured_at']]), ',', '"', '');
                fputcsv($stream, ['Date', 'Event', 'Category', 'Recorded or planned'], ',', '"', '');
                foreach ($person['roadmap']['events'] as $event) {
                    fputcsv($stream, array_map($safeCell, [$event['date'], $event['title'], $event['category'], $event['status']]), ',', '"', '');
                }
                rewind($stream);
                $body = stream_get_contents($stream);
                fclose($stream);
                $type = 'text/csv; charset=UTF-8';
            }

            return response($body)->header('Content-Type', $type)->header('Content-Disposition', 'attachment; filename="GTA-'.($report['demo'] ? 'Demonstration-' : '').'Journey-67.'.$format.'"')->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Content-Type-Options', 'nosniff');
        }
        $safe = $report;
        unset($safe['selected']);
        $selectedIds = array_column($report['details'], 'id');
        $safe['rows'] = $report['details'];
        unset($safe['details']);
        $safe['actions'] = array_values(array_filter($safe['actions'], fn ($a) => in_array($a['person_id'], $selectedIds, true)));
        $safe['export_scope'] = 'Headlines/charts cover the filtered cohort; rows cover the chosen record metric. Source fields and internal histories are never exported.';
        $label = $report['demo'] ? 'Demonstration — fictional data' : 'GTA authorised progress report';
        if ($format === 'json') {
            $body = json_encode($safe, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $type = 'application/json';
        } else {
            $stream = fopen('php://temp', 'w+');
            $cell = fn ($v) => is_string($v) && preg_match('/^(?:\s*[=+@\-]|[\t\r\n])/u', $v) ? "'".$v : $v;
            fputcsv($stream, array_map($cell, [$label, 'Captured '.$report['captured_at'], 'Attendance '.$report['period'], 'Current source '.$report['source_as_of']]), ',', '"', '');
            fputcsv($stream, ['Reference', 'Apprentice', 'Programme', 'Approved tasks', 'Allocated tasks', 'Awaiting marking', 'Expected tasks due', 'Attendance %', 'Attended records', 'Expected records', 'Unmarked records', 'Recorded OTJ hours', 'Approved target hours', 'Due-plan hours', 'Review due reference', 'Forecast'], ',', '"', '');
            foreach ($report['details'] as $a) {
                $v = [$a['reference'], $a['name'], $a['programme'], $a['approved'], $a['tasks'], $a['task_status']['Awaiting marking'] ?? null, $a['expected_tasks'], $a['attendance']['percent'], $a['attendance']['attended'], $a['attendance']['expected'], $a['attendance']['unmarked'], $a['otj']['recorded_hours'], $a['otj']['target_hours'], $a['otj']['planned_due_hours'], $a['review_due'], $a['forecast']];
                fputcsv($stream, array_map(fn ($x) => $cell($x ?? 'Not available'), $v), ',', '"', '');
            }
            rewind($stream);
            $body = stream_get_contents($stream);
            fclose($stream);
            $type = 'text/csv; charset=UTF-8';
        }

        return response($body)->header('Content-Type',$type)->header('Content-Disposition','attachment; filename="GTA-'.($report['demo'] ? 'Demonstration-' : '').'Progress-66.'.$format.'"')->header('Cache-Control','private, no-store')->header('Referrer-Policy','no-referrer')->header('X-Content-Type-Options','nosniff');
    }
}
