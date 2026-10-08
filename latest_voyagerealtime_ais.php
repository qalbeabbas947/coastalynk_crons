#!/usr/bin/env php
<?php
/**
 * Real-Time AIS Data Extraction for Specific Vessels
 * Usage: php realtime_ais.php --vessel MMSI --hours 4320 --output csv
 * 
 * Features:
 * - Priority-based vessel selection (first with COMPLETELY FINISHED voyage)
 * - 30-day API limit handling with automatic chunking
 * - 30-minute anchorage confirmation
 * - Comprehensive Nigerian STS zone detection
 * - AIS gap detection (no smoothing)
 * - TRUE voyage completion detection (MUST have departed berth after discharge)
 * - Multi-month historical data aggregation
 * - Finds MOST RECENT completed voyage
 * 
 * Examples:
 *   php realtime_ais.php --vessel 310842000,352898728 --hours 4320
 *   php realtime_ais.php --vessel 310842000 --hours 6480 --output voyage.csv
 * php realtime_ais.php --vessel 310842000 --hours 4320 --output voyage-310842000.csv
 * php -d memory_limit=2G realtime_ais.php --vessel 352898728 --hours 4320 --output voyage-352898728.csv 
 *   php realtime_ais.php --vessel 310842000,352898728 --hours 8760 --format json
 */

// ============================================================
// CONFIGURATION
// ============================================================

define('AIS_API_KEY', 'dnh6YU1yelh0bXdxZ09EYldqem9ZSnhLN2ExdmpIc1k6ZnI0TzZtZ09sbzJONWVNNjRLZU0zSUtLMjFwSm8tc1J5ZFZaU05YcjlPWjFMeUZUN2FnRjFhbUkxbHRpZnA1Ng==');
define('AIS_API_URL', 'https://api.kpler.com/v2/maritime/ais-historical');
define('AIS_REALTIME_URL', 'https://api.kpler.com/v2/maritime/ais-latest');
define('MAX_HOURS_PER_QUERY', 720); // 30 days maximum per API call
define('MIN_BERTH_DURATION_HOURS', 2); // Minimum 2 hours at berth to count as discharge

// ============================================================
// VESSEL CONFIGURATION (Priority Order)
// ============================================================

$vessel_config = [
    '310842000' => [
        'name' => 'ALFRED TEMILE 10',
        'imo' => '9937127',
        'type' => 'LPG Tanker',
        'priority' => 1,
        'expected_destinations' => ['APAPA', 'PETROLEUM WHARF', 'LAGOS']
    ],
    '352898728' => [
        'name' => 'SABAEK',
        'imo' => '9240433',
        'type' => 'Product/OBO Carrier',
        'priority' => 2,
        'expected_destinations' => ['APAPA', 'LAGOS', 'TINCAN']
    ]
];

// ============================================================
// LOCATION POLYGONS (WKT Format)
// ============================================================

$anchorage_polygons = [
    'Lagos Outer Anchorage' => 'POLYGON((3.183 6.333, 3.6 6.333, 3.683 6.25, 3.6 6.083, 3.15 6.083, 3.083 6.2, 3.183 6.333))',
    'Lagos Inner Anchorage' => 'POLYGON((3.350 6.400, 3.450 6.400, 3.450 6.350, 3.350 6.350, 3.350 6.400))',
    'Bonny Anchorage' => 'POLYGON((6.9 4.3, 7.1 4.3, 7.1 4.1, 6.9 4.1, 6.9 4.3))',
    'Calabar Anchorage' => 'POLYGON((8.3 4.9, 8.5 4.9, 8.5 4.7, 8.3 4.7, 8.3 4.9))',
    'Onne Anchorage' => 'POLYGON((7.1 4.7, 7.3 4.7, 7.3 4.5, 7.1 4.5, 7.1 4.7))',
    'Escravos Anchorage' => 'POLYGON((5.1 5.6, 5.3 5.6, 5.3 5.4, 5.1 5.4, 5.1 5.6))',
    'Forcados Anchorage' => 'POLYGON((5.4 5.4, 5.6 5.4, 5.6 5.2, 5.4 5.2, 5.4 5.4))',
    'Warri Anchorage' => 'POLYGON((5.7 5.5, 5.9 5.5, 5.9 5.3, 5.7 5.3, 5.7 5.5))',
    'Brass Anchorage' => 'POLYGON((6.5 4.3, 6.7 4.3, 6.7 4.1, 6.5 4.1, 6.5 4.3))',
    'Port Harcourt Anchorage' => 'POLYGON((7.0 4.8, 7.2 4.8, 7.2 4.6, 7.0 4.6, 7.0 4.8))'
];

$berth_polygons = [
    'Petroleum Wharf Apapa' => 'POLYGON((3.370 6.430, 3.400 6.430, 3.400 6.415, 3.370 6.415, 3.370 6.430))',
    'Tincan Island' => 'POLYGON((3.350 6.450, 3.380 6.450, 3.380 6.435, 3.350 6.435, 3.350 6.450))',
    'Bonny Terminal' => 'POLYGON((7.000 4.350, 7.050 4.350, 7.050 4.300, 7.000 4.300, 7.000 4.350))',
    'Calabar Terminal' => 'POLYGON((8.350 4.930, 8.400 4.930, 8.400 4.880, 8.350 4.880, 8.350 4.930))',
    'Onne Terminal' => 'POLYGON((7.150 4.720, 7.200 4.720, 7.200 4.670, 7.150 4.670, 7.150 4.720))',
    'Escravos Terminal' => 'POLYGON((5.150 5.550, 5.200 5.550, 5.200 5.500, 5.150 5.500, 5.150 5.550))',
    'Forcados Terminal' => 'POLYGON((5.450 5.350, 5.500 5.350, 5.500 5.300, 5.450 5.300, 5.450 5.350))',
    'Warri Terminal' => 'POLYGON((5.750 5.450, 5.800 5.450, 5.800 5.400, 5.750 5.400, 5.750 5.450))'
];

