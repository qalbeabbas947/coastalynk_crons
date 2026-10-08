#!/usr/bin/env php
<?php
/**
 * Real-Time AIS Data Extraction for Specific Vessels
 * Usage: php realtime_ais.php --vessel MMSI --hours 168 --output csv
 * 
 * Features:
 * - Priority-based vessel selection (first with complete voyage)
 * - 30-minute anchorage confirmation
 * - Comprehensive Nigerian STS zone detection
 * - AIS gap detection (no smoothing)
 * - Complete voyage cycle reconstruction
 * 
 * Examples:
 *   php realtime_ais.php --vessel 310842000,352898728 --hours 720
 *   php realtime_ais.php --vessel 310842000 --hours 336 --output voyage.csv
 *   php realtime_ais.php --vessel 310842000 --hours 720 --output voyage-310842000.csv
 *   php realtime_ais.php --vessel 352898728 --hours 720 --output voyage-352898728.csv
 *   php realtime_ais.php --vessel 310842000,352898728 --hours 168 --format json
 */

// ============================================================
// CONFIGURATION
// ============================================================

define('AIS_API_KEY', 'dnh6YU1yelh0bXdxZ09EYldqem9ZSnhLN2ExdmpIc1k6ZnI0TzZtZ09sbzJONWVNNjRLZU0zSUtLMjFwSm8tc1J5ZFZaU05YcjlPWjFMeUZUN2FnRjFhbUkxbHRpZnA1Ng==');
define('AIS_API_URL', 'https://api.kpler.com/v2/maritime/ais-historical');
define('AIS_REALTIME_URL', 'https://api.kpler.com/v2/maritime/ais-latest');

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
// COMPREHENSIVE NIGERIAN STS ZONES
// ============================================================

