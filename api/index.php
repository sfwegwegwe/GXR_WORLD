<?php

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

function create_guzzle_client() {
    return new Client([
        'timeout' => 10,
        'headers' => [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
        ]
    ]);
}

function fetch_and_parse_schedule() {
    try {
        $client = create_guzzle_client();
        $ist_timezone = new DateTimeZone('Asia/Kolkata');
        $now = new DateTime('now', $ist_timezone);
        $current_time = $now->getTimestamp();
        $start_of_day = (clone $now)->setTime(0, 0, 0)->getTimestamp();
        $end_of_day = null;
        
        $api_url = "https://gxr-cached.api.viewlift.com/graphql?extensions=" . urlencode('{"persistedQuery":{"version":1,"sha256Hash":"6c9b1f7ce2ea7748154db1d1ee7f9ec3b10d5832e454a88df8cea504d28c7594"}}') .
                   "&variables=" . urlencode('{"site":"gxr","limit":200,"offset":0,"startDate":' . $start_of_day . ',"endDate":' . ($end_of_day ?? 'null') . ',"sortBy":null,"sortOrder":null,"countryCode":"IN"}');
        
        try {
            $response = $client->get($api_url);
            $data = json_decode($response->getBody(), true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Error decoding JSON: " . json_last_error_msg());
            }
            
            $events = $data['data']['gameSchedule']['items'] ?? [];
            $parsed_data = [];
            
            foreach ($events as $event) {
                $start_date = $event['schedules'][0]['startDate'] ?? null;
                $end_date = $event['schedules'][0]['endDate'] ?? null;
                
                if ($start_date >= $start_of_day) {
                    $parsed_event = [
                        "title" => $event['title'] ?? null,
                        "image_url" => $event['gist']['imageGist']['r16x9'] ?? null,
                        "game_id" => $event['id'] ?? null,
                        "home_team" => $event['homeTeam']['shortName'] ?? null,
                        "away_team" => $event['awayTeam']['shortName'] ?? null,
                        "schedule_start" => $start_date,
                        "schedule_end" => $end_date,
                        "broadcaster" => $event['broadcaster'] ?? null,
                        "current_state" => $event['currentState'] ?? null
                    ];
                    
                    if ($current_time > $end_date) {
                        $parsed_event['current_state'] = "End";
                    }
                    if ($parsed_event['current_state'] === "default") {
                        $parsed_event['current_state'] = "Coming Soon";
                    }
                    
                    $parsed_data[] = $parsed_event;
                }
            }
            
            $last_refresh_time = $now->format('d-m-Y h:i:s A');
            
            usort($parsed_data, function ($a, $b) {
                $status_order = ["live" => 1, "Coming Soon" => 2, "End" => 3];
                $a_state = $a['current_state'] ?? "Coming Soon";
                $b_state = $b['current_state'] ?? "Coming Soon";
                if ($status_order[$a_state] !== $status_order[$b_state]) {
                    return $status_order[$a_state] <=> $status_order[$b_state];
                }
                return $a['schedule_start'] <=> $b['schedule_start'];
            });
            
            // Process live stream details for live events
            $parsed_data = fetch_live_stream_details($parsed_data);
            
            $final_data = [
                "last_refresh_time" => $last_refresh_time,
                "author" => "@Darshan_101005",
                "matches" => $parsed_data
            ];
            
            return $final_data;
            
        } catch (RequestException $e) {
            throw new Exception("Error fetching the API: " . $e->getMessage());
        }
        
    } catch (Exception $e) {
        return ["error" => $e->getMessage()];
    }
}

