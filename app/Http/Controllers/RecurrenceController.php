<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class RecurrenceController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Recurrences/Index');
    }
}