$sts_zones = [
    // LAGOS OFFSHORE STS ZONES
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
    'Lagos Offshore STS Zone C' => [
        'lat_range' => [6.150, 6.300],
        'lon_range' => [3.500, 3.700],
        'description' => 'LPG STS area near Lagos',
        'type' => 'LPG STS'
    ],
    'Lagos Outer STS Zone' => [
        'lat_range' => [6.050, 6.200],
        'lon_range' => [3.250, 3.450],
        'description' => 'Deep water STS for very large vessels',
        'type' => 'Deep Water STS'
    ],
    
    // BONNY OFFSHORE STS ZONES
    'Bonny STS Zone' => [
        'lat_range' => [4.100, 4.350],
        'lon_range' => [6.800, 7.100],
        'description' => 'Bonny River STS area for crude oil exports',
        'type' => 'Crude Oil STS'
    ],
    'Bonny Deep STS Zone' => [
        'lat_range' => [4.000, 4.200],
        'lon_range' => [7.000, 7.300],
        'description' => 'Deep water STS for Bonny terminal exports',
        'type' => 'Deep Water STS'
    ],
    
    // CALABAR OFFSHORE STS ZONES
    'Calabar STS Zone' => [
        'lat_range' => [4.700, 4.950],
        'lon_range' => [8.200, 8.500],
        'description' => 'Calabar STS area for product and crude transfers',
        'type' => 'Mixed STS'
    ],
    'Calabar Deep STS Zone' => [
        'lat_range' => [4.500, 4.750],
        'lon_range' => [8.400, 8.700],
        'description' => 'Deep water STS for Calabar offshore',
        'type' => 'Deep Water STS'
    ],
    
    // ONNE OFFSHORE STS ZONES
    'Onne STS Zone' => [
        'lat_range' => [4.550, 4.750],
        'lon_range' => [7.050, 7.300],
        'description' => 'Onne STS area for industrial and product transfers',
        'type' => 'Product STS'
    ],
    'Onne Outer STS Zone' => [
        'lat_range' => [4.450, 4.650],
        'lon_range' => [7.200, 7.450],
        'description' => 'Outer Onne STS for larger vessels',
        'type' => 'Crude Oil STS'
    ],
    
    // ESCRAVOS OFFSHORE STS ZONES
    'Escravos STS Zone' => [
        'lat_range' => [5.300, 5.550],
        'lon_range' => [5.000, 5.300],
        'description' => 'Escravos STS area for crude and product transfers',
        'type' => 'Crude Oil STS'
    ],
    'Escravos Deep STS Zone' => [
        'lat_range' => [5.200, 5.450],
        'lon_range' => [4.800, 5.100],
        'description' => 'Deep water STS for Escravos terminal',
        'type' => 'Deep Water STS'
    ],
    
    // FORCADOS OFFSHORE STS ZONES
    'Forcados STS Zone' => [
        'lat_range' => [5.250, 5.450],
        'lon_range' => [5.300, 5.600],
        'description' => 'Forcados STS area for crude oil exports',
        'type' => 'Crude Oil STS'
    ],
    'Forcados Deep STS Zone' => [
        'lat_range' => [5.150, 5.350],
        'lon_range' => [5.450, 5.750],
        'description' => 'Deep water STS for Forcados terminal',
        'type' => 'Deep Water STS'
    ],
    
    // BRASS OFFSHORE STS ZONES
    'Brass STS Zone' => [
        'lat_range' => [4.200, 4.450],
        'lon_range' => [6.400, 6.700],
        'description' => 'Brass STS area for crude oil transfers',
        'type' => 'Crude Oil STS'
    ],
    'Brass Deep STS Zone' => [
        'lat_range' => [4.100, 4.350],
        'lon_range' => [6.500, 6.800],
        'description' => 'Deep water STS for Brass terminal',
        'type' => 'Deep Water STS'
    ],
    
    // PENNINGTON OFFSHORE STS ZONES
    'Pennington STS Zone' => [
        'lat_range' => [4.500, 4.750],
        'lon_range' => [6.200, 6.500],
        'description' => 'Pennington STS area for product transfers',
        'type' => 'Product STS'
    ],
    'Pennington Deep STS Zone' => [
        'lat_range' => [4.400, 4.650],
        'lon_range' => [6.300, 6.600],
        'description' => 'Deep water STS for Pennington area',
        'type' => 'Deep Water STS'
    ],
    
    // AKPO OFFSHORE STS ZONES (FPSO)
    'Akpo STS Zone' => [
        'lat_range' => [4.300, 4.500],
        'lon_range' => [6.700, 7.000],
        'description' => 'Akpo FPSO STS area for condensate transfers',
        'type' => 'Condensate STS'
    ],
    
    // EGINA OFFSHORE STS ZONES (FPSO)
    'Egina STS Zone' => [
        'lat_range' => [4.100, 4.350],
        'lon_range' => [6.500, 6.800],
        'description' => 'Egina FPSO STS area for crude transfers',
        'type' => 'Crude Oil STS'
    ],
    
    // BONGA OFFSHORE STS ZONES (FPSO)
    'Bonga STS Zone' => [
        'lat_range' => [5.800, 6.100],
        'lon_range' => [3.800, 4.200],
        'description' => 'Bonga FPSO STS area for crude transfers',
        'type' => 'Crude Oil STS'
    ],
    'Bonga Deep STS Zone' => [
        'lat_range' => [5.700, 6.000],
        'lon_range' => [4.000, 4.400],
        'description' => 'Deep water STS for Bonga FPSO area',
        'type' => 'Deep Water STS'
    ],
    
    // ERHA OFFSHORE STS ZONES (FPSO)
    'Erha STS Zone' => [
        'lat_range' => [4.300, 4.550],
        'lon_range' => [6.500, 6.800],
        'description' => 'Erha FPSO STS area for crude transfers',
        'type' => 'Crude Oil STS'
    ],
    
    // USAN OFFSHORE STS ZONES (FPSO)
    'Usan STS Zone' => [
        'lat_range' => [4.100, 4.350],
        'lon_range' => [6.100, 6.400],
        'description' => 'Usan FPSO STS area for crude transfers',
        'type' => 'Crude Oil STS'
    ],
    
    // AGBAMI OFFSHORE STS ZONES (FPSO)
    'Agbami STS Zone' => [
        'lat_range' => [4.250, 4.500],
        'lon_range' => [6.800, 7.100],
        'description' => 'Agbami FPSO STS area for crude transfers',
        'type' => 'Crude Oil STS'
    ],
    
    // OKWORI OFFSHORE STS ZONES (FPSO)
    'Okwori STS Zone' => [
        'lat_range' => [5.000, 5.250],
        'lon_range' => [4.500, 4.800],
        'description' => 'Okwori FPSO STS area for crude transfers',
        'type' => 'Crude Oil STS'
    ],
    
    // ZAFIRO OFFSHORE STS ZONES (FPSO)
    'Zafiro STS Zone' => [
        'lat_range' => [4.550, 4.800],
        'lon_range' => [6.500, 6.800],
        'description' => 'Zafiro FPSO STS area for crude transfers',
        'type' => 'Crude Oil STS'
    ],
    
    // NIGERIAN EEZ TRANSIT STS ZONES
    'Eastern EEZ STS Zone' => [
        'lat_range' => [4.500, 5.000],
        'lon_range' => [7.500, 8.500],
        'description' => 'Eastern EEZ STS area for multiple transfers',
        'type' => 'Mixed STS'
    ],
    'Central EEZ STS Zone' => [
        'lat_range' => [4.500, 5.500],
        'lon_range' => [5.000, 6.500],
        'description' => 'Central EEZ STS area for crude transfers',
        'type' => 'Crude Oil STS'
    ],
    'Western EEZ STS Zone' => [
        'lat_range' => [4.500, 6.000],
        'lon_range' => [2.500, 4.000],
        'description' => 'Western EEZ STS area for product and crude transfers',
        'type' => 'Mixed STS'
    ],
    
    // SPECIFIC LAGOS STS LOCATIONS
    'Lagos Fairway STS' => [
        'lat_range' => [6.150, 6.300],
        'lon_range' => [3.350, 3.550],
        'description' => 'Lagos fairway STS area',
        'type' => 'Product STS'
    ],
    'Lagos Anchorage STS' => [
        'lat_range' => [6.250, 6.400],
        'lon_range' => [3.400, 3.600],
        'description' => 'STS operations near Lagos anchorage',
        'type' => 'Mixed STS'
    ],
    
    // BAYELSA OFFSHORE STS
    'Bayelsa STS Zone' => [
        'lat_range' => [4.000, 4.300],
        'lon_range' => [5.800, 6.200],
        'description' => 'Bayelsa offshore STS area',
        'type' => 'Crude Oil STS'
    ],
    
    // DELTA OFFSHORE STS
    'Delta STS Zone' => [
        'lat_range' => [5.000, 5.400],
        'lon_range' => [4.500, 5.000],
        'description' => 'Delta offshore STS area',
        'type' => 'Crude Oil STS'
    ],
    'Delta Deep STS Zone' => [
        'lat_range' => [4.800, 5.200],
        'lon_range' => [4.200, 4.700],
        'description' => 'Deep water STS for Delta area',
        'type' => 'Deep Water STS'
    ],
    
    // RIVERS OFFSHORE STS
    'Rivers STS Zone' => [
        'lat_range' => [4.300, 4.700],
        'lon_range' => [6.800, 7.400],
        'description' => 'Rivers offshore STS area',
        'type' => 'Mixed STS'
    ],
    
    // AKWA IBOM OFFSHORE STS
    'Akwa Ibom STS Zone' => [
        'lat_range' => [4.500, 4.800],
        'lon_range' => [7.800, 8.200],
        'description' => 'Akwa Ibom offshore STS area',
        'type' => 'Crude Oil STS'
    ],
    
    // CROSS RIVER OFFSHORE STS
    'Cross River STS Zone' => [
        'lat_range' => [4.600, 4.900],
        'lon_range' => [8.400, 8.800],
        'description' => 'Cross River offshore STS area',
        'type' => 'Mixed STS'
    ],
    
    // ONDO OFFSHORE STS
    'Ondo STS Zone' => [
        'lat_range' => [5.500, 6.000],
        'lon_range' => [4.000, 4.500],
        'description' => 'Ondo offshore STS area',
        'type' => 'Crude Oil STS'
    ]
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

// Parse arguments
$vessel_mmsis = getArgValue('--vessel', '310842000,352898728');
$hours_back = (int) getArgValue('--hours', '720');
$output_file = getArgValue('--output', 'voyage_analysis.csv');
$output_format = getArgValue('--format', 'csv');
$verbose = hasArg('--verbose');
$min_positions = (int) getArgValue('--min-positions', '50');
$list_sts = hasArg('--list-sts');

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

/**
 * Fetch latest AIS position for a vessel by MMSI
 */
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

/**
 * Fetch historical AIS data for a vessel by MMSI
 */
function fetchVesselHistory($mmsi, $hours_back) {
    $end_time = date('Y-m-d\TH:i:s.000\Z');
    $start_time = date('Y-m-d\TH:i:s.000\Z', strtotime("-$hours_back hours"));
    
    $filter = "mmsi = $mmsi AND posDt BETWEEN '$start_time' AND '$end_time'";
    
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
    curl_close($ch);
    
    if ($http_code !== 200) {
        return ['error' => "HTTP $http_code"];
    }
    
    $data = json_decode($response, true);
    return $data['features'] ?? [];
}

// ============================================================
// GEOSPATIAL FUNCTIONS
// ============================================================

/**
 * Point-in-polygon check for WKT POLYGON
 */
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

/**
 * Check if position is in any anchorage polygon
 */
function isInAnchorage($lat, $lon) {
    global $anchorage_polygons;
    foreach ($anchorage_polygons as $name => $wkt) {
        if (pointInPolygon($lat, $lon, $wkt)) {
            return $name;
        }
    }
    return false;
}

/**
 * Check if position is in any berth polygon
 */
function isInBerth($lat, $lon) {
    global $berth_polygons;
    foreach ($berth_polygons as $name => $wkt) {
        if (pointInPolygon($lat, $lon, $wkt)) {
            return $name;
        }
    }
    return false;
}

/**
 * Comprehensive check if position indicates STS operation
 * Now includes all Nigerian STS zones
 */
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

/**
 * Get all STS zones that a position might be in
 */
function getAllSTSZones($lat, $lon) {
    global $sts_zones;
    $zones = [];
    
    foreach ($sts_zones as $zone_name => $zone) {
        if ($lat >= $zone['lat_range'][0] && $lat <= $zone['lat_range'][1] &&
            $lon >= $zone['lon_range'][0] && $lon <= $zone['lon_range'][1]) {
            $zones[] = [
                'zone_name' => $zone_name,
                'type' => $zone['type'],
                'description' => $zone['description']
            ];
        }
    }
    return $zones;
}

// ============================================================
// VOYAGE ANALYSIS FUNCTIONS
// ============================================================

/**
 * Find AIS gaps (positions > 30 minutes apart)
 */
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

/**
 * Enhanced STS detection with comprehensive Nigerian zones
 */
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
        
        // Check for STS condition: in STS zone, speed < 0.5 kn, and low nav status
        if ($sts_info && $speed < 0.5) {
            $nav_status = (int)($props['navStatus'] ?? 15);
            // STS typically happens with "Under way using engine" or "At anchor"
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
            // Confirm STS if at least 3 positions and duration > 30 min
            $duration = strtotime($props['posDt']) - strtotime($potential_sts['start_time']);
            if ($duration > 1800) { // At least 30 minutes
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

/**
 * Find anchorage arrival with 30-minute confirmation
 */
function findAnchorageArrival($positions, $start_index = 0) {
    $anchorage_candidates = [];
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
                if ($duration >= 1800) { // 30 minutes minimum
                    return [
                        'timestamp' => $anchor_start,
                        'position' => $anchor_position,
                        'location' => $anchor_location,
                        'confirmed_by' => $timestamp,
                        'duration_minutes' => $duration / 60,
                        'index' => $anchor_start_idx
                    ];
                }
                // Reset if not long enough
                $consecutive_anchor = 0;
                $anchor_start = null;
                $anchor_start_idx = null;
            }
        }
    }
    
    return null;
}

