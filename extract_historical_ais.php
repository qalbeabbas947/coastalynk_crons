<?php
/**
 * Historical AIS Data Extraction - Nigerian Waters
 * Usage: php extract_historical_ais.php [--start YYYY-MM-DD] [--end YYYY-MM-DD] [--append]
 * 
 * Examples:
 *   php extract_historical_ais.php --start 2015-01-01 --end 2015-12-31
 *   php extract_historical_ais.php --start 2016-01-01 --end 2016-12-31 --append
 *   php extract_historical_ais.php --start 2015-01-01 --end 2026-06-24
 *   php -d memory_limit=8G extract_historical_ais.php --start 2023-01-01 --end 2023-12-31 --output nigeria_ais_data_2023-full-year.csv
 */

// ============================================================
// COMMAND LINE ARGUMENT PARSING
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
$start_date_str = getArgValue('--start', '2015-01-01');
$end_date_str = getArgValue('--end', '2026-06-24');
$append_mode = hasArg('--append');
$output_file = getArgValue('--output', 'nigerian_ais_export.csv');
$rate_limit_ms = (int) getArgValue('--rate', '500'); // milliseconds between calls

// Validate dates
$start_date = strtotime($start_date_str);
$end_date = strtotime($end_date_str);

if ($start_date === false || $end_date === false) {
    echo "❌ Invalid date format. Use YYYY-MM-DD\n";
    exit(1);
}

if ($start_date > $end_date) {
    echo "❌ Start date must be before end date\n";
    exit(1);
}

define('AIS_API_KEY', 'dklBS3cyTzFHZWtXclhkYnJwSFFpUzV4ZGpJcFJTMW86bXRuaTFTYTI1cHZwek00bFNKcWttUFFMYVpSYXA3SkFRNkt1UDlDd0pnemxaSWhpeFhaUHQ2Ml9Ba3BQc0dPVg==');
define('AIS_API_URL', 'https://api.kpler.com/v2/maritime/ais-historical');

// ============================================================
// LOCATION POLYGONS (WKT Format)
// ============================================================

$locations = [
    'Lagos Outer Anchorage' => 'POLYGON((3.183 6.333, 3.6 6.333, 3.683 6.25, 3.6 6.083, 3.15 6.083, 3.083 6.2, 3.183 6.333))',
    'Bonny Channel' => 'POLYGON((6.9 4.3, 7.1 4.3, 7.1 4.1, 6.9 4.1, 6.9 4.3))',
    'Calabar Anchorage' => 'POLYGON((8.3 4.9, 8.5 4.9, 8.5 4.7, 8.3 4.7, 8.3 4.9))',
    'Onne' => 'POLYGON((7.1 4.7, 7.3 4.7, 7.3 4.5, 7.1 4.5, 7.1 4.7))',
    'Forcados' => 'POLYGON((5.4 5.4, 5.6 5.4, 5.6 5.2, 5.4 5.2, 5.4 5.4))',
    'Escravos' => 'POLYGON((5.1 5.6, 5.3 5.6, 5.3 5.4, 5.1 5.4, 5.1 5.6))',
    'Brass' => 'POLYGON((6.5 4.3, 6.7 4.3, 6.7 4.1, 6.5 4.1, 6.5 4.3))',
    'Warri' => 'POLYGON((5.7 5.5, 5.9 5.5, 5.9 5.3, 5.7 5.3, 5.7 5.5))',
    'Port Harcourt' => 'POLYGON((7.0 4.8, 7.2 4.8, 7.2 4.6, 7.0 4.6, 7.0 4.8))',
    'Nigerian EEZ' => 'POLYGON((2.6849 1.9217, 8.6535 1.9217, 8.6535 6.6227, 2.6849 6.6227, 2.6849 1.9217))',
];

// ============================================================
// FPSO NAMES TO TRACK
// ============================================================

$fpso_names = ['AKPO', 'EGINA', 'BONGA', 'ERHA', 'USAN', 'AGBAMI', 'OKWORI'];

// ============================================================
// VESSEL TYPES (Commercial)
// ============================================================