function fetch_live_stream_details($parsed_data) {
    $client = create_guzzle_client();
    
    $headers = [
        'accept' => 'application/json, text/plain, */*',
        'accept-language' => 'en-US,en;q=0.9,ta;q=0.8',
        'authorization' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJhbm9ueW1vdXNJZCI6ImM4MzhmZTI4ZDUwOGI2MmNkYjViMTVkODg1ODg1ZGE0N2RiNThlOWFhYWU2ZjNjNjQzM2Q2NjZhMjNlZTRkNjUiLCJjb3VudHJ5Q29kZSI6IklOIiwiZGV2aWNlSWQiOiJicm93c2VyLTY5YjllODZlLTJhZDgtZmZkMS1hMmE4LTM1MDYyMGZjODBmNyIsImV4cCI6MTc3NDk1NDQzOCwiaWF0IjoxNzQzNDE4NDM4LCJpZCI6Ijg1YjkzMTQ1LWRhMzEtNGVjNy1iN2RiLWNmMTI2NzBhZmNiOSIsImlwYWRkcmVzcyI6IjExNS45OS4xODIuMTMyIiwiaXBhZGRyZXNzZXMiOiIxMTUuOTkuMTgyLjEzMiwzNC4zNi41OS4yMzgsMTAuMjAwLjAuNjgiLCJwb3N0YWxjb2RlIjoiNTYyMTMwIiwicHJvdmlkZXIiOiJ2aWV3bGlmdCIsInNpdGUiOiJneHIiLCJzaXRlSWQiOiJiZTJmMjQ5Zi1lMTZlLTRhZDgtOThmYi0wZGViZTJmMzQ5MjMiLCJ1c2VySWQiOiI4NWI5MzE0NS1kYTMxLTRlYzctYjdkYi1jZjEyNjcwYWZjYjkiLCJ1c2VybmFtZSI6ImFub255bW91cyJ9._jw4EKUq3E5SZJfbMBfi6BCPiCcqbg25yxS6YWsVDTQ',
        'origin' => 'https://www.gxr.world',
        'priority' => 'u=1, i',
        'referer' => 'https://www.gxr.world/',
        'sec-ch-ua' => '"Google Chrome";v="131", "Chromium";v="131", "Not_A Brand";v="24"',
        'sec-ch-ua-mobile' => '?0',
        'sec-ch-ua-platform' => '"Windows"',
        'sec-fetch-dest' => 'empty',
        'sec-fetch-mode' => 'cors',
        'sec-fetch-site' => 'cross-site',
    ];
    
    foreach ($parsed_data as &$event) {
        if ($event['current_state'] === "live" && $event['game_id']) {
            try {
                // First request to get livestream ID
                $response = $client->get('https://gxr.api.viewlift.com/v3/content/game', [
                    'query' => [
                        'ids' => $event['game_id'],
                        'site' => 'gxr'
                    ],
                    'headers' => $headers
                ]);
                
                $livestream_data = json_decode($response->getBody(), true);
                
                if (isset($livestream_data['records'][0]['livestreams'][0]['id'])) {
                    $event['livestream_id'] = $livestream_data['records'][0]['livestreams'][0]['id'];
                    
                    // Second request to get stream details
                    $response = $client->get('https://gxr.api.viewlift.com/entitlement/video/status', [
                        'query' => [
                            'id' => $event['livestream_id'],
                            'deviceType' => 'web_browser',
                            'contentConsumption' => 'web'
                        ],
                        'headers' => $headers
                    ]);
                    
                    $live_stream_data = json_decode($response->getBody(), true);
                    
                    if ($live_stream_data['success'] && isset($live_stream_data['video']['streamingInfo'])) {
                        $widevine_details = $live_stream_data['video']['streamingInfo']['videoAssets']['widevine'];
                        $event['mpd_url'] = $widevine_details['url'] ?? null;
                        $event['lic_url'] = $widevine_details['licenseUrl'] ?? null;
                        $event['lic_token'] = $widevine_details['licenseToken'] ?? null;
                        
                        // Get MPD file
                        $response = $client->get($event['mpd_url']);
                        $mpd_response = $response->getBody()->getContents();
                        
                        $xml = simplexml_load_string($mpd_response);
                        $pssh = '';
                        
                        foreach ($xml->Period->AdaptationSet as $adaptationSet) {
                            foreach ($adaptationSet->ContentProtection as $protection) {
                                if ((string)$protection['schemeIdUri'] === 'urn:uuid:edef8ba9-79d6-4ace-a3c8-27dcd51d21ed') {
                                    $pssh = (string)$protection->pssh;
                                    break 2;
                                }
                            }
                        }
                        
                        $event['pssh'] = $pssh;
                        
                        if ($pssh) {
                            $key_headers = [
                                'accept' => 'application/json, text/plain, */*',
                                'accept-language' => 'en-US,en;q=0.9,ta;q=0.8',
                                'cache-control' => 'no-cache',
                                'content-type' => 'application/json',
                                'origin' => 'https://keydb.drmlive.net',
                                'pragma' => 'no-cache',
                                'priority' => 'u=1, i',
                                'referer' => 'https://keydb.drmlive.net/',
                                'sec-ch-ua' => '"Google Chrome";v="135", "Not-A.Brand";v="8", "Chromium";v="135"',
                                'sec-ch-ua-mobile' => '?0',
                                'sec-ch-ua-platform' => '"Windows"',
                                'sec-fetch-dest' => 'empty',
                                'sec-fetch-mode' => 'cors',
                                'sec-fetch-site' => 'same-origin',
                            ];
                            
                            $json_data = [
                                'license' => $event['lic_url'],
                                'headers' => 'x-axdrm-message: ' . $event['lic_token'],
                                'pssh' => $event['pssh'],
                                'buildInfo' => 'x86_64',
                                'proxy' => '',
                                'cache' => false,
                            ];
                            
                            $response = $client->post('https://keydb.drmlive.net/wv', [
                                'headers' => $key_headers,
                                'json' => $json_data
                            ]);
                            
                            $key_response = $response->getBody()->getContents();
                            preg_match('/<li[^>]*>([a-f0-9]+:[a-f0-9]+)<\/li>/i', $key_response, $matches);
                            
                            if (!empty($matches[1])) {
                                $event['clearkey_hex'] = $matches[1];
                            }
                        }
                    }
                }
            } catch (RequestException $e) {
                // Skip this event if there's an error
                continue;
            }
        }
        
        if ($event['schedule_start'] && $event['schedule_end']) {
            $event['start_time'] = convert_unix_to_time_formats($event['schedule_start']);
            $event['end_time'] = convert_unix_to_time_formats($event['schedule_end']);
        }
    }
    
    return $parsed_data;
}

