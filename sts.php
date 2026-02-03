<?php
set_time_limit(0);
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

use function PHPSTORM_META\elementType;

ini_set( "display_errors", "On" );
error_reporting(E_ALL);
define( 'ALLOWED_STS_RANGE_NM', 0.6479482 ); //0.107991
define( 'ALLOWED_STS_RANGE_METERS', 1200 ); //0.107991
define( 'ALLOWED_STS_END_RANGE_M', 1500 ); 
define('ALLOWED_STS_END_RANGE_NM', 0.81); // 1500 meters in nautical miles (~0.81 NM)
define( 'ALLOWED_STS_MAX_TRANSFER_HOURS', 8 );
define('ALLOWED_STS_END_DURATION_MINUTES', 20); // Time threshold for distance check

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/sts-predictions.php';
///require_once __DIR__ . '/test.php';
require_once __DIR__ . '/after-draugt.php';
global $table_prefix, $vessel_product_type;
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

$heading_threshold = 25;               // degrees
$sog_threshold = 0.5;                  // knots
$heading_end_threshold = 40;           // degrees
$sog_end_threshold = 1.0;              // knots
$joining_minutes_threshold = 10;              // knots
// $heading_threshold = 45;               // degrees
// $sog_threshold = 5.0;                  // knots
// $heading_end_threshold = 50;           // degrees
// $sog_end_threshold = 6.0;              // knots

function search_vessel_by_name( $name, $mmsi ) {
    sleep(1);
    $api_key                        = get_option_data('coatalynk_datalastic_apikey');
    $url = 'https://api.datalastic.com/api/v0/vessel_find?api-key='.urlencode($api_key).'&name='.urlencode($name);
    $response = file_get_contents($url);
    $data = json_decode($response, true);
    if( is_array( $data['data'] ) && count( $data['data'] ) ) {
        foreach( $data['data'] as $vessel ) {
         
            if( $vessel['mmsi'] == $mmsi ) {
                return $vessel;
            }
        }
    }

    return false;
}


$coastalynk_sts_email_subject_original = get_option_data( 'coastalynk_sts_email_subject' );
$coastalynk_sts_email_subject_original = !empty( $coastalynk_sts_email_subject_original ) ? $coastalynk_sts_email_subject_original : 'Coastalynk STS Alert - [port]';
$coastalynk_sts_body_original 	    = get_option_data( 'coastalynk_sts_body' );
$strbody = "Dear Sir/Madam,
\n
<p>This is an automatic notification from Coastalynk Maritime Intelligence regarding a Ship-to-Ship (STS) operation detected at [port].</p>\n
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
\n\n
<p>View on Coastalynk Map(<a href='[sts-page-url]'>Click Here</a>)</p>
\n
<p>This notification is part of Coastalynk\'s effort to provide real-time intelligence to support anti-bunkering enforcement, maritime security, and revenue protection.</p>
\n
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

$lat = $argv[1];
$lon = $argv[2];

if( empty( $lat ) ) {
    $lat = $mysqli->real_escape_string( floatval( $_REQUEST['lat'] ) );
}

if( empty( $lon ) ) {
    $lon = $mysqli->real_escape_string( floatval( $_REQUEST['lon'] ) );
}

if( empty( $lat ) || empty( $lon ) ) {
    echo 'Please provide lat and lon as query params';
    exit;
} else {
    $param_lat = $mysqli->real_escape_string( floatval( $lat ) );
    $param_lon = $mysqli->real_escape_string( floatval( $lon ) );
}

//coastalynk_log_entry(0, 'Started the console cron with Latitude:'.$lat.' and Longitude:'.$lon.' for STS operatoin.', 'sts');
$zone_terminal_name = '';
$candidates = [];
$last_updated = date('Y-m-d H:i:s');
$port_id = '';
$port_name = '';
$polygon = null;
$ports = [];
$port_link_index = 1;
$port_radius = 50;

$detector = new STSTransferDetector( $api_key );
$table_name = $table_prefix . 'coastalynk_ports';
$sql = "select * from ".$table_name." where country_iso='NG' and port_type in( 'Coastal Zone', 'Territorial Zone', 'EEZ' ) order by title";
$i = 0;
if ($result = $mysqli->query($sql)) {
    while ( $obj = $result->fetch_object() ) { 
        echo '<br>';
        $url = sprintf(
                "https://coastalynk.com/coastalynk_crons/sts.php?lat=%f&lon=%f",
                $obj->lat,
                $obj->lon
            );
        echo '<br>'.($obj->port_id).' - <a href="'.$url.'" target="_blank">'.$url.'        '.$obj->title.'</a>';
    }
}
$result->free();
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
$result->free();

if( empty( $port_name ) ) {
    $url = sprintf(
        "https://api.datalastic.com/api/v0/port_find?api-key=%s&lat=%f&lon=%f&radius=%d",
        urlencode( $api_key ),
        $lat,
        $lon,
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

$event_table_mother = $table_prefix . 'coastalynk_sts_events';
$event_table_daughter = $table_prefix . 'coastalynk_sts_event_detail';
$sql = "CREATE TABLE IF NOT EXISTS `".$event_table_mother."` (
  id INT AUTO_INCREMENT,
  `uuid` varchar(50) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `mmsi` varchar(50) DEFAULT NULL,
  `imo` varchar(50) DEFAULT NULL,
  `country_iso` varchar(2) DEFAULT NULL,
  `type` varchar(50) DEFAULT NULL,
  `type_specific` varchar(255) DEFAULT NULL,
  `lat` varchar(10) DEFAULT NULL,
  `lon` varchar(10) DEFAULT NULL,
  `speed` float DEFAULT 0,
  `navigation_status` varchar(50) DEFAULT NULL,
  `draught` float DEFAULT 0,
  `completed_draught` float DEFAULT 0,
  `last_position_UTC` timestamp NULL DEFAULT NULL,
  `ais_signal` enum('','AIS Signal Gap Detected','AIS  Consistent Signal Detected') NOT NULL,
  `deadweight` float DEFAULT 0,
  `gross_tonnage` float DEFAULT 0,
  `port` varchar(255) DEFAULT '',
  `port_id` varchar(50) DEFAULT '',
  `distance` float DEFAULT 0,
  `event_ref_id` varchar(30) DEFAULT '',
  `zone_type` varchar(15) DEFAULT '',
  `zone_ship` varchar(255) DEFAULT '',
  `zone_terminal_name` varchar(255) DEFAULT '',
  `start_date` timestamp NULL DEFAULT NULL,
  `end_date` timestamp NULL DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT '',  
  `is_email_sent` enum('No','Yes') NOT NULL DEFAULT 'No',
  `is_complete` enum('No','Yes') NOT NULL DEFAULT 'No',
  `is_disappeared` enum('No','Yes') NOT NULL DEFAULT 'No',
  `last_updated` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (id)
)";

if ( $mysqli->query($sql) !== TRUE ) {
    echo "Error: " . $sql . "\n" . $mysqli->error;
}

$sql = "CREATE TABLE IF NOT EXISTS `".$event_table_daughter."` (
  id INT AUTO_INCREMENT,
  `event_id` int(10) NOT NULL DEFAULT 0,
  `uuid` varchar(50) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `mmsi` varchar(50) DEFAULT NULL,
  `imo` varchar(50) DEFAULT NULL,
  `country_iso` varchar(2) DEFAULT NULL,
  `type` varchar(50) DEFAULT NULL,
  `type_specific` varchar(255) DEFAULT NULL,
  `lat` varchar(10) DEFAULT NULL,
  `lon` varchar(10) DEFAULT NULL,
  `speed` float DEFAULT 0,
  `navigation_status` varchar(50) DEFAULT NULL,
  `draught` float DEFAULT 0,
  `completed_draught` float DEFAULT 0,
  `last_position_UTC` timestamp NULL DEFAULT NULL,
  `deadweight` float DEFAULT 0,
  `gross_tonnage` float DEFAULT 0,
  `draught_change` float DEFAULT 0,
  `ais_signal` enum('','AIS Signal Gap Detected','AIS  Consistent Signal Detected') DEFAULT NULL,
  `joining_date` timestamp NULL DEFAULT NULL,
   lock_time timestamp NULL DEFAULT NULL,
  `end_date` timestamp NULL DEFAULT NULL,
  `distance` float DEFAULT 0,
  `latest_distance` FLOAT NULL DEFAULT '0',
  `remarks` varchar(255) DEFAULT NULL,
  `event_percentage` float DEFAULT NULL,
  `event_desc` varchar(255) DEFAULT NULL,
  `cargo_category_type` varchar(255) DEFAULT NULL,
  `risk_level` varchar(10) DEFAULT NULL,
  `stationary_duration_hours` decimal(10,0) DEFAULT NULL,
  `proximity_consistency` varchar(20) DEFAULT NULL,
  `data_points_analyzed` float DEFAULT NULL,
  `estimated_cargo` float DEFAULT NULL,
  `is_disappeared` enum('No','Yes') DEFAULT 'No',
  `operationmode` varchar(15) DEFAULT NULL,
  `is_complete` enum('No','Yes') DEFAULT 'No',
  `last_updated` timestamp NULL DEFAULT NULL,
  `status` varchar(25) NOT NULL DEFAULT '',
  `outcome_status` varchar(30)  NULL DEFAULT '',
  `flag_status` varchar(30)  NULL DEFAULT '',
  `step` TINYINT(1) NOT NULL DEFAULT '0',
  `transfer_status` VARCHAR(50) NULL,
  `transfer_confidence` ENUM('Low', 'Medium','High') NULL DEFAULT 'Low',
   PRIMARY KEY (id)
)";

