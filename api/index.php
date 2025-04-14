<?php

function fetch_and_parse_schedule() {
    try {
        $ist_timezone = new DateTimeZone('Asia/Kolkata');
        $now = new DateTime('now', $ist_timezone);
        $current_time = $now->getTimestamp();
        $start_of_day = (clone $now)->setTime(0, 0, 0)->getTimestamp();
        $end_of_day = null;
        $api_url = "https://gxr-cached.api.viewlift.com/graphql?extensions=" . urlencode('{"persistedQuery":{"version":1,"sha256Hash":"6c9b1f7ce2ea7748154db1d1ee7f9ec3b10d5832e454a88df8cea504d28c7594"}}') .
                   "&variables=" . urlencode('{"site":"gxr","limit":200,"offset":0,"startDate":' . $start_of_day . ',"endDate":' . ($end_of_day ?? 'null') . ',"sortBy":null,"sortOrder":null,"countryCode":"IN"}');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new Exception("Error fetching the API: " . curl_error($ch));
        }
        curl_close($ch);
        $data = json_decode($response, true);
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
    } catch (Exception $e) {
        return ["error" => $e->getMessage()];
    }
}

function fetch_live_stream_details($parsed_data) {
    $headers = [
        'accept: application/json, text/plain, */*',
        'accept-language: en-US,en;q=0.9,ta;q=0.8',
        'authorization: eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJhbm9ueW1vdXNJZCI6IjliOWNjN2MxODhmN2E5M2IwMzc3M2E2NzNkNTY5NTY0NWRlNDMxNzA4M2U2ZTRmNDY1OThiMGUxNjZiZDc2YjgiLCJjb3VudHJ5Q29kZSI6IklOIiwiZGV2aWNlSWQiOiJicm93c2VyLTgzODBiMDNkLTNiZGEtMGZiMS03ZmExLWExZmFmOTZhMTJmYiIsImV4cCI6MTc2NjIzODE1NywiaWF0IjoxNzM0NzAyMTU3LCJpZCI6ImJiZGViZTgzLTRiN2MtNGQzZC1iZGUzLWRiNjlhMzJkMmQxMiIsImlwYWRkcmVzcyI6IjE3MS43OS42MS4yMDgiLCJpcGFkZHJlc3NlcyI6IjE3MS43OS42MS4yMDgsMzQuNDkuMTAwLjIzNiwxMC4yMDIuMC43MyIsInBvc3RhbGNvZGUiOiI2MDAwNDIiLCJwcm92aWRlciI6InZpZXdsaWZ0Iiwic2l0ZSI6Imd4ciIsInNpdGVJZCI6ImJlMmYyNDlmLWUxNmUtNGFkOC05OGZiLTBkZWJlMmYzNDkyMyIsInVzZXJJZCI6ImJiZGViZTgzLTRiN2MtNGQzZC1iZGUzLWRiNjlhMzJkMmQxMiIsInVzZXJuYW1lIjoiYW5vbnltb3VzIn0.5aLwaSsEXrzOxuAZCeXUDymE-WWvyO3C_2k_tZh3nLM',
        'origin: https://www.gxr.world',
        'priority: u=1, i',
        'referer: https://www.gxr.world/',
        'sec-ch-ua: "Google Chrome";v="131", "Chromium";v="131", "Not_A Brand";v="24"',
        'sec-ch-ua-mobile: ?0',
        'sec-ch-ua-platform: "Windows"',
        'sec-fetch-dest: empty',
        'sec-fetch-mode: cors',
        'sec-fetch-site: cross-site',
        'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
    ];
    
    foreach ($parsed_data as &$event) {
        if ($event['current_state'] === "live" && $event['game_id']) {
            $params = [
                'ids' => $event['game_id'],
                'site' => 'gxr',
            ];
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://gxr.api.viewlift.com/v3/content/game?' . http_build_query($params));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $response = curl_exec($ch);
            if (curl_errno($ch)) {
                continue; // Skip if error occurs
            }
            curl_close($ch);
            $livestream_data = json_decode($response, true);
            if (isset($livestream_data['records'][0]['livestreams'][0]['id'])) {
                $event['livestream_id'] = $livestream_data['records'][0]['livestreams'][0]['id'];
                $params = [
                    'id' => $event['livestream_id'],
                    'deviceType' => 'web_browser',
                    'contentConsumption' => 'web',
                ];
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, 'https://gxr.api.viewlift.com/entitlement/video/status?' . http_build_query($params));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                $response = curl_exec($ch);
                if (curl_errno($ch)) {
                    continue; // Skip if error occurs
                }
                curl_close($ch);
                $live_stream_data = json_decode($response, true);
                if ($live_stream_data['success'] && isset($live_stream_data['video']['streamingInfo'])) {
                    $widevine_details = $live_stream_data['video']['streamingInfo']['videoAssets']['widevine'];
                    $event['mpd_url'] = $widevine_details['url'] ?? null;
                    $event['lic_url'] = $widevine_details['licenseUrl'] ?? null;
                    $event['lic_token'] = $widevine_details['licenseToken'] ?? null;
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $event['mpd_url']);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    $mpd_response = curl_exec($ch);
                    if (curl_errno($ch)) {
                        continue; // Skip if error occurs
                    }
                    curl_close($ch);
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
                            'accept: application/json, text/plain, */*',
                            'accept-language: en-US,en;q=0.9,ta;q=0.8',
                            'cache-control: no-cache',
                            'content-type: application/json',
                            'origin: https://keydb.drmlive.net',
                            'pragma: no-cache',
                            'priority: u=1, i',
                            'referer: https://keydb.drmlive.net/',
                            'sec-ch-ua: "Google Chrome";v="135", "Not-A.Brand";v="8", "Chromium";v="135"',
                            'sec-ch-ua-mobile: ?0',
                            'sec-ch-ua-platform: "Windows"',
                            'sec-fetch-dest: empty',
                            'sec-fetch-mode: cors',
                            'sec-fetch-site: same-origin',
                            'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36',
                        ];
                        $json_data = [
                            'license' => $event['lic_url'],
                            'headers' => 'x-axdrm-message: ' . $event['lic_token'],
                            'pssh' => $event['pssh'],
                            'buildInfo' => 'x86_64',
                            'proxy' => '',
                            'cache' => false,
                        ];
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, 'https://keydb.drmlive.net/wv');
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json_data));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $key_headers);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        $key_response = curl_exec($ch);
                        if (curl_errno($ch)) {
                            continue; // Skip if error occurs
                        }
                        curl_close($ch);
                        preg_match('/<li[^>]*>([a-f0-9]+:[a-f0-9]+)<\/li>/i', $key_response, $matches);
                        if (!empty($matches[1])) {
                            $event['clearkey_hex'] = $matches[1];
                        }
                    }
                }
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
?>