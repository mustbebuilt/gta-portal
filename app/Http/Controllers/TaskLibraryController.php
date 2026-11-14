<?php

namespace App\Http\Controllers;

use App\Support\CurriculumSources;
use App\Support\TaskCatalogue;
use App\Support\TaskLibrary;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaskLibraryController extends Controller
{
    private function allowed(Request $request): void
    {
        abort_unless(TaskLibrary::allowed($request->user()), 403);
    }

    public function index(Request $request)
    {
        $this->allowed($request);
        $data = $request->validate(['programme' => ['sometimes', 'required', Rule::in(array_keys(TaskCatalogue::PROGRAMMES))],
            'block' => ['nullable', 'integer', 'between:1,10']]);
        $key = $data['programme'] ?? 'autocare';
        $release = CurriculumSources::release($key);
        $tasks = collect($release['technical_ok'] ? $release['tasks'] : [])->when(filled($data['block'] ?? null), fn ($rows) => $rows->where('block', (int) $data['block']));
        return response()->view('learning.library', ['key' => $key, 'catalogue' => TaskCatalogue::PROGRAMMES[$key],
            'tasks' => $tasks, 'release' => CurriculumSources::safeRelease($release)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request, string $programme, string $reference)
    {
        $this->allowed($request);
        return response()->view('learning.library-task', TaskLibrary::entry($programme, $reference))
            ->header('Cache-Control', 'no-store, private');
    }

    public function pdf(Request $request, string $programme, string $reference)
    {
        $this->allowed($request);
        $entry = TaskLibrary::entry($programme, $reference);
        $name = $entry['template']['sourcePdfFilename'];
        // Entry checks all source locations/hashes; recheck the exact file immediately before download.
        $path = resource_path('task-pdfs/'.$name);
        abort_unless(basename($name) === $name && ! is_link($path) && is_file($path)
            && hash_equals($entry['template']['sourceTemplateHash'], hash_file('sha256', $path)), 409);
        return response()->download($path, $name, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'no-store, private']);
    }
}