if ( $mysqli->query($sql) !== TRUE ) {
    echo "Error: " . $sql . "\n" . $mysqli->error;
}
function determineMother($vessel1, $vessel2, $mmsi1, $mmsi2) {
    // Priority 1: Higher DWT
    $dwt1 = $vessel1['deadweight'] ?? $vessel1['deadweight'] ?? 0;
    $dwt2 = $vessel2['deadweight'] ?? $vessel2['deadweight'] ?? 0;
    
    if ($dwt1 > 0 && $dwt2 > 0) {
        if ($dwt1 > $dwt2) {
            return [
                'mmsi' => $mmsi1,
                'data' => $vessel1,
                'basis' => 'deadweight'
            ];
        } elseif ($dwt2 > $dwt1) {
            return [
                'mmsi' => $mmsi2,
                'data' => $vessel2,
                'basis' => 'deadweight'
            ];
        }
    }
    
    // Priority 2: Higher LOA (Length Overall)
    $loa1 = $vessel1['length'] ?? $vessel1['length'] ?? 0;
    $loa2 = $vessel2['length'] ?? $vessel2['length'] ?? 0;
    
    if ($loa1 > 0 && $loa2 > 0) {
        if ($loa1 > $loa2) {
            return [
                'mmsi' => $mmsi1,
                'data' => $vessel1,
                'basis' => 'length'
            ];
        } elseif ($loa2 > $loa1) {
            return [
                'mmsi' => $mmsi2,
                'data' => $vessel2,
                'basis' => 'length'
            ];
        }
    }
    
    // Priority 3: Earlier anchorage arrival (simplified - use current time)
    // In production, you'd analyze historical data for when vessel arrived
    $arrival1 = getAnchorageArrivalTime($mmsi1, $vessel1);
    $arrival2 = getAnchorageArrivalTime($mmsi2, $vessel2);
    
    if ($arrival1 < $arrival2) {
        return [
            'mmsi' => $mmsi1,
            'data' => $vessel1,
            'basis' => 'arrival'
        ];
    } else {
        return [
            'mmsi' => $mmsi2,
            'data' => $vessel2,
            'basis' => 'arrival'
        ];
    }
}

 function getAnchorageArrivalTime($mmsi, $vesselData) {
    // Simplified: Use current time minus random offset for demo
    // In production, analyze historical speed/position data
    $speed = $vesselData['speed'] ?? 0;
    $navStatus = strtolower($vesselData['navigation_status'] ?? '');
    
    if ($speed < 1.0 || in_array($navStatus, ['anchored', 'moored', 'at anchor'])) {
        return time() - rand(3600, 86400); // 1-24 hours ago
    }
    
    return time(); // Still moving
}

$array_ids = [];
$array_daughter_ids = [];
$array_uidds = [];

$url = sprintf(
    "https://api.datalastic.com/api/v0/vessel_inradius?api-key=%s&lat=%f&lon=%f&radius=%d",
    urlencode( $api_key ),
    $lat,
    $lon,
    $port_radius
);

// Fetch vessels in area
$response = file_get_contents( $url );
$data = json_decode( $response, true );

$item_count_main = 0;


