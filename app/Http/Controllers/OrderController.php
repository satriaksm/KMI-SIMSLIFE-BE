<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index()
    {
        return Order::with('jasa')->latest()->get();
    }

    public function show($id)
    {
        $order = Order::with('jasa')->findOrFail($id);
        return response()->json($order);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'jasa_id' => 'required|exists:jasas,id',
            'nama' => 'required|string|max:255',
            'tel' => 'required|string|max:20',
            'alamat' => 'required|string',
            'tanggal' => 'required|date',
            'waktu' => 'required|string',
            'metode_pembayaran' => 'required|in:COD,QRIS',
            'promo_code' => 'nullable|string',
            'total' => 'required|integer',
            'status' => 'in:pending,proses,selesai,batal'
        ]);

        $order = Order::create($data);
        return response()->json($order, 201);
    }
}