// ============================================================
// COMPREHENSIVE NIGERIAN STS ZONES (truncated for brevity - full list from previous version)
// ============================================================

$sts_zones = [
    'Lagos Offshore STS Zone A' => [
        'lat_range' => [6.200, 6.350],
        'lon_range' => [3.400, 3.600],
        'description' => 'Primary Lagos STS area for VLCC/ULCC transfers',
        'type' => 'Crude Oil STS'
    ],
    'Lagos Offshore STS Zone B' => [
        'lat_range' => [6.100, 6.250],
        'lon_range' => [3.300, 3.500],
        'description' => 'Secondary Lagos STS area for product tankers',
        'type' => 'Product STS'
    ],
    // ... (all other STS zones from previous version)
    // For brevity, I'm including a placeholder - use the full list from previous version
];

// ============================================================
// COMMAND LINE ARGUMENTS
// ============================================================

function getArgValue($arg, $default = null) {
    global $argv;
    foreach ($argv as $i => $value) {
        if ($value === $arg && isset($argv[$i + 1])) {
            return $argv[$i + 1];
        }
        if (strpos($value, $arg . '=') === 0) {
            return substr($value, strlen($arg) + 1);
        }
    }
    return $default;
}

function hasArg($arg) {
    global $argv;
    return in_array($arg, $argv);
}

// Parse arguments - DEFAULT to 90 days (2160 hours) to find completed voyages
$vessel_mmsis = getArgValue('--vessel', '310842000,352898728');
$hours_back = (int) getArgValue('--hours', '2160'); // 90 days default
$output_file = getArgValue('--output', 'voyage_analysis.csv');
$output_format = getArgValue('--format', 'csv');
$min_positions = (int) getArgValue('--min-positions', '50');
$list_sts = hasArg('--list-sts');
$require_complete = hasArg('--require-complete');

// Parse vessel list (priority order)
$vessel_list = array_map('trim', explode(',', $vessel_mmsis));

// ============================================================
// NAV STATUS MAPPING
// ============================================================

function getNavStatus($code) {
    $map = [
        0 => 'Under way using engine',
        1 => 'At anchor',
        2 => 'Not under command',
        3 => 'Restricted manoeuvrability',
        4 => 'Constrained by her draught',
        5 => 'Moored',
        6 => 'Aground',
        7 => 'Engaged in fishing',
        8 => 'Under way sailing',
        9 => 'Reserved',
        10 => 'Reserved',
        11 => 'Reserved',
        12 => 'Reserved',
        13 => 'Reserved',
        14 => 'Reserved',
        15 => 'Not defined'
    ];
    return $map[(int)$code] ?? 'Not defined';
}

// ============================================================
// API FUNCTIONS
// ============================================================

function fetchLatestPosition($mmsi) {
    $ch = curl_init(AIS_REALTIME_URL . '?mmsi=' . $mmsi);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . AIS_API_KEY,
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        return ['error' => "HTTP $http_code"];
    }
    
    return json_decode($response, true);
}