$total_allowed = 0;
echo '<br><pre>Total vessels:'.$data['data']['total'].'\n';
if( isset( $data ) && isset( $data['data'] ) && isset( $data['data']['total'] ) && intval( $data['data']['total'] ) > 0 ) {
    $vessels = $data['data']['vessels'];
    
    //Check proximity
    $final_array = [];
    foreach ($vessels as $v1) {
        $item_count_main++;
        $item_count_sub = 0;
        
        $vessel_array = [$v1['mmsi'] => ['uuid'=> $v1['uuid'], 'name'=> $v1['name'], 'first'=> 'yes']];
        
        if( !empty( $v1['type'] ) && str_contains( $v1['type'], 'Tanker' ) ) {
            $event_id = 0;
            $daughter_id = 0;
            foreach ($vessels as $v2) {
                $item_count_sub++;
                if ($v1['uuid'] != $v2['uuid'] && !empty( $v2['type'] ) && str_contains($v2['type'], 'Tanker') && !in_array( $v1['mmsi'], ['657263500'] )) { // && $total_allowed < 1
                   // echo '<br><hr>'.$v1['name'].' - '.$v2['name'].' - '.$v1['type'].' - '.$v2['type'];
                    $new_entry = false;
                    $row = false;

                    $sql = "SELECT e.id, d.id as did, e.status, e.draught as mother_draught, d.draught as daughter_draught from ".$event_table_mother." as e inner join ".$event_table_daughter." as d on(e.id=d.event_id) where (e.uuid='".$mysqli->real_escape_string($v1['uuid'])."' and d.uuid='".$mysqli->real_escape_string($v2['uuid'])."') or (e.uuid='".$mysqli->real_escape_string($v2['uuid'])."' and d.uuid='".$mysqli->real_escape_string($v1['uuid'])."') and e.is_disappeared = 'No'";
                    $lat_lon_updatechk = $mysqli->query( $sql );
                    $lat_lon_update = mysqli_num_rows( $lat_lon_updatechk );  
                    if( $lat_lon_update > 0 ) {
                        $trow = $lat_lon_updatechk->fetch_object();
                        $status = $trow->status;
                        if( $status == 'Detected' ) {
                            $tevent_id = $trow->id;
                            $tdaughter_id = $trow->did;
                            $dist = haversineDistance( $v1['lat'], $v1['lon'], $v2['lat'], $v2['lon'] );

                            $sql = "Update $event_table_mother set
                                `lat`='".$v1['lat']."',
                                `lon`='".$v1['lon']."',
                                `distance`='".floatval($dist)."',
                                `last_updated`=NOW() where id='".$tevent_id."'";
                            if ($mysqli->query( $sql ) !== TRUE) {
                                echo "Error: " . $sql . "\n" . $mysqli->error;
                            }

                            $trans_signal = $detector->calculateTransferSignal($v1, $v2, $trow->mother_draught, $trow->daughter_draught);
                            
                            $sql = "Update $event_table_daughter set
                                `lat`='".$v2['lat']."',
                                `lon`='".$v2['lon']."',
                                `latest_distance`='".floatval($dist)."',
                                `transfer_status`='".$trans_signal['signal']."',
                                `transfer_confidence`='".$trans_signal['confidence']."',
                                `last_updated`=NOW() where id='".$tdaughter_id."'";
                            if ($mysqli->query( $sql ) !== TRUE) {
                                echo "Error: " . $sql . "\n" . $mysqli->error;
                            }


                        }
                        
                    }

                    $sql = "SELECT e.id, d.id as did from ".$event_table_mother." as e inner join ".$event_table_daughter." as d on(e.id=d.event_id) where (e.uuid='".$mysqli->real_escape_string($v1['uuid'])."' or d.uuid='".$mysqli->real_escape_string($v2['uuid'])."' or e.uuid='".$mysqli->real_escape_string($v2['uuid'])."' or d.uuid='".$mysqli->real_escape_string($v1['uuid'])."') and e.is_disappeared = 'No'";
                    $resultchk = $mysqli->query( $sql );
                    $is_already_engaged = mysqli_num_rows( $resultchk );  
                    
                    $sql = "SELECT ST_Distance_Sphere( POINT(".$v1['lon'].", ".$v1['lat']."), POINT(".$v2['lon'].", ".$v2['lat'].") ) AS distance_meters limit 1;";
                    //if distance is 200m then qualify for the event creation.
                    $distance_meters = 0;
                    if( $result = $mysqli->query($sql) ) {
                        $distance_obj = $result->fetch_object();
                        $distance_meters = $distance_obj->distance_meters;
                    }
                    $result->free();
                    $first_row = false;
                    $heading_diff = abs( floatval( $v1['heading'] ) - floatval( $v2['heading'] ) );
                    $heading_diff = min($heading_diff, 360 - $heading_diff); // Handle circular nature 
                    $sqlmain = "SELECT e.id, d.id as did, d.event_id, e.deadweight as vessel1_deadweight, d.deadweight as vessel2_deadweight, e.port, e.zone_type, e.zone_ship, e.uuid as vessel1_uuid, e.zone_terminal_name, e.name as vessel1_name, d.name as vessel2_name, e.mmsi as vessel1_mmsi, d.mmsi as vessel2_mmsi, d.uuid as vessel2_uuid, e.is_email_sent, e.end_date, e.draught as vessel1_draught, d.draught as vessel2_draught, e.last_position_UTC as vessel1_last_position_UTC, d.last_position_UTC as vessel2_last_position_UTC, e.completed_draught as vessel1_completed_draught, d.completed_draught as vessel2_completed_draught , e.ais_signal as vessel1_signal, d.ais_signal as vessel2_signal, d.status as daughter_status from ".$event_table_mother." as e inner join ".$event_table_daughter." as d on(e.id=d.event_id) where ( (e.uuid='".$mysqli->real_escape_string($v1['uuid'])."' and d.uuid='".$mysqli->real_escape_string($v2['uuid'])."') or ( e.uuid='".$mysqli->real_escape_string($v2['uuid'])."' and d.uuid='".$mysqli->real_escape_string($v1['uuid'])."')) and e.is_disappeared = 'No'";
                    $result2 = $mysqli->query( $sqlmain );
                    $num_rows = mysqli_num_rows( $result2 );

                    if( $num_rows == 0 && $is_already_engaged == 0 ) {
                        $new_entry = true;
                        $event_id = 0;
                        $daughter_id = 0;
                    } else {
                        while( $row = mysqli_fetch_assoc($result2) ) {
                            if( !$first_row )
                                $first_row = $row;
                            if( $row['daughter_status'] == 'ended' ) {
                                $event_id = $row['id'];
                                $new_entry = true;
                                $daughter_id = $row['did'];
                                
                            } else {
                                if( $row['daughter_status'] != 'ended' ) {
                                    $is_event_ended = false;
                                    if( $distance_meters > ALLOWED_STS_END_RANGE_M ) {
                                        $is_event_ended = true;
                                    }
                                    
                                    if( floatval( $v1['speed'] ) > $sog_end_threshold || floatval( $v2['speed'] ) > $sog_end_threshold ) {
                                        $is_event_ended = true;
                                    }

                                    // if( $heading_diff  > $heading_end_threshold ) {   
                                    //     $is_event_ended = true;
                                    //     echo '<br>step 3';
                                    // }
                                
                                    if( $is_event_ended ) {

                                        
                                        process_complete_sts_vessels( $row );
                                    } else {
                                        if( ! in_array( $row['did'], $array_daughter_ids ) ) {
                                            $array_daughter_ids[] = $row['did'];
                                        }
                                    }
                                }
                            }
                        }
                    }

                    $result2->free();
                    $resultchk->free();

                    if( $new_entry == true ) {
                    
                        $at_event_creation_close_distance = false;
                        $at_event_creation_allowed_speed = false;
                        $at_event_creation_allowed_heading = true;

                        if( $distance_meters <= ALLOWED_STS_RANGE_METERS ) {
                            $at_event_creation_close_distance = true;
                        }

                        if( floatval( $v1['speed'] ) <= $sog_threshold && floatval( $v2['speed'] ) <= $sog_threshold ) {
                            $at_event_creation_allowed_speed = true;
                        }

                        // if( $heading_diff <= $heading_threshold ) {   
                        //     $at_event_creation_allowed_heading = true;
                        // }

                        if ($at_event_creation_close_distance && $at_event_creation_allowed_speed && $at_event_creation_allowed_heading) {
                            
                            $detectresult = $detector->detectSTSTransfer($v1, $v2);
                            //echo '<pre>';print_r($detectresult);echo '</pre>'; 
                            $vessel_array[$v1['mmsi']]['start_date'] = $detectresult['start_date'];
                            $stationary_duration_hours  = $detectresult['proximity_analysis']['stationary_duration_hours'];
                            $stationary_duration_mins = (floatval( $stationary_duration_hours)*60);
                            if( $stationary_duration_mins > $joining_minutes_threshold ) {
                                $vessel_array[$v2['mmsi']] = ['uuid'=> $v2['uuid'], 'name'=> $v2['name'], 'start_date' => $detectresult['start_date'] ];
                            }
                        }

                    } else if( $first_row ) {

                        $vessel1_signal = coastalynk_signal_status( $first_row['vessel1_last_position_UTC'], date('Y-m-d H:i:s', strtotime($v1['last_position_UTC'])) );
                        $vessel2_signal = coastalynk_signal_status( $first_row['vessel2_last_position_UTC'], date('Y-m-d H:i:s', strtotime($v2['last_position_UTC'])) );

                        $sql = "update ".$event_table_mother." set ais_signal = '".$vessel1_signal."' where id='".$first_row['id']."'";
                        $mysqli->query($sql);

                        $sql = "update ".$event_table_daughter." set ais_signal = '".$vessel2_signal."' where id='".$first_row['did']."'";
                        $mysqli->query($sql);
                        
                        if( !in_array( $first_row['did'], $array_daughter_ids ) ) {
                            $array_daughter_ids[] = $first_row['did'];
                        }

                        if( !in_array( $first_row['id'], $array_ids ) ) {
                            $array_ids[] = $first_row['id'];
                            $array_uidds[] = [ $first_row['vessel1_uuid'], $first_row['vessel2_uuid'] ];
                        }
                    }
                }   
            }
            
            if( count( $vessel_array ) > 1 ) {
                
                $vessel_array_keys = array_keys($vessel_array);
                $mother_ship = false;
                $cached_data = [];
                
                $cached_detail_data = [];
                for( $i=0; $i<count($vessel_array_keys); $i++) {
                    
                    $mmsi = $vessel_array_keys[$i];
                    $vessel_data1 = $vessel_array[$mmsi];
                    $cached_data[$mmsi] = get_datalastic_field( $vessel_data1['uuid'], '', true );
                    $cached_detail_data[$mmsi] = search_vessel_by_name( $vessel_data1['name'], $mmsi );
                    
                    if( ! $mother_ship ) {
                        $mother_ship = [ 'mmsi' => $mmsi, 'data' => [ 'mmsi' => $mmsi, 'name' => $cached_detail_data[$mmsi]['name'], 'length' => $cached_detail_data[$mmsi]['length'], 'deadweight' => $cached_detail_data[$mmsi]['deadweight'], 'speed' => $cached_data[$mmsi]['speed'], 'navigation_status' => $cached_data[$mmsi]['navigation_status'] ], 'basis' => 'first_entry' ];
                    } else {
                        $vess1 = [
                            'name' => $mother_ship['data']['name'],
                            'mmsi' => $mother_ship['mmsi'],
                            'length' => $mother_ship['data']['length'],
                            'deadweight' => $mother_ship['data']['deadweight'],
                            'speed' => $mother_ship['data']['speed'],
                            'navigation_status' => $mother_ship['data']['navigation_status']
                        ];

                        $vess2 = [
                            'name' => $cached_detail_data[$mmsi]['name'],
                            'mmsi' => $cached_detail_data[$mmsi]['mmsi'],
                            'length' => $cached_detail_data[$mmsi]['length'],
                            'deadweight' => $cached_detail_data[$mmsi]['deadweight'],
                            'speed' => $cached_data[$mmsi]['speed'],
                            'navigation_status' => $cached_data[$mmsi]['navigation_status']
                        ];

                        $mother_ship = determineMother( $vess1, $vess2,$vess1['mmsi'], $vess2['mmsi'] ); 
                    }
                }
                $start_date_m = '';
                foreach( $vessel_array as $mmsi => $vessel_data ) {
                    if( $mother_ship['mmsi'] != $mmsi ) {
                        $vehicel_1 = $cached_data[$mother_ship['mmsi']];
                        $vehicel_2 = $cached_data[$mmsi];
                        
                       //echo '<br>'.$mother_ship['mmsi'].'-'.$mmsi.' = '.($mother_ship['mmsi']==$mmsi?'equal':'not_equal');print_r($vessel_data);
                        $detectresult = $detector->detectSTSTransfer($vehicel_1, $vehicel_2);
                        //echo '<pre>';print_r($detectresult);echo '</pre>'; 
                       // if( intval( $detectresult['sts_transfer_detected'] ) == 1 ) 
                        {
                            if( $event_id == 0 ) {
                                $sql = "SELECT e.id, d.id as did, e.status, e.end_date,e.uuid as vessel1_uuid, d.uuid as vessel2_uuid from ".$event_table_mother." as e inner join ".$event_table_daughter." as d on(e.id=d.event_id) where (e.mmsi='".$mysqli->real_escape_string($vehicel_1['mmsi'])."') and e.is_disappeared = 'No'";
                                $not_in_process = true;
                                $result_event_id = $mysqli->query( $sql );
                                $num_rows = mysqli_num_rows( $result_event_id );
                                if( $num_rows > 0) {
                                    $evt_obj = $result_event_id->fetch_object();
                                    $event_id = $evt_obj->id;
                                    if( $evt_obj->status != 'Detected' ) {
                                        continue;
                                    }

                                    if( !in_array( $evt_obj->did, $array_daughter_ids ) ) {
                                        $array_daughter_ids[] = $evt_obj->did;
                                    }
                                    
                                    if( !in_array( $evt_obj->id, $array_ids ) ) {
                                        $array_ids[] = $evt_obj->id; 
                                        $array_uidds[] = [ $evt_obj->vessel1_uuid, $evt_obj->vessel2_uuid ];
                                    }
                                }

                                $result_event_id->free();
                            }
                            
                            
                            $vehicel_1_detail = $cached_detail_data[$mother_ship['mmsi']];
                            $vehicel_2_detail = $cached_detail_data[$mmsi];
                            
                            $zone_type = 'No STS Zone';
                            $new_zone_terminal_name = '';
                            $param_v1_lat = floatval( $vehicel_2['lat'] );
                            $param_v1_lon = floatval( $vehicel_2['lon'] );
                            $sql = "SELECT *, ST_Distance_Sphere( POINT(lon, lat), POINT($param_v1_lon, $param_v1_lat) ) AS distance_meters FROM ".$table_name." where country_iso='NG' and port_type = 'PBA' HAVING distance_meters is not null and distance_meters > 5557 order by distance_meters asc limit 1;";
                            if( $result = $mysqli->query($sql) ) {
                                if( $obj = $result->fetch_object() ) {
                                    $zone_type  = $obj->port_type;
                                    $new_zone_terminal_name = $obj->title;
                                }
                            }
                            
                            $result->free();

                            $param_v2_lat = floatval( $vehicel_1['lat'] );
                            $param_v2_lon = floatval( $vehicel_1['lon'] );
                            if( empty( $zone_type ) ) {
                                $sql = "SELECT *, ST_Distance_Sphere( POINT(lon, lat), POINT($param_v2_lon, $param_v2_lat) ) AS distance_meters FROM ".$table_name." where country_iso='NG' and port_type = 'PBA' HAVING distance_meters is not null and distance_meters > 5557 order by distance_meters asc limit 1;";
                                if( $result = $mysqli->query($sql) ) {
                                    if( $obj = $result->fetch_object() ) {
                                        $zone_type  = $obj->port_type; 
                                        $new_zone_terminal_name = $obj->title;
                                    }
                                }

                                $result->free();
                            }
                            
                            if( empty( $zone_type ) ) {
                                $sql = "SELECT * FROM ".$table_name." WHERE MBRWithin( POINT($param_v1_lon, $param_v1_lat), port_area ) limit 1;";
                                if( $result = $mysqli->query($sql) ) {
                                    if( $obj = $result->fetch_object() ) {
                                        $zone_type  = $obj->port_type; 
                                        $new_zone_terminal_name = $obj->title;
                                    }
                                }
                                $result->free();
                            }

                            if( empty( $zone_type ) ) {
                                $sql = "SELECT * FROM ".$table_name." WHERE MBRWithin( POINT($param_v2_lon, $param_v2_lat), port_area ) limit 1;";
                                if( $result = $mysqli->query($sql) ) {
                                    if( $obj = $result->fetch_object() ) {
                                        $zone_type  = $obj->port_type; 
                                        $new_zone_terminal_name = $obj->title;
                                    }
                                }
                                $result->free();
                            }

                            $dist = haversineDistance( $vehicel_1['lat'], $vehicel_1['lon'], $vehicel_2['lat'], $vehicel_2['lon'] );
                            $v1_navigation_status = $vehicel_2[ 'navigation_status' ];
                            $v2_navigation_status = $vehicel_2[ 'navigation_status' ];

                            $vessel1_deadweight = floatval($cached_detail_data[$mmsi][ 'deadweight' ]);
                            $vessel2_deadweight = floatval($vehicel_2_detail[ 'deadweight' ]);
                            $vessel1_gross_tonnage = floatval($cached_detail_data[$mmsi][ 'gross_tonnage' ]);
                            $vessel2_gross_tonnage = floatval($vehicel_2_detail[ 'gross_tonnage' ]);

                            $vessel1_length = $cached_detail_data[$mmsi][ 'length' ];
                            $vessel2_length = $vehicel_2_detail[ 'length' ];

                            $vessel1_length = $cached_detail_data[$mmsi][ 'length' ];
                            $vessel2_length = $vehicel_2_detail[ 'length' ];

                            $v1_current_draught = floatval( $vehicel_2[ 'current_draught' ]);
                            $v2_current_draught = floatval( $vehicel_2[ 'current_draught' ]);
                            $sql = "select max(id) as id from ".$event_table_mother;
                            $result2 = $mysqli->query($sql);
                            $pk_id = intval($result2->fetch_column());
                            $result2->free();
                            $ref_id = 'STS'.date('Ymd').str_pad( $pk_id, strlen( $pk_id ) + 4, '0', STR_PAD_LEFT);
                            
                            $stationary_duration_hours  = $detectresult[ 'proximity_analysis' ][ 'stationary_duration_hours' ];
                            $stationary_duration_mins   = ( floatval( $stationary_duration_hours ) * 60 );
                            $proximity_consistency      = $detectresult['proximity_analysis']['proximity_consistency'];
                            $data_points_analyzed       = $detectresult['proximity_analysis']['data_points_analyzed'];
                            
                            $risk_level     = $detectresult['risk_assessment']['risk_level'];

                            $remarks        = $detectresult['risk_assessment']['remarks'];
                            $confidence     = $detectresult['risk_assessment']['confidence'];
                            $lock_time      = $detectresult['lock_time'];
                            if( $lock_time != '' && ! empty( $detectresult['lock_time'] ) ) {
                                $dateTime = new DateTime($detectresult['lock_time']);
                                $mysqlDate = $dateTime->format('Y-m-d H:i:s');
                                $lock_time = "'".$mysqlDate."'";
                            } else {
                                $lock_time = 'Null';
                            }

                            $start_date_d      = $detectresult['start_date'];
                            if( $start_date_d != '' && ! empty( $detectresult['start_date'] ) ) {
                                $dateTime = new DateTime($detectresult['start_date']);
                                $mysqlDate = $dateTime->format('Y-m-d H:i:s');
                                $start_date_d = $mysqlDate;
                            } else {
                                $start_date_d = date('Y-m-d H:i:s');
                            }

                            $dateTime = new DateTime($vessel_data['start_date']);
                            $child_start = $dateTime->format('Y-m-d H:i:s');
                            
                            if( empty( $start_date_m ) ) {
                                $start_date_m = $child_start;
                            } else if( strtotime( $start_date_m ) > strtotime( $child_start ) ) {
                                $start_date_m = $child_start;
                            } else {
                                $start_date_m = date('Y-m-d H:i:s');
                            }
                        
                            $timestamp      = $detectresult['timestamp'];
                            $operation_mode = $detectresult['operation_mode']; 
                            $predicted_cargo_1 = $detectresult['vessel_1']['predicted_cargo'];
                            $predicted_cargo_2 = $detectresult['vessel_2']['predicted_cargo'];
                            $status = $detectresult['status']; 
                            $status = 'Detected';
                            if( $event_id == 0 ) {
                                $dateTime = new DateTime($vehicel_1['last_position_UTC']);
                                $mysqlDate = $dateTime->format('Y-m-d H:i:s');
                                $sql = "INSERT INTO $event_table_mother (
                                            `uuid`,`name`,`mmsi`,`imo`,`country_iso`,`type`,`type_specific`,`lat` ,
                                            `lon` ,`speed` ,`navigation_status`,`draught`,
                                            `last_position_UTC`, `deadweight`, `gross_tonnage`, `port` ,
                                            `port_id`, `distance`, `event_ref_id`, `zone_type`, `zone_ship`, `zone_terminal_name` ,
                                            `start_date`,`last_updated`, status,
                                            `is_email_sent`, `ais_signal`  )
                                        VALUES (
                                            '" . $mysqli->real_escape_string($vehicel_1['uuid']) . "',
                                            '" . $mysqli->real_escape_string($vehicel_1['name']) . "',
                                            '" . $mysqli->real_escape_string($vehicel_1['mmsi']) . "',
                                            '" . $mysqli->real_escape_string($vehicel_1['imo']) . "',
                                            '" . $mysqli->real_escape_string($vehicel_1['country_iso']) . "',
                                            '" . $mysqli->real_escape_string($vehicel_1['type']) . "',
                                            '" . $mysqli->real_escape_string($vehicel_1['type_specific']) . "',
                                            '" . $mysqli->real_escape_string($vehicel_1['lat']) . "',
                                            '" . $mysqli->real_escape_string($vehicel_1['lon']) . "',
                                            '" . floatval($vehicel_1['speed']) . "',
                                            '" . $v1_navigation_status . "',
                                            '" . $v1_current_draught . "',
                                            '" . $mysqlDate . "',
                                            '" . $vessel1_deadweight . "',      
                                            '" . $vessel1_gross_tonnage . "',  
                                            '" . $mysqli->real_escape_string( $port_name ) . "',
                                            '" . $mysqli->real_escape_string( $port_id ) . "',
                                            '" . floatval($dist) . "',
                                            '".$ref_id."',
                                            '".$zone_type."', 
                                            '".$zone_ship."',
                                                '".$new_zone_terminal_name."',
                                            '".$start_date_m."', 
                                            '".$last_updated."', '".$status."',
                                            'No', 'AIS  Consistent Signal Detected'
                                            )";
                                
                                if ($mysqli->query( $sql ) !== TRUE) {
                                    echo "Error: " . $sql . "\n" . $mysqli->error;
                                } else {
                                    $event_id = $mysqli->insert_id;coastalynk_log_entry($event_id, $sql, 'm query');

                                    if( ! in_array( $event_id, $array_ids ) ) {
                                        $array_ids[] = $event_id;
                                        $array_uidds[] = [ $vehicel_1['uuid'], $vehicel_2['uuid'] ];
                                    }
                                    coastalynk_log_entry($event_id, 'Added the parent vessel '.$vehicel_1['name'].' with MMSI '.$vehicel_1['mmsi'].' and IMO '.$vehicel_1['imo'].' to the event.', 'sts');
                                }
                                
                            } else if( $daughter_id > 0 && $event_id > 0) {  //

                                $sql = "Update $event_table_mother set
                                            `lat`='".$vehicel_1['lat']."',
                                            `lon`='".$vehicel_1['lon']."',
                                            `speed`='".floatval($vehicel_1['speed'])."',
                                            `navigation_status`='".$v1_navigation_status."',
                                            `distance`='".floatval($dist)."',
                                             end_date = Null, 
                                             is_complete = 'No',
                                            `last_updated`=NOW(),
                                            `status`='".$status."' where id='".$event_id."'";
                                    coastalynk_log_entry($event_id, $sql, 'm query');
                                    if ($mysqli->query( $sql ) !== TRUE) {
                                        echo "Error: " . $sql . "\n" . $mysqli->error;
                                    }
                            }

                            if( $event_id > 0 ) {
                                
                                $sql = "SELECT id from ".$event_table_daughter." where event_id = '".$mysqli->real_escape_string($event_id)."' and mmsi='".$mysqli->real_escape_string($vehicel_2['mmsi'])."'";
                                $result_event_id = $mysqli->query( $sql );
                                $num_rows = mysqli_num_rows( $result_event_id );
                                if( $num_rows == 0 && $vehicel_1['mmsi'] != $vehicel_2['mmsi']) {
                                    
                                    $daughter_status = 'tentative';
                                    if( floatval( $stationary_duration_hours ) >= 6 ) {
                                        $daughter_status = 'active';
                                    }
                                    $dateTime = new DateTime($vehicel_2['last_position_UTC']);
                                    $mysqlDate = $dateTime->format('Y-m-d H:i:s');
                                    $sql = "INSERT INTO $event_table_daughter (
                                        `event_id`,`uuid`,`name`,`mmsi`,`imo`,`country_iso`,`type`,`type_specific`,`lat`,
                                        `lon`,`speed`,`navigation_status`,`draught`,`last_position_UTC`,
                                        `ais_signal`,`deadweight`,`gross_tonnage`,distance, latest_distance, last_updated, remarks, event_percentage, cargo_category_type, `risk_level`, `stationary_duration_hours`, `proximity_consistency`, `data_points_analyzed`, `operationmode`, `status`, step, joining_date, lock_time )
                                        VALUES (
                                        '" . $mysqli->real_escape_string($event_id) . "',
                                        '" . $mysqli->real_escape_string($vehicel_2['uuid']) . "',
                                        '" . $mysqli->real_escape_string($vehicel_2['name']) . "',
                                        '" . $mysqli->real_escape_string($vehicel_2['mmsi']) . "',
                                        '" . $mysqli->real_escape_string($vehicel_2['imo']) . "',
                                        '" . $mysqli->real_escape_string($vehicel_2['country_iso']) . "',
                                        '" . $mysqli->real_escape_string($vehicel_2['type']) . "',
                                        '" . $mysqli->real_escape_string($vehicel_2['type_specific']) . "',
                                        '" . $mysqli->real_escape_string($vehicel_2['lat']) . "',
                                        '" . $mysqli->real_escape_string($vehicel_2['lon']) . "',
                                        '" . floatval($vehicel_2['speed']) . "',
                                        '" . $v2_navigation_status . "',
                                        '" . $v2_current_draught . "',
                                        '" . $mysqlDate . "',
                                        'AIS  Consistent Signal Detected',
                                        '$vessel2_deadweight',
                                        '$vessel2_gross_tonnage',
                                        '" . floatval($dist) . "',
                                        '" . floatval($dist) . "',
                                        NOW(),
                                        '".$remarks."',
                                        '".floatval( $confidence )."',  
                                        '".$predicted_cargo_1."', 
                                        '".$risk_level."',
                                        '".$stationary_duration_hours."',
                                        '".$proximity_consistency."',
                                        '".$data_points_analyzed."',
                                        '".$operation_mode."',
                                        '".$daughter_status."', 
                                        0,
                                        '".$start_date_d."',
                                        ".$lock_time."
                                        )";
                                    
                                    if ($mysqli->query( $sql ) !== TRUE) {
                                        echo "Error: " . $sql . "\n" . $mysqli->error;
                                    } else {
                                        
                                        $insert_id = $mysqli->insert_id;
                                        coastalynk_log_entry($insert_id, $sql, 'd query');
                                        if( !in_array( $insert_id, $array_daughter_ids ) ) {
                                            $array_daughter_ids[] = $insert_id;
                                        }
                                        
                                        coastalynk_log_entry($insert_id, 'STS Daughter vessel Between '.$vehicel_1['name'].' and '.$vehicel_2['name'].': '.$remarks, 'sts-daughter');
                                        $total_allowed++;
                                        $coastalynk_sts_body = str_replace( "[vessel1_uuid]", $vehicel_1['uuid'], $coastalynk_sts_body_original );
                                        $coastalynk_sts_body = str_replace( "[vessel1_name]", $vehicel_1['name'], $coastalynk_sts_body );
                                        $coastalynk_sts_body = str_replace( "[vessel1_mmsi]", $vehicel_1['mmsi'], $coastalynk_sts_body );
                                        $coastalynk_sts_body = str_replace( "[vessel1_imo]", $vehicel_1['imo'], $coastalynk_sts_body );
                                        $coastalynk_sts_body = str_replace( "[vessel1_country_iso]", $vehicel_1['country_iso'], $coastalynk_sts_body );
                                        $coastalynk_sts_body = str_replace( "[vessel1_type]", $vehicel_1['type'], $coastalynk_sts_body );
                                        $coastalynk_sts_body = str_replace( "[vessel1_type_specific]", $vehicel_1['type_specific'], $coastalynk_sts_body );
                                        $coastalynk_sts_body = str_replace( "[vessel1_lat]", $vehicel_1['lat'], $coastalynk_sts_body );
                                        $coastalynk_sts_body = str_replace( "[vessel1_lon]", $vehicel_1['lon'], $coastalynk_sts_body );
                                        $coastalynk_sts_body = str_replace( "[vessel1_speed]", $vehicel_1['speed'], $coastalynk_sts_body );
                                        $coastalynk_sts_body = str_replace( "[vessel1_navigation_status]", $v1_navigation_status, $coastalynk_sts_body );
                                        $coastalynk_sts_body = str_replace( "[vessel1_draught]", $v1_current_draught, $coastalynk_sts_body );
                                        $coastalynk_sts_body = str_replace( "[vessel1_country_flag]", $siteurl.'/flags/'.strtolower($vehicel_1['country_iso']).'.jpg', $coastalynk_sts_body );
                                        $coastalynk_sts_body = str_replace( "[sts-page-url]", $siteurl.'/sts-map/', $coastalynk_sts_body );            
                                        $coastalynk_sts_body = str_replace( "[vessel1_last_position_UTC]", $vehicel_1['last_position_UTC'], $coastalynk_sts_body );
                                        $coastalynk_sts_body = str_replace( "[vessel2_uuid]", $vehicel_2['uuid'], $coastalynk_sts_body );
                                        $coastalynk_sts_body = str_replace( "[vessel2_name]", $vehicel_2['name'], $coastalynk_sts_body );
                                        $coastalynk_sts_body = str_replace( "[vessel2_mmsi]", $vehicel_2['mmsi'], $coastalynk_sts_body );
                                        $coastalynk_sts_body = str_replace( "[vessel2_imo]", $vehicel_2['imo'], $coastalynk_sts_body );
                                        $coastalynk_sts_body = str_replace( "[vessel2_country_iso]", $vehicel_2['country_iso'], $coastalynk_sts_body );
                                        $coastalynk_sts_body = str_replace( "[vessel2_type]", $vehicel_2['type'], $coastalynk_sts_body );
                                        $coastalynk_sts_body = str_replace( "[vessel2_type_specific]", $vehicel_2['type_specific'], $coastalynk_sts_body );
                                        $coastalynk_sts_body = str_replace( "[vessel2_lat]", $vehicel_2['lat'], $coastalynk_sts_body );
                                        $coastalynk_sts_body = str_replace( "[vessel2_lon]", $vehicel_2['lon'], $coastalynk_sts_body );
                                        $coastalynk_sts_body = str_replace( "[vessel2_speed]", $vehicel_2['speed'], $coastalynk_sts_body );
                                        $coastalynk_sts_body = str_replace( "[vessel2_navigation_status]", $v2_navigation_status, $coastalynk_sts_body );
                                        $coastalynk_sts_body = str_replace( "[vessel2_draught]", $v2_current_draught, $coastalynk_sts_body );
                                        $coastalynk_sts_body = str_replace( "[vessel2_country_flag]", $siteurl.'/flags/'.strtolower($vehicel_2['country_iso']).'.jpg', $coastalynk_sts_body );
                                        $coastalynk_sts_body = str_replace( "[vessel2_last_position_UTC]", $vehicel_2['last_position_UTC'], $coastalynk_sts_body );
                                        $coastalynk_sts_body = str_replace( "[distance]", $dist, $coastalynk_sts_body );
                                        $coastalynk_sts_body = str_replace( "[latest_distance]", $dist, $coastalynk_sts_body );
                                        $coastalynk_sts_body = str_replace( "[port]", $port_name, $coastalynk_sts_body );
                                        $coastalynk_sts_body = str_replace( "[port_id]", $port_id, $coastalynk_sts_body );
                                        $coastalynk_sts_body = str_replace( "[last_updated]", date('Y-m-d H:i:s', strtotime($vehicel_1['last_position_UTC'])), $coastalynk_sts_body ); 

                                        $coastalynk_sts_email_subject = str_replace( "[vessel1_uuid]", $vehicel_1['uuid'], $coastalynk_sts_email_subject_original );
                                        $coastalynk_sts_email_subject = str_replace( "[vessel1_name]", $vehicel_1['name'], $coastalynk_sts_email_subject );
                                        $coastalynk_sts_email_subject = str_replace( "[vessel1_mmsi]", $vehicel_1['mmsi'], $coastalynk_sts_email_subject );
                                        $coastalynk_sts_email_subject = str_replace( "[vessel1_imo]", $vehicel_1['imo'], $coastalynk_sts_email_subject );
                                        $coastalynk_sts_email_subject = str_replace( "[vessel1_country_iso]", $vehicel_1['country_iso'], $coastalynk_sts_email_subject );
                                        $coastalynk_sts_email_subject = str_replace( "[vessel1_type]", $vehicel_1['type'], $coastalynk_sts_email_subject );
                                        $coastalynk_sts_email_subject = str_replace( "[vessel1_type_specific]", $vehicel_1['type_specific'], $coastalynk_sts_email_subject );
                                        $coastalynk_sts_email_subject = str_replace( "[vessel1_lat]", $vehicel_1['lat'], $coastalynk_sts_email_subject );
                                        $coastalynk_sts_email_subject = str_replace( "[vessel1_lon]", $vehicel_1['lon'], $coastalynk_sts_email_subject );
                                        $coastalynk_sts_email_subject = str_replace( "[vessel1_speed]", $vehicel_1['speed'], $coastalynk_sts_email_subject );
                                        $coastalynk_sts_email_subject = str_replace( "[vessel1_navigation_status]", $v1_navigation_status, $coastalynk_sts_email_subject );
                                        $coastalynk_sts_email_subject = str_replace( "[vessel1_draught]", $v1_current_draught, $coastalynk_sts_email_subject );                   
                                        $coastalynk_sts_email_subject = str_replace( "[sts-page-url]", $siteurl.'sts-map/', $coastalynk_sts_email_subject );
                                        $coastalynk_sts_email_subject = str_replace( "[vessel2_draught]", $v2_current_draught, $coastalynk_sts_email_subject );            
                                        $coastalynk_sts_email_subject = str_replace( "[vessel1_last_position_UTC]", $vehicel_1['last_position_UTC'], $coastalynk_sts_email_subject );
                                        $coastalynk_sts_email_subject = str_replace( "[vessel2_uuid]", $vehicel_2['uuid'], $coastalynk_sts_email_subject );
                                        $coastalynk_sts_email_subject = str_replace( "[vessel2_name]", $vehicel_2['name'], $coastalynk_sts_email_subject );
                                        $coastalynk_sts_email_subject = str_replace( "[vessel2_mmsi]", $vehicel_2['mmsi'], $coastalynk_sts_email_subject );
                                        $coastalynk_sts_email_subject = str_replace( "[vessel2_imo]", $vehicel_2['imo'], $coastalynk_sts_email_subject );
                                        $coastalynk_sts_email_subject = str_replace( "[vessel2_country_iso]", $vehicel_2['country_iso'], $coastalynk_sts_email_subject );
                                        $coastalynk_sts_email_subject = str_replace( "[vessel2_type]", $vehicel_2['type'], $coastalynk_sts_email_subject );
                                        $coastalynk_sts_email_subject = str_replace( "[vessel2_type_specific]", $vehicel_2['type_specific'], $coastalynk_sts_email_subject );
                                        $coastalynk_sts_email_subject = str_replace( "[vessel2_lat]", $vehicel_2['lat'], $coastalynk_sts_email_subject );
                                        $coastalynk_sts_email_subject = str_replace( "[vessel2_lon]", $vehicel_2['lon'], $coastalynk_sts_email_subject );
                                        $coastalynk_sts_email_subject = str_replace( "[vessel2_speed]", $vehicel_2['speed'], $coastalynk_sts_email_subject );
                                        $coastalynk_sts_email_subject = str_replace( "[vessel2_navigation_status]", $v2_navigation_status, $coastalynk_sts_email_subject );
                                        $coastalynk_sts_email_subject = str_replace( "[vessel2_last_position_UTC]", $v2['last_position_UTC'], $coastalynk_sts_email_subject );
                                        $coastalynk_sts_email_subject = str_replace( "[distance]", $dist, $coastalynk_sts_email_subject );
                                        $coastalynk_sts_email_subject = str_replace( "[latest_distance]", $dist, $coastalynk_sts_email_subject );
                                        $coastalynk_sts_email_subject = str_replace( "[port]", $port_name, $coastalynk_sts_email_subject );
                                        $coastalynk_sts_email_subject = str_replace( "[port_id]", $port_id, $coastalynk_sts_email_subject );

                                        $coastalynk_sts_email_subject = str_replace( "[last_updated]", date('Y-m-d H:i:s', strtotime($vehicel_1['last_position_UTC'])), $coastalynk_sts_email_subject );

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

                                            $sql = "update ".$event_table_mother." set is_email_sent='Yes' where id='".$event_id."'";
                                            $mysqli->query($sql);
                                            coastalynk_log_entry($event_id, 'STS Between '.$vehicel_1['name'].' and '.$vehicel_2['name'].': Email sent successfully!', 'sts');

                                            echo 'Email sent successfully!';
                                        } catch (Exception $e) {
                                            echo "Email could not be sent. Error: {$mail->ErrorInfo}";
                                        }
                                    }
                                } else {
                                    while( $row = mysqli_fetch_array($result_event_id, MYSQLI_ASSOC) ) {
                                        $daughter_status = 'tentative';
                                        if( floatval( $stationary_duration_hours ) >= 6 ) {
                                            $daughter_status = 'active';
                                        }

                                        $dateTime = new DateTime($vehicel_2['last_position_UTC']);
                                        $mysqlDate = $dateTime->format('Y-m-d H:i:s');

                                        $sql = "Update $event_table_daughter set
                                            `event_id`='".$event_id."',
                                            `uuid`='".$vehicel_2['uuid']."',
                                            `name`='".$vehicel_2['name']."',
                                            `mmsi`='".$vehicel_2['mmsi']."',
                                            `imo`='".$vehicel_2['imo']."',
                                            `country_iso`='".$vehicel_2['country_iso']."',
                                            `type`='".$vehicel_2['type']."',
                                            `type_specific`='".$vehicel_2['type_specific']."',
                                            `lat`='".$vehicel_2['lat']."',
                                            `lon`='".$vehicel_2['lon']."',
                                            `speed`='".floatval($vehicel_2['speed'])."',
                                            `navigation_status`='".$v2_navigation_status."',
                                            `draught`='".$v2_current_draught."',
                                            `last_position_UTC`='".$mysqlDate."',
                                            `ais_signal`='AIS  Consistent Signal Detected',
                                            `deadweight`='".$vessel2_deadweight."',
                                            `gross_tonnage`='".$vessel2_gross_tonnage."',
                                            `distance`='".floatval($dist)."',
                                            `latest_distance`='".floatval($dist)."',
                                            `last_updated`=NOW(),
                                            `remarks`='".$remarks."',
                                            `event_percentage`='".floatval( $confidence )."',
                                            `cargo_category_type`='".$predicted_cargo_1."',
                                            `risk_level`='".$risk_level."',
                                            `stationary_duration_hours`='".$stationary_duration_hours."',
                                            `proximity_consistency`='".$proximity_consistency."',
                                            `data_points_analyzed`='".$data_points_analyzed."',
                                            `operationmode`='".$operation_mode."',
                                            `status`='".$daughter_status."',
                                            `step`='0',`flag_status`='',`outcome_status`='',is_complete = 'No',
                                            end_date = Null,is_disappeared = 'No',
                                            `joining_date`='".$start_date_d."',
                                            `lock_time`=".$lock_time." where id='".$row['id']."'";

                                        coastalynk_log_entry($row['id'], $sql, 'd query');

                                        if ($mysqli->query( $sql ) !== TRUE) {
                                            echo "Error: " . $sql . "\n" . $mysqli->error;
                                        }
                                    
                                    }
                                }
                                $result_event_id->free();
                            }
                        }
                    }
                }
            }
        }
    }
}

