<?php
set_time_limit(0);
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

use function PHPSTORM_META\elementType;

ini_set( "display_errors", "On" );
error_reporting(E_ALL);

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/sts-predictions.php';

global $table_prefix;
coastalynk_summary_table();
// Connect to database directly
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

$api_key                        = get_option_data('coatalynk_datalastic_apikey');
$siteurl                        = get_option_data('siteurl');
$coatalynk_site_admin_email 	= get_option_data('coatalynk_site_admin_email');
$coatalynk_npa_admin_email 	    = get_option_data( 'coatalynk_npa_admin_email' );
$coatalynk_finance_admin_email 	= get_option_data( 'coatalynk_finance_admin_email' );
$coatalynk_nimasa_admin_email 	= get_option_data( 'coatalynk_nimasa_admin_email' );

$coastalynk_sts_email_subject_original = get_option_data( 'coastalynk_sts_email_subject' );
$coastalynk_sts_email_subject_original = !empty( $coastalynk_sts_email_subject_original ) ? $coastalynk_sts_email_subject_original : 'Coastalynk STS Alert - [port]';
$coastalynk_sts_body_original 	    = get_option_data( 'coastalynk_sts_body' );
$strbody = "Dear Sir/Madam,
<br>
<p>This is an automatic notification from Coastalynk Maritime Intelligence regarding a Ship-to-Ship (STS) operation detected at [port].</p><br>
<h3>Event Summary:</h3>
<p>Date/Time (UTC): [last_updated]</p>
<p>Location: ([vessel1_lat], [vessel1_lon]) (Lagos Offshore)</p>
<p>Distance Between Vessels: [distance]</p>
<p>Port Reference: [port]</p>
<h3>Parent Vessel</h3>
<p>Name: [vessel1_name] | IMO: [vessel1_imo] | MMSI: [vessel1_mmsi]</p>
<p>Type: [vessel1_type] | Flag: <img src='[vessel1_country_flag]' width='30px' alt='[vessel1_country_iso]' /></p>
<p>Status: [vessel1_navigation_status]</p>
<p>Current Draught: [vessel1_draught]</p>

<h3>Child Vessel</h3>
<p>Name: [vessel2_name] | IMO: [vessel2_imo] | MMSI: [vessel2_mmsi]</p>
<p>Type: [vessel2_type] | Flag: <img src='[vessel2_country_flag]' width='30px' alt='[vessel2_country_iso]' /></p>
<p>Status: [vessel2_navigation_status]</p>
<p>Current Draught: [vessel2_draught]</p>
<br><br>
<p>View on Coastalynk Map(<a href='[sts-page-url]'>Click Here</a>)</p>
<br>
<p>This notification is part of Coastalynk\'s effort to provide real-time intelligence to support anti-bunkering enforcement, maritime security, and revenue protection.</p>
<br>
<p>Regards,</p>
<p>Coastalynk Maritime Intelligence</p>";
$coastalynk_sts_body_original = !empty( $coastalynk_sts_body_original ) ? $coastalynk_sts_body_original : $strbody;

// Configuration
function haversineDistance($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371000; // meters
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $earth_radius * $c;
}

function get_datalastic_field( $uuid, $field = 'navigation_status', $is_full = true ) {
    
    global $api_key;
   sleep(1); 
    $url = 'https://api.datalastic.com/api/v0/vessel_pro?api-key='.urlencode($api_key).'&uuid='.$uuid;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Bearer '.$api_key));
    $output = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error) {
        sleep(1);
        get_datalastic_field( $uuid, $field, $is_full );
    }

    $data = json_decode($output, true);

    if( $is_full ) {
        return $data['data'];
    } else {
        return $data['data'][$field];
    }
}

if( empty( $_REQUEST['lat'] ) || empty( $_REQUEST['lon'] ) ) {
    echo 'Please provide lat and lon as query params';
    exit;
} else {
    $param_lat = $mysqli->real_escape_string( floatval( $_REQUEST['lat'] ) );
    $param_lon = $mysqli->real_escape_string( floatval( $_REQUEST['lon'] ) );
}

$zone_terminal_name = '';
$candidates = [];
$last_updated = date('Y-m-d H:i:s');
$port_id = '';
$port_name = '';
$polygon = null;
$ports = [];
$port_link_index = 1;
$port_radius = 10;

$detector = new STSTransferDetector( $api_key );
$table_name = $table_prefix . 'coastalynk_ports';
$sql = "SELECT *, ST_Distance_Sphere( POINT(lat, lon), POINT($param_lat, $param_lon) ) AS distance_meters FROM ".$table_name." where country_iso='NG' and port_type in( 'Port', 'Coastal Zone', 'Territorial Zone', 'EEZ' ) HAVING distance_meters is not null order by distance_meters asc limit 1;";
if ($result = $mysqli->query($sql)) {
    if( $obj = $result->fetch_object() ) {
        $port_id = $obj->port_id;
        $port_name = $obj->title;
        $polygon = $obj->port_area;
        $zone_terminal_name = $port_name;
        $zone_type  = $obj->port_type; 
        $zone_ship  = "N/A";
        $port_radius = floatval( $obj->radius ) > 0 ? floatval( $obj->radius ): 10;
    }
}

// $sql = "select *, ST_AsText(port_area) as port_area from ".$table_name." where country_iso='NG' and port_type in( 'Port', 'Coastal Zone', 'Territorial Zone', 'EEZ' ) order by title";