$commercial_types = [
    70, 71, 72, 73, 74, 75, 76, 77, 78, 79, // Cargo
    80, 81, 82, 83, 84, 85, 86, 87, 88, 89, // Tankers
    90, 91, 92, 93, 94, 95, 96, 97, 98, 99, // Other commercial
    60, 61, 62, 63, 64, 65, 66, 67, 68, 69, // Passenger
    30, 31, 32, 33, 34, 35, 36, 37, 38, 39, // Fishing
    40, 41, 42, 43, 44, 45, 46, 47, 48, 49, // Tugs/Offshore
];

// ============================================================
// DATA STRUCTURES
// ============================================================

$all_positions = [];
$vessel_info = [];
$daily_presence = [];
$serial_number = 1;
$total_api_calls = 0;
$error_log = [];
$processed_days = 0;
$position_count = 0;

$vessel_last_destination = []; // vessel_key => last_destination
$vessel_last_timestamp = [];   // vessel_key => last_timestamp

// ============================================================
// API FETCH FUNCTION
// ============================================================

/**
 * Get origin port and arrival date from previous destination
 * Stores the destination and timestamp when a vessel arrives at a new port
 * 
 * @param int|string $mmsi Vessel MMSI
 * @param string $currentTimestamp Current position timestamp
 * @param string $currentDestination Current destination port
 * @return array|null ['origin_port' => string, 'date_arrived' => string] or null if no previous voyage
 */
function getOriginFromPreviousDestination($mmsi, $currentTimestamp, $currentDestination) {
    global $vessel_last_destination, $vessel_last_timestamp;
    
    $key = (string) $mmsi;
    $result = null;
    
    // Check if we have a previous destination for this vessel
    if (isset($vessel_last_destination[$key])) {
        $last_dest = $vessel_last_destination[$key];
        $last_ts = $vessel_last_timestamp[$key] ?? null;
        
        // Only use if the destination is different from current
        // (This indicates a new voyage has started)
        if ($last_dest !== $currentDestination && !empty($last_dest) && !empty($currentDestination)) {
            // Previous destination becomes current origin
            // Previous timestamp becomes date_arrived
            $result = [
                'origin_port' => $last_dest,
                'date_arrived' => $last_ts
            ];
            
            // Update tracking for the new voyage
            $vessel_last_destination[$key] = $currentDestination;
            $vessel_last_timestamp[$key] = $currentTimestamp;
            
            return $result;
        }
    }
    
    // Store current destination for future records
    // Only update if destination has changed or is new
    if (!isset($vessel_last_destination[$key]) || $vessel_last_destination[$key] !== $currentDestination) {
        $vessel_last_destination[$key] = $currentDestination;
        $vessel_last_timestamp[$key] = $currentTimestamp;
    }
    
    return null;
}

function fetchAISData($date, $polygon, $vesselTypes = null)
{
    $start_iso = date('Y-m-d\T00:00:00.000\Z', $date);
    $end_iso = date('Y-m-d\T23:59:59.999\Z', $date);
    
    $filter = "posDt BETWEEN '$start_iso' AND '$end_iso' AND INTERSECTS(position, $polygon)";
    
    if ($vesselTypes && is_array($vesselTypes)) {
        $typeConditions = [];
        foreach ($vesselTypes as $type) {
            $typeConditions[] = "vesselTypeAis = $type";
        }
        $filter .= " AND (" . implode(' OR ', $typeConditions) . ")";
    }
    
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
    
    if ($http_code !== 200) {
        return ['error' => "HTTP $http_code - $error", 'data' => []];
    }
    
    $data = json_decode($response, true);
    return ['error' => null, 'data' => $data['features'] ?? []];
}

// ============================================================
// DETERMINE LOCATION/AREA
// ============================================================

