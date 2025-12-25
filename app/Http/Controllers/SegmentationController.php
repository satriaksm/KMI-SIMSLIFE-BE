<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Segmentation;

class SegmentationController
{
    public function index(Request $request)
    {
        $items = Segmentation::query()
            ->select(['id', 'name'])
            ->orderByDesc('id')
            ->limit(1000)
            ->get();

        return response()->json($items);
    }
}