/**
 * Find load point departure
 */
function findLoadPointDeparture($positions, $start_index = 0) {
    $in_port = false;
    $departure_found = false;
    
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
        
        // Check if at berth, anchorage, or doing STS
        if (($nav_status == 5 || $nav_status == 1 || $in_berth || $in_anchorage || $sts_info) && $speed < 0.5) {
            $in_port = true;
        }
        
        // Departure when leaving with speed > 0.5
        if ($in_port && $nav_status == 0 && $speed >= 0.5) {
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

/**
 * Find berth arrival
 */
function findBerthArrival($positions, $start_index = 0) {
    for ($i = $start_index; $i < count($positions); $i++) {
        $props = $positions[$i]['properties'];
        $coords = $positions[$i]['geometry']['coordinates'];
        $nav_status = (int)($props['navStatus'] ?? 15);
        $speed = (float)($props['sog'] ?? 0);
        $lat = $coords[1];
        $lon = $coords[0];
        
        $in_berth = isInBerth($lat, $lon);
        
        if (($nav_status == 5 || $in_berth) && $speed < 0.5) {
            return [
                'timestamp' => $props['posDt'],
                'lat' => $lat,
                'lon' => $lon,
                'draught' => $props['draught'] ?? null,
                'berth_name' => isInBerth($lat, $lon) ?: 'Unknown Berth',
                'nav_status' => getNavStatus($nav_status),
                'index' => $i
            ];
        }
    }
    
    return null;
}

/**
 * Find discharge/departure
 */
function findDischargeDeparture($positions, $start_index = 0) {
    $at_berth = false;
    $last_berth_position = null;
    $last_berth_draught = null;
    
    for ($i = $start_index; $i < count($positions); $i++) {
        $props = $positions[$i]['properties'];
        $coords = $positions[$i]['geometry']['coordinates'];
        $nav_status = (int)($props['navStatus'] ?? 15);
        $speed = (float)($props['sog'] ?? 0);
        $lat = $coords[1];
        $lon = $coords[0];
        $draught = (float)($props['draught'] ?? 0);
        
        $in_berth = isInBerth($lat, $lon);
        
        if (($nav_status == 5 || $in_berth) && $speed < 0.5) {
            $at_berth = true;
            $last_berth_position = ['lat' => $lat, 'lon' => $lon];
            $last_berth_draught = $draught;
            $last_berth_time = $props['posDt'];
        }
        
        if ($at_berth && $nav_status == 0 && $speed >= 0.5 && !$in_berth) {
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
                'index' => $i
            ];
        }
    }
    
    return null;
}

/**
 * Analyze voyage for complete cycle
 */
function analyzeCompleteVoyage($positions, $vessel_info) {
    if (count($positions) < 30) {
        return ['error' => 'Insufficient positions'];
    }
    
    // Sort positions by timestamp
    usort($positions, function($a, $b) {
        return strtotime($a['properties']['posDt']) - strtotime($b['properties']['posDt']);
    });
    
    // Find AIS gaps
    $gaps = findAISGaps($positions);
    $has_significant_gaps = false;
    foreach ($gaps as $gap) {
        if ($gap['duration_minutes'] > 60) {
            $has_significant_gaps = true;
            break;
        }
    }
    
    $voyage = [
        'vessel_name' => $vessel_info['name'],
        'mmsi' => $vessel_info['mmsi'],
        'imo' => $vessel_info['imo'],
        'total_positions' => count($positions),
        'time_range' => [
            'start' => $positions[0]['properties']['posDt'],
            'end' => end($positions)['properties']['posDt']
        ],
        'gaps' => $gaps,
        'has_significant_gaps' => $has_significant_gaps,
        'events' => []
    ];
    
    // Find load point departure
    $load_departure = findLoadPointDeparture($positions);
    if ($load_departure) {
        $voyage['events']['load_point_departure'] = $load_departure;
        $start_index = $load_departure['index'];
    } else {
        $start_index = 0;
        $voyage['events']['load_point_departure'] = null;
    }
    
    // Find anchorage arrival
    $anchorage_arrival = findAnchorageArrival($positions, $start_index);
    if ($anchorage_arrival) {
        $voyage['events']['anchorage_arrival'] = $anchorage_arrival;
        $start_index = $anchorage_arrival['index'];
    } else {
        $voyage['events']['anchorage_arrival'] = null;
    }
    
    // Find berth arrival
    $berth_arrival = findBerthArrival($positions, $start_index);
    if ($berth_arrival) {
        $voyage['events']['berth_arrival'] = $berth_arrival;
        $start_index = $berth_arrival['index'];
    } else {
        $voyage['events']['berth_arrival'] = null;
    }
    
    // Find discharge departure
    $discharge_departure = findDischargeDeparture($positions, $start_index);
    if ($discharge_departure) {
        $voyage['events']['discharge_departure'] = $discharge_departure;
    } else {
        $voyage['events']['discharge_departure'] = null;
    }
    
    // Detect STS operations
    $sts_ops = detectSTSOperations($positions);
    $voyage['sts_operations'] = $sts_ops;
    
    // Check if complete voyage
    $voyage['is_complete'] = (
        $voyage['events']['load_point_departure'] !== null &&
        $voyage['events']['anchorage_arrival'] !== null &&
        $voyage['events']['berth_arrival'] !== null &&
        $voyage['events']['discharge_departure'] !== null
    );
    
    // Calculate durations
    if ($voyage['is_complete']) {
        $load_time = strtotime($voyage['events']['load_point_departure']['timestamp']);
        $anchor_time = strtotime($voyage['events']['anchorage_arrival']['timestamp']);
        $berth_time = strtotime($voyage['events']['berth_arrival']['timestamp']);
        $depart_time = strtotime($voyage['events']['discharge_departure']['timestamp']);
        
        $voyage['durations'] = [
            'transit_load_to_anchorage' => round(($anchor_time - $load_time) / 3600, 2),
            'anchorage_dwell' => round(($berth_time - $anchor_time) / 3600, 2),
            'berth_duration' => round(($depart_time - $berth_time) / 3600, 2),
            'total_voyage' => round(($depart_time - $load_time) / 3600, 2)
        ];
        
        // Laden vs post-discharge draught
        $laden_draught = $voyage['events']['load_point_departure']['draught'] ?? null;
        $post_discharge_draught = $voyage['events']['discharge_departure']['draught'] ?? null;
        $last_berth_draught = $voyage['events']['discharge_departure']['last_berth_draught'] ?? null;
        
        $voyage['draught_analysis'] = [
            'laden_draught' => $laden_draught,
            'post_discharge_draught' => $post_discharge_draught,
            'last_berth_draught' => $last_berth_draught,
            'draught_change' => ($laden_draught && $post_discharge_draught) ? 
                round($laden_draught - $post_discharge_draught, 2) : null
        ];
    }
    
    return $voyage;
}

// ============================================================
// OUTPUT FORMATTING FUNCTIONS
// ============================================================

/**
 * Format STS zone summary
 */
function formatSTSSummary() {
    global $sts_zones;
    
    $output = [];
    $output[] = "\n";
    $output[] = "╔═══════════════════════════════════════════════════════════════════╗";
    $output[] = "║     NIGERIAN STS ZONES - COMPREHENSIVE LIST                     ║";
    $output[] = "╚═══════════════════════════════════════════════════════════════════╝\n";
    
    $types = [];
    foreach ($sts_zones as $name => $zone) {
        $types[$zone['type']][] = $name;
    }
    
    $output[] = "📊 STS ZONE SUMMARY:";
    $output[] = "───────────────────────────────────────────────────────────────────";
    $output[] = "   Total STS Zones: " . count($sts_zones);
    $output[] = "";
    
    $output[] = "📋 STS ZONES BY TYPE:";
    foreach ($types as $type => $zones) {
        $output[] = "   • " . $type . ": " . count($zones) . " zones";
    }
    $output[] = "";
    
    $output[] = "🗺️ DETAILED ZONE LIST:";
    $output[] = "───────────────────────────────────────────────────────────────────";
    foreach ($sts_zones as $name => $zone) {
        $output[] = "   📍 " . $name;
        $output[] = "      Type: " . $zone['type'];
        $output[] = "      Description: " . $zone['description'];
        $output[] = "      Coordinates: " . $zone['lat_range'][0] . "°N-" . $zone['lat_range'][1] . "°N, " .
                    $zone['lon_range'][0] . "°E-" . $zone['lon_range'][1] . "°E";
        $output[] = "";
    }
    
    return implode("\n", $output);
}

/**
 * Format voyage analysis for display
 */
function formatVoyageReport($voyage) {
    if (isset($voyage['error'])) {
        return "❌ Error: " . $voyage['error'] . "\n";
    }
    
    $output = [];
    
    $output[] = "\n";
    $output[] = "╔═══════════════════════════════════════════════════════════════════╗";
    $output[] = "║  VOYAGE ANALYSIS: " . str_pad($voyage['vessel_name'] . " (MMSI: " . $voyage['mmsi'] . ")", 61) . "║";
    $output[] = "╚═══════════════════════════════════════════════════════════════════╝\n";
    
    $output[] = "📊 VOYAGE STATUS: " . ($voyage['is_complete'] ? "✅ COMPLETE" : "⚠️ INCOMPLETE");
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
    
    // STS Operations
    if (!empty($voyage['sts_operations'])) {
        $output[] = "🛳️ SHIP-TO-SHIP (STS) OPERATIONS DETECTED:";
        $output[] = "───────────────────────────────────────────────────────────────────";
        foreach ($voyage['sts_operations'] as $sts) {
            if ($sts['confirmed'] ?? false) {
                $output[] = "   📍 " . $sts['zone_name'];
                $output[] = "      Type: " . $sts['zone_type'];
                $output[] = "      Description: " . $sts['description'];
                $output[] = "      Start: " . $sts['start_time'];
                $output[] = "      End: " . $sts['end_time'];
                $output[] = "      Duration: " . round($sts['duration_hours'], 2) . " hours";
                $output[] = "      Draught Change: " . $sts['draught_change'] . "m";
                $output[] = "      Nav Status: " . $sts['nav_status'];
                $output[] = "";
            }
        }
    }
    
    // Events
    $output[] = "📋 VOYAGE EVENTS (UTC):";
    $output[] = "───────────────────────────────────────────────────────────────────";
    
    $events = [
        'load_point_departure' => '🚢 LOAD POINT DEPARTURE',
        'anchorage_arrival' => '⚓ ANCHORAGE ARRIVAL',
        'berth_arrival' => '🏗️ BERTH ARRIVAL',
        'discharge_departure' => '🚢 DISCHARGE/DEPARTURE'
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
                if (isset($event['sts_info']['type'])) {
                    $output[] = "   STS Type: " . $event['sts_info']['type'];
                }
            }
            if (isset($event['confirmed_by'])) {
                $output[] = "   ✅ Confirmed: " . $event['confirmed_by'] . " (30+ min)";
            }
        } else {
            $output[] = "\n❌ " . $label . ": NOT FOUND";
        }
    }
    
    // Durations
    if ($voyage['is_complete'] && isset($voyage['durations'])) {
        $output[] = "\n";
        $output[] = "⏱️ DURATION SUMMARY:";
        $output[] = "───────────────────────────────────────────────────────────────────";
        $output[] = "   Transit (Load → Anchorage): " . $voyage['durations']['transit_load_to_anchorage'] . " hours";
        $output[] = "   Anchorage Dwell: " . $voyage['durations']['anchorage_dwell'] . " hours";
        $output[] = "   Berth Duration: " . $voyage['durations']['berth_duration'] . " hours";
        $output[] = "   Total Voyage: " . $voyage['durations']['total_voyage'] . " hours";
    }
    
    // Draught Analysis
    if ($voyage['is_complete'] && isset($voyage['draught_analysis'])) {
        $da = $voyage['draught_analysis'];
        $output[] = "\n";
        $output[] = "📏 DRAUGHT ANALYSIS:";
        $output[] = "───────────────────────────────────────────────────────────────────";
        $output[] = "   Laden Draught (at departure): " . ($da['laden_draught'] ?? 'N/A') . "m";
        $output[] = "   Last Berth Draught: " . ($da['last_berth_draught'] ?? 'N/A') . "m";
        $output[] = "   Post-Discharge Draught: " . ($da['post_discharge_draught'] ?? 'N/A') . "m";
        if ($da['draught_change'] !== null) {
            $output[] = "   Draught Change: " . $da['draught_change'] . "m " . 
                ($da['draught_change'] > 0 ? "(discharged)" : "(loaded)");
        }
    }
    
    $output[] = "\n";
    $output[] = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
    
    return implode("\n", $output);
}