function determineArea($lon, $lat)
{
    if ($lon >= 3.0 && $lon <= 3.7 && $lat >= 6.0 && $lat <= 6.4) {
        return 'Lagos Outer Anchorage';
    }
    if ($lon >= 6.8 && $lon <= 7.2 && $lat >= 4.0 && $lat <= 4.4) {
        return 'Bonny Channel';
    }
    if ($lon >= 8.2 && $lon <= 8.6 && $lat >= 4.6 && $lat <= 5.0) {
        return 'Calabar Anchorage';
    }
    if ($lon >= 7.0 && $lon <= 7.4 && $lat >= 4.4 && $lat <= 4.8) {
        return 'Onne';
    }
    if ($lon >= 5.3 && $lon <= 5.7 && $lat >= 5.1 && $lat <= 5.5) {
        return 'Forcados';
    }
    if ($lon >= 5.0 && $lon <= 5.4 && $lat >= 5.3 && $lat <= 5.7) {
        return 'Escravos';
    }
    if ($lon >= 6.4 && $lon <= 6.8 && $lat >= 4.0 && $lat <= 4.4) {
        return 'Brass';
    }
    if ($lon >= 5.6 && $lon <= 6.0 && $lat >= 5.2 && $lat <= 5.6) {
        return 'Warri';
    }
    if ($lon >= 6.9 && $lon <= 7.3 && $lat >= 4.5 && $lat <= 4.9) {
        return 'Port Harcourt';
    }
    if ($lon >= 2.0 && $lon <= 9.0 && $lat >= 4.0 && $lat <= 7.0) {
        return 'Nigerian EEZ';
    }
    return 'Other Nigerian Waters';
}

// ============================================================
// EXPORT FUNCTIONS
// ============================================================

function writeHeader($filename)
{
    $fp = fopen($filename, 'w');
    fwrite($fp, "\xEF\xBB\xBF"); // UTF-8 BOM
    fputcsv($fp, [
        'S/N', 'VESSEL NAME', 'VOYAGE NUMBER', 'IMO', 'MMSI', 'CALL SIGN',
        'FLAG', 'TYPE', 'GT', 'LAT', 'LONG', 'LOCATION/AREA', 'SPEED (kn)',
        'COURSE (°)', 'HEADING (°)', 'NAV STATUS', 'DRAUGHT (m)',
        'DESTINATION', 'ORIGIN PORT', 'ETA', 'DATE ARRIVED', 'RISK',
        'DATE REPORTED', 'LAST AIS UPDATE', 'DATA SOURCE', 'REMARKS'
    ]);
    fclose($fp);
}

function appendToCSV($positions, $filename, $includeHeader = false)
{
    $mode = $includeHeader ? 'w' : 'a';
    $fp = fopen($filename, $mode);
    
    if ($includeHeader) {
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, [
            'S/N', 'VESSEL NAME', 'VOYAGE NUMBER', 'IMO', 'MMSI', 'CALL SIGN',
            'FLAG', 'TYPE', 'GT', 'LAT', 'LONG', 'LOCATION/AREA', 'SPEED (kn)',
            'COURSE (°)', 'HEADING (°)', 'NAV STATUS', 'DRAUGHT (m)',
            'DESTINATION', 'ORIGIN PORT', 'ETA', 'DATE ARRIVED', 'RISK',
            'DATE REPORTED', 'LAST AIS UPDATE', 'DATA SOURCE', 'REMARKS'
        ]);
    }
    
    foreach ($positions as $pos) {
        fputcsv($fp, [
            $pos['serial'],
            $pos['vessel_name'],
            $pos['voyage_number'],
            $pos['imo'],
            $pos['mmsi'],
            $pos['call_sign'],
            $pos['flag'],
            $pos['type'],
            $pos['gt'],
            $pos['lat'],
            $pos['lon'],
            $pos['location_area'],
            $pos['speed'],
            $pos['course'],
            $pos['heading'],
            $pos['nav_status'],
            $pos['draught'],
            $pos['destination'],
            $pos['origin_port'],
            $pos['eta'],
            $pos['date_arrived'],
            $pos['risk'],
            $pos['date_reported'],
            $pos['last_ais_update'],
            $pos['data_source'],
            $pos['remarks']
        ]);
    }
    
    fclose($fp);
}

// ============================================================
// GET LAST SERIAL NUMBER FROM EXISTING FILE
// ============================================================

function getLastSerialNumber($filename)
{
    if (!file_exists($filename)) {
        return 0;
    }
    
    $fp = fopen($filename, 'r');
    $last_serial = 0;
    
    // Skip header
    fgetcsv($fp);
    
    while (($row = fgetcsv($fp)) !== false) {
        if (!empty($row[0]) && is_numeric($row[0])) {
            $last_serial = (int) $row[0];
        }
    }
    
    fclose($fp);
    return $last_serial;
}

