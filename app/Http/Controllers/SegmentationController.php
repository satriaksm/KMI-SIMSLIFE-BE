<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Segmentation; // pastikan model ada

class SegmentationController
{
    public function index(Request $request)
    {
        $items = Segmentation::query()
            ->orderByDesc('id')
            ->limit(1000)
            ->get();

        return response()->json($items);
    }
}