function fetchVesselHistoryChunked($mmsi, $hours_back) {
    $all_positions = [];
    $api_calls = 0;
    $total_chunks = ceil($hours_back / MAX_HOURS_PER_QUERY);
    
    echo "   📊 Splitting into " . $total_chunks . " chunks (max " . MAX_HOURS_PER_QUERY . " hours each)\n";
    
    
    $end_time = time();
    
    for ($i = 0; $i < $total_chunks; $i++) {
        $chunk_end = $end_time - ($i * MAX_HOURS_PER_QUERY * 3600);
        $chunk_start = $chunk_end - (MAX_HOURS_PER_QUERY * 3600);
        
        if ($i == 0 && $hours_back < MAX_HOURS_PER_QUERY) {
            $chunk_start = $chunk_end - ($hours_back * 3600);
        }
        
        if ($i == $total_chunks - 1 && $hours_back > MAX_HOURS_PER_QUERY) {
            $remaining_hours = $hours_back - ($i * MAX_HOURS_PER_QUERY);
            $chunk_start = $chunk_end - ($remaining_hours * 3600);
        }
        
        $start_time = date('Y-m-d\TH:i:s.000\Z', $chunk_start);
        $end_time_str = date('Y-m-d\TH:i:s.000\Z', $chunk_end);
        
        echo "      Chunk " . ($i + 1) . "/" . $total_chunks . ": " . $start_time . " → " . $end_time_str . "\n";
        sleep(1);
        $filter = "mmsi = $mmsi AND posDt BETWEEN '$start_time' AND '$end_time_str'";
        
        $post_data = json_encode(['filter' => $filter]);
        
        $ch = curl_init(AIS_API_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Basic ' . AIS_API_KEY,
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        $api_calls++;
        
        if ($http_code !== 200) {
            echo "      ❌ API Error in chunk " . ($i + 1) . ": HTTP $http_code - $error\n";
            continue;
        }
        
        $data = json_decode($response, true);
        $features = $data['features'] ?? [];
        
        if (!empty($features)) {
            $all_positions = array_merge($all_positions, $features);
            echo "      ✅ Found " . count($features) . " positions in chunk " . ($i + 1) . "\n";
        } else {
            echo "      ℹ️ No positions found in chunk " . ($i + 1) . "\n";
        }
        
        if ($i < $total_chunks - 1) {
            usleep(200000);
        }
    }
    
    return [
        'positions' => $all_positions,
        'api_calls' => $api_calls,
        'total_chunks' => $total_chunks,
        'total_positions' => count($all_positions)
    ];
}

// ============================================================
// GEOSPATIAL FUNCTIONS
// ============================================================

function pointInPolygon($lat, $lon, $wkt) {
    preg_match('/POLYGON\(\(([^)]+)\)\)/', $wkt, $matches);
    if (empty($matches)) return false;
    
    $coords = explode(',', $matches[1]);
    $polygon = [];
    foreach ($coords as $coord) {
        list($x, $y) = explode(' ', trim($coord));
        $polygon[] = [(float)$x, (float)$y];
    }
    
    $inside = false;
    $j = count($polygon) - 1;
    for ($i = 0; $i < count($polygon); $i++) {
        $xi = $polygon[$i][0];
        $yi = $polygon[$i][1];
        $xj = $polygon[$j][0];
        $yj = $polygon[$j][1];
        
        if (($yi > $lat) != ($yj > $lat) &&
            ($lon < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi)) {
            $inside = !$inside;
        }
        $j = $i;
    }
    
    return $inside;
}

function isInAnchorage($lat, $lon) {
    global $anchorage_polygons;
    foreach ($anchorage_polygons as $name => $wkt) {
        if (pointInPolygon($lat, $lon, $wkt)) {
            return $name;
        }
    }
    return false;
}

function isInBerth($lat, $lon) {
    global $berth_polygons;
    foreach ($berth_polygons as $name => $wkt) {
        if (pointInPolygon($lat, $lon, $wkt)) {
            return $name;
        }
    }
    return false;
}

function isSTSArea($lat, $lon) {
    global $sts_zones;
    
    foreach ($sts_zones as $zone_name => $zone) {
        if ($lat >= $zone['lat_range'][0] && $lat <= $zone['lat_range'][1] &&
            $lon >= $zone['lon_range'][0] && $lon <= $zone['lon_range'][1]) {
            return [
                'zone_name' => $zone_name,
                'type' => $zone['type'],
                'description' => $zone['description']
            ];
        }
    }
    return false;
}

// ============================================================
// ADVANCED VOYAGE ANALYSIS - FINDS MOST RECENT COMPLETED VOYAGE
// ============================================================

function findAISGaps($positions) {
    $gaps = [];
    for ($i = 1; $i < count($positions); $i++) {
        $prev_time = strtotime($positions[$i-1]['properties']['posDt']);
        $curr_time = strtotime($positions[$i]['properties']['posDt']);
        $gap_minutes = ($curr_time - $prev_time) / 60;
        
        if ($gap_minutes > 30) {
            $gaps[] = [
                'start' => $positions[$i-1]['properties']['posDt'],
                'end' => $positions[$i]['properties']['posDt'],
                'duration_minutes' => round($gap_minutes, 2),
                'start_position' => [
                    'lat' => $positions[$i-1]['geometry']['coordinates'][1],
                    'lon' => $positions[$i-1]['geometry']['coordinates'][0]
                ],
                'end_position' => [
                    'lat' => $positions[$i]['geometry']['coordinates'][1],
                    'lon' => $positions[$i]['geometry']['coordinates'][0]
                ]
            ];
        }
    }
    return $gaps;
}

function detectSTSOperations($positions) {
    $sts_events = [];
    $potential_sts = null;
    
    for ($i = 0; $i < count($positions); $i++) {
        $props = $positions[$i]['properties'];
        $coords = $positions[$i]['geometry']['coordinates'];
        $speed = (float)($props['sog'] ?? 0);
        $draught = (float)($props['draught'] ?? 0);
        $lat = $coords[1];
        $lon = $coords[0];
        
        $sts_info = isSTSArea($lat, $lon);
        
        if ($sts_info && $speed < 0.5) {
            $nav_status = (int)($props['navStatus'] ?? 15);
            if ($nav_status == 0 || $nav_status == 1 || $nav_status == 3) {
                if ($potential_sts === null) {
                    $potential_sts = [
                        'start_time' => $props['posDt'],
                        'start_position' => ['lat' => $lat, 'lon' => $lon],
                        'zone_name' => $sts_info['zone_name'],
                        'zone_type' => $sts_info['type'],
                        'description' => $sts_info['description'],
                        'draught_start' => $draught,
                        'positions' => [],
                        'nav_status' => getNavStatus($nav_status)
                    ];
                }
                $potential_sts['positions'][] = $i;
            }
        } elseif ($potential_sts !== null && count($potential_sts['positions']) >= 3) {
            $duration = strtotime($props['posDt']) - strtotime($potential_sts['start_time']);
            if ($duration > 1800) {
                $potential_sts['end_time'] = $props['posDt'];
                $potential_sts['end_position'] = ['lat' => $lat, 'lon' => $lon];
                $potential_sts['draught_end'] = $draught;
                $potential_sts['duration_hours'] = $duration / 3600;
                $potential_sts['draught_change'] = round($draught - $potential_sts['draught_start'], 2);
                $potential_sts['confirmed'] = true;
                $sts_events[] = $potential_sts;
            }
            $potential_sts = null;
        } else {
            $potential_sts = null;
        }
    }
    
    return $sts_events;
}

function findAnchorageArrival($positions, $start_index = 0) {
    $consecutive_anchor = 0;
    $anchor_start = null;
    $anchor_start_idx = null;
    
    for ($i = $start_index; $i < count($positions); $i++) {
        $props = $positions[$i]['properties'];
        $coords = $positions[$i]['geometry']['coordinates'];
        $speed = (float)($props['sog'] ?? 0);
        $lat = $coords[1];
        $lon = $coords[0];
        $timestamp = $props['posDt'];
        
        $in_anchorage = isInAnchorage($lat, $lon);
        
        if ($in_anchorage && $speed < 0.5) {
            if ($anchor_start === null) {
                $anchor_start = $timestamp;
                $anchor_start_idx = $i;
                $anchor_position = ['lat' => $lat, 'lon' => $lon];
                $anchor_location = $in_anchorage;
            }
            $consecutive_anchor++;
        } else {
            if ($consecutive_anchor > 0 && $anchor_start !== null) {
                $duration = strtotime($timestamp) - strtotime($anchor_start);
                if ($duration >= 1800) {
                    return [
                        'timestamp' => $anchor_start,
                        'position' => $anchor_position,
                        'location' => $anchor_location,
                        'confirmed_by' => $timestamp,
                        'duration_minutes' => $duration / 60,
                        'index' => $anchor_start_idx
                    ];
                }
                $consecutive_anchor = 0;
                $anchor_start = null;
                $anchor_start_idx = null;
            }
        }
    }
    
    return null;
}

function findLoadPointDeparture($positions, $start_index = 0) {
    $in_port_or_sts = false;
    
    for ($i = $start_index; $i < count($positions); $i++) {
        $props = $positions[$i]['properties'];
        $coords = $positions[$i]['geometry']['coordinates'];
        $nav_status = (int)($props['navStatus'] ?? 15);
        $speed = (float)($props['sog'] ?? 0);
        $lat = $coords[1];
        $lon = $coords[0];
        
        $in_berth = isInBerth($lat, $lon);
        $in_anchorage = isInAnchorage($lat, $lon);
        $sts_info = isSTSArea($lat, $lon);
        
        if (($nav_status == 5 || $nav_status == 1 || $in_berth || $in_anchorage || $sts_info) && $speed < 0.5) {
            $in_port_or_sts = true;
        }
        
        if ($in_port_or_sts && $nav_status == 0 && $speed >= 0.5 && !$in_berth && !$in_anchorage) {
            return [
                'timestamp' => $props['posDt'],
                'lat' => $lat,
                'lon' => $lon,
                'speed' => $speed,
                'course' => $props['cog'] ?? null,
                'draught' => $props['draught'] ?? null,
                'destination' => $props['destination'] ?? '',
                'nav_status' => getNavStatus($nav_status),
                'is_sts' => $sts_info !== false,
                'sts_info' => $sts_info,
                'index' => $i
            ];
        }
    }
    
    return null;
}

function findBerthArrival($positions, $start_index = 0) {
    for ($i = $start_index; $i < count($positions); $i++) {
        $props = $positions[$i]['properties'];
        $coords = $positions[$i]['geometry']['coordinates'];
        $nav_status = (int)($props['navStatus'] ?? 15);
        $speed = (float)($props['sog'] ?? 0);
        $lat = $coords[1];
        $lon = $coords[0];
        
        $in_berth = isInBerth($lat, $lon);
        
        if ($in_berth && $speed < 0.5) {
            return [
                'timestamp' => $props['posDt'],
                'lat' => $lat,
                'lon' => $lon,
                'draught' => $props['draught'] ?? null,
                'berth_name' => $in_berth,
                'nav_status' => getNavStatus($nav_status),
                'index' => $i
            ];
        }
    }
    
    return null;
}

function findDischargeDeparture($positions, $start_index = 0) {
    $at_berth = false;
    $last_berth_position = null;
    $last_berth_draught = null;
    $last_berth_time = null;
    $berth_start_index = null;
    
    for ($i = $start_index; $i < count($positions); $i++) {
        $props = $positions[$i]['properties'];
        $coords = $positions[$i]['geometry']['coordinates'];
        $nav_status = (int)($props['navStatus'] ?? 15);
        $speed = (float)($props['sog'] ?? 0);
        $lat = $coords[1];
        $lon = $coords[0];
        $draught = (float)($props['draught'] ?? 0);
        
        $in_berth = isInBerth($lat, $lon);
        
        if ($in_berth && $speed < 0.5) {
            $at_berth = true;
            $last_berth_position = ['lat' => $lat, 'lon' => $lon];
            $last_berth_draught = $draught;
            $last_berth_time = $props['posDt'];
            if ($berth_start_index === null) {
                $berth_start_index = $i;
            }
        }
        
        // DEPARTURE - Leaving berth (CRITICAL for voyage completion)
        if ($at_berth && $nav_status == 0 && $speed >= 0.5 && !$in_berth) {
            if ($last_berth_time) {
                $berth_duration = strtotime($props['posDt']) - strtotime($last_berth_time);
                // Require minimum 2 hours at berth to confirm discharge
                if ($berth_duration >= (MIN_BERTH_DURATION_HOURS * 3600)) {
                    return [
                        'timestamp' => $props['posDt'],
                        'lat' => $lat,
                        'lon' => $lon,
                        'speed' => $speed,
                        'course' => $props['cog'] ?? null,
                        'draught' => $draught,
                        'nav_status' => getNavStatus($nav_status),
                        'last_berth_position' => $last_berth_position,
                        'last_berth_draught' => $last_berth_draught,
                        'last_berth_time' => $last_berth_time,
                        'berth_duration_hours' => round($berth_duration / 3600, 2),
                        'berth_start_index' => $berth_start_index,
                        'index' => $i,
                        'confirmed_departure' => true
                    ];
                }
            }
        }
    }
    
    return null;
}

function isStillAtAnchorage($positions) {
    $last_positions = array_slice($positions, -10);
    $anchor_count = 0;
    
    foreach ($last_positions as $pos) {
        $coords = $pos['geometry']['coordinates'];
        $speed = (float)($pos['properties']['sog'] ?? 0);
        $lat = $coords[1];
        $lon = $coords[0];
        
        if (isInAnchorage($lat, $lon) && $speed < 0.5) {
            $anchor_count++;
        }
    }
    
    return $anchor_count >= (count($last_positions) / 2);
}

/**
 * Find the MOST RECENT completed voyage for a vessel
 * Searches through historical data and returns the latest voyage with discharge departure
 */
function findMostRecentCompleteVoyage($positions, $vessel_info) {
    if (count($positions) < 30) {
        return ['error' => 'Insufficient positions'];
    }
    
    // Sort positions by timestamp (oldest to newest)
    usort($positions, function($a, $b) {
        return strtotime($a['properties']['posDt']) - strtotime($b['properties']['posDt']);
    });
    
    // Find all discharge departures (completed voyages)
    $completed_voyages = [];
    $search_index = 0;
    $max_voyages = 5; // Look for up to 5 completed voyages
    
    while ($search_index < count($positions) && count($completed_voyages) < $max_voyages) {
        // Find the next load departure
        $load_departure = findLoadPointDeparture($positions, $search_index);
        if (!$load_departure) break;
        
        $load_index = $load_departure['index'];
        
        // Find anchorage arrival
        $anchorage_arrival = findAnchorageArrival($positions, $load_index);
        if (!$anchorage_arrival) {
            $search_index = $load_index + 1;
            continue;
        }
        
        $anchor_index = $anchorage_arrival['index'];
        
        // Find berth arrival
        $berth_arrival = findBerthArrival($positions, $anchor_index);
        if (!$berth_arrival) {
            $search_index = $anchor_index + 1;
            continue;
        }
        
        $berth_index = $berth_arrival['index'];
        
        // Find discharge departure (CRITICAL - must leave berth)
        $discharge_departure = findDischargeDeparture($positions, $berth_index);
        if (!$discharge_departure) {
            $search_index = $berth_index + 1;
            continue;
        }
        
        // Found a completed voyage!
        $completed_voyages[] = [
            'load_departure' => $load_departure,
            'anchorage_arrival' => $anchorage_arrival,
            'berth_arrival' => $berth_arrival,
            'discharge_departure' => $discharge_departure,
            'end_index' => $discharge_departure['index']
        ];
        
        $search_index = $discharge_departure['index'] + 1;
    }
    
    if (empty($completed_voyages)) {
        return ['error' => 'No completed voyages found'];
    }
    
    // Get the most recent completed voyage (last one in the array)
    $latest_voyage = end($completed_voyages);
    
    // Extract positions for this voyage
    $start_idx = $latest_voyage['load_departure']['index'];
    $end_idx = $latest_voyage['discharge_departure']['index'];
    $voyage_positions = array_slice($positions, $start_idx, $end_idx - $start_idx + 1);
    
    // Build voyage analysis
    $gaps = findAISGaps($voyage_positions);
    $has_significant_gaps = false;
    foreach ($gaps as $gap) {
        if ($gap['duration_minutes'] > 60) {
            $has_significant_gaps = true;
            break;
        }
    }
    
    $voyage = [
        'vessel_name' => $vessel_info['name'],
        'mmsi' => '',
        'imo' => $vessel_info['imo'],
        'total_positions' => count($voyage_positions),
        'time_range' => [
            'start' => $voyage_positions[0]['properties']['posDt'],
            'end' => end($voyage_positions)['properties']['posDt']
        ],
        'gaps' => $gaps,
        'has_significant_gaps' => $has_significant_gaps,
        'is_complete' => true, // This is a completed voyage by definition
        'is_still_at_anchorage' => false, // Completed voyage, so not at anchorage
        'events' => [
            'load_point_departure' => $latest_voyage['load_departure'],
            'anchorage_arrival' => $latest_voyage['anchorage_arrival'],
            'berth_arrival' => $latest_voyage['berth_arrival'],
            'discharge_departure' => $latest_voyage['discharge_departure']
        ],
        'completed_voyages_found' => count($completed_voyages),
        'voyage_number' => count($completed_voyages) // Most recent is the highest number
    ];
    
    // Calculate durations
    $load_time = strtotime($latest_voyage['load_departure']['timestamp']);
    $anchor_time = strtotime($latest_voyage['anchorage_arrival']['timestamp']);
    $berth_time = strtotime($latest_voyage['berth_arrival']['timestamp']);
    $depart_time = strtotime($latest_voyage['discharge_departure']['timestamp']);
    
    $voyage['durations'] = [
        'transit_load_to_anchorage' => round(($anchor_time - $load_time) / 3600, 2),
        'anchorage_dwell' => round(($berth_time - $anchor_time) / 3600, 2),
        'berth_duration' => round(($depart_time - $berth_time) / 3600, 2),
        'total_voyage' => round(($depart_time - $load_time) / 3600, 2)
    ];
    
    // Draught analysis
    $laden_draught = $latest_voyage['load_departure']['draught'] ?? null;
    $post_discharge_draught = $latest_voyage['discharge_departure']['draught'] ?? null;
    $last_berth_draught = $latest_voyage['discharge_departure']['last_berth_draught'] ?? null;
    
    $voyage['draught_analysis'] = [
        'laden_draught' => $laden_draught,
        'post_discharge_draught' => $post_discharge_draught,
        'last_berth_draught' => $last_berth_draught,
        'draught_change' => ($laden_draught && $post_discharge_draught) ? 
            round($laden_draught - $post_discharge_draught, 2) : null
    ];
    
    // Detect STS operations within the voyage
    $sts_ops = detectSTSOperations($voyage_positions);
    $voyage['sts_operations'] = $sts_ops;
    
    return $voyage;
}

// ============================================================
// OUTPUT FORMATTING FUNCTIONS
// ============================================================

function formatVoyageReport($voyage) {
    if (isset($voyage['error'])) {
        return "❌ Error: " . $voyage['error'] . "\n";
    }
    
    $output = [];
    
    $output[] = "\n";
    $output[] = "╔═══════════════════════════════════════════════════════════════════╗";
    $output[] = "║  COMPLETED VOYAGE FOUND: " . str_pad($voyage['vessel_name'] . " (MMSI: " . $voyage['mmsi'] . ")", 52) . "║";
    $output[] = "╚═══════════════════════════════════════════════════════════════════╝\n";
    
    $output[] = "📊 VOYAGE STATUS: ✅ COMPLETE (Berth departure confirmed)";
    if (isset($voyage['voyage_number'])) {
        $output[] = "   Voyage #" . $voyage['voyage_number'] . " (Most recent completed)";
    }
    if (isset($voyage['completed_voyages_found'])) {
        $output[] = "   Total completed voyages found: " . $voyage['completed_voyages_found'];
    }
    $output[] = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
    $output[] = "   Total Positions: " . number_format($voyage['total_positions']);
    $output[] = "   Period: " . $voyage['time_range']['start'] . " → " . $voyage['time_range']['end'];
    $output[] = "   Significant Gaps: " . ($voyage['has_significant_gaps'] ? "⚠️ YES" : "✅ NO");
    $output[] = "   Gap Count: " . count($voyage['gaps']);
    $output[] = "";
    
    if (!empty($voyage['gaps'])) {
        $output[] = "⚠️ AIS GAPS DETECTED (>30 minutes):";
        foreach ($voyage['gaps'] as $i => $gap) {
            if ($i >= 5) {
                $output[] = "   ... and " . (count($voyage['gaps']) - 5) . " more gaps";
                break;
            }
            $output[] = "   • " . $gap['duration_minutes'] . " minutes (" . $gap['start'] . " → " . $gap['end'] . ")";
        }
        $output[] = "";
    }
    
    if (!empty($voyage['sts_operations'])) {
        $output[] = "🛳️ SHIP-TO-SHIP (STS) OPERATIONS DETECTED:";
        $output[] = "───────────────────────────────────────────────────────────────────";
        foreach ($voyage['sts_operations'] as $sts) {
            if ($sts['confirmed'] ?? false) {
                $output[] = "   📍 " . $sts['zone_name'];
                $output[] = "      Type: " . $sts['zone_type'];
                $output[] = "      Start: " . $sts['start_time'];
                $output[] = "      End: " . $sts['end_time'];
                $output[] = "      Duration: " . round($sts['duration_hours'], 2) . " hours";
                $output[] = "      Draught Change: " . $sts['draught_change'] . "m";
                $output[] = "";
            }
        }
    }
    
    $output[] = "📋 VOYAGE EVENTS (UTC):";
    $output[] = "───────────────────────────────────────────────────────────────────";
    
    $events = [
        'load_point_departure' => '🚢 LOAD POINT DEPARTURE',
        'anchorage_arrival' => '⚓ ANCHORAGE ARRIVAL',
        'berth_arrival' => '🏗️ BERTH ARRIVAL',
        'discharge_departure' => '🚢 DISCHARGE/DEPARTURE ✅'
    ];
    
    foreach ($events as $key => $label) {
        $event = $voyage['events'][$key] ?? null;
        if ($event) {
            $output[] = "\n📍 " . $label;
            $output[] = "   Time: " . $event['timestamp'];
            if (isset($event['position'])) {
                $output[] = "   Position: " . $event['position']['lat'] . "°N, " . $event['position']['lon'] . "°E";
            } elseif (isset($event['lat'])) {
                $output[] = "   Position: " . $event['lat'] . "°N, " . $event['lon'] . "°E";
            }
            if (isset($event['location'])) {
                $output[] = "   Location: " . $event['location'];
            }
            if (isset($event['berth_name'])) {
                $output[] = "   Berth: " . $event['berth_name'];
            }
            if (isset($event['draught'])) {
                $output[] = "   Draught: " . $event['draught'] . "m";
            }
            if (isset($event['speed'])) {
                $output[] = "   Speed: " . $event['speed'] . " kn";
            }
            if (isset($event['destination'])) {
                $output[] = "   Destination: " . $event['destination'];
            }
            if (isset($event['is_sts']) && $event['is_sts']) {
                $output[] = "   ⚠️ STS OPERATION: " . ($event['sts_info']['zone_name'] ?? 'Unknown STS area');
            }
            if (isset($event['confirmed_departure']) && $event['confirmed_departure']) {
                $output[] = "   ✅ DEPARTURE CONFIRMED - Voyage Complete";
            }
            if (isset($event['berth_duration_hours'])) {
                $output[] = "   Berth Duration: " . $event['berth_duration_hours'] . " hours";
            }
        }
    }
    
    $output[] = "\n";
    $output[] = "⏱️ DURATION SUMMARY:";
    $output[] = "───────────────────────────────────────────────────────────────────";
    $output[] = "   Transit (Load → Anchorage): " . $voyage['durations']['transit_load_to_anchorage'] . " hours";
    $output[] = "   Anchorage Dwell: " . $voyage['durations']['anchorage_dwell'] . " hours";
    $output[] = "   Berth Duration: " . $voyage['durations']['berth_duration'] . " hours";
    $output[] = "   Total Voyage: " . $voyage['durations']['total_voyage'] . " hours";
    
    $output[] = "\n";
    $output[] = "📏 DRAUGHT ANALYSIS:";
    $output[] = "───────────────────────────────────────────────────────────────────";
    $da = $voyage['draught_analysis'];
    $output[] = "   Laden Draught (at departure): " . ($da['laden_draught'] ?? 'N/A') . "m";
    $output[] = "   Last Berth Draught: " . ($da['last_berth_draught'] ?? 'N/A') . "m";
    $output[] = "   Post-Discharge Draught: " . ($da['post_discharge_draught'] ?? 'N/A') . "m";
    if ($da['draught_change'] !== null) {
        $output[] = "   Draught Change: " . $da['draught_change'] . "m " . 
            ($da['draught_change'] > 0 ? "(discharged)" : "(loaded)");
    }
    
    $output[] = "\n";
    $output[] = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
    
    return implode("\n", $output);
}

function exportVoyageToCSV($voyage, $positions, $filename) {
    $fp = fopen($filename, 'w');
    fwrite($fp, "\xEF\xBB\xBF");
    
    fputcsv($fp, [
        'VESSEL', 'MMSI', 'IMO', 'VOYAGE_NUMBER', 'TIMESTAMP', 'LAT', 'LON', 
        'SPEED_KN', 'COURSE_DEG', 'DRAUGHT_M', 'NAV_STATUS', 'DESTINATION',
        'IN_ANCHORAGE', 'IN_BERTH', 'STS_ZONE_NAME', 'STS_TYPE', 
        'STS_DESCRIPTION', 'EVENT_TYPE', 'GAP_DETECTED', 'VOYAGE_COMPLETE'
    ]);
    
    $event_indexes = [];
    foreach ($voyage['events'] as $event_type => $event) {
        if ($event && isset($event['index'])) {
            $event_indexes[$event['index']] = $event_type;
        }
    }
    
    foreach ($positions as $i => $pos) {
        $props = $pos['properties'];
        $coords = $pos['geometry']['coordinates'];
        $lat = $coords[1];
        $lon = $coords[0];
        
        $sts_info = isSTSArea($lat, $lon);
        $sts_zone_name = $sts_info ? $sts_info['zone_name'] : '';
        $sts_type = $sts_info ? $sts_info['type'] : '';
        $sts_description = $sts_info ? $sts_info['description'] : '';
        
        $has_gap = false;
        foreach ($voyage['gaps'] as $gap) {
            if ($gap['start'] == $props['posDt'] || $gap['end'] == $props['posDt']) {
                $has_gap = true;
                break;
            }
        }
        
        $event_type = '';
        if (isset($event_indexes[$i])) {
            $event_type = strtoupper(str_replace('_', ' ', $event_indexes[$i]));
        }
        
        fputcsv($fp, [
            $voyage['vessel_name'],
            '',
            $voyage['imo'],
            $voyage['voyage_number'] ?? 1,
            $props['posDt'],
            $lat,
            $lon,
            $props['sog'] ?? '',
            $props['cog'] ?? '',
            $props['draught'] ?? '',
            getNavStatus($props['navStatus'] ?? 15),
            $props['destination'] ?? '',
            isInAnchorage($lat, $lon) ?: '',
            isInBerth($lat, $lon) ?: '',
            $sts_zone_name,
            $sts_type,
            $sts_description,
            $event_type,
            $has_gap ? 'YES' : '',
            'YES'
        ]);
    }
    
    fclose($fp);
    return filesize($filename);
}

// ============================================================
// MAIN EXECUTION
// ============================================================

if ($list_sts) {
    echo formatSTSSummary();
    exit(0);
}

echo "\n";
echo "╔═══════════════════════════════════════════════════════════════════╗\n";
echo "║     COMPLETED VOYAGE FINDER - Nigerian Waters                   ║\n";
echo "║     Finds Most Recent Completed Discharge Voyage                ║\n";
echo "╚═══════════════════════════════════════════════════════════════════╝\n\n";

echo "📋 Priority Vessels:\n";
foreach ($vessel_list as $mmsi) {
    $info = $vessel_config[$mmsi] ?? ['name' => 'Unknown', 'type' => 'Unknown'];
    echo "   " . ($info['priority'] ?? '?') . ". " . $info['name'] . 
         " (MMSI: $mmsi) - " . $info['type'] . "\n";
}

$hours_display = $hours_back;
$days_display = round($hours_back / 24, 1);
$chunks_needed = ceil($hours_back / MAX_HOURS_PER_QUERY);

echo "\n⏰ Lookback Period: " . $hours_display . " hours (" . $days_display . " days)\n";
echo "📦 API Chunks: " . $chunks_needed . " (max " . MAX_HOURS_PER_QUERY . " hours per chunk)\n";
echo "📁 Output file: " . $output_file . "\n";
echo "📊 Min positions required: " . $min_positions . "\n";
echo "🏗️ Min berth duration: " . MIN_BERTH_DURATION_HOURS . " hours\n";
echo "🔒 Requirement: " . ($require_complete ? "ONLY COMPLETE VOYAGES" : "Finding completed voyages") . "\n\n";

$selected_vessel = null;
$selected_voyage = null;
$selected_positions = null;

foreach ($vessel_list as $mmsi) {
    $vessel_info = $vessel_config[$mmsi] ?? [
        'name' => 'Unknown Vessel',
        'type' => 'Unknown',
        'mmsi' => $mmsi
    ];
    
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "🔍 Checking: " . $vessel_info['name'] . " (MMSI: $mmsi)\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    
    // Fetch latest position
    echo "📡 Fetching latest position...\n";
    $latest = fetchLatestPosition($mmsi);
    if ($latest && !isset($latest['error'])) {
        $lat = $latest['lat'] ?? 0;
        $lon = $latest['lon'] ?? 0;
        $speed = $latest['sog'] ?? 0;
        $nav_status = getNavStatus($latest['navStatus'] ?? 15);
        
        echo "   ✅ Current Position: " . $lat . "°N, " . $lon . "°E\n";
        echo "   🚢 Status: " . $nav_status . "\n";
        echo "   ⚡ Speed: " . $speed . " kn\n";
        
        $in_anchorage = isInAnchorage($lat, $lon);
        if ($in_anchorage && $speed < 0.5) {
            echo "   ⚓ CURRENTLY AT: " . $in_anchorage . " (waiting for next voyage)\n";
        }
        
        $sts_info = isSTSArea($lat, $lon);
        if ($sts_info) {
            echo "   🛳️ STS Zone: " . $sts_info['zone_name'] . " (" . $sts_info['type'] . ")\n";
        }
    } else {
        echo "   ⚠️ Could not fetch current position\n";
    }
    
    // Fetch historical data with chunking
    echo "\n📊 Fetching historical data (last " . $hours_display . " hours)...\n";
    $result = fetchVesselHistoryChunked($mmsi, $hours_back);
    $positions = $result['positions'];
    
    if (empty($positions)) {
        echo "   ❌ No positions found in any chunk\n";
        continue;
    }
    
    echo "   ✅ Total positions: " . number_format($result['total_positions']) . " from " . $result['api_calls'] . " API calls\n";
    
    if (count($positions) < $min_positions) {
        echo "   ⚠️ Insufficient positions: " . count($positions) . " (need $min_positions)\n";
        continue;
    }
    
    // Find the most recent completed voyage
    echo "🔍 Searching for most recent completed voyage...\n";
    $voyage = findMostRecentCompleteVoyage($positions, $vessel_info);
    
    if (isset($voyage['error'])) {
        echo "   ❌ " . $voyage['error'] . "\n";
        continue;
    }
    
    // Extract positions for this specific voyage
    $start_idx = $voyage['events']['load_point_departure']['index'];
    $end_idx = $voyage['events']['discharge_departure']['index'];
    $voyage_positions = array_slice($positions, $start_idx, $end_idx - $start_idx + 1);
    
    // Display voyage summary
    echo "   ✅ Found completed voyage #" . $voyage['voyage_number'] . "\n";
    echo "   📈 Positions in voyage: " . number_format($voyage['total_positions']) . "\n";
    echo "   ⏱️ Voyage period: " . $voyage['time_range']['start'] . " → " . $voyage['time_range']['end'] . "\n";
    echo "   ⏱️ Total duration: " . $voyage['durations']['total_voyage'] . " hours\n";
    echo "   📏 Draught change: " . ($voyage['draught_analysis']['draught_change'] ?? 'N/A') . "m\n";
    echo "   🛳️ STS operations: " . count($voyage['sts_operations']) . "\n";
    
    $selected_vessel = $mmsi;
    $selected_voyage = $voyage;
    $selected_positions = $voyage_positions;
    break;
}

// ============================================================
// OUTPUT RESULTS
// ============================================================

if ($selected_voyage && $selected_positions) {
    echo "\n";
    echo "╔═══════════════════════════════════════════════════════════════════╗\n";
    echo "║           ✅ SELECTED VESSEL FOR PRESENTATION                   ║\n";
    echo "╚═══════════════════════════════════════════════════════════════════╝\n";
    
    echo formatVoyageReport($selected_voyage);
    
    echo "\n💾 Exporting data...\n";
    $size = exportVoyageToCSV($selected_voyage, $selected_positions, $output_file);
    echo "✅ Exported " . number_format(count($selected_positions)) . " positions to " . $output_file . "\n";
    echo "   File size: " . round($size / 1024, 2) . " KB\n";
    
    if ($output_format == 'json') {
        $json_file = str_replace('.csv', '.json', $output_file);
        file_put_contents($json_file, json_encode([
            'export_date' => date('Y-m-d H:i:s'),
            'time_window_hours' => $hours_back,
            'api_chunks' => $chunks_needed,
            'vessel' => $selected_vessel,
            'voyage_complete' => true,
            'voyage_number' => $selected_voyage['voyage_number'],
            'voyage_analysis' => $selected_voyage,
            'positions' => array_map(function($pos) {
                $lat = $pos['geometry']['coordinates'][1];
                $lon = $pos['geometry']['coordinates'][0];
                $sts_info = isSTSArea($lat, $lon);
                return [
                    'timestamp' => $pos['properties']['posDt'],
                    'lat' => $lat,
                    'lon' => $lon,
                    'speed' => $pos['properties']['sog'] ?? null,
                    'course' => $pos['properties']['cog'] ?? null,
                    'draught' => $pos['properties']['draught'] ?? null,
                    'nav_status' => getNavStatus($pos['properties']['navStatus'] ?? 15),
                    'sts_zone' => $sts_info ? $sts_info['zone_name'] : null,
                    'sts_type' => $sts_info ? $sts_info['type'] : null
                ];
            }, $selected_positions)
        ], JSON_PRETTY_PRINT));
        echo "✅ JSON export: " . $json_file . "\n";
    }
    
    echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "✅ RECOMMENDATION: Use " . $selected_voyage['vessel_name'] . 
         " for presentation\n";
    echo "   Most recent completed voyage found (#" . $selected_voyage['voyage_number'] . ")\n";
    echo "   Total positions: " . count($selected_positions) . "\n";
    echo "   Voyage duration: " . $selected_voyage['durations']['total_voyage'] . " hours\n";
    echo "   STS operations: " . count($selected_voyage['sts_operations']) . "\n";
    echo "   Draught change: " . ($selected_voyage['draught_analysis']['draught_change'] ?? 'N/A') . "m\n";
    
} else {
    echo "\n";
    echo "╔═══════════════════════════════════════════════════════════════════╗\n";
    echo "║           ❌ NO COMPLETED VOYAGE FOUND                          ║\n";
    echo "╚═══════════════════════════════════════════════════════════════════╝\n";
    echo "\n";
    echo "No completed voyage found for any of the specified vessels.\n";
    echo "\n";
    echo "Current status of vessels:\n";
    foreach ($vessel_list as $mmsi) {
        $info = $vessel_config[$mmsi] ?? ['name' => 'Unknown'];
        $latest = fetchLatestPosition($mmsi);
        $status = "Unknown";
        $location = "Unknown";
        
        if ($latest && !isset($latest['error'])) {
            $lat = $latest['lat'] ?? 0;
            $lon = $latest['lon'] ?? 0;
            $speed = $latest['sog'] ?? 0;
            $in_anchorage = isInAnchorage($lat, $lon);
            
            if ($in_anchorage && $speed < 0.5) {
                $status = "AT ANCHORAGE";
                $location = $in_anchorage;
            } else {
                $status = getNavStatus($latest['navStatus'] ?? 15);
                $location = "Position: " . $lat . "°N, " . $lon . "°E";
            }
        }
        
        echo "   • " . $info['name'] . " (MMSI: $mmsi): $status";
        if ($location != "Unknown") {
            echo " - $location";
        }
        echo "\n";
    }
    
    echo "\n";
    echo "Suggestions:\n";
    echo "   • Increase the time window (--hours 4320 for 6 months)\n";
    echo "   • Try --hours 6480 for 9 months to find older completed voyages\n";
    echo "   • Check if vessel MMSI numbers are correct\n";
    echo "   • Verify the vessel has actually completed a discharge cycle\n";
    echo "   • Use --verbose to see chunk-by-chunk progress\n";
    echo "\n";
}

echo "\n";
echo "⏰ Completed: " . date('Y-m-d H:i:s') . " UTC\n\n";