// ============================================================
// MAIN EXTRACTION LOOP
// ============================================================

echo "\n";
echo "╔═══════════════════════════════════════════════════════════════════╗\n";
echo "║        NIGERIAN WATERS HISTORICAL AIS EXTRACTION                ║\n";
echo "╚═══════════════════════════════════════════════════════════════════╝\n\n";

$total_days = ceil(($end_date - $start_date) / 86400);
$total_locations = count($locations);
$total_api_calls_expected = $total_days * $total_locations;

echo "PERIOD: " . date('Y-m-d', $start_date) . " to " . date('Y-m-d', $end_date) . "\n";
echo "TOTAL DAYS: " . number_format($total_days) . "\n";
echo "LOCATIONS: " . $total_locations . "\n";
echo "API CALLS: " . number_format($total_api_calls_expected) . "\n";
echo "OUTPUT FILE: $output_file\n";
echo "APPEND MODE: " . ($append_mode ? 'YES' : 'NO') . "\n";
echo "RATE LIMIT: {$rate_limit_ms}ms between calls\n\n";

// Get last serial number if appending
if ($append_mode && file_exists($output_file)) {
    $serial_number = getLastSerialNumber($output_file);
    echo "Resuming from serial number: " . number_format($serial_number) . "\n\n";
    $serial_number++;
} else {
    $serial_number = 1;
    writeHeader($output_file);
    echo "Starting fresh export\n\n";
}

$day_count = 0;
$successful_calls = 0;
$failed_calls = 0;
$flushed_count = 0;
$origin_port_found = 0;
for ($day = $start_date; $day <= $end_date; $day += 86400) {
    $day_count++;
    $date_str = date('Y-m-d', $day);
    
    $progress = round(($day_count / $total_days) * 100, 2);
    
    echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "DAY $day_count/$total_days ($progress%) - $date_str\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    
    $day_positions = 0;
    $location_count = 0;
    
    foreach ($locations as $location_name => $polygon) {
        $location_count++;
        echo "  [$location_count/$total_locations] $location_name ... ";
        
        $result = fetchAISData($day, $polygon, $commercial_types);
        $total_api_calls++;
        
        if ($result['error']) {
            echo "❌ Error: {$result['error']}\n";
            $error_log[] = [
                'day' => $date_str,
                'location' => $location_name,
                'error' => $result['error']
            ];
            $failed_calls++;
            sleep(3);
            continue;
        }
        
        $features = $result['data'];
        $found = count($features);
        echo "✅ Found " . number_format($found) . " positions\n";
        $successful_calls++;
        
        if (empty($features)) {
            usleep($rate_limit_ms * 1000);
            continue;
        }
        
        // Process features
        foreach ($features as $feature) {
            $props = $feature['properties'];
            $geom = $feature['geometry'];
            $coords = $geom['coordinates'];
            $mmsi = $props['mmsi'] ?? null;
            $imo = $props['imo'] ?? null;
            $vessel_name = $props['vesselName'] ?? 'Unknown';
            $destination = $props['destination'] ?? '';
            $timestamp = $props['posDt'] ?? date('Y-m-d H:i:s');
            // ============================================================
            // DERIVE ORIGIN PORT FROM PREVIOUS DESTINATION
            // ============================================================
            $origin_port = null;
            $date_arrived = null;

            if ($mmsi) {
                $voyage_info = getOriginFromPreviousDestination($mmsi, $timestamp, $destination);
                if ($voyage_info) {
                    $origin_port = $voyage_info['origin_port'];
                    $date_arrived = $voyage_info['date_arrived'];
                    $origin_port_found++;
                }
            }

            // Check if vessel is FPSO
            $is_fpso = false;
            $fpso_match = [];
            foreach ($fpso_names as $fpso) {
                if (stripos($vessel_name, $fpso) !== false) {
                    $is_fpso = true;
                    $fpso_match[] = $fpso;
                }
            }
            
            // Determine location/area
            $area = determineArea($coords[0], $coords[1]);
            
            $position = [
                'serial' => $serial_number++,
                'vessel_name' => $vessel_name,
                'voyage_number' => $feature['id'],
                'imo' => $imo ?? '',
                'mmsi' => $mmsi ?? '',
                'call_sign' => $props['callsign'] ?? '',
                'flag' => $props['flag'] ?? '',
                'type' => $props['vesselType'] ?? '',
                'gt' => $props['grt'] ?? '',
                'lat' => $coords[1] ?? 0,
                'lon' => $coords[0] ?? 0,
                'location_area' => $area,
                'speed' => $props['sog'] ?? null,
                'course' => $props['cog'] ?? null,
                'heading' => $props['heading'] ?? null,
                'nav_status' => $props['navStatus'] ?? null,
                'draught' => $props['draught'] ?? null,
                'destination' => $destination,
                'origin_port' => $origin_port, // Derived from previous destination
                'eta' => $props['eta'] ?? null,
                'date_arrived' => $date_arrived ?? null,
                'risk' => '',
                'date_reported' => $props['posDt'] ?? null,
                'last_ais_update' => $props['posDt'] ?? null,
                'data_source' => 'Kpler MarineTraffic',
                'remarks' => $is_fpso ? 'FPSO: ' . implode(', ', $fpso_match) : ''
            ];
            
            $all_positions[] = $position;
            $day_positions++;
            $position_count++;
            
            // Store vessel info
            $key = $mmsi ?: $imo;
            if ($key && !isset($vessel_info[$key])) {
                $vessel_info[$key] = [
                    'name' => $vessel_name,
                    'imo' => $imo,
                    'mmsi' => $mmsi,
                    'type' => $props['vesselType'] ?? '',
                    'flag' => $props['flag'] ?? '',
                    'call_sign' => $props['callsign'] ?? ''
                ];
            }
        }
        
        // Rate limiting
        usleep($rate_limit_ms * 1000);
    }
    
    echo "  ────────────────────────────────────────────────────────────────\n";
    echo "  Day total: " . number_format($day_positions) . " positions\n";
    echo "  Cumulative: " . number_format($position_count) . " positions\n";
    
    // Flush if memory threshold reached (500K records)
    if (count($all_positions) > 500000) {
        echo "  ⚠️ Flushing to CSV (memory threshold reached)...\n";
        appendToCSV($all_positions, $output_file, false);
        echo "  ✓ Appended " . number_format(count($all_positions)) . " records to $output_file\n";
        $all_positions = [];
        $flushed_count++;
        gc_collect_cycles();
    }
}

