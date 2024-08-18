<?php

namespace App\Http\Controllers\Dashboard\Logs;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class LogController extends Controller
{public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(['permission:View Logs']);
    }
    public function index(){
        $file = storage_path('logs/laravel.log');

        // Check if the log file exists
        if (File::exists($file)) {
            // Get the contents of the log file
            $logs = File::get($file);
            // Split the contents by newline to create an array of log entries
            $logs = explode(PHP_EOL, $logs);
        } else {
            $logs = [];
        }

        return view('dashboard.logs.logs', ['logs' => $logs]);
    }
}