$sql = "select e.id, d.id as did, d.event_id, e.deadweight as vessel1_deadweight, d.deadweight as vessel2_deadweight, e.uuid as vessel1_uuid, e.zone_terminal_name, e.name as vessel1_name, d.name as vessel2_name, e.mmsi as vessel1_mmsi, d.mmsi as vessel2_mmsi, d.uuid as vessel2_uuid, e.end_date, e.draught as vessel1_draught, e.last_position_UTC as vessel1_last_position_UTC, d.last_position_UTC as vessel2_last_position_UTC, d.draught as vessel2_draught, e.completed_draught as vessel1_completed_draught, d.completed_draught as vessel2_completed_draught from ".$event_table_mother." as e inner join ".$event_table_daughter." as d on(e.id=d.event_id) where e.is_disappeared = 'No' and e.port='".$port_name."' and e.status in( 'Completed', 'Concluded' ) and e.is_complete = 'Yes';";
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
        $v1_current_draught = floatval( $v1['current_draught'] );
        $v2_current_draught = floatval( $v2['current_draught'] );
        
        $calculator = new NigerianPortsAfterDraught(true);
            
        //echo "<pre>=== NPA AFTER-DRAUGHT LOGIC SYSTEM - COMPLETE DEMO ===\n\n";
        $tanker_by_type = $calculator->getTankerTypeByDWT($row['vessel1_deadweight']);

        $vessel_tanker_type     = 'MR';
        $vessel_prodtype    = 'Crude Light';
        if( floatval($row['vessel1_deadweight']) > 0 ) {
            $vessel_tanker_type = $tanker_by_type['primary_type']['type'];
            $vessel_prodtype    = $vessel_product_type[$tanker_by_type['primary_type']['type']];
        }
        $vessel_1 = [
            'previous_draught' => $row['vessel1_draught'],
            'current_draught' => $v1_current_draught,
            'zone' => $row['zone_terminal_name'],
            'tanker_type' => $vessel_tanker_type,
            'product_type' => $vessel_prodtype,
            'timestamp' => date('Y-m-d H:i:s'),
            'ais_source' => $vessel1_signal,
            'mmsi' => $row['vessel1_mmsi'] 
        ];

        $draught_2_diff = $draught_change = $draught_1_diff = 0;
        $result1 = $calculator->calculateAfterDraught($vessel_1);
        if( is_array( $result1 ) && count($result1 ) > 0 ) {
            $v1_current_draught = $result1['after_draught'];
            $draught_change = $draught_1_diff = floatval( $result1['draught_change'] );
            $event_desc = $result1['event_description'];
            $current_status = $result1['current_status'];
        }
        

        $tanker_by_type2 = $calculator->getTankerTypeByDWT($row['vessel2_deadweight']);
        $vessel_tanker_type     = 'MR';
        $vessel_prodtype    = 'Crude Light';
        
        if( floatval($row['vessel2_deadweight']) > 0 ) {
            $vessel_tanker_type     = $tanker_by_type2['primary_type']['type'];
            $vessel_prodtype    = $vessel_product_type[$tanker_by_type2['primary_type']['type']];
        }

        $vessel_2 = [
            'previous_draught' => $row['vessel2_draught'],
            'current_draught' => $v2_current_draught,
            'zone' => $row['zone_terminal_name'],
            'tanker_type' => $vessel_tanker_type,
            'product_type' => $vessel_prodtype,
            'timestamp' => date('Y-m-d H:i:s'),
            'ais_source' => $vessel2_signal,
            'mmsi' => $row['vessel2_mmsi']
        ];

        $result2 = $calculator->calculateAfterDraught($vessel_2);
        if( is_array( $result2 ) && count($result2 ) > 0 ) {
            $v2_current_draught = $result2['after_draught'];
            $draught_2_diff = $result2['draught_change'];
            if( floatval( $draught_change ) == 0 ) {
                $draught_change = floatval( $draught_2_diff );
            }
        }

        $estimated_cargo = 0;
        
        $log = 'Vessel 1 Draught Difference: '.$draught_1_diff;
        $log .= ', Vessel 2 Draught Difference: '.$draught_2_diff;
        $log .= ', Vessel 1 Signal: '.$vessel1_signal;
        $log .= ', Vessel 2 Signal: '.$vessel2_signal;
        $draught_diff = $draught_1_diff;
        $event_desc = '';
        if( $draught_1_diff >= 0.3 || $draught_1_diff <= -0.3 ) {
            $estimated_cargo = (floatval( $v1_current_draught ) - floatval( $row['vessel1_draught'] ) ) / 1000;
            
            $event_desc = $result1['event_description'];
            $current_status = $result1['current_status'];
        } else if( $draught_2_diff >= 0.3 || $draught_2_diff <= -0.3 ) {
            
            $estimated_cargo = (floatval( $v2_current_draught ) - floatval( $row['vessel2_draught'] ) ) / 1000;
            
            $draught_diff = $draught_2_diff;
            $event_desc = $result2['event_description'];
            $current_status = $result2['current_status'];
        }
        
        $updatable_mother_fields = '';
        $updatable_daughter_fields = '';
        if( floatval( $v1_current_draught ) > 0 ) {
            $updatable_mother_fields .= "completed_draught='".floatval( $v1_current_draught )."', ";
        }
       
        if( floatval( $v2_current_draught ) > 0 ) {
            $updatable_daughter_fields .= " completed_draught='" . floatval( $v2_current_draught ) . "', ";
        }

        $total_hours = $interval->days * 24 + $interval->h + ($interval->i / 60) + ($interval->s / 3600);
        if( $total_hours <= 9 && $total_hours >= 6  ) {

            $outcome_status = 'Likely Transfer';
            $flag_status = '';
            if( $draught_1_diff <= 0.3 && $draught_1_diff >= -0.3 && $draught_2_diff <= 0.3 && $draught_2_diff >= -0.3 ) {
                $outcome_status = 'AIS-Limited';
                $flag_status = 'Awaiting Draught Update';
            } else {
                if( ($draught_1_diff >= 0.3 && $draught_2_diff >= 0.3) || ( $draught_1_diff < -0.3 && $draught_2_diff < -0.3 ) ) {
                    $outcome_status = 'Inconclusive';
                    $flag_status = 'Awaiting Draught Update';
                }
            }

            $log .= ', outcome_status:'.$outcome_status;
            $log .= ', flag_status:'.$flag_status;

            $sql = "update ".$event_table_daughter." set ".$updatable_daughter_fields."step = 2,flag_status = '".$flag_status."', outcome_status = '".$outcome_status."',draught_change = '".$draught_diff."',operationmode = '".$current_status."',event_desc = '".$event_desc."', ais_signal = '".$vessel1_signal."', estimated_cargo = '".$estimated_cargo."', last_updated = NOW() where id='".$row['did']."'";
            $mysqli->query($sql);
            coastalynk_log_entry($row['did'], $sql, 'middle d 1 query');
            $total_daughters = total_sts_daughter_vessels( $row['event_id'] );
            $total_daughters_same_steps = total_sts_daughter_vessels( $row['event_id'], 2  );
            if( $total_daughters == $total_daughters_same_steps  ) {

               $sql = "update ".$event_table_mother." set ".$updatable_mother_fields." status = 'Concluded', ais_signal = '".$vessel2_signal."', last_updated = NOW() where id='".$row['id']."'";
                $mysqli->query($sql);coastalynk_log_entry($row['id'], $sql, 'middle m 1 query');
            }
           
            coastalynk_log_entry($row['id'], 'STS Between '.$v1['name'].' and '.$v2['name'].' upto 12hrs: '.$log, 'sts');
        } else if( $total_hours >= 24 ) {
            
            
            $outcome_status = 'Likely Transfer';
            $flag_status = '';

            if( $draught_1_diff <= 0.3 && $draught_1_diff >= -0.3 && $draught_2_diff <= 0.3 && $draught_2_diff >= -0.3 ) {
                $outcome_status = 'AIS-Limited';
                $flag_status = 'Pending Manual Review';
            } else {
                if( ($draught_1_diff >= 0.3 && $draught_2_diff >= 0.3) || ( $draught_1_diff < -0.3 && $draught_2_diff < -0.3 ) ) {
                    $outcome_status = 'Inconclusive';
                    $flag_status = 'Pending Manual Review';
                }
            }
            
            $log .= ', outcome_status:'.$outcome_status;
            $log .= ', flag_status:'.$flag_status;

            $sql = "update ".$event_table_daughter." set flag_status = '".$flag_status."', outcome_status = '".$outcome_status."', step = 3, draught_change = '".$draught_diff."',operationmode = '".$current_status."',event_desc = '".$event_desc."',last_updated = NOW(), is_disappeared='Yes' where id='".$row['did']."'";
            $mysqli->query($sql);
            coastalynk_log_entry($row['did'], $sql, 'middle d 2 query');
            $total_daughters = total_sts_daughter_vessels( $row['event_id'] );
            $total_daughters_same_steps = total_sts_daughter_vessels( $row['event_id'], 3  );
            if( $total_daughters == $total_daughters_same_steps  ) {
                $sql = "update ".$event_table_mother." set ais_signal = '".$vessel2_signal."', is_disappeared='Yes', last_updated = NOW() where id='".$row['id']."'";
                $mysqli->query($sql);coastalynk_log_entry($row['id'], $sql, 'middle m 2 query');
            }

            coastalynk_log_entry($row['id'], 'STS Between '.$v1['name'].' and '.$v2['name'].' after 24hrs: '.$log, 'sts');
        }
    }
}