// ============================================================
// FINAL FLUSH
// ============================================================

if (!empty($all_positions)) {
    echo "\n💾 Final flush to CSV...\n";
    appendToCSV($all_positions, $output_file, false);
    echo "✓ Appended final " . number_format(count($all_positions)) . " records to $output_file\n";
}

// ============================================================
// GENERATE SUMMARY REPORT
// ============================================================

echo "\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "              HISTORICAL AIS EXTRACTION REPORT\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

echo "EXTRACTION PERIOD: " . date('Y-m-d', $start_date) . " to " . date('Y-m-d', $end_date) . "\n";
echo "DAYS PROCESSED: " . number_format($day_count) . "\n";
echo "SUCCESSFUL API CALLS: " . number_format($successful_calls) . "\n";
echo "FAILED API CALLS: " . number_format($failed_calls) . "\n";
echo "TOTAL POSITIONS EXTRACTED: " . number_format($position_count) . "\n";
echo "UNIQUE VESSELS IDENTIFIED: " . number_format(count($vessel_info)) . "\n";
echo "ORIGIN PORTS DERIVED: " . number_format($origin_port_found) . "\n";
echo "OUTPUT FILE: $output_file\n";
echo "FILE SIZE: ~" . round(filesize($output_file) / 1048576, 2) . " MB\n\n";

if (!empty($error_log)) {
    echo "⚠️ ERRORS ENCOUNTERED: " . count($error_log) . "\n";
    $error_samples = array_slice($error_log, 0, 10);
    foreach ($error_samples as $error) {
        echo "  - {$error['day']}, {$error['location']}: {$error['error']}\n";
    }
    if (count($error_log) > 10) {
        echo "  ... and " . (count($error_log) - 10) . " more errors\n";
    }
}

echo "\n✅ EXTRACTION COMPLETE!\n\n";