// if ($result = $mysqli->query($sql)) {
//     while ( $obj = $result->fetch_object() ) { 
//         $url = sprintf(
//                 "https://coastalynk.com/coastalynk_crons/sts.php?lat=%f&lon=%f",
//                 $obj->lat,
//                 $obj->lon
//             );
//         echo '<br>'.$port_link_index++.' '.$obj->title.': <a href="'.$url.'" target="_blank">';
//         echo $url;
//         echo '</a>';
//         echo '<br>'.$port_link_index.' '.$obj->title.' :       <a href="'.$url.'" target="_blank">	wget -O /dev/null "'.$url.'"</a>';
//         if( number_format($_REQUEST['lat'], 2) == number_format( $obj->lat, 2) && number_format( $_REQUEST['lon'], 2) == number_format( $obj->lon, 2) ) {
//             $port_id = $obj->port_id;
//             $port_name = $obj->title;
//             $polygon = $obj->port_area;
//             $zone_terminal_name = $port_name;
//             $port_radius = floatval( $obj->radius ) > 0 ? floatval( $obj->radius ): 10;
//         }

//         $ports[] = $obj->title;
//     }
// } 

//SELECT *, ST_Distance_Sphere( lat_lng, POINT(6.25000000, 3.30000000) ) AS distance_meters FROM wp_coastalynk_ports HAVING distance_meters is not null;
//SELECT * FROM wp_coastalynk_ports WHERE MBRWithin( POINT(6.25000000, 3.30000000), port_area );


// isPointInPolygon(3.30000000, 6.25000000, wktToArray($polygon)) or die('outside1');
// echo 'inside1';
// // isPointInPolygon(3.20000000, 6.30000000, wktToArray($polygon)) or die('outside2');
// // echo 'inside2';
// isPointInPolygon(3.35000000, 6.30000000, wktToArray($polygon)) or die('outside3');
// echo 'inside3';
// isPointInPolygon(3.26611111, 6.31666667, wktToArray($polygon)) or die('outside4');
// echo 'inside4';
// isPointInPolygon(3.30000000, 6.20000000, wktToArray($polygon)) or die('outside5');
// echo 'inside5';

if( empty( $port_name ) ) {
    $url = sprintf(
        "https://api.datalastic.com/api/v0/port_find?api-key=%s&lat=%f&lon=%f&radius=%d",
        urlencode( $api_key ),
        $_REQUEST['lat'],
        $_REQUEST['lon'],
        10
    );

    // Fetch vessels in area
    $response = file_get_contents( $url );
    $data = json_decode( $response, true );
    if( isset( $data['data'] ) && !empty( $data['data'] ) ) {
        foreach( $data['data'] as $port_data ) {
            if(  in_array( $port_data['port_name'], $ports )) {
                $port_id = $port_data['uuid'];
                $port_name = $port_data['port_name'];
                
            }
        }

        if( empty( $port_name ) ) {
            $port_id = $data['data'][0]['uuid'];
            $port_name = $data['data'][0]['port_name'];
        }

        if( 'lagos' == strtolower( $port_name )) {
            $port_name = 'Lagos Complex';
        }
    } 
}

$table_name_sts = $table_prefix . 'coastalynk_sts';

// Create table if not exists
$sql = "CREATE TABLE IF NOT EXISTS $table_name_sts (
    id INT AUTO_INCREMENT,
    vessel1_uuid VARCHAR(50) default '',
    vessel1_name VARCHAR(255) default '',
    vessel1_mmsi VARCHAR(50) default '',
    vessel1_imo VARCHAR(50) default '',
    vessel1_country_iso VARCHAR(2) default '',
    vessel1_type VARCHAR(50) default '',
    vessel1_type_specific VARCHAR(255) default '',
    vessel1_lat VARCHAR(10) default '',
    vessel1_lon VARCHAR(10) default '',
    vessel1_speed float default 0,
    vessel1_navigation_status VARCHAR(50) default '',
    vessel1_draught float default 0,
    vessel1_completed_draught float default 0,
    vessel1_last_position_UTC TIMESTAMP,
    vessel1_signal ENUM('', 'AIS Gap','AIS Consistent') NOT NULL DEFAULT '',
    vessel2_uuid VARCHAR(50) default '',
    vessel2_name VARCHAR(255) default '',
    vessel2_mmsi VARCHAR(50) default '',
    vessel2_imo VARCHAR(50) default '',
    vessel2_country_iso VARCHAR(2) default '',
    vessel2_type VARCHAR(50) default '',
    vessel2_type_specific VARCHAR(255) default '',
    vessel2_lat VARCHAR(10) default '',
    vessel2_lon VARCHAR(10) default '',
    vessel2_speed float default 0,
    vessel2_navigation_status VARCHAR(50),
    vessel2_draught float default 0,
    vessel2_completed_draught float default 0,
    vessel2_last_position_UTC TIMESTAMP,
    vessel2_signal ENUM('', 'AIS Gap','AIS Consistent') NOT NULL DEFAULT '',
    port VARCHAR(255) default '',
    port_id VARCHAR(50) default '',
    distance float default 0,
    event_ref_id VARCHAR(30) default '', /* e.g. STS20251017-0001 */
    zone_type VARCHAR(15) default '',
    zone_ship VARCHAR(15) default '',
    zone_terminal_name VARCHAR(255) default '',
    start_date TIMESTAMP default Null,
    end_date TIMESTAMP default Null,
    remarks VARCHAR(255) default '',
    event_percentage float default 0,
    vessel_condition1 VARCHAR(15) default '', /**(Loaded/Ballast) */
    vessel_condition2 VARCHAR(15) default '', /**(Loaded/Ballast) */
    cargo_category_type  VARCHAR(255) default '',/* Crude, PMS, AGO, LPG etc.*/
    risk_level VARCHAR(10) default '',
    current_distance_nm float default 0,
    stationary_duration_hours float default 0,
    proximity_consistency VARCHAR(20) default '',
    data_points_analyzed float default 0,
    estimated_cargo float default 0,
    mother_vessel_number TINYINT(1) NULL DEFAULT '0', 
    operationmode  ENUM('', 'Loading','Discharge','STS') NOT NULL DEFAULT '', /* (Loading/Discharge/STS) */
    status varchar(30) NOT NULL DEFAULT '',
    is_email_sent ENUM('No','Yes') NOT NULL DEFAULT 'No',
    is_complete ENUM('No','Yes') NOT NULL DEFAULT 'No',
    is_disappeared ENUM('No','Yes') NOT NULL DEFAULT 'No',
    last_updated TIMESTAMP,
    PRIMARY KEY (id)
)";
if ( $mysqli->query($sql) !== TRUE ) {
    echo "Error: " . $sql . "<br>" . $mysqli->error;
}