/**
 * Export voyage data to CSV with STS zones included
 */
function exportVoyageToCSV($voyage, $positions, $filename) {
    $fp = fopen($filename, 'w');
    fwrite($fp, "\xEF\xBB\xBF");
    
    // Enhanced header with STS information
    fputcsv($fp, [
        'VESSEL', 'MMSI', 'IMO', 'TIMESTAMP', 'LAT', 'LON', 'SPEED_KN',
        'COURSE_DEG', 'DRAUGHT_M', 'NAV_STATUS', 'DESTINATION',
        'IN_ANCHORAGE', 'IN_BERTH', 'STS_ZONE_NAME', 'STS_TYPE', 
        'STS_DESCRIPTION', 'EVENT_TYPE', 'GAP_DETECTED'
    ]);
    
    // Find event indexes for marking
    $event_indexes = [];
    foreach ($voyage['events'] as $event_type => $event) {
        if ($event && isset($event['index'])) {
            $event_indexes[$event['index']] = $event_type;
        }
    }
    
    // Output all positions
    foreach ($positions as $i => $pos) {
        $props = $pos['properties'];
        $coords = $pos['geometry']['coordinates'];
        $lat = $coords[1];
        $lon = $coords[0];
        
        // Check for STS zone
        $sts_info = isSTSArea($lat, $lon);
        $sts_zone_name = $sts_info ? $sts_info['zone_name'] : '';
        $sts_type = $sts_info ? $sts_info['type'] : '';
        $sts_description = $sts_info ? $sts_info['description'] : '';
        
        // Check if position has a gap
        $has_gap = false;
        foreach ($voyage['gaps'] as $gap) {
            if ($gap['start'] == $props['posDt'] || $gap['end'] == $props['posDt']) {
                $has_gap = true;
                break;
            }
        }
        
        // Determine event type
        $event_type = '';
        if (isset($event_indexes[$i])) {
            $event_type = strtoupper(str_replace('_', ' ', $event_indexes[$i]));
        }
        
        fputcsv($fp, [
            $voyage['vessel_name'],
            $voyage['mmsi'],
            $voyage['imo'],
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
            $has_gap ? 'YES' : ''
        ]);
    }
    
    fclose($fp);
    return filesize($filename);
}

