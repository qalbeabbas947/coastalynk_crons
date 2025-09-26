<?php

set_time_limit(0);
ini_set( "display_errors", "On" );
error_reporting(E_ALL);

require_once __DIR__ . '/common.php';
global $table_prefix;
// Connect to database directly
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

$api_key                        = get_option_data('coatalynk_datalastic_apikey');
$coastalTimeout = 3600; // 1 hour in seconds
$offshoreTimeout = 7200; // 2 hours in seconds
$distanceThreshold = 30; // nautical miles
$maxResonableSpeed = 30; // nautical miles
$SBM_Zone_Radius = 10; // nautical miles
$STS_Zone_Radius = 10; // nautical miles
$STS_Zones = [];

$table_name = $table_prefix . 'coastalynk_ports';

$sql = "select port_id, title, lat, lon,port_type from ".$table_name." where country_iso='NG'";
$result = $mysqli->query($sql);
$num_rows = mysqli_num_rows($result);
if( $num_rows > 0 ) {
    
    $table_name_sts = $table_prefix . 'coastalynk_sts';
    $sql = "select vessel1_uuid, vessel1_lat, vessel1_lon from ".$table_name_sts." where vessel1_country_iso='NG' order by vessel1_name";
    $sts_result = $mysqli->query($sql);
    $sts_num_rows = mysqli_num_rows($sts_result);
    if( $num_rows > 0 ) {
        while ($sts = mysqli_fetch_assoc($sts_result)) {
            $STS_Zones[] = ['uuid' => $sts['vessel1_uuid'], 'lat' => $sts['vessel1_lat'], 'lon' => $sts['vessel1_lon'], 'radius' => $STS_Zone_Radius ];
        }
    }

    // Create table if not exists
    $table_name_dark_ships = $table_prefix . 'coastalynk_dark_ships';
    $sql = "CREATE TABLE IF NOT EXISTS $table_name_dark_ships (
        uuid VARCHAR(50) Not Null,
        name VARCHAR(255) default '',
        mmsi VARCHAR(50) default '',
        imo VARCHAR(50) default '',
        country_iso VARCHAR(2) default '',
        type VARCHAR(50) default '',
        reason VARCHAR(255) default '',
        reason_type VARCHAR(10) default '',
        type_specific VARCHAR(255) default '',
        lat VARCHAR(10) default '',
        lon VARCHAR(10) default '',
        last_position_UTC TIMESTAMP,
        port VARCHAR(255) default '',
        port_id VARCHAR(50) default '',
        distance float default 0,
        last_updated TIMESTAMP,
        PRIMARY KEY (uuid)
    )";
    if ($mysqli->query($sql) !== TRUE) {
        echo "Error: " . $sql . "<br>" . $mysqli->error;
    }

    while ($row = mysqli_fetch_assoc($result)) {
        echo "<br>ID: " . $row['port_id'] . ", Name: " . $row['title'] . "<br>";
        $url = sprintf(
                "https://api.datalastic.com/api/v0/vessel_inradius?api-key=%s&lat=%f&lon=%f&radius=%f",
                urlencode($api_key),
                $row['lat'],
                $row['lon'],
                50
            );

            // Fetch vessels in area
            $response = file_get_contents($url);
            $data = json_decode($response, true);
            $vessels = $data['data']['vessels'];
            foreach( $vessels as $vessel ) {
                
                sleep(1);
                $url = "https://api.datalastic.com/api/v0/vessel_history?api-key=$api_key&uuid=".$vessel['uuid']."&from=" . date('Y-m-d', strtotime("-7 days"));
                $response = file_get_contents($url);
                $data = json_decode($response, true);
                
                if( isset( $data['data']['positions'] ) && is_array($data['data']['positions']) ) {

                    $positions = $data['data']['positions'];
                    if (count($positions) < 2) return false;
            
                    for( $i=1; $i<count($positions); $i++ ) {

                        $lastPosition = $positions[$i];
                        $previousPosition = $positions[$i-1];

                        $signal_disappeared = checkSignalDisappearance( $previousPosition, $lastPosition, $row['lat'], $row['lon'] );
                        if( !empty( $signal_disappeared ) ) {
                            $disappear_data = explode('|', $signal_disappeared);
                            $reason = "Possible dark ship - last seen ".date('H:i', strtotime($vessel['last_position_UTC']))." UTC, gap ".coastalynk_display_time($disappear_data[1])." near ".$row['title']." zone.";
                            $reason_type = $disappear_data[0];
                            add_dark_ships($vessel['uuid'], $vessel['name'], $vessel['mmsi'], $vessel['imo'], $vessel['country_iso'], $vessel['type'], $vessel['type_specific'], $reason, $reason_type, $vessel['lat'], $vessel['lon'], $vessel['last_position_UTC'], $vessel['distance'], $row['title'], $row['port_id']);
                        }
                    }

                    if( $row['port_type'] == 'Offshore Terminal' ) {  //SBM Area
                        if ( $diff = checkUnrealisticSBMMovement( $positions, $row['lat'], $row['lon'] ) ) {
                            $reason = "Possible dark ship - last seen ".date('H:i', strtotime($vessel['last_position_UTC']))." UTC, gap ".coastalynk_display_time($diff)." near ".$row['title']." SBM zone.";
                            $reason_type = 'SBM Area';
                            add_dark_ships($vessel['uuid'], $vessel['name'], $vessel['mmsi'], $vessel['imo'], $vessel['country_iso'], $vessel['type'], $vessel['type_specific'], $reason, $reason_type, $vessel['lat'], $vessel['lon'], $vessel['last_position_UTC'], $vessel['distance'], $row['title'], $row['port_id']);
                        
                        }
                    }

                    if( count( $STS_Zones ) > 0 ) {
                        if ( $diff = checkUnrealisticSTSMovement( $positions ) ) {
                            $reason = "Possible dark ship - last seen ".date('H:i', strtotime($vessel['last_position_UTC']))." UTC, gap ".coastalynk_display_time($diff)." near ".$row['title']." STS zone.";
                            $reason_type = 'STS Area';
                            add_dark_ships($vessel['uuid'], $vessel['name'], $vessel['mmsi'], $vessel['imo'], $vessel['country_iso'], $vessel['type'], $vessel['type_specific'], $reason, $reason_type, $vessel['lat'], $vessel['lon'], $vessel['last_position_UTC'], $vessel['distance'], $row['title'], $row['port_id']);
                        
                        }
                    }
                }
            }
            
            sleep(1);
    }
}

