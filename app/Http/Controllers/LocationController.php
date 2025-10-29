<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Models\Province;
use App\Models\City;   // kabupaten/kota
use App\Models\District;  // kecamatan
use App\Models\Village;   // kelurahan/desa

class LocationController
{
    // GET /api/locations/provinces
    public function provinces(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $cacheKey = 'provinces:' . md5($q);

        $data = Cache::remember($cacheKey, 86400, function () use ($q) {
            $query = Province::query()->select('id', 'name')->orderBy('name');
            if ($q !== '') {
                $query->where('name', 'like', '%' . $q . '%');
            }
            return $query->get();
        });

        return response()->json($data);
    }

    // GET /api/locations/cities/{provinceId}
    // Note: "cities" di sini = regencies (kabupaten/kota)
    public function cities(Request $request, $provinceId)
    {
        $q = trim((string) $request->query('q', ''));
        $cacheKey = "cities:{$provinceId}:" . md5($q);

        $data = Cache::remember($cacheKey, 86400, function () use ($provinceId, $q) {
            $query = City::query()
                ->select('id', 'name', 'province_id')
                ->where('province_id', $provinceId)
                ->orderBy('name');
            if ($q !== '') {
                $query->where('name', 'like', '%' . $q . '%');
            }
            return $query->get();
        });

        return response()->json($data);
    }

    // GET /api/locations/districts/{cityId}
    public function districts(Request $request, $cityId)
    {
        $q = trim((string) $request->query('q', ''));
        $cacheKey = "districts:{$cityId}:" . md5($q);

        $data = Cache::remember($cacheKey, 86400, function () use ($cityId, $q) {
            $query = District::query()
                ->select('id', 'name', 'city_id')
                ->where('city_id', $cityId)
                ->orderBy('name');
            if ($q !== '') {
                $query->where('name', 'like', '%' . $q . '%');
            }
            return $query->get();
        });

        return response()->json($data);
    }

    // GET /api/locations/villages/{districtId}
    public function villages(Request $request, $districtId)
    {
        $q = trim((string) $request->query('q', ''));
        $cacheKey = "villages:{$districtId}:" . md5($q);

        $data = Cache::remember($cacheKey, 86400, function () use ($districtId, $q) {
            $query = Village::query()
                ->select('id', 'name', 'district_id')
                ->where('district_id', $districtId)
                ->orderBy('name');
            if ($q !== '') {
                $query->where('name', 'like', '%' . $q . '%');
            }
            return $query->get();
        });

        return response()->json($data);
    }
}