// ============================================================
// MAIN EXECUTION
// ============================================================

// Check if user wants to list STS zones
if ($list_sts) {
    echo formatSTSSummary();
    exit(0);
}

echo "\n";
echo "╔═══════════════════════════════════════════════════════════════════╗\n";
echo "║     COMPLETE VOYAGE EXTRACTION - Nigerian Waters                ║\n";
echo "║     With Comprehensive Nigerian STS Zone Detection              ║\n";
echo "╚═══════════════════════════════════════════════════════════════════╝\n\n";

echo "📋 Priority Vessels:\n";
foreach ($vessel_list as $mmsi) {
    $info = $vessel_config[$mmsi] ?? ['name' => 'Unknown', 'type' => 'Unknown'];
    echo "   " . ($info['priority'] ?? '?') . ". " . $info['name'] . 
         " (MMSI: $mmsi) - " . $info['type'] . "\n";
}
echo "\n⏰ Time window: Last " . $hours_back . " hours\n";
echo "📁 Output file: " . $output_file . "\n";
echo "📊 Min positions required: " . $min_positions . "\n";
echo "🗺️ STS Zones Loaded: " . count($GLOBALS['sts_zones']) . "\n\n";

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
        echo "   ✅ Current Position: " . ($latest['lat'] ?? 'N/A') . "°N, " . 
             ($latest['lon'] ?? 'N/A') . "°E\n";
        echo "   🚢 Status: " . getNavStatus($latest['navStatus'] ?? 15) . "\n";
        echo "   ⚡ Speed: " . ($latest['sog'] ?? 'N/A') . " kn\n";
        
        // Check if current position is in STS zone
        $sts_info = isSTSArea($latest['lat'] ?? 0, $latest['lon'] ?? 0);
        if ($sts_info) {
            echo "   🛳️ STS Zone: " . $sts_info['zone_name'] . " (" . $sts_info['type'] . ")\n";
        }
    } else {
        echo "   ⚠️ Could not fetch current position\n";
    }
    
    // Fetch historical data
    echo "\n📊 Fetching historical data (last $hours_back hours)...\n";
    $positions = fetchVesselHistory($mmsi, $hours_back);
    
    if (isset($positions['error'])) {
        echo "   ❌ API Error: " . $positions['error'] . "\n";
        continue;
    }
    
    if (count($positions) < $min_positions) {
        echo "   ⚠️ Insufficient positions: " . count($positions) . " (need $min_positions)\n";
        continue;
    }
    
    echo "   ✅ Found " . number_format(count($positions)) . " positions\n";
    
    // Analyze voyage
    echo "🔍 Analyzing voyage for complete cycle...\n";
    $voyage = analyzeCompleteVoyage($positions, $vessel_info);
    
    if (isset($voyage['error'])) {
        echo "   ❌ Analysis error: " . $voyage['error'] . "\n";
        continue;
    }
    
    // Display brief status
    echo "   📊 Voyage Status: " . ($voyage['is_complete'] ? "✅ COMPLETE" : "⚠️ INCOMPLETE") . "\n";
    echo "   📈 Positions: " . number_format($voyage['total_positions']) . "\n";
    echo "   ⏱️ Period: " . $voyage['time_range']['start'] . " → " . $voyage['time_range']['end'] . "\n";
    echo "   ⚠️ Gaps: " . count($voyage['gaps']) . " (" . 
         ($voyage['has_significant_gaps'] ? "significant" : "minor") . ")\n";
    echo "   🛳️ STS Operations: " . count($voyage['sts_operations']) . " detected\n";
    
    // Check if complete
    if ($voyage['is_complete']) {
        echo "\n   ✅ COMPLETE VOYAGE FOUND!\n";
        $selected_vessel = $mmsi;
        $selected_voyage = $voyage;
        $selected_positions = $positions;
        break;
    } else {
        echo "\n   ❌ Not a complete voyage. Checking next vessel...\n";
    }
}

