<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function getCsrfToken()
    {
        $response = $this->get('/sanctum/csrf-cookie');
        return $response->headers->getCookies();
    }

    protected function apiGet($url, $params = [])
    {
        $query = http_build_query($params);
        $fullUrl = $query ? "{$url}?{$query}" : $url;

        return $this->withCookies($this->getCsrfToken())
            ->getJson($fullUrl);
    }


    protected function apiPost($url, $data = [])
    {
        return $this->withCookies($this->getCsrfToken())
            ->postJson($url, $data);
    }

    protected function apiPut($url, $data = [])
    {
        return $this->withCookies($this->getCsrfToken())
            ->putJson($url, $data);
    }

    protected function apiPatch($url, $data = [])
    {
        return $this->withCookies($this->getCsrfToken())
            ->patchJson($url, $data);
    }

    protected function apiDelete($url)
    {
        return $this->withCookies($this->getCsrfToken())
            ->deleteJson($url);
    }

    protected function seedSegmentations()
    {
        DB::table('segmentations')->insert([
            ['id' => 1, 'name' => 'UMKM Toko', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'UMKM Kuliner', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'UMKM Jasa', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    protected function seedRoles()
    {
        DB::table('roles')->insert([
            ['id' => 1, 'name' => 'admin', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'umkm-owner', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'customer', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
