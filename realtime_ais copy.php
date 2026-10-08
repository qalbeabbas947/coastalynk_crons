<?php
/**
 * Real-Time AIS Data Extraction for Specific Vessels
 * Usage: php realtime_ais.php [--vessel IMO] [--hours 24] [--output csv]
 * 
 * Features:
 * - Real-time position tracking for specific vessels
 * - Voyage reconstruction for complete cycles
 * - Detailed timeline with navigation status changes
 * - Export to CSV with voyage metrics
 * 
 * Examples:
 *   php  -d memory_limit=2G realtime_ais.php --vessel 310842000 --hours 168
 *   php  -d memory_limit=2G realtime_ais.php --vessel 310842000 --hours 1440 --output voyage_data_310842000.csv
 *   php  -d memory_limit=2G realtime_ais.php --vessel 352898728 --hours 1440 --output voyage_data_352898728.csv
 *   php  -d memory_limit=2G realtime_ais.php --vessel 310842000 --hours 336 --format json
 */

// ============================================================
// CONFIGURATION
// ============================================================

define('AIS_API_KEY', 'dnh6YU1yelh0bXdxZ09EYldqem9ZSnhLN2ExdmpIc1k6ZnI0TzZtZ09sbzJONWVNNjRLZU0zSUtLMjFwSm8tc1J5ZFZaU05YcjlPWjFMeUZUN2FnRjFhbUkxbHRpZnA1Ng==');
define('AIS_API_URL', 'https://api.kpler.com/v2/maritime/ais-historical');
define('AIS_REALTIME_URL', 'https://api.kpler.com/v2/maritime/ais-latest');

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
$vessel_imos = getArgValue('--vessel', '310842000');
$hours_back = (int) getArgValue('--hours', '168'); // 7 days default
$output_file = getArgValue('--output', 'realtime_ais_export.csv');
$output_format = getArgValue('--format', 'csv'); // csv or json
$verbose = hasArg('--verbose');

// Parse vessel list
$vessel_list = array_map('trim', explode(',', $vessel_imos));

// ============================================================
// VESSEL INFORMATION
// ============================================================

$vessel_info = [
    '9937127' => [
        'name' => 'ALFRED TEMILE 10',
        'type' => 'LPG Tanker',
        'priority' => 1
    ],
    '9240433' => [
        'name' => 'SABAEK',
        'type' => 'Product/OBO Carrier',
        'priority' => 2
    ]
];

// ============================================================
// LOCATION POLYGONS (for anchorage detection)
// ============================================================

$anchorage_polygons = [
    'Lagos Outer Anchorage' => 'POLYGON((3.183 6.333, 3.6 6.333, 3.683 6.25, 3.6 6.083, 3.15 6.083, 3.083 6.2, 3.183 6.333))',
    'Bonny Anchorage' => 'POLYGON((6.9 4.3, 7.1 4.3, 7.1 4.1, 6.9 4.1, 6.9 4.3))',
    'Calabar Anchorage' => 'POLYGON((8.3 4.9, 8.5 4.9, 8.5 4.7, 8.3 4.7, 8.3 4.9))'
];

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
 * Fetch latest AIS position for a vessel
 */