function convert_unix_to_time_formats($unix_timestamp) {
    $dt = new DateTime("@$unix_timestamp");
    $dt->setTimezone(new DateTimeZone('UTC'));
    $utc_time = $dt->format("d-m-Y h:i A");
    $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
    $indian_time = $dt->format("d-m-Y h:i A");
    $dt->setTimezone(new DateTimeZone('America/Los_Angeles'));
    $california_time = $dt->format("d-m-Y h:i A");
    return [
        "unix" => $unix_timestamp,
        "utc" => $utc_time,
        "indian_time" => $indian_time,
        "usa_california_time" => $california_time
    ];
}

function generate_playlist_content($parsed_data) {
    $playlist_content = "#EXTM3U\n";
    foreach ($parsed_data as $event) {
        if (isset($event['mpd_url']) && $event['current_state'] !== "Coming Soon" && isset($event['clearkey_hex'])) {
            $playlist_content .= "#KODIPROP:inputstream.adaptive.license_type=clearkey\n";
            $playlist_content .= "#KODIPROP:inputstream.adaptive.license_key={$event['clearkey_hex']}\n";
            $playlist_content .= "#EXTINF:-1 tvg-logo=\"{$event['image_url']}\" group-title=\"GXR WORLD LIVE @DARSHNIPTV\",{$event['title']}\n";
            $playlist_content .= "{$event['mpd_url']}\n\n";
        }
    }
    return $playlist_content;
}

// Main request handler
$request_path = $_SERVER['REQUEST_URI'] ?? '';

if (strpos($request_path, '/api/gxr_fixtures.json') !== false) {
    $data = fetch_and_parse_schedule();
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT);
} elseif (strpos($request_path, '/api/gxr_playlist.m3u') !== false) {
    $data = fetch_and_parse_schedule();
    $playlist_content = generate_playlist_content($data['matches']);
    header('Content-Type: application/vnd.apple.mpegurl');
    echo $playlist_content;
} else {
    header('Content-Type: application/json');
    echo json_encode(["error" => "Invalid endpoint"], JSON_PRETTY_PRINT);
}