$array_ids = [];
$array_uidds = [];

$url = sprintf(
    "https://api.datalastic.com/api/v0/vessel_inradius?api-key=%s&lat=%f&lon=%f&radius=%d",
    urlencode( $api_key ),
    $_REQUEST['lat'],
    $_REQUEST['lon'],
    $port_radius
);

$proximity_threshold = 555; // meters
$test_fetch = 0;
// Fetch vessels in area
$response = file_get_contents( $url );
$data = json_decode( $response, true );

if( isset( $data ) && isset( $data['data'] ) && isset( $data['data']['total'] ) && intval( $data['data']['total'] ) > 0 ) {
    $vessels = $data['data']['vessels'];
    
    // Check proximity
    foreach ($vessels as $v1) {
        if( !empty( $v1['type'] ) && str_contains( $v1['type'], 'Tanker' ) ) {
            
            foreach ($vessels as $v2) {
                
                if ($v1['uuid'] != $v2['uuid'] && !empty( $v2['type'] ) && str_contains($v2['type'], 'Tanker')) {  // && $test_fetch <=2
                    
                    echo '<br>detecting:'.$v1['uuid'].' != '.$v2['uuid'];                    
                    $detectresult = $detector->detectSTSTransfer($v1, $v2);
                    if( intval( $detectresult['sts_transfer_detected'] ) == 1 ) {
                        
                        $zone_type = 'No STS Zone';
                        $zone_ship = '';
                        $new_zone_terminal_name = $zone_terminal_name;
                        $param_v1_lat = floatval( $v1['lat'] );
                        $param_v1_lon = floatval( $v1['lon'] );
                        $sql = "SELECT *, ST_Distance_Sphere( POINT(lon, lat), POINT($param_v1_lon, $param_v1_lat) ) AS distance_meters FROM ".$table_name." where country_iso='NG' and port_type = 'PBA' HAVING distance_meters is not null and distance_meters > 5557 order by distance_meters asc limit 1;";
                        if( $result = $mysqli->query($sql) ) {
                            if( $obj = $result->fetch_object() ) {
                                $zone_type  = $obj->port_type;
                                $zone_ship  = "Mother Ship";
                                $new_zone_terminal_name = $obj->title;
                            }
                        }

                        $param_v2_lat = floatval( $v2['lat'] );
                        $param_v2_lon = floatval( $v2['lon'] );
                        if( empty( $zone_type ) ) {
                            $sql = "SELECT *, ST_Distance_Sphere( POINT(lon, lat), POINT($param_v2_lon, $param_v2_lat) ) AS distance_meters FROM ".$table_name." where country_iso='NG' and port_type = 'PBA' HAVING distance_meters is not null and distance_meters > 5557 order by distance_meters asc limit 1;";
                            if( $result = $mysqli->query($sql) ) {
                                if( $obj = $result->fetch_object() ) {
                                    $zone_type  = $obj->port_type; 
                                    $zone_ship  = "Daughter Ship";
                                    $new_zone_terminal_name = $obj->title;
                                }
                            }
                        }
                        
                        if( empty( $zone_type ) ) {
                            $sql = "SELECT * FROM ".$table_name." WHERE MBRWithin( POINT($param_v1_lon, $param_v1_lat), port_area ) limit 1;";
                            if( $result = $mysqli->query($sql) ) {
                                if( $obj = $result->fetch_object() ) {
                                    $zone_type  = $obj->port_type; 
                                    $zone_ship  = "Mother Ship";
                                    $new_zone_terminal_name = $obj->title;
                                }
                            }

                            // $vehicle_one = isPointInPolygon($v1['lat'], $v1['lon'], wktToArray($polygon));
                            // $vehicle_two = isPointInPolygon($v2['lat'], $v2['lon'], wktToArray($polygon));
                            
                            // if( $vehicle_one || $vehicle_two ) {
                            //     if( ! empty( $port_name ) ) {
                            //         $zone_terminal_name = $port_name;
                            //         $zone_type = 'Yes';
                            //     }
                            // }
                        }

                        if( empty( $zone_type ) ) {
                            $sql = "SELECT * FROM ".$table_name." WHERE MBRWithin( POINT($param_v2_lon, $param_v2_lat), port_area ) limit 1;";
                            if( $result = $mysqli->query($sql) ) {
                                if( $obj = $result->fetch_object() ) {
                                    $zone_type  = $obj->port_type; 
                                    $zone_ship  = "Mother Ship";
                                    $new_zone_terminal_name = $obj->title;
                                }
                            }
                        }
                        
                        $sql = "select id, vessel1_uuid, vessel2_uuid, is_email_sent, vessel1_draught, vessel2_draught, vessel1_last_position_UTC, vessel2_last_position_UTC, vessel1_signal, vessel2_signal from ".$table_name_sts." where ( ( vessel1_uuid='".$mysqli->real_escape_string($v1['uuid'])."' and vessel2_uuid='".$mysqli->real_escape_string($v2['uuid'])."' ) or ( vessel2_uuid='".$mysqli->real_escape_string($v1['uuid'])."' and vessel1_uuid='".$mysqli->real_escape_string($v2['uuid'])."' ) ) and is_disappeared = 'No'";
                        $result2 = $mysqli->query( $sql );
                        $num_rows = mysqli_num_rows( $result2 );
                        if( $num_rows == 0 ) {
                            $vehicel_1 = get_datalastic_field( $v1['uuid'], '', true );
                            $vehicel_2 = get_datalastic_field( $v2['uuid'], '', true );
                            $dist = haversineDistance( $v1['lat'], $v1['lon'], $v2['lat'], $v2['lon'] );
                            $v1_navigation_status = $vehicel_1[ 'navigation_status' ];
                            $v2_navigation_status = $vehicel_2[ 'navigation_status' ];

                            $v1_current_draught = floatval( $vehicel_1[ 'current_draught' ]);
                            $v2_current_draught = floatval( $vehicel_2[ 'current_draught' ]);
                            $sql = "select max(id) as id from ".$table_name_sts;
                            $result2 = $mysqli->query($sql);
                            $pk_id = $result2->fetch_column();
                            $ref_id = 'STS'.date('Ymd').str_pad( $pk_id, strlen( $pk_id ) + 4, '0', STR_PAD_LEFT);
                            
                            $predicted_cargo_1 = $detectresult['vessel_1']['predicted_cargo'];
                            $predicted_cargo_2 = $detectresult['vessel_2']['predicted_cargo'];

                            $current_distance_nm        = $detectresult['proximity_analysis']['current_distance_nm'];
                            $stationary_duration_hours  = $detectresult['proximity_analysis']['stationary_duration_hours'];
                            $proximity_consistency      = $detectresult['proximity_analysis']['proximity_consistency'];
                            $data_points_analyzed       = $detectresult['proximity_analysis']['data_points_analyzed'];
                            
                            $risk_level     = $detectresult['risk_assessment']['risk_level'];
                            $confidence     = $detectresult['risk_assessment']['confidence'];
                            $remarks        = $detectresult['risk_assessment']['remarks'];
                            
                            $timestamp      = $detectresult['timestamp'];

                            $vessel_condition1 = $detectresult['vessel_1']['vessel_condition'];
                            
                            $vessel_condition2 = $detectresult['vessel_2']['vessel_condition'];
                            
                            $operation_mode = $detectresult['operation_mode']; 
                            $status = $detectresult['status']; 
                            if( empty( $status ) ) {
                                $status = 'Detected';
                            }

                            
                            $sql = "INSERT INTO $table_name_sts (vessel1_uuid , vessel1_name, vessel1_mmsi, vessel1_imo, vessel1_country_iso, vessel1_type, vessel1_type_specific, vessel1_lat, vessel1_lon,vessel1_speed,vessel1_navigation_status, vessel1_draught, vessel1_last_position_UTC, vessel2_uuid , vessel2_name, vessel2_mmsi, vessel2_imo, vessel2_country_iso, vessel2_type, vessel2_type_specific, vessel2_lat, vessel2_lon,vessel2_speed,vessel2_navigation_status,vessel2_draught,vessel2_last_position_UTC, distance, port, port_id, last_updated, start_date, event_ref_id,remarks,event_percentage, cargo_category_type, risk_level, vessel_condition1,vessel_condition2, zone_terminal_name, operationmode, status, current_distance_nm, stationary_duration_hours, proximity_consistency, data_points_analyzed, is_disappeared,vessel1_signal, vessel2_signal, zone_type, zone_ship )
                                    VALUES (
                                        '" . $mysqli->real_escape_string($v1['uuid']) . "',
                                        '" . $mysqli->real_escape_string($v1['name']) . "',
                                        '" . $mysqli->real_escape_string($v1['mmsi']) . "',
                                        '" . $mysqli->real_escape_string($v1['imo']) . "',
                                        '" . $mysqli->real_escape_string($v1['country_iso']) . "',
                                        '" . $mysqli->real_escape_string($v1['type']) . "',
                                        '" . $mysqli->real_escape_string($v1['type_specific']) . "',
                                        '" . $mysqli->real_escape_string($v1['lat']) . "',
                                        '" . $mysqli->real_escape_string($v1['lon']) . "',
                                        '" . floatval($v1['speed']) . "',
                                        '" . $v1_navigation_status . "',
                                        '" . $v1_current_draught . "',
                                        '" . date('Y-m-d H:i:s', strtotime($v1['last_position_UTC'])) . "',
                                        '" . $mysqli->real_escape_string($v2['uuid']) . "',
                                        '" . $mysqli->real_escape_string($v2['name']) . "',
                                        '" . $mysqli->real_escape_string($v2['mmsi']) . "',
                                        '" . $mysqli->real_escape_string($v2['imo']) . "',
                                        '" . $mysqli->real_escape_string($v2['country_iso']) . "',
                                        '" . $mysqli->real_escape_string($v2['type']) . "',
                                        '" . $mysqli->real_escape_string($v2['type_specific']) . "',
                                        '" . $mysqli->real_escape_string($v2['lat']) . "',
                                        '" . $mysqli->real_escape_string($v2['lon']) . "',
                                        '" . floatval($v2['speed']) . "',
                                        '" . $v2_navigation_status . "',
                                        '" . $v2_current_draught . "',
                                        '" . date('Y-m-d H:i:s', strtotime($v2['last_position_UTC'])) . "',
                                        '" . floatval($dist) . "',
                                        '" . $mysqli->real_escape_string( $port_name ) . "',
                                        '" . $mysqli->real_escape_string( $port_id ) . "',
                                        '".$last_updated."', NOW(), '".$ref_id."',
                                        '".$remarks."', 
                                        '".floatval( $confidence )."', 
                                        '".$predicted_cargo_1."', 
                                        '".$risk_level."',
                                        '".$vessel_condition1."',
                                        '".$vessel_condition2."',
                                        '".$new_zone_terminal_name."',
                                        '".$operation_mode."',
                                        '".$status."',
                                        '".$current_distance_nm."',
                                        '".$stationary_duration_hours."',
                                        '".$proximity_consistency."',
                                        '".$data_points_analyzed."',
                                        'No', 'AIS Consistent', 'AIS Consistent', '".$zone_type."', '".$zone_ship."'
                                        )";
                        
                            if ($mysqli->query( $sql ) !== TRUE) {
                                echo "Error: " . $sql . "<br>" . $mysqli->error;
                            } else {
                                $test_fetch++;
                                $insert_id = $array_ids[] = $mysqli->insert_id;
                                $array_uidds[] = [ $v1['uuid'], $v2['uuid'] ];
                                coastalynk_log_entry($insert_id, 'STS Between '.$v1['name'].' and '.$v2['name'].': '.$remarks, $type='sts');

                                $coastalynk_sts_body = str_replace( "[vessel1_uuid]", $v1['uuid'], $coastalynk_sts_body_original );
                                $coastalynk_sts_body = str_replace( "[vessel1_name]", $v1['name'], $coastalynk_sts_body );
                                $coastalynk_sts_body = str_replace( "[vessel1_mmsi]", $v1['mmsi'], $coastalynk_sts_body );
                                $coastalynk_sts_body = str_replace( "[vessel1_imo]", $v1['imo'], $coastalynk_sts_body );
                                $coastalynk_sts_body = str_replace( "[vessel1_country_iso]", $v1['country_iso'], $coastalynk_sts_body );
                                $coastalynk_sts_body = str_replace( "[vessel1_type]", $v1['type'], $coastalynk_sts_body );
                                $coastalynk_sts_body = str_replace( "[vessel1_type_specific]", $v1['type_specific'], $coastalynk_sts_body );
                                $coastalynk_sts_body = str_replace( "[vessel1_lat]", $v1['lat'], $coastalynk_sts_body );
                                $coastalynk_sts_body = str_replace( "[vessel1_lon]", $v1['lon'], $coastalynk_sts_body );
                                $coastalynk_sts_body = str_replace( "[vessel1_speed]", $v1['speed'], $coastalynk_sts_body );
                                $coastalynk_sts_body = str_replace( "[vessel1_navigation_status]", $v1_navigation_status, $coastalynk_sts_body );
                                $coastalynk_sts_body = str_replace( "[vessel1_draught]", $v1_current_draught, $coastalynk_sts_body );
                                $coastalynk_sts_body = str_replace( "[vessel1_country_flag]", $siteurl.'/flags/'.strtolower($v1['country_iso']).'.jpg', $coastalynk_sts_body );
                                $coastalynk_sts_body = str_replace( "[sts-page-url]", $siteurl.'/sts-map/', $coastalynk_sts_body );            
                                $coastalynk_sts_body = str_replace( "[vessel1_last_position_UTC]", $v1['last_position_UTC'], $coastalynk_sts_body );
                                $coastalynk_sts_body = str_replace( "[vessel2_uuid]", $v2['uuid'], $coastalynk_sts_body );
                                $coastalynk_sts_body = str_replace( "[vessel2_name]", $v2['name'], $coastalynk_sts_body );
                                $coastalynk_sts_body = str_replace( "[vessel2_mmsi]", $v2['mmsi'], $coastalynk_sts_body );
                                $coastalynk_sts_body = str_replace( "[vessel2_imo]", $v2['imo'], $coastalynk_sts_body );
                                $coastalynk_sts_body = str_replace( "[vessel2_country_iso]", $v2['country_iso'], $coastalynk_sts_body );
                                $coastalynk_sts_body = str_replace( "[vessel2_type]", $v2['type'], $coastalynk_sts_body );
                                $coastalynk_sts_body = str_replace( "[vessel2_type_specific]", $v2['type_specific'], $coastalynk_sts_body );
                                $coastalynk_sts_body = str_replace( "[vessel2_lat]", $v2['lat'], $coastalynk_sts_body );
                                $coastalynk_sts_body = str_replace( "[vessel2_lon]", $v2['lon'], $coastalynk_sts_body );
                                $coastalynk_sts_body = str_replace( "[vessel2_speed]", $v2['speed'], $coastalynk_sts_body );
                                $coastalynk_sts_body = str_replace( "[vessel2_navigation_status]", $v2_navigation_status, $coastalynk_sts_body );
                                $coastalynk_sts_body = str_replace( "[vessel2_draught]", $v2_current_draught, $coastalynk_sts_body );
                                $coastalynk_sts_body = str_replace( "[vessel2_country_flag]", $siteurl.'/flags/'.strtolower($v2['country_iso']).'.jpg', $coastalynk_sts_body );
                                $coastalynk_sts_body = str_replace( "[vessel2_last_position_UTC]", $v2['last_position_UTC'], $coastalynk_sts_body );
                                $coastalynk_sts_body = str_replace( "[distance]", $dist, $coastalynk_sts_body );
                                $coastalynk_sts_body = str_replace( "[port]", $port_name, $coastalynk_sts_body );
                                $coastalynk_sts_body = str_replace( "[port_id]", $port_id, $coastalynk_sts_body );
                                $coastalynk_sts_body = str_replace( "[last_updated]", date('Y-m-d H:i:s', strtotime($v1['last_position_UTC'])), $coastalynk_sts_body ); 

                                $coastalynk_sts_email_subject = str_replace( "[vessel1_uuid]", $v1['uuid'], $coastalynk_sts_email_subject_original );
                                $coastalynk_sts_email_subject = str_replace( "[vessel1_name]", $v1['name'], $coastalynk_sts_email_subject );
                                $coastalynk_sts_email_subject = str_replace( "[vessel1_mmsi]", $v1['mmsi'], $coastalynk_sts_email_subject );
                                $coastalynk_sts_email_subject = str_replace( "[vessel1_imo]", $v1['imo'], $coastalynk_sts_email_subject );
                                $coastalynk_sts_email_subject = str_replace( "[vessel1_country_iso]", $v1['country_iso'], $coastalynk_sts_email_subject );
                                $coastalynk_sts_email_subject = str_replace( "[vessel1_type]", $v1['type'], $coastalynk_sts_email_subject );
                                $coastalynk_sts_email_subject = str_replace( "[vessel1_type_specific]", $v1['type_specific'], $coastalynk_sts_email_subject );
                                $coastalynk_sts_email_subject = str_replace( "[vessel1_lat]", $v1['lat'], $coastalynk_sts_email_subject );
                                $coastalynk_sts_email_subject = str_replace( "[vessel1_lon]", $v1['lon'], $coastalynk_sts_email_subject );
                                $coastalynk_sts_email_subject = str_replace( "[vessel1_speed]", $v1['speed'], $coastalynk_sts_email_subject );
                                $coastalynk_sts_email_subject = str_replace( "[vessel1_navigation_status]", $v1_navigation_status, $coastalynk_sts_email_subject );
                                $coastalynk_sts_email_subject = str_replace( "[vessel1_draught]", $v1_current_draught, $coastalynk_sts_email_subject );                   
                                $coastalynk_sts_email_subject = str_replace( "[sts-page-url]", $siteurl.'sts-map/', $coastalynk_sts_email_subject );
                                $coastalynk_sts_email_subject = str_replace( "[vessel2_draught]", $v2_current_draught, $coastalynk_sts_email_subject );            
                                $coastalynk_sts_email_subject = str_replace( "[vessel1_last_position_UTC]", $v1['last_position_UTC'], $coastalynk_sts_email_subject );
                                $coastalynk_sts_email_subject = str_replace( "[vessel2_uuid]", $v2['uuid'], $coastalynk_sts_email_subject );
                                $coastalynk_sts_email_subject = str_replace( "[vessel2_name]", $v2['name'], $coastalynk_sts_email_subject );
                                $coastalynk_sts_email_subject = str_replace( "[vessel2_mmsi]", $v2['mmsi'], $coastalynk_sts_email_subject );
                                $coastalynk_sts_email_subject = str_replace( "[vessel2_imo]", $v2['imo'], $coastalynk_sts_email_subject );
                                $coastalynk_sts_email_subject = str_replace( "[vessel2_country_iso]", $v2['country_iso'], $coastalynk_sts_email_subject );
                                $coastalynk_sts_email_subject = str_replace( "[vessel2_type]", $v2['type'], $coastalynk_sts_email_subject );
                                $coastalynk_sts_email_subject = str_replace( "[vessel2_type_specific]", $v2['type_specific'], $coastalynk_sts_email_subject );
                                $coastalynk_sts_email_subject = str_replace( "[vessel2_lat]", $v2['lat'], $coastalynk_sts_email_subject );
                                $coastalynk_sts_email_subject = str_replace( "[vessel2_lon]", $v2['lon'], $coastalynk_sts_email_subject );
                                $coastalynk_sts_email_subject = str_replace( "[vessel2_speed]", $v2['speed'], $coastalynk_sts_email_subject );
                                $coastalynk_sts_email_subject = str_replace( "[vessel2_navigation_status]", $v2_navigation_status, $coastalynk_sts_email_subject );
                                $coastalynk_sts_email_subject = str_replace( "[vessel2_last_position_UTC]", $v2['last_position_UTC'], $coastalynk_sts_email_subject );
                                $coastalynk_sts_email_subject = str_replace( "[distance]", $dist, $coastalynk_sts_email_subject );
                                $coastalynk_sts_email_subject = str_replace( "[port]", $port_name, $coastalynk_sts_email_subject );
                                $coastalynk_sts_email_subject = str_replace( "[port_id]", $port_id, $coastalynk_sts_email_subject );

                                $coastalynk_sts_email_subject = str_replace( "[last_updated]", date('Y-m-d H:i:s', strtotime($v1['last_position_UTC'])), $coastalynk_sts_email_subject );

                                $mail = new PHPMailer(true);
                                try {
                                    // Server settings
                                    $mail->isSMTP();
                                    $mail->Host       = 'smtp.gmail.com';
                                    $mail->SMTPAuth   = true;
                                    $mail->Username   = smtp_user_name; // Your Gmail address
                                    $mail->Password   = smtp_password; // Generated App Password
                                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // Use TLS encryption
                                    $mail->Port       = 587; // TCP port for TLS

                                    // Recipients
                                    $mail->setFrom('coastalynk@gmail.com', 'CoastaLynk');
                                    $mail->addAddress( $coatalynk_site_admin_email, 'CoastaLynk' );
                                    $mail->addAddress( $coatalynk_npa_admin_email, 'NPA' );
                                    $mail->addAddress( $coatalynk_finance_admin_email, 'Finance Department' );
                                    $mail->addAddress( $coatalynk_nimasa_admin_email, 'NIMASA' );

                                    // Content
                                    $mail->isHTML(true); // Set email format to HTML
                                    $mail->Subject = $coastalynk_sts_email_subject;
                                    $mail->Body    = $coastalynk_sts_body;
                                    $mail->AltBody = strip_tags($coastalynk_sts_body);

                                    $mail->send();

                                    $sql = "update ".$table_name_sts." set is_email_sent='Yes' where id='".$insert_id."'";
                                    $mysqli->query($sql);
                                    coastalynk_log_entry($insert_id, 'STS Between '.$v1['name'].' and '.$v2['name'].': Email sent successfully!', 'sts');

                                    echo 'Email sent successfully!';
                                } catch (Exception $e) {
                                    echo "Email could not be sent. Error: {$mail->ErrorInfo}";
                                }
                            }
                        } else {
                            $row = mysqli_fetch_assoc($result2);
                            $vessel1_signal = coastalynk_signal_status( $row['vessel1_last_position_UTC'], date('Y-m-d H:i:s', strtotime($v1['last_position_UTC'])) );
                            $vessel2_signal = coastalynk_signal_status( $row['vessel2_last_position_UTC'], date('Y-m-d H:i:s', strtotime($v2['last_position_UTC'])) );

                            $sql = "update ".$table_name_sts." set vessel1_signal = '".$vessel1_signal."', vessel2_signal = '".$vessel2_signal."' where id='".$row['id']."'";
                            $mysqli->query($sql);
                            
                            $array_ids[] = $row['id'];
                            $array_uidds[] = [ $row['vessel1_uuid'], $row['vessel2_uuid'] ];
                        }

                        $result2->free();
                    }
                }
            }
        }

      
    }
}

