<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\JournalAdmin;
use Illuminate\Contracts\View\View;

class JournalController extends Controller
{
    public function index(): View
    {
        return view('admin.journal.index', [
            'entrees' => JournalAdmin::with('admin')->latest('id')->paginate(50),
        ]);
    }
}