$result3->free();

if( count( $array_ids ) == 0 ) {
    $array_ids[] = 0;
}

if( count( $array_daughter_ids ) == 0 ) {
    $array_daughter_ids[] = 0;
}

$array_daughter_ids = array_unique($array_daughter_ids);

if( count( $array_ids ) > 0 ) {
    $array_ids_implode = implode( ',', array_unique($array_daughter_ids) );
    $sql = "select e.id, d.id as did, d.status as daughter_status, d.event_id, e.deadweight as vessel1_deadweight, d.deadweight as vessel2_deadweight, e.port, e.zone_type, e.zone_ship, e.uuid as vessel1_uuid, e.zone_terminal_name, e.name as vessel1_name, d.name as vessel2_name, e.mmsi as vessel1_mmsi, d.mmsi as vessel2_mmsi, d.uuid as vessel2_uuid, e.end_date, e.draught as vessel1_draught, e.last_position_UTC as vessel1_last_position_UTC, d.last_position_UTC as vessel2_last_position_UTC, d.draught as vessel2_draught, e.completed_draught as vessel1_completed_draught, d.completed_draught as vessel2_completed_draught from ".$event_table_mother." as e inner join ".$event_table_daughter." as d on(e.id=d.event_id) where e.port='".$port_name."' and e.status = 'Detected' and d.id not in (".$array_ids_implode.") and e.is_complete = 'No' and d.step = 0;";

    $result4 = $mysqli->query( $sql );
    $num_rows = mysqli_num_rows( $result4 );
    if( $num_rows > 0 ) {
        while( $row = mysqli_fetch_array( $result4, MYSQLI_ASSOC ) ) {
            process_complete_sts_vessels( $row );
        }
    }

    $result4->free();
}