function fetchLatestPosition($imo) {
    $ch = curl_init(AIS_REALTIME_URL . '?imo=' . $imo);
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
 * Fetch historical AIS data for a vessel
 */
function fetchVesselHistory($imo, $hours_back) {
    $end_time = date('Y-m-d\TH:i:s.000\Z');
    $start_time = date('Y-m-d\TH:i:s.000\Z', strtotime("-$hours_back hours"));
    
    echo $filter = "mmsi = $imo AND posDt BETWEEN '$start_time' AND '$end_time'";
    
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
// VOYAGE ANALYSIS FUNCTIONS
// ============================================================

/**
 * Detect if vessel is at anchorage
 */
function isAtAnchorage($lat, $lon, $speed) {
    global $anchorage_polygons;
    
    // Check speed first (must be < 0.5 kn for at least 30 min)
    if ($speed >= 0.5) {
        return false;
    }
    
    // Check if position is within any anchorage polygon
    foreach ($anchorage_polygons as $name => $wkt) {
        if (pointInPolygon($lat, $lon, $wkt)) {
            return $name;
        }
    }
    
    return false;
}

/**
 * Simple point-in-polygon check (simplified)
 */
function pointInPolygon($lat, $lon, $wkt) {
    // Extract coordinates from WKT POLYGON
    preg_match('/POLYGON\(\(([^)]+)\)\)/', $wkt, $matches);
    if (empty($matches)) return false;
    
    $coords = explode(',', $matches[1]);
    $polygon = [];
    foreach ($coords as $coord) {
        list($x, $y) = explode(' ', trim($coord));
        $polygon[] = [(float)$x, (float)$y];
    }
    
    // Ray casting algorithm
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
 * Analyze voyage and extract key events
 */
function analyzeVoyage($positions, $vessel_name) {
    if (empty($positions)) {
        return null;
    }
    
    $events = [];
    $previous_status = null;
    $anchorage_start = null;
    $is_at_anchorage = false;
    $anchorage_duration = 0;
    
    // Sort positions by timestamp
    usort($positions, function($a, $b) {
        return strtotime($a['properties']['posDt']) - strtotime($b['properties']['posDt']);
    });
    
    // Track status changes and key events
    foreach ($positions as $position) {
        $props = $position['properties'];
        $geom = $position['geometry'];
        $coords = $geom['coordinates'];
        $timestamp = $props['posDt'];
        $speed = (float)($props['sog'] ?? 0);
        $nav_status = (int)($props['navStatus'] ?? 15);
        $status_text = getNavStatus($nav_status);
        
        // Check for anchorage
        $anchorage = isAtAnchorage($coords[1], $coords[0], $speed);
        
        $event = [
            'timestamp' => $timestamp,
            'lat' => $coords[1],
            'lon' => $coords[0],
            'speed' => $speed,
            'course' => $props['cog'] ?? null,
            'heading' => $props['heading'] ?? null,
            'nav_status' => $status_text,
            'nav_code' => $nav_status,
            'draught' => $props['draught'] ?? null,
            'destination' => $props['destination'] ?? '',
            'anchorage' => $anchorage
        ];
        
        // Detect status changes
        if ($previous_status !== null && $previous_status !== $nav_status) {
            $events[] = [
                'type' => 'status_change',
                'from' => getNavStatus($previous_status),
                'to' => $status_text,
                'timestamp' => $timestamp,
                'position' => [$coords[1], $coords[0]]
            ];
        }
        
        // Detect anchorage arrival
        if ($anchorage && !$is_at_anchorage && $speed < 0.5) {
            $anchorage_start = $timestamp;
            $is_at_anchorage = true;
            $events[] = [
                'type' => 'anchorage_arrival',
                'location' => $anchorage,
                'timestamp' => $timestamp,
                'position' => [$coords[1], $coords[0]]
            ];
        }
        
        // Detect anchorage departure
        if (!$anchorage && $is_at_anchorage && $speed >= 0.5) {
            $is_at_anchorage = false;
            $anchorage_duration = strtotime($timestamp) - strtotime($anchorage_start);
            $events[] = [
                'type' => 'anchorage_departure',
                'location' => $anchorage,
                'timestamp' => $timestamp,
                'position' => [$coords[1], $coords[0]],
                'duration_hours' => round($anchorage_duration / 3600, 2)
            ];
        }
        
        $previous_status = $nav_status;
    }
    
    // Identify voyage milestones
    $milestones = identifyMilestones($events, $positions);
    
    return [
        'vessel' => $vessel_name,
        'total_positions' => count($positions),
        'events' => $events,
        'milestones' => $milestones,
        'duration' => [
            'start' => $positions[0]['properties']['posDt'],
            'end' => end($positions)['properties']['posDt']
        ]
    ];
}

/**
 * Identify key voyage milestones
 */
function identifyMilestones($events, $positions) {
    $milestones = [];
    
    // Find load point departure (first "Under way" after a period of "Moored" or "At anchor")
    $last_moored = null;
    $departure_found = false;
    
    foreach ($positions as $pos) {
        $nav = (int)($pos['properties']['navStatus'] ?? 15);
        $timestamp = $pos['properties']['posDt'];
        $coords = $pos['geometry']['coordinates'];
        
        if ($nav == 5 && !$departure_found) {
            $last_moored = $timestamp;
        }
        
        if ($nav == 0 && $last_moored !== null && !$departure_found) {
            $milestones['load_point_departure'] = [
                'timestamp' => $timestamp,
                'lat' => $coords[1],
                'lon' => $coords[0],
                'draught' => $pos['properties']['draught'] ?? null,
                'destination' => $pos['properties']['destination'] ?? ''
            ];
            $departure_found = true;
        }
    }
    
    // Find anchorage arrival from events
    foreach ($events as $event) {
        if ($event['type'] == 'anchorage_arrival') {
            $milestones['anchorage_arrival'] = [
                'timestamp' => $event['timestamp'],
                'location' => $event['location'],
                'position' => $event['position']
            ];
        }
        if ($event['type'] == 'anchorage_departure') {
            $milestones['anchorage_departure'] = [
                'timestamp' => $event['timestamp'],
                'location' => $event['location'],
                'position' => $event['position'],
                'duration_hours' => $event['duration_hours']
            ];
        }
    }
    
    // Find berth arrival (Moored status after being Under way)
    $found_underway = false;
    foreach ($positions as $pos) {
        $nav = (int)($pos['properties']['navStatus'] ?? 15);
        $timestamp = $pos['properties']['posDt'];
        $coords = $pos['geometry']['coordinates'];
        
        if ($nav == 0) {
            $found_underway = true;
        }
        
        if ($nav == 5 && $found_underway) {
            if (!isset($milestones['berth_arrival'])) {
                $milestones['berth_arrival'] = [
                    'timestamp' => $timestamp,
                    'lat' => $coords[1],
                    'lon' => $coords[0],
                    'draught' => $pos['properties']['draught'] ?? null
                ];
            }
        }
    }
    
    // Find discharge/departure (leaving berth)
    $last_berth = null;
    foreach ($positions as $pos) {
        $nav = (int)($pos['properties']['navStatus'] ?? 15);
        $timestamp = $pos['properties']['posDt'];
        $coords = $pos['geometry']['coordinates'];
        
        if ($nav == 5) {
            $last_berth = $timestamp;
            $last_draught = $pos['properties']['draught'] ?? null;
            $last_position = [$coords[1], $coords[0]];
        }
        
        if ($nav == 0 && $last_berth !== null && !isset($milestones['discharge_departure'])) {
            $milestones['discharge_departure'] = [
                'timestamp' => $timestamp,
                'lat' => $coords[1],
                'lon' => $coords[0],
                'draught' => $pos['properties']['draught'] ?? null,
                'last_draught_at_berth' => $last_draught,
                'last_position_at_berth' => $last_position
            ];
        }
    }
    
    return $milestones;
}

// ============================================================
// FORMATTING FUNCTIONS
// ============================================================

/**
 * Format voyage analysis for output
 */
function formatVoyageReport($analysis) {
    if (!$analysis || empty($analysis['milestones'])) {
        return "No complete voyage found in the specified time period.\n";
    }
    
    $milestones = $analysis['milestones'];
    $output = [];
    
    $output[] = "\n═══════════════════════════════════════════════════════════════";
    $output[] = "  VOYAGE ANALYSIS: " . $analysis['vessel'];
    $output[] = "═══════════════════════════════════════════════════════════════\n";
    
    // Voyage timeline
    $output[] = "📋 VOYAGE TIMELINE";
    $output[] = "───────────────────────────────────────────────────────────────";
    
    if (isset($milestones['load_point_departure'])) {
        $dep = $milestones['load_point_departure'];
        $output[] = "📍 LOAD POINT DEPARTURE";
        $output[] = "   Time: " . $dep['timestamp'];
        $output[] = "   Position: " . $dep['lat'] . "°N, " . $dep['lon'] . "°E";
        $output[] = "   Draught: " . ($dep['draught'] ?? 'N/A') . "m";
        $output[] = "   Destination: " . ($dep['destination'] ?: 'N/A');
        $output[] = "";
    }
    
    if (isset($milestones['anchorage_arrival'])) {
        $arr = $milestones['anchorage_arrival'];
        $output[] = "⚓ ANCHORAGE ARRIVAL";
        $output[] = "   Time: " . $arr['timestamp'];
        $output[] = "   Location: " . $arr['location'];
        $output[] = "   Position: " . $arr['position'][0] . "°N, " . $arr['position'][1] . "°E";
        $output[] = "";
    }
    
    if (isset($milestones['anchorage_departure'])) {
        $dep = $milestones['anchorage_departure'];
        $output[] = "⚓ ANCHORAGE DEPARTURE";
        $output[] = "   Time: " . $dep['timestamp'];
        $output[] = "   Duration: " . $dep['duration_hours'] . " hours";
        $output[] = "";
    }
    
    if (isset($milestones['berth_arrival'])) {
        $berth = $milestones['berth_arrival'];
        $output[] = "🏗️ BERTH ARRIVAL";
        $output[] = "   Time: " . $berth['timestamp'];
        $output[] = "   Position: " . $berth['lat'] . "°N, " . $berth['lon'] . "°E";
        $output[] = "   Draught: " . ($berth['draught'] ?? 'N/A') . "m";
        $output[] = "";
    }
    
    if (isset($milestones['discharge_departure'])) {
        $dep = $milestones['discharge_departure'];
        $output[] = "🚢 DISCHARGE/DEPARTURE";
        $output[] = "   Time: " . $dep['timestamp'];
        $output[] = "   Position: " . $dep['lat'] . "°N, " . $dep['lon'] . "°E";
        $output[] = "   Draught: " . ($dep['draught'] ?? 'N/A') . "m";
        if (isset($dep['last_draught_at_berth'])) {
            $output[] = "   Last Berth Draught: " . $dep['last_draught_at_berth'] . "m";
        }
        $output[] = "";
    }
    
    // Calculate durations
    $output[] = "⏱️ DURATION SUMMARY";
    $output[] = "───────────────────────────────────────────────────────────────";
    
    if (isset($milestones['load_point_departure']) && isset($milestones['anchorage_arrival'])) {
        $dep_time = strtotime($milestones['load_point_departure']['timestamp']);
        $arr_time = strtotime($milestones['anchorage_arrival']['timestamp']);
        $hours = round(($arr_time - $dep_time) / 3600, 2);
        $output[] = "   Transit (Load → Anchorage): " . $hours . " hours";
    }
    
    if (isset($milestones['anchorage_arrival']) && isset($milestones['anchorage_departure'])) {
        $arr_time = strtotime($milestones['anchorage_arrival']['timestamp']);
        $dep_time = strtotime($milestones['anchorage_departure']['timestamp']);
        $hours = round(($dep_time - $arr_time) / 3600, 2);
        $output[] = "   Anchorage Dwell: " . $hours . " hours";
    }
    
    if (isset($milestones['berth_arrival']) && isset($milestones['discharge_departure'])) {
        $arr_time = strtotime($milestones['berth_arrival']['timestamp']);
        $dep_time = strtotime($milestones['discharge_departure']['timestamp']);
        $hours = round(($dep_time - $arr_time) / 3600, 2);
        $output[] = "   Berth Duration: " . $hours . " hours";
    }
    
    if (isset($milestones['load_point_departure']) && isset($milestones['discharge_departure'])) {
        $dep_time = strtotime($milestones['load_point_departure']['timestamp']);
        $arr_time = strtotime($milestones['discharge_departure']['timestamp']);
        $hours = round(($arr_time - $dep_time) / 3600, 2);
        $output[] = "   Total Voyage: " . $hours . " hours";
    }
    
    $output[] = "";
    $output[] = "📊 DATA QUALITY";
    $output[] = "───────────────────────────────────────────────────────────────";
    $output[] = "   Total Positions: " . $analysis['total_positions'];
    $output[] = "   Gaps Detected: " . (count($analysis['gaps'] ?? []) > 0 ? count($analysis['gaps']) : "None");
    
    return implode("\n", $output);
}

/**
 * Export to CSV
 */
function exportToCSV($data, $filename) {
    $fp = fopen($filename, 'w');
    fwrite($fp, "\xEF\xBB\xBF"); // UTF-8 BOM
    
    fputcsv($fp, [
        'VESSEL', 'IMO', 'TIMESTAMP', 'LAT', 'LON', 'SPEED', 'COURSE',
        'HEADING', 'NAV_STATUS', 'DRAUGHT', 'DESTINATION', 'ANCHORAGE',
        'EVENT_TYPE'
    ]);
    
    foreach ($data as $row) {
        fputcsv($fp, [
            $row['vessel_name'],
            $row['imo'],
            $row['timestamp'],
            $row['lat'],
            $row['lon'],
            $row['speed'],
            $row['course'],
            $row['heading'],
            $row['nav_status'],
            $row['draught'],
            $row['destination'],
            $row['anchorage'] ?? '',
            $row['event_type'] ?? ''
        ]);
    }
    
    fclose($fp);
    return filesize($filename);
}

// ============================================================
// MAIN EXECUTION
// ============================================================

echo "\n";
echo "╔═══════════════════════════════════════════════════════════════════╗\n";
echo "║        REAL-TIME AIS DATA EXTRACTION                            ║\n";
echo "║     Specific Vessel Voyage Tracking                            ║\n";
echo "╚═══════════════════════════════════════════════════════════════════╝\n\n";

echo "📋 Vessels to track: " . implode(', ', $vessel_list) . "\n";
echo "⏰ Time window: Last " . $hours_back . " hours\n";
echo "📁 Output format: " . $output_format . "\n";
echo "📄 Output file: " . $output_file . "\n\n";

$all_voyages = [];
$export_data = [];

foreach ($vessel_list as $imo) {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "📡 Fetching data for Vessel: " . ($vessel_info[$imo]['name'] ?? $imo) . "\n";
    echo "   IMO: " . $imo . "\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    
    // Fetch latest position
    echo "🔄 Fetching latest position...\n";
    $latest = fetchLatestPosition($imo);
    if ($latest && !isset($latest['error'])) {
        echo "   ✅ Latest position found\n";
        echo "   📍 " . ($latest['lat'] ?? 'N/A') . "°N, " . ($latest['lon'] ?? 'N/A') . "°E\n";
        echo "   🚢 Status: " . getNavStatus($latest['navStatus'] ?? 15) . "\n";
        echo "   ⚡ Speed: " . ($latest['sog'] ?? 'N/A') . " kn\n";
    } else {
        echo "   ⚠️ Could not fetch latest position\n";
    }
    
    // Fetch historical data
    echo "\n📊 Fetching historical data (last $hours_back hours)...\n";
    $positions = fetchVesselHistory($imo, $hours_back);
    
    if (isset($positions['error'])) {
        echo "   ❌ Error: " . $positions['error'] . "\n";
        continue;
    }
    
    if (empty($positions)) {
        echo "   ⚠️ No positions found in the specified time window\n";
        continue;
    }
    
    echo "   ✅ Found " . count($positions) . " positions\n";
    
    // Analyze voyage
    echo "🔍 Analyzing voyage...\n";
    $vessel_name = $vessel_info[$imo]['name'] ?? $imo;
    $analysis = analyzeVoyage($positions, $vessel_name);
    
    if ($analysis) {
        $all_voyages[$imo] = $analysis;
        
        // Prepare export data
        foreach ($positions as $pos) {
            $props = $pos['properties'];
            $geom = $pos['geometry'];
            $coords = $geom['coordinates'];
            
            $export_data[] = [
                'vessel_name' => $vessel_name,
                'imo' => $imo,
                'timestamp' => $props['posDt'],
                'lat' => $coords[1],
                'lon' => $coords[0],
                'speed' => $props['sog'] ?? '',
                'course' => $props['cog'] ?? '',
                'heading' => $props['heading'] ?? '',
                'nav_status' => getNavStatus($props['navStatus'] ?? 15),
                'draught' => $props['draught'] ?? '',
                'destination' => $props['destination'] ?? '',
                'anchorage' => isAtAnchorage($coords[1], $coords[0], $props['sog'] ?? 0)
            ];
        }
        
        // Display report
        echo formatVoyageReport($analysis);
        
        // Display key milestones
        if (!empty($analysis['milestones'])) {
            echo "\n🎯 KEY MILESTONES\n";
            echo "───────────────────────────────────────────────────────────────\n";
            foreach ($analysis['milestones'] as $key => $value) {
                echo "   • " . strtoupper(str_replace('_', ' ', $key)) . "\n";
                echo "     Time: " . $value['timestamp'] . "\n";
                if (isset($value['location'])) {
                    echo "     Location: " . $value['location'] . "\n";
                }
            }
        }
        echo "\n";
    } else {
        echo "   ⚠️ Could not analyze voyage (insufficient data)\n";
    }
}

// Export data
if (!empty($export_data) && $output_format == 'csv') {
    echo "\n💾 Exporting to CSV...\n";
    $size = exportToCSV($export_data, $output_file);
    echo "✅ Exported " . count($export_data) . " records to " . $output_file . "\n";
    echo "   File size: " . round($size / 1024, 2) . " KB\n";
} elseif (!empty($export_data) && $output_format == 'json') {
    echo "\n💾 Exporting to JSON...\n";
    file_put_contents($output_file, json_encode([
        'export_date' => date('Y-m-d H:i:s'),
        'vessels' => $all_voyages,
        'positions' => $export_data
    ], JSON_PRETTY_PRINT));
    echo "✅ Exported to " . $output_file . "\n";
}

// Summary
echo "\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "                    EXTRACTION SUMMARY\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";
echo "📊 VESSELS PROCESSED: " . count($vessel_list) . "\n";
echo "✅ SUCCESSFUL ANALYSES: " . count($all_voyages) . "\n";
echo "📈 TOTAL POSITIONS: " . count($export_data) . "\n";
echo "📁 OUTPUT FILE: " . $output_file . "\n";
echo "⏰ COMPLETED: " . date('Y-m-d H:i:s') . "\n\n";

if (count($all_voyages) > 0) {
    echo "✅ RECOMMENDED VESSEL FOR PRESENTATION: ";
    foreach ($all_voyages as $imo => $analysis) {
        $name = $vessel_info[$imo]['name'] ?? $imo;
        if (!empty($analysis['milestones']['load_point_departure']) && 
            !empty($analysis['milestones']['discharge_departure'])) {
            echo $name . " (IMO: $imo) - COMPLETE VOYAGE FOUND ✅\n";
            break;
        }
    }
} else {
    echo "⚠️ No complete voyages found in the specified time window.\n";
    echo "   Try increasing the --hours parameter (currently $hours_back hours)\n";
}

echo "\n";