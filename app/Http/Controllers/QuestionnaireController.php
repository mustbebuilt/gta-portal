<?php

namespace App\Http\Controllers;

use App\Support\QuestionnaireLinks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class QuestionnaireController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless(QuestionnaireLinks::canView($request->user()), 403);

        return response()->view('surveys.questionnaires', ['cards' => QuestionnaireLinks::cards()])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function configuration(): Response
    {
        Gate::authorize('manage-directories');

        return response()->view('surveys.configuration', ['inspection' => QuestionnaireLinks::inspect()])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
