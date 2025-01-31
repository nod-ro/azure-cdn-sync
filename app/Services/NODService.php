<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class NODService
{
    public function getFullFeedV2($env)
    {
        $endpoints = [
            "sbx" => "http://api.b2b-sbx.nod.ro/",
            "prod" => "http://api.b2b.nod.ro/"
        ];

        $endpoint = $endpoints[$env] ?? null;

        if (!$endpoint) {
            throw new \Exception("Invalid environment specified: $env");
        }

        $query_string = 'v2/products/feed?columns[]=pictures&format=json';
        $url = $endpoint . $query_string;

        $nod_user = env("NOD_USER_" . strtoupper($env));
        $nod_password = env("NOD_PASSWORD_" . strtoupper($env));
        $date = gmdate('r'); // Matches the API example format

        if (!$nod_user || !$nod_password) {
            throw new \Exception("Missing API credentials for environment: $env");
        }

        // Generate the correct signature string
        $signature_string = "GET" . preg_replace('/^(\/+\/)/', '', $query_string) . "/" . $nod_user . $date;
        $signature = base64_encode(hash_hmac('sha1', $signature_string, $nod_password, true));

        $headers = [
            'X-NodWS-Date' => $date,
            'X-NodWS-User' => $nod_user,
            'X-NodWS-Auth' => $signature,
            'X-NodWS-Accept' => 'application/json',
            'X-NodWS-Navigation' => '1',
        ];

        $response = Http::withHeaders($headers)->get($url);

        return $response->json();
    }

}