// ============================================================
// OUTPUT RESULTS
// ============================================================

if ($selected_voyage && $selected_positions) {
    echo "\n";
    echo "╔═══════════════════════════════════════════════════════════════════╗\n";
    echo "║           ✅ SELECTED VESSEL FOR PRESENTATION                   ║\n";
    echo "╚═══════════════════════════════════════════════════════════════════╝\n";
    
    // Display full report
    echo formatVoyageReport($selected_voyage);
    
    // Export data
    echo "\n💾 Exporting data...\n";
    $size = exportVoyageToCSV($selected_voyage, $selected_positions, $output_file);
    echo "✅ Exported " . number_format(count($selected_positions)) . " positions to " . $output_file . "\n";
    echo "   File size: " . round($size / 1024, 2) . " KB\n";
    
    // JSON export if requested
    if ($output_format == 'json') {
        $json_file = str_replace('.csv', '.json', $output_file);
        file_put_contents($json_file, json_encode([
            'export_date' => date('Y-m-d H:i:s'),
            'vessel' => $selected_vessel,
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
         " (MMSI: " . $selected_voyage['mmsi'] . ") for presentation\n";
    echo "   Complete voyage found with " . count($selected_positions) . " positions\n";
    echo "   " . count($selected_voyage['sts_operations']) . " STS operations detected\n";
    
} else {
    echo "\n";
    echo "╔═══════════════════════════════════════════════════════════════════╗\n";
    echo "║           ❌ NO COMPLETE VOYAGE FOUND                           ║\n";
    echo "╚═══════════════════════════════════════════════════════════════════╝\n";
    echo "\n";
    echo "No complete voyage found for any of the specified vessels.\n";
    echo "Suggestions:\n";
    echo "   • Increase the time window (--hours 1440 for 60 days)\n";
    echo "   • Check vessel MMSI numbers\n";
    echo "   • Verify API connectivity\n";
    echo "   • Use --list-sts to see all STS zones\n";
    echo "\n";
}

echo "\n";
echo "⏰ Completed: " . date('Y-m-d H:i:s') . " UTC\n\n";