function coastalynk_display_time( $diff_seconds ) {

    $hours = floor($diff_seconds / 3600);
    $minutes = floor(($diff_seconds % 3600) / 60);

    return "$hours hrs $minutes min";
}

/**
 * Add new dark ship records
 */
function add_dark_ships($uuid, $name, $mmsi, $imo, $country_iso, $type, $type_specific, $reason, $reason_type, $lat, $lon, $last_position_UTC, $distance, $port, $port_id) {

    global $table_prefix, $mysqli;
    $table_name_dark_ships = $table_prefix . 'coastalynk_dark_ships';
    $sql = "select uuid from ".$table_name_dark_ships." where uuid='".$mysqli->real_escape_string($uuid)."'";
    $result = $mysqli->query($sql);
    $num_rows = mysqli_num_rows($result);
    if( $num_rows == 0 ) {
        
        $sql = "INSERT INTO $table_name_dark_ships (uuid , name, mmsi, imo, country_iso, type, type_specific,reason,reason_type, lat, lon, last_position_UTC, distance, port, port_id, last_updated)
                VALUES (
                    '" . (!empty($uuid)?$mysqli->real_escape_string($uuid):'') . "',
                    '" . (!empty($uuid)?$mysqli->real_escape_string($name):'') . "',
                    '" . (!empty($uuid)?$mysqli->real_escape_string($mmsi):'') . "',
                    '" . (!empty($uuid)?$mysqli->real_escape_string($imo):'') . "',
                    '" . (!empty($uuid)?$mysqli->real_escape_string($country_iso):'') . "',
                    '" . (!empty($uuid)?$mysqli->real_escape_string($type):'') . "',
                    '" . (!empty($uuid)?$mysqli->real_escape_string($type_specific):'') . "',
                    '" . (!empty($uuid)?$mysqli->real_escape_string($reason):'') . "',
                    '" . (!empty($uuid)?$mysqli->real_escape_string($reason_type):'') . "',
                    '" . (!empty($uuid)?$mysqli->real_escape_string($lat):'') . "',
                    '" . (!empty($uuid)?$mysqli->real_escape_string($lon):'') . "',
                    '" . date('Y-m-d H:i:s', strtotime($last_position_UTC)) . "',
                    '" . (!empty($uuid)?$mysqli->real_escape_string($distance):'') . "',
                    '" . (!empty($uuid)?$mysqli->real_escape_string($port):'') . "',
                    '" . (!empty($uuid)?$mysqli->real_escape_string($port_id):'') . "',
                    NOW())";
        if ($mysqli->query($sql) !== TRUE) {
            echo "Error: " . $sql . "<br>" . $mysqli->error;
        }
    }
}
function checkUnrealisticSTSMovement($positions) {

    global $distanceThreshold, $maxResonableSpeed, $STS_Zones;
    for ($i = 1; $i < count($positions); $i++) {
        $current = $positions[$i];
        $previous = $positions[$i-1];
        
        $timeDiff = intval($current['last_position_epoch']) - intval($previous['last_position_epoch']);
        $distance = calculateDistance(
            $previous['lat'], $previous['lon'],
            $current['lat'], $current['lon']
        );
        
        // Calculate maximum possible speed (assuming 30 knots max reasonable speed)
        $maxPossibleDistance = $maxResonableSpeed * ($timeDiff / 3600); // nautical miles
        
        if ($distance > $maxPossibleDistance && $distance > $distanceThreshold) {
            
            foreach ($STS_Zones as $zone) {
                $distance = calculateDistance($current['lat'], $current['lon'], $zone['lat'], $zone['lon']);
                if ($distance < $zone['radius']) {
                    return $timeDiff;
                }
            }
        }
    }
    
    return 0;
}