$sql = "SELECT id as total FROM $event_table_mother where last_updated = ( select max( last_updated ) from $event_table_mother )";
$result = $mysqli->query($sql);
$num_rows = mysqli_num_rows($result);
coastalynk_update_summary('STS', $num_rows);
$mysqli->close(); // Close the database connection

function process_complete_sts_vessels( $row, $passed_end_date = '' ) {
    
    global $table_prefix, $vessel_product_type, $detector;

    $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

    if ($mysqli->connect_error) {
        die("Connection failed: " . $mysqli->connect_error);
    }

    $event_table_mother = $table_prefix . 'coastalynk_sts_events';
    $event_table_daughter = $table_prefix . 'coastalynk_sts_event_detail';

    $estimated_cargo = 0;
    if( $row['daughter_status'] != 'ended' ) {
        $v1 = get_datalastic_field( $row['vessel1_uuid'], '', true );
        $v2 = get_datalastic_field( $row['vessel2_uuid'], '', true );
        
        $v1_current_draught = $v1['current_draught'];
        $v2_current_draught = $v2['current_draught'];

        $draught_1_diff = ( floatval( $v1_current_draught ) - floatval($row['vessel1_draught']) );
        $draught_2_diff = ( floatval( $v2_current_draught ) - floatval($row['vessel2_draught']) );

        $vessel1_signal = coastalynk_signal_status( $row['vessel1_last_position_UTC'], date('Y-m-d H:i:s', strtotime($v1['last_position_UTC'])) );
        $vessel2_signal = coastalynk_signal_status( $row['vessel2_last_position_UTC'], date('Y-m-d H:i:s', strtotime($v2['last_position_UTC'])) );

        $calculator = new NigerianPortsAfterDraught(true);
        
        $tanker_by_type = $calculator->getTankerTypeByDWT($row['vessel1_deadweight']);

        $vessel_tanker_type     = 'MR';
        $vessel_prodtype    = 'Crude Light';
        if( floatval($row['vessel1_deadweight']) > 0 ) {
            $vessel_tanker_type     = $tanker_by_type['primary_type']['type'];
            $vessel_prodtype    = $vessel_product_type[$tanker_by_type['primary_type']['type']];
        }

        $vessel_1 = [
            'previous_draught' => $row['vessel1_draught'],
            'current_draught' => $v1_current_draught,
            'zone' => $row['zone_terminal_name'],
            'tanker_type' => $vessel_tanker_type,
            'product_type' => $vessel_prodtype,
            'timestamp' => date('Y-m-d H:i:s'),
            'ais_source' => $vessel1_signal,
            'mmsi' => $row['vessel1_mmsi']
        ];

        $event_desc = 'event_description';
        $current_status = 'current_status';
        $result1 = $calculator->calculateAfterDraught($vessel_1);
        if( is_array($result1) && count($result1) > 0 ) {
            $v1_current_draught = $result1['after_draught'];
            $draught_1_diff = $result1['draught_change'];
            $event_desc = $result1['event_description'];
            $current_status = $result1['current_status'];
        }
        
        
        $tanker_by_type2 = $calculator->getTankerTypeByDWT($row['vessel2_deadweight']);
        $vessel_tanker_type     = 'MR';
        $vessel_prodtype    = 'Crude Light';
        if( floatval($row['vessel2_deadweight']) > 0 ) {
            $vessel_tanker_type     = $tanker_by_type2['primary_type']['type'];
            $vessel_prodtype    = $vessel_product_type[$tanker_by_type2['primary_type']['type']];
        }

        $vessel_2 = [
            'previous_draught' => $row['vessel2_draught'],
            'current_draught' => $v2_current_draught,
            'zone' => $row['zone_terminal_name'],
            'tanker_type' => $vessel_tanker_type,
            'product_type' => $vessel_prodtype,
            'timestamp' => date('Y-m-d H:i:s'),
            'ais_source' => $vessel2_signal,
            'mmsi' => $row['vessel2_mmsi']
        ];

        $result2 = $calculator->calculateAfterDraught($vessel_2);
        if( is_array($result2) && count($result2) > 0 ) {
            $v2_current_draught = $result2['after_draught'];
            $draught_2_diff = $result2['draught_change'];
        }
        $event_desc = '';
        $current_status = '';
        $estimated_cargo = 0;
        if( $draught_1_diff >= 0.3 || $draught_1_diff <= -0.3 ) {
            if( is_array($result1) && count($result1) > 0 ) {
                $event_desc = $result1['event_description'];
                $current_status = $result1['current_status'];
                $estimated_cargo = $result1['cargo_mt'];
            }
        } else if( $draught_2_diff >= 0.3 || $draught_2_diff <= -0.3 ) {
            if( is_array($result2) && count($result2) > 0 ) {
                $event_desc = $result2['event_description'];
                $current_status = $result2['current_status'];
                $estimated_cargo = $result2['cargo_mt']; 
            }
        }
        
        $mother_vessel_number = '';
        $draught_diff = 0;
        if( $draught_1_diff >= 0.3 && $draught_1_diff <= -0.3 ) {
            $mother_vessel_number = " mother_vessel_number = '1',";
            $draught_diff = $draught_1_diff;
        } else if( $draught_2_diff >= 0.3 && $draught_2_diff <= -0.3 ) {
            $mother_vessel_number = " mother_vessel_number = '2',";
            $draught_diff = $draught_2_diff;
        }

        $outcome_status = 'Likely Transfer';
        if( $draught_1_diff <= 0.3 && $draught_1_diff >= -0.3 && $draught_2_diff <= 0.3 && $draught_2_diff >= -0.3 ) {
            $outcome_status = 'AIS-Limited';
        } else {
            if( ($draught_1_diff >= 0.3 && $draught_2_diff >= 0.3) || ( $draught_1_diff < -0.3 && $draught_2_diff < -0.3 ) ) {
                $outcome_status = 'Inconclusive';
            }
        }

        $log = 'Vessel 1 Draught Difference: '.$draught_1_diff;
        $log .= ', Vessel 2 Draught Difference: '.$draught_2_diff;
        $log .= ', Vessel 1 Signal: '.$vessel1_signal;
        $log .= ', Vessel 2 Signal: '.$vessel2_signal;
        $log .= ', Event Desc: '.$event_desc;

        $detectresult = $detector->detectSTSTransfer($v1, $v2);
        //echo '<pre>';print_r($detectresult);echo '</pre>';                                
        $end_date = 'Now()';
        if( !empty( $detectresult['end_date'] ) ) {
            $end_date = "'".date('Y-m-d H:i:s', strtotime($detectresult['end_date']))."'";
        }

        $sql = "update ".$event_table_daughter." set step = 1, draught_change = '".$draught_diff."', status = 'ended',outcome_status = '".$outcome_status."',operationmode = '".$current_status."',event_desc = '".$event_desc."',is_complete = 'Yes', ais_signal = '".$vessel2_signal."', estimated_cargo = '".$estimated_cargo."', completed_draught = '".floatval( $v2_current_draught)."', end_date = ".$end_date.", last_updated = NOW() where id='".$row['did']."'";
        $mysqli->query($sql);
        
        coastalynk_log_entry($row['did'], $sql, 'bottom d query');
        $total_daughters = total_sts_daughter_vessels( $row['event_id'] );
        $total_daughters_same_steps = total_sts_daughter_vessels( $row['event_id'], 1  );
        
        if( $total_daughters == $total_daughters_same_steps  ) {

            $sql = "select end_date from ".$event_table_daughter." where event_id='".$row['id']."' and end_date > $end_date order by end_date desc";
            $res_end_date = $mysqli->query($sql);
            $num_rows = mysqli_num_rows( $res_end_date );
            if( $num_rows > 0 ) {
                $end_date_row = mysqli_fetch_array( $res_end_date, MYSQLI_ASSOC );
                $end_date = "'".$end_date_row['end_date']."'";
            }

            $sql = "update ".$event_table_mother." set is_complete = 'Yes', status = 'Completed',ais_signal = '".$vessel1_signal."', completed_draught = '".floatval( $v1_current_draught )."', end_date = ".$end_date.", last_updated = NOW() where is_complete='No' and id='".$row['id']."'";
            $mysqli->query($sql);
            coastalynk_log_entry($row['id'], $sql, 'bottom m query');
        }
        coastalynk_log_entry($row['id'], 'STS Between '.$v1['name'].' and '.$v2['name'].' after vessels leave the area: '.$log, 'sts');
    }
}