$sql = "select id, vessel1_uuid, vessel2_uuid, end_date, vessel1_draught, vessel1_last_position_UTC, vessel2_last_position_UTC, vessel2_draught, vessel1_completed_draught, vessel2_completed_draught from ".$table_name_sts." where is_disappeared = 'No' and port='".$port_name."' and is_complete = 'Yes';";
$result3 = $mysqli->query( $sql );
$num_rows = mysqli_num_rows( $result3 );
if( $num_rows > 0 ) {
    while( $row = mysqli_fetch_array($result3, MYSQLI_ASSOC) ) {
        
        $dateTime1 = new DateTime($row['end_date']);
        $dateTime2 = new DateTime();
        $interval = $dateTime2->diff($dateTime1);
        
        $log = '';
        $v1 = get_datalastic_field( $row['vessel1_uuid'], '', true );
        $v2 = get_datalastic_field( $row['vessel2_uuid'], '', true );
        $vessel1_signal = coastalynk_signal_status( $row[ 'vessel1_last_position_UTC' ], date( 'Y-m-d H:i:s', strtotime( $v1[ 'last_position_UTC' ] ) ) );
        $vessel2_signal = coastalynk_signal_status( $row[ 'vessel2_last_position_UTC' ], date( 'Y-m-d H:i:s', strtotime( $v2[ 'last_position_UTC' ] ) ) );
        $v1_current_draught = $v1['current_draught'];
        $v2_current_draught = $v2['current_draught'];

        $estimated_cargo = 0;
        $draught_1_diff = ( floatval( $v1_current_draught ) - floatval( $row['vessel1_draught'] ) );
        $draught_2_diff = ( floatval( $v2_current_draught ) - floatval( $row['vessel2_draught'] ) );
        $log = 'Vessel 1 Draught Difference: '.$draught_1_diff;
        $log .= ', Vessel 2 Draught Difference: '.$draught_2_diff;
        $log .= ', Vessel 1 Signal: '.$vessel1_signal;
        $log .= ', Vessel 2 Signal: '.$vessel2_signal;
        
        $mother_vessel_number = '';
        if( $draught_1_diff >= 0.3 || $draught_1_diff <= -0.3 ) {
            $estimated_cargo = (floatval( $v1_current_draught ) - floatval( $row['vessel1_draught'] ) ) / 1000;
            if( $estimated_cargo > 0 ) {
                $mother_vessel_number = " mother_vessel_number = '2',";
            }
        } else if( $draught_2_diff >= 0.3 || $draught_2_diff <= -0.3 ) {
            $estimated_cargo = (floatval( $v2_current_draught ) - floatval( $row['vessel2_draught'] ) ) / 1000;
            if( $estimated_cargo > 0 ) {
                $mother_vessel_number = " mother_vessel_number = '1',";
            }
        }
        
        $updatable_fields = '';
        if( floatval( $v1_current_draught ) > 0 ) {
            $updatable_fields .= "vessel1_completed_draught='".floatval( $v1_current_draught )."', ";
        }
       
        if( floatval( $v2_current_draught ) > 0 ) {
            $updatable_fields .= " vessel2_completed_draught='" . floatval( $v2_current_draught ) . "', ";
        }

        $total_hours = $interval->days * 24 + $interval->h + ($interval->i / 60) + ($interval->s / 3600);
        
        // if( $draught_1_diff >= 0.3 ) {
        //     $mother_vessel_number = " mother_vessel_number = '2',";
        // } else if( $draught_2_diff >= 0.3 ) {
        //     $mother_vessel_number = " mother_vessel_number = '1',";
        // }
        
        // if( $total_hours <= 8 ) {
        //     $status = 'Completed';
        //     if( $draught_1_diff <= 0.3 && $draught_1_diff >= -0.3 && $draught_2_diff <= 0.3 && $draught_2_diff >= -0.3 ) {
        //         $status = 'No Change';
        //     } else {
        //         if( ($draught_1_diff >= 0.3 && $draught_2_diff >= 0.3) || ( $draught_1_diff < -0.3 && $draught_2_diff < -0.3 ) ) {
        //             $status = 'Inconclusive';
        //         } else {
        //             $updatable_fields .= " is_disappeared = 'Yes', ";
        //         }
        //     }

        //     $log .= ', Status: '.$status;
        //     $sql = "update ".$table_name_sts." set ".$mother_vessel_number.$updatable_fields." status = '".$status."',vessel1_signal = '".$vessel1_signal."', vessel2_signal = '".$vessel2_signal."', estimated_cargo = '".$estimated_cargo."', last_updated = NOW() where id='".$row['id']."'";
        //     $mysqli->query($sql);

        //     coastalynk_log_entry($row['id'], 'STS Between '.$v1['name'].' and '.$v2['name'].' upto 8hrs: '.$log, 'sts');
        // } else 
        if( $total_hours <= 9 && $total_hours >= 6  ) {

            $status = 'Completed';
            if( $draught_1_diff <= 0.3 && $draught_1_diff >= -0.3 && $draught_2_diff <= 0.3 && $draught_2_diff >= -0.3 ) {
                $status = 'Awaiting Draught Update';
            } else {
                if( ($draught_1_diff >= 0.3 && $draught_2_diff >= 0.3) || ( $draught_1_diff < -0.3 && $draught_2_diff < -0.3 ) ) {
                    $status = 'Inconclusive';
                } 
                // else {
                //     //$updatable_fields .= " is_disappeared = 'Yes', ";
                // }
            }

            $log .= ', Status:'.$status;

            $sql = "update ".$table_name_sts." set ".$mother_vessel_number.$updatable_fields." status = '".$status."',vessel1_signal = '".$vessel1_signal."', vessel2_signal = '".$vessel2_signal."', estimated_cargo = '".$estimated_cargo."', last_updated = NOW() where id='".$row['id']."'";
            $mysqli->query($sql);

            coastalynk_log_entry($row['id'], 'STS Between '.$v1['name'].' and '.$v2['name'].' upto 12hrs: '.$log, 'sts');
        } else 
        if( $total_hours >= 24 ) {
            
            $status = 'Completed';
            if( $draught_1_diff <= 0.3 && $draught_1_diff >= -0.3 && $draught_2_diff <= 0.3 && $draught_2_diff >= -0.3 ) {
                $status = 'Pending Manual Review';
            } else {
                if( ($draught_1_diff >= 0.3 && $draught_2_diff >= 0.3) || ( $draught_1_diff < -0.3 && $draught_2_diff < -0.3 ) ) {
                    $status = 'Inconclusive';
                }
            }
            
            $log .= ', Status:'.$status;

            $sql = "update ".$table_name_sts." set status = '".$status."',last_updated = NOW() where id='".$row['id']."'";
            $mysqli->query($sql);

            coastalynk_log_entry($row['id'], 'STS Between '.$v1['name'].' and '.$v2['name'].' after 24hrs: '.$log, 'sts');
        }
    }
}