function checkUnrealisticSBMMovement($positions, $port_lat, $port_lon) {

    global $distanceThreshold, $maxResonableSpeed, $SBM_Zone_Radius;
    for ($i = 1; $i < count($positions); $i++) {
        $current = $positions[$i];
        $previous = $positions[$i-1];
        
        $timeDiff = intval($current['last_position_epoch']) - intval($previous['last_position_epoch']);
        $distance = calculateDistance(
            $previous['lat'], $previous['lon'],
            $current['lat'], $current['lon']
        );
        
        // Calculate maximum possible speed (assuming 30 knots max reasonable speed)
        $maxPossibleDistance = $maxResonableSpeed * ($timeDiff / 3600); // nautical miles
        
        if ($distance > $maxPossibleDistance && $distance > $distanceThreshold) {

            $distance = calculateDistance($current['lat'], $current['lon'], $port_lat, $port_lon);
            if ($distance < $SBM_Zone_Radius) {
                return $timeDiff;
            }
        }
    }
    
    return 0;
}

/**
 * Check if vessel is near suspicious zone like sts sbm or EEZ boundy
 */
function isNearSuspiciousZone($lat, $lon) {

    global $suspiciousZones;
    foreach ($suspiciousZones as $zone) {
        $distance = calculateDistance($lat, $lon, $zone['lat'], $zone['lon']);
        if ($distance < $zone['radius']) {
            return true;
        }
    }
    return false;
}

function checkSignalDisappearance($lastPos, $prevPos, $port_lat, $port_lon) {
    global $coastalTimeout, $offshoreTimeout;
    $timeDiff = intval($lastPos['last_position_epoch']) - intval($prevPos['last_position_epoch']);

    $port_distance = calculateDistance($lastPos['lat'], $lastPos['lon'], $port_lat, $port_lon);

    $isCoastal = $port_distance < 20;
    
    $timeout = $isCoastal? $coastalTimeout : $offshoreTimeout;
    
    if ($timeDiff > $timeout) {
        // Check if it's not a poor reception area
        if (!isPoorReceptionArea($lastPos['lat'], $lastPos['lon'])) {
            return $isCoastal? 'Coastal|'.$timeDiff : 'offshore|'.$timeDiff;
        }
    }
    
    return false;
}

function isPoorReceptionArea($lat, $lon) {
    // Define areas with known poor AIS reception
    $poorReceptionAreas = [
        ['lat' => 7.176, 'lon' => -4.427, 'radius' => 10], // Bonny/Opobo side
        ['lat' => 8.238, 'lon' => 4.817, 'radius' => 10], //Calabar/Oron
        ['lat' => 4.557, 'lon' => 4.616, 'radius' => 10], //BONGA offshore terminal
        ['lat' => 3.461, 'lon' => 5.56, 'radius' => 10], //Agbami offshore terminal
        ['lat' => 4.976, 'lon' => 8.321, 'radius' => 10], //CALABAR Port
        ['lat' => 5.608, 'lon' => 5.193, 'radius' => 10],//ESCRAVOS Port
        ['lat' => 5.513, 'lon' => 5.735, 'radius' => 10]//WARRI
    ];
    
    foreach ($poorReceptionAreas as $area) {
        $distance = calculateDistance($lat, $lon, $area['lat'], $area['lon']);
        if ($distance < $area['radius']) {
            return true;
        }
    }
    return false;
}

function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    // Haversine formula to calculate distance in nautical miles
    $earthRadius = 3440; // nautical miles
    
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    
    $a = sin($dLat/2) * sin($dLat/2) + 
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * 
            sin($dLon/2) * sin($dLon/2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    
    return $earthRadius * $c;
}
exit;