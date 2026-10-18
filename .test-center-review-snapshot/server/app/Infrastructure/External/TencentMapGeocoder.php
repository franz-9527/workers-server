<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\External;

/**
 * V2.5.0 平台承接单地址解析。
 *
 * 文字地址是业务事实，地图坐标只作为定位辅助：
 * - 有地图选点：保留人工填写地址，仅用坐标做距离/地图定位；可反查地图地址作为辅助信息。
 * - 无地图选点：使用完整文字地址正向解析坐标；解析失败时要求管理员补地图点，避免生成无法参与距离规则的订单。
 */
final class TencentMapGeocoder
{
    private string $key;

    public function __construct(string $key)
    {
        $this->key = trim($key);
    }

    /** @return array{lat:float,lng:float,map_address:string,map_name:string}|null */
    public function geocode(string $address): ?array
    {
        $address = trim($address);
        if ($address === '' || $this->key === '') return null;
        $payload = $this->request('https://apis.map.qq.com/ws/geocoder/v1/?address=' . rawurlencode($address)
            . '&key=' . rawurlencode($this->key));
        if (!$payload || (int)($payload['status'] ?? -1) !== 0) return null;
        $result = is_array($payload['result'] ?? null) ? $payload['result'] : [];
        $location = is_array($result['location'] ?? null) ? $result['location'] : [];
        $lat = isset($location['lat']) && is_numeric($location['lat']) ? (float)$location['lat'] : null;
        $lng = isset($location['lng']) && is_numeric($location['lng']) ? (float)$location['lng'] : null;
        if (!$this->validCoordinate($lat, $lng)) return null;
        return [
            'lat'=>(float)$lat,
            'lng'=>(float)$lng,
            'map_address'=>mb_substr(trim((string)($result['title'] ?? $address)), 0, 255),
            'map_name'=>mb_substr(trim((string)($result['address_components']['street_number'] ?? '')), 0, 160),
        ];
    }

    /** @return array{lat:float,lng:float,map_address:string,map_name:string}|null */
    public function reverseGeocode(float $lat, float $lng): ?array
    {
        if ($this->key === '' || !$this->validCoordinate($lat, $lng)) return null;
        $payload = $this->request('https://apis.map.qq.com/ws/geocoder/v1/?location=' . rawurlencode($lat . ',' . $lng)
            . '&key=' . rawurlencode($this->key) . '&get_poi=1');
        if (!$payload || (int)($payload['status'] ?? -1) !== 0) return null;
        $result = is_array($payload['result'] ?? null) ? $payload['result'] : [];
        $formatted = trim((string)($result['address'] ?? ''));
        $poiName = '';
        if (is_array($result['pois'] ?? null) && isset($result['pois'][0]) && is_array($result['pois'][0])) {
            $poiName = trim((string)($result['pois'][0]['title'] ?? ''));
        }
        return [
            'lat'=>$lat,
            'lng'=>$lng,
            'map_address'=>mb_substr($formatted, 0, 255),
            'map_name'=>mb_substr($poiName, 0, 160),
        ];
    }

    /** @return array<string,mixed>|null */
    private function request(string $url): ?array
    {
        $body = null;
        if (function_exists('curl_init')) {
            $handle = curl_init($url);
            if ($handle !== false) {
                curl_setopt_array($handle, [
                    CURLOPT_RETURNTRANSFER=>true,
                    CURLOPT_CONNECTTIMEOUT=>2,
                    CURLOPT_TIMEOUT=>4,
                    CURLOPT_FOLLOWLOCATION=>false,
                    CURLOPT_SSL_VERIFYPEER=>true,
                    CURLOPT_HTTPHEADER=>['Accept: application/json'],
                ]);
                $response = curl_exec($handle);
                $code = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
                curl_close($handle);
                if (is_string($response) && $code >= 200 && $code < 300) $body = $response;
            }
        }
        if ($body === null && ini_get('allow_url_fopen')) {
            $context = stream_context_create(['http'=>['timeout'=>4,'ignore_errors'=>true,'header'=>"Accept: application/json\r\n"]]);
            $response = @file_get_contents($url, false, $context);
            if (is_string($response) && $response !== '') $body = $response;
        }
        if (!is_string($body) || $body === '') return null;
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @param float|null $lat @param float|null $lng */
    private function validCoordinate($lat, $lng): bool
    {
        return $lat !== null && $lng !== null
            && $lat >= -90.0 && $lat <= 90.0
            && $lng >= -180.0 && $lng <= 180.0;
    }
}