if( count( $array_ids ) == 0 ) {
    $array_ids[] = 0;
}

if( count( $array_ids ) > 0 ) {
    $array_ids_implode = implode( ',', array_unique($array_ids) );
    
    $sql = "select id, vessel1_uuid, vessel2_uuid, vessel1_draught, vessel1_last_position_UTC, vessel2_last_position_UTC, vessel2_draught from ".$table_name_sts." where is_complete='No' and port='".$port_name."' and id not in (".$array_ids_implode.")";
    $result4 = $mysqli->query( $sql );
    $num_rows = mysqli_num_rows( $result4 );
    if( $num_rows > 0 ) {
        while( $row = mysqli_fetch_array( $result4, MYSQLI_ASSOC ) ) {
            
            $estimated_cargo = 0;
            $v1 = get_datalastic_field( $row['vessel1_uuid'], '', true );
            $v2 = get_datalastic_field( $row['vessel2_uuid'], '', true );
            
            $vessel1_signal = coastalynk_signal_status( $row['vessel1_last_position_UTC'], date('Y-m-d H:i:s', strtotime($v1['last_position_UTC'])) );
            $vessel2_signal = coastalynk_signal_status( $row['vessel2_last_position_UTC'], date('Y-m-d H:i:s', strtotime($v2['last_position_UTC'])) );
            
            $v1_current_draught = $v1['current_draught'];
            $v2_current_draught = $v2['current_draught'];
          

            $draught_1_diff = ( floatval( $v1_current_draught ) - floatval($row['vessel1_draught']) );
            $draught_2_diff = ( floatval( $v2_current_draught ) - floatval($row['vessel2_draught']) );
            
            if( $draught_1_diff >= 0.3 || $draught_1_diff <= -0.3 ) {
                $estimated_cargo = (floatval( $v1_current_draught ) - floatval( $row['vessel1_draught'] )) / 1000;
            } else if( $draught_2_diff >= 0.3 || $draught_2_diff <= -0.3 ) {
                $estimated_cargo = (floatval( $v2_current_draught ) - floatval( $row['vessel2_draught'] )) / 1000;
            }
            
            $mother_vessel_number = '';
            if( $draught_1_diff >= 0.3 && $draught_1_diff <= -0.3 ) {
                $mother_vessel_number = " mother_vessel_number = '1',";
            } else if( $draught_2_diff >= 0.3 && $draught_2_diff <= -0.3 ) {
                $mother_vessel_number = " mother_vessel_number = '2',";
            }
            $status = 'Completed';
            if( $draught_1_diff <= 0.3 && $draught_1_diff >= -0.3 && $draught_2_diff <= 0.3 && $draught_2_diff >= -0.3 ) {
                $status = 'Ongoing';
            } else {
                
                if( ($draught_1_diff >= 0.3 && $draught_2_diff >= 0.3) || ( $draught_1_diff < -0.3 && $draught_2_diff < -0.3 ) ) {
                    $status = 'Inconclusive';
                }
            }

            $log = 'Vessel 1 Draught Difference: '.$draught_1_diff;
            $log .= ', Vessel 2 Draught Difference: '.$draught_2_diff;
            $log .= ', Vessel 1 Signal: '.$vessel1_signal;
            $log .= ', Vessel 2 Signal: '.$vessel2_signal;
            
            $sql = "update ".$table_name_sts." set ".$mother_vessel_number."is_complete = 'Yes', status = '".$status."',vessel1_signal = '".$vessel1_signal."', vessel2_signal = '".$vessel2_signal."', estimated_cargo = '".$estimated_cargo."', vessel1_completed_draught = '".floatval( $v1_current_draught )."', vessel2_completed_draught = '".floatval( $v2_current_draught)."', end_date = NOW(), last_updated = NOW() where is_complete='No' and id='".$row['id']."'";
            $mysqli->query($sql);

            coastalynk_log_entry($row['id'], 'STS Between '.$v1['name'].' and '.$v2['name'].' after vessels leave the area: '.$log, 'sts');
        }
    }
}


$result->free(); // Free the result set

$sql = "SELECT id as total FROM $table_name_sts where last_updated = ( select max( last_updated ) from $table_name_sts )";
$result = $mysqli->query($sql);
$num_rows = mysqli_num_rows($result);
coastalynk_update_summary('STS', $num_rows);

$mysqli->close(); // Close the database connection