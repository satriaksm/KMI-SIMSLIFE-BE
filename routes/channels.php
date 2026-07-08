<?php

use App\Models\Merchant;
use App\Models\Order;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('orders.{orderId}', function ($user, $orderId) {
    $order = Order::query()->select('id', 'user_id', 'merchant_id')->find($orderId);
    if (!$order) {
        return false;
    }

    if ((int) $order->user_id === (int) $user->id) {
        return true;
    }

    if (!$order->merchant_id) {
        return false;
    }

    return Merchant::query()
        ->where('id', $order->merchant_id)
        ->where('user_id', $user->id)
        ->exists();
});

Broadcast::channel('users.{userId}.orders', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

Broadcast::channel('users.{userId}.community', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

Broadcast::channel('merchants.{merchantId}.orders', function ($user, $merchantId) {
    return Merchant::query()
        ->where('id', $merchantId)
        ->where('user_id', $user->id)
        ->exists();
});
