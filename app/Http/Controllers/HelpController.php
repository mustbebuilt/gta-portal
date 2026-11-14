<?php

namespace App\Http\Controllers;

use App\Support\HelpCatalogue;
use App\Support\HelpNarration;
use Illuminate\Http\Request;

class HelpController extends Controller
{
    public function index(Request $r)
    {
        $d = $r->validate(['q' => ['nullable', 'string', 'max:120'], 'category' => ['nullable', 'string', 'max:80'], 'guide' => ['nullable', 'string', 'regex:/^[a-z][a-z0-9-]{0,79}$/D']]);
        $all = HelpCatalogue::visible($r->user());
        $categories = array_values(array_unique(array_column($all, 'category')));
        sort($categories);
        $q = trim($d['q'] ?? '');
        $category = $d['category'] ?? '';
        $selected = $d['guide'] ?? null;
        if ($selected) {
            abort_unless(isset($all[$selected]), 404);
        }
        if ($category !== '' && ! in_array($category, $categories, true)) {
            abort(404);
        }
        $guides = $selected ? [$selected => $all[$selected]] : array_filter($all, fn ($g) => ($category === '' || $g['category'] === $category) && ($q === '' || mb_stripos($g['title'].' '.$g['body'].' '.$g['category'], $q) !== false));

        return response()->view('help.index', ['guides' => $guides, 'categories' => $categories, 'q' => $q, 'category' => $category, 'selected' => $selected, 'recordings' => HelpNarration::published(array_keys($guides))])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
