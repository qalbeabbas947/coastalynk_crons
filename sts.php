<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
ini_set( "display_errors", "On" );
error_reporting(E_ALL);
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require_once __DIR__ . '/common.php';
global $table_prefix;

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

function get_datalastic_field( $uuid, $field = 'navigation_status' ) {
    
    global $api_key;
    $url = 'https://api.datalastic.com/api/v0/vessel_pro?api-key='.urlencode($api_key).'&uuid='.$uuid;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Bearer '.$api_key));
    $output = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($output, true);

    return $data['data'][$field];
}

$table_name = $table_prefix . 'coastalynk_ports';
$sql = "select * from ".$table_name." where port_type='Port'";
$candidates = [];
if ($result = $mysqli -> query($sql)) {
    
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
        vessel1_draught VARCHAR(50) default '',
        vessel1_last_position_UTC TIMESTAMP,
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
        vessel2_draught VARCHAR(50) default '',
        vessel2_last_position_UTC TIMESTAMP,
        port VARCHAR(255) default '',
        port_id VARCHAR(50) default '',
        distance float default 0,
        is_email_sent ENUM('No','Yes') NOT NULL DEFAULT 'No',
        is_complete ENUM('No','Yes') NOT NULL DEFAULT 'No',
        last_updated TIMESTAMP,
        PRIMARY KEY (id)
    )";
    if ($mysqli->query($sql) !== TRUE) {
        echo "Error: " . $sql . "<br>" . $mysqli->error;
    }

    // if ($mysqli->query("TRUNCATE TABLE ".$table_name_sts.";") !== TRUE) {
    //     echo "Error: " . $sql . "<br>" . $mysqli->error;
    // }

    $types = ['Tanker', 'Tanker - Hazard A (Major)', 'Tanker - Hazard B', 'Tanker - Hazard C (Minor)', 'Tanker - Hazard D (Recognizable)', 'Tanker: Hazardous category A', 'Tanker: Hazardous category B', 'Tanker: Hazardous category C', 'Tanker: Hazardous category D'];
    $array_ids = [];
    $array_uidds = [];
    foreach( $types as $type ) {
        while ( $obj = $result->fetch_object() ) {
            $url = sprintf(
                "https://api.datalastic.com/api/v0/vessel_inradius?api-key=%s&lat=%f&lon=%f&radius=%d&type=%s",
                urlencode($api_key),
                $obj->lat,
                $obj->lon,
                10,
                $type
            );

            $proximity_threshold = 500; // meters

            // Fetch vessels in area
            $response = file_get_contents($url);
            $data = json_decode($response, true);
            $vessels = $data['data']['vessels'];

            // Check proximity
            foreach ($vessels as $v1) {
                foreach ($vessels as $v2) {
                    
                    if ($v1['uuid'] != $v2['uuid']) { 
                        $dist = haversineDistance($v1['lat'], $v1['lon'], $v2['lat'], $v2['lon']);
                        if ($dist < $proximity_threshold) {
                            $time_diff = abs(strtotime($v1['last_position_UTC']) - strtotime($v2['last_position_UTC']));
                            if ($time_diff < 600 && $v1['speed'] < 2 && $v2['speed'] < 2) {
                                $v1_navigation_status = get_datalastic_field($v1['uuid']);
                                $v2_navigation_status = get_datalastic_field($v2['uuid']);

                                $v1_current_draught = get_datalastic_field($v2['uuid'], 'current_draught');
                                $v2_current_draught = get_datalastic_field($v2['uuid'], 'current_draught');

                                $sql = "select id, vessel1_uuid, is_email_sent from ".$table_name_sts." where ( vessel1_uuid='".$mysqli->real_escape_string($v1['uuid'])."' and vessel2_uuid='".$mysqli->real_escape_string($v2['uuid'])."' ) or ( vessel2_uuid='".$mysqli->real_escape_string($v1['uuid'])."' and vessel1_uuid='".$mysqli->real_escape_string($v2['uuid'])."' )";
                                $result = $mysqli->query($sql);
                                $num_rows = mysqli_num_rows($result);
                                if( $num_rows == 0 ) {
                                    $sql = "INSERT INTO $table_name_sts (vessel1_uuid , vessel1_name, vessel1_mmsi, vessel1_imo, vessel1_country_iso, vessel1_type, vessel1_type_specific, vessel1_lat, vessel1_lon,vessel1_speed,vessel1_navigation_status, vessel1_draught, vessel1_last_position_UTC, vessel2_uuid , vessel2_name, vessel2_mmsi, vessel2_imo, vessel2_country_iso, vessel2_type, vessel2_type_specific, vessel2_lat, vessel2_lon,vessel2_speed,vessel2_navigation_status,vessel2_draught,vessel2_last_position_UTC, distance, port, port_id, last_updated)
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
                                                '" . $mysqli->real_escape_string($obj->title) . "',
                                                '" . $mysqli->real_escape_string($obj->port_id) . "',
                                                NOW())";
                                    if ($mysqli->query($sql) !== TRUE) {
                                        echo "Error: " . $sql . "<br>" . $mysqli->error;
                                    } else {
                                        $insert_id = $array_ids[] = $mysqli->insert_id;
                                        $array_uidds[] = $v1['uuid'];

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
                                        $coastalynk_sts_body = str_replace( "[port]", $obj->title, $coastalynk_sts_body );
                                        $coastalynk_sts_body = str_replace( "[port_id]", $obj->port_id, $coastalynk_sts_body );
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
                                        $coastalynk_sts_email_subject = str_replace( "[port]", $obj->title, $coastalynk_sts_email_subject );
                                        $coastalynk_sts_email_subject = str_replace( "[port_id]", $obj->port_id, $coastalynk_sts_email_subject );
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

                                            echo 'Email sent successfully!';
                                        } catch (Exception $e) {
                                            echo "Email could not be sent. Error: {$mail->ErrorInfo}";
                                        }
                                    }
                                } else {
                                    $row = mysqli_fetch_assoc($result);
                                    $array_ids[] = $row['id'];
                                    $array_uidds[] = $row['vessel1_uuid'];
                                }
                            }
                        }
                    }
                }
            }
        }
    }


    if( count($array_ids) > 0 ) {
        $array_ids = implode(',', $array_ids);
        $sql = "update ".$table_name_sts." set is_complete='Yes' where is_complete='No' and id not in (".$array_ids.")";
        $mysqli->query($sql);
    }
}

$result->free(); // Free the result set
$mysqli->close(); // Close the database connection