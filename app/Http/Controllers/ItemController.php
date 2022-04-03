<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class ItemController extends Controller
{
    /**
     * Fetch items to test read speed

     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        $time_start = microtime(true);

        $items = DB::table('items')->orderByDesc('created_at')->limit(5)->get();

        $time_end = microtime(true);

        // ... convert to ms
        $time = ($time_end - $time_start) * 1000;

        return view('pages.read', [
            'items' => $items,
            'time' => $time
        ]);
    }

    /**
     * Create an item to test write speed

     * @return \Illuminate\View\View
     */
    public function store(Request $request)
    {
        $time_start = microtime(true);

        $name = Str::random(10);

        // not using a model here so have to provide the times (handy to order by time to debug)
        DB::table('items')->insert([
            'name' => $name,
            'created_at' => \Carbon\Carbon::now(),
            'updated_at' => \Carbon\Carbon::now()
        ]);

        $time_end = microtime(true);

        // ... convert to ms
        $time = ($time_end - $time_start) * 1000;

        return view('pages.write', [
            'name' => $name,
            'time' => $time
        ]);
    }
}
