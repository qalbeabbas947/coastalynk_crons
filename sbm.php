<?php
set_time_limit(0);
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
ini_set( "display_errors", "On" );
error_reporting(E_ALL);
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require_once __DIR__ . '/common.php';
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
$coatalynk_finance_admin_email 	= get_option_data('coatalynk_finance_admin_email');
$coatalynk_nimasa_admin_email 	= get_option_data('coatalynk_nimasa_admin_email');
$coatalynk_npa_admin_email 	    = get_option_data( 'coatalynk_npa_admin_email' );
$coastalynk_sbm_email_subject   = get_option_data( 'coastalynk_sbm_email_subject' );
$coastalynk_sbm_email_subject_original   = !empty( $coastalynk_sbm_email_subject ) ? $coastalynk_sbm_email_subject : 'Coastalynk SBM Alert - [port]';
$coastalynk_sbm_body   = get_option_data( 'coastalynk_sbm_body' );
$strbody = "Dear Sir/Madam,
<br>
<p>This is an automatic notification from Coastalynk Maritime Intelligence regarding a Single Buoy Mooring (sbm) operation detected at [port].</p><br>
<h3>General Detail:</h3>
<p>Date/Time (UTC): [last_updated]</p>
<p>Location: ([lat], [lon]) (Lagos Offshore)</p>
<p>Distance Between Vessels: [distance]</p>
<p>Port Reference: [port]</p>
<h3>Vessel Detail</h3>
<p>Name: [name] | IMO: [imo] | MMSI: [mmsi]</p>
<p>Type: [type] | Flag: <img src='[country_flag]' width='30px' alt='[country_iso]' /></p>
<p>Status: [navigation_status]</p>
<p>Draught: [draught]</p>
<br>
<p>View on Coastalynk Map(<a href='[sbm-page-url]'>Click Here</a>)</p>
<br>
<p>This notification is part of Coastalynk\'s effort to provide real-time intelligence to support anti-bunkering enforcement, maritime security, and revenue protection.</p>
<br>
<p>Regards,</p>
<p>Coastalynk Maritime Intelligence</p>";
$coastalynk_sbm_body_original = !empty( $coastalynk_sbm_body ) ? $coastalynk_sbm_body : $strbody;

$coastalynk_sbm_complete_email_subject  = get_option_data( 'coastalynk_sbm_complete_email_subject' );
$coastalynk_sbm_complete_email_subject_original = !empty( $coastalynk_sbm_complete_email_subject ) ? $coastalynk_sbm_complete_email_subject : 'Coastalynk SBM Completed Alert - [port]';
$coastalynk_sbm_complete_body  = get_option_data( 'coastalynk_sbm_complete_body' );
$coastalynk_sbm_complete_email_default  = "Dear Sir/Madam,
<br>
<p>This is an automatic notification from Coastalynk Maritime Intelligence regarding a Single Buoy Mooring (sbm) operation detected at [port] is complete.</p><br>
<h3>General Detail:</h3>
<p>Date/Time (UTC): [last_updated]</p>
<p>Location: ([lat], [lon]) (Lagos Offshore)</p>
<p>Distance Between Vessels: [distance]</p>
<p>Port Reference: [port]</p>
<h3>Vessel Detail</h3>
<p>Name: [name] | IMO: [imo] | MMSI: [mmsi]</p>
<p>Type: [type] | Flag: <img src='[country_flag]' width='30px' alt='[country_iso]' /></p>
<p>Status: [navigation_status]</p>
<p>Before Draught: [before_draught]</p>
<p>After Draught: [after_draught]</p>
<br>
Leavy Data:<br>
[Leavy_data]<br>
<p>View on Coastalynk Map(<a href='[sbm-page-url]'>Click Here</a>)</p>
<br>
<p>This notification is part of Coastalynk\'s effort to provide real-time intelligence to support anti-bunkering enforcement, maritime security, and revenue protection.</p>
<br>
<p>Regards,</p>
<p>Coastalynk Maritime Intelligence</p>";
$coastalynk_sbm_complete_body_original = !empty( $coastalynk_sbm_complete_body ) ? $coastalynk_sbm_complete_body : $coastalynk_sbm_complete_email_default;

/**
 * Default no operation email data
 */
$coastalynk_sbm_no_opt_email_subject  = get_option_data( 'coastalynk_sbm_no_opt_email_subject' );
$coastalynk_sbm_no_opt_email_subject_original = !empty( $coastalynk_sbm_no_opt_email_subject ) ? $coastalynk_sbm_no_opt_email_subject : 'Coastalynk SBM No Operation Alert - [port]';

$coastalynk_sbm_no_opt_body  = get_option_data( 'coastalynk_sbm_no_opt_body' );
$coastalynk_sbm_no_opt_body_default  = "Dear Sir/Madam,
                                            <br>
                                            <p>This is an automatic notification from Coastalynk Maritime Intelligence regarding a Single Buoy Mooring (sbm) operation detected but not performed at [port].</p><br>
                                            <h3>General Detail:</h3>
                                            <p>Date/Time (UTC): [last_updated]</p>
                                            <p>Location: ([lat], [lon]) (Lagos Offshore)</p>
                                            <p>Distance Between Vessels: [distance]</p>
                                            <p>Port Reference: [port]</p>
                                            <h3>Vessel Detail</h3>
                                            <p>Name: [name] | IMO: [imo] | MMSI: [mmsi]</p>
                                            <p>Type: [type] | Flag: <img src='[country_flag]' width='30px' alt='[country_iso]' /></p>
                                            <p>Status: [navigation_status]</p>
                                            <p>Draught: [draught]</p>
                                            <p>View on Coastalynk Map(<a href='[sbm-page-url]'>Click Here</a>)</p>
                                            <br>
                                            <p>This notification is part of Coastalynk\'s effort to provide real-time intelligence to support anti-bunkering enforcement, maritime security, and revenue protection.</p>
                                            <br>
                                            <p>Regards,</p>
                                            <p>Coastalynk Maritime Intelligence</p>";
$coastalynk_sbm_no_opt_body_original = !empty( $coastalynk_sbm_no_opt_body ) ? $coastalynk_sbm_no_opt_body : $coastalynk_sbm_no_opt_body_default;


function haversineDistance($lat1, $lon1, $lat2, $lon2, $unit = 'meters') {
    // Validate coordinates
    if (!is_numeric($lat1) || !is_numeric($lon1) || !is_numeric($lat2) || !is_numeric($lon2)) {
        throw new InvalidArgumentException('All coordinates must be numeric values');
    }
    
    // Validate latitude range (-90 to 90)
    if (abs($lat1) > 90 || abs($lat2) > 90) {
        throw new InvalidArgumentException('Latitude must be between -90 and 90 degrees');
    }
    
    // Validate longitude range (-180 to 180)
    if (abs($lon1) > 180 || abs($lon2) > 180) {
        throw new InvalidArgumentException('Longitude must be between -180 and 180 degrees');
    }
    
    $earth_radius = 6371000; // meters
    
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    
    $a = sin($dLat/2) * sin($dLat/2) + 
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * 
         sin($dLon/2) * sin($dLon/2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    
    $distance = $earth_radius * $c;
    
    // Convert to different units if requested
    switch (strtolower($unit)) {
        case 'km':
        case 'kilometers':
            return $distance / 1000;
        case 'miles':
            return $distance / 1609.344;
        case 'nautical':
            return $distance / 1852;
        case 'meters':
        default:
            return $distance;
    }
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

$port_type = 'Offshore Terminal';
if( !isset( $_REQUEST['port_type'] ) && !empty( $_REQUEST['port_type'] ) ) {
    $port_type = $mysqli->real_escape_string($_REQUEST['port_type']);
}

$table_name = $table_prefix . 'coastalynk_ports';
$sql = "select * from ".$table_name." where country_iso='NG' and port_type='".$port_type."' order by title";

$candidates = [];
$idex = 0;
if ($result = $mysqli -> query($sql)) {
    
    $table_name_sbm = $table_prefix . 'coastalynk_sbm';

    // Create table if not exists
    $sql = "CREATE TABLE IF NOT EXISTS $table_name_sbm (
        id INT AUTO_INCREMENT,
        uuid VARCHAR(50) default '',
        name VARCHAR(255) default '',
        mmsi VARCHAR(50) default '',
        imo VARCHAR(50) default '',
        country_iso VARCHAR(2) default '',
        type VARCHAR(50) default '',
        type_specific VARCHAR(255) default '',
        lat VARCHAR(10) default '',
        lon VARCHAR(10) default '',
        speed float default 0,
        navigation_status VARCHAR(50) default '',
        draught VARCHAR(50) default '',
        completed_draught VARCHAR(50) default '',
        last_position_UTC TIMESTAMP,
        port VARCHAR(255) default '',
        port_id VARCHAR(50) default '',
        port_type VARCHAR(50) default '',
        distance float default 0,
        is_offloaded ENUM('No','Yes') NOT NULL DEFAULT 'No',
        is_start_email_sent ENUM('No','Yes') NOT NULL DEFAULT 'No',
        is_complete_email_sent ENUM('No','Yes') NOT NULL DEFAULT 'No',
        last_updated TIMESTAMP,
        PRIMARY KEY (id)
    )";

    if ($mysqli->query($sql) !== TRUE) {
        echo "Error: " . $sql . "<br>" . $mysqli->error;
    }

    $types = ['Tanker', 'Tanker - Hazard A (Major)', 'Tanker - Hazard B', 'Tanker - Hazard C (Minor)', 'Tanker - Hazard D (Recognizable)', 'Tanker: Hazardous category A', 'Tanker: Hazardous category B', 'Tanker: Hazardous category C', 'Tanker: Hazardous category D'];
    $array_ids = [];
    $array_uidds = [];
    while ( $obj = $result->fetch_object() ) {
        foreach( $types as $type ) {

            $url = sprintf(
                "https://api.datalastic.com/api/v0/vessel_inradius?api-key=%s&lat=%f&lon=%f&radius=%f&type=%s",
                urlencode($api_key),
                $obj->lat,
                $obj->lon,
                0.5,
                $type
            );

            // Fetch vessels in area
            $response = file_get_contents($url);
            $data = json_decode($response, true);
            
            $vessels = $data['data']['vessels'];

            // Check proximity
            if( is_array( $vessels ) && count( $vessels ) > 0 ) {
                foreach ($vessels as $v1) {
                    $sql = "select id, uuid, is_start_email_sent from ".$table_name_sbm." where port_type='".$mysqli->real_escape_string($_REQUEST['port_type'])."' and is_offloaded='No' and uuid='".$mysqli->real_escape_string($v1['uuid'])."'";
                    $checkresult = $mysqli->query($sql);
                    $num_rows = mysqli_num_rows($checkresult);
                    
                    if( $num_rows == 0 ) {

                            $navigation_status = get_datalastic_field($v1['uuid']);
                            $current_draught = get_datalastic_field($v1['uuid'], 'current_draught');
                            $dist = haversineDistance($v1['lat'], $v1['lon'], $obj->lat, $obj->lon);
                            $sql = "INSERT INTO $table_name_sbm (uuid , name, mmsi, imo, country_iso, type, type_specific, lat, lon,speed,navigation_status, draught, last_position_UTC, distance, port, port_id, port_type, last_updated)
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
                                    '" . $navigation_status . "',
                                    '" . $current_draught . "',
                                    '" . date('Y-m-d H:i:s', strtotime($v1['last_position_UTC'])) . "',
                                    '" . floatval($dist) . "',
                                    '" . $mysqli->real_escape_string($obj->title) . "',
                                    '" . $mysqli->real_escape_string($obj->port_id) . "',
                                    '" . $mysqli->real_escape_string($obj->port_type) . "',
                                        NOW())";
                            if ($mysqli->query($sql) !== TRUE) {
                                echo "Error: " . $sql . "<br>" . $mysqli->error;
                            } else {
                                $insert_id = $array_ids[] = $mysqli->insert_id;
                                $array_uidds[] = $v1['uuid'];
                                
                                $coastalynk_sbm_body = str_replace( "[uuid]", $v1['uuid'], $coastalynk_sbm_body_original );
                                $coastalynk_sbm_body = str_replace( "[name]", $v1['name'], $coastalynk_sbm_body );
                                $coastalynk_sbm_body = str_replace( "[mmsi]", $v1['mmsi'], $coastalynk_sbm_body );
                                $coastalynk_sbm_body = str_replace( "[imo]", $v1['imo'], $coastalynk_sbm_body );
                                $coastalynk_sbm_body = str_replace( "[country_iso]", $v1['country_iso'], $coastalynk_sbm_body );
                                $coastalynk_sbm_body = str_replace( "[type]", $v1['type'], $coastalynk_sbm_body );
                                $coastalynk_sbm_body = str_replace( "[type_specific]", $v1['type_specific'], $coastalynk_sbm_body );
                                $coastalynk_sbm_body = str_replace( "[lat]", $v1['lat'], $coastalynk_sbm_body );
                                $coastalynk_sbm_body = str_replace( "[lon]", $v1['lon'], $coastalynk_sbm_body );
                                $coastalynk_sbm_body = str_replace( "[speed]", $v1['speed'], $coastalynk_sbm_body );
                                $coastalynk_sbm_body = str_replace( "[navigation_status]", $navigation_status, $coastalynk_sbm_body );
                                $coastalynk_sbm_body = str_replace( "[draught]", $current_draught, $coastalynk_sbm_body );
                                $coastalynk_sbm_body = str_replace( "[country_flag]", $siteurl.'/flags/'.strtolower($v1['country_iso']).'.jpg', $coastalynk_sbm_body );
                                $coastalynk_sbm_body = str_replace( "[sbm-page-url]", $siteurl.'/sbm-map/', $coastalynk_sbm_body );            
                                $coastalynk_sbm_body = str_replace( "[distance]", $dist, $coastalynk_sbm_body );
                                $coastalynk_sbm_body = str_replace( "[port]", $obj->title, $coastalynk_sbm_body );
                                $coastalynk_sbm_body = str_replace( "[port_id]", $obj->port_id, $coastalynk_sbm_body );
                                $coastalynk_sbm_body = str_replace( "[last_updated]", date('Y-m-d H:i:s', strtotime($v1['last_position_UTC'])), $coastalynk_sbm_body ); 

                                $coastalynk_sbm_email_subject = str_replace( "[name]", $v1['name'], $coastalynk_sbm_email_subject_original );
                                $coastalynk_sbm_email_subject = str_replace( "[mmsi]", $v1['mmsi'], $coastalynk_sbm_email_subject );
                                $coastalynk_sbm_email_subject = str_replace( "[imo]", $v1['imo'], $coastalynk_sbm_email_subject );
                                $coastalynk_sbm_email_subject = str_replace( "[country_iso]", $v1['country_iso'], $coastalynk_sbm_email_subject );
                                $coastalynk_sbm_email_subject = str_replace( "[type]", $v1['type'], $coastalynk_sbm_email_subject );
                                $coastalynk_sbm_email_subject = str_replace( "[type_specific]", $v1['type_specific'], $coastalynk_sbm_email_subject );
                                $coastalynk_sbm_email_subject = str_replace( "[lat]", $v1['lat'], $coastalynk_sbm_email_subject );
                                $coastalynk_sbm_email_subject = str_replace( "[lon]", $v1['lon'], $coastalynk_sbm_email_subject );
                                $coastalynk_sbm_email_subject = str_replace( "[speed]", $v1['speed'], $coastalynk_sbm_email_subject );
                                $coastalynk_sbm_email_subject = str_replace( "[navigation_status]", $navigation_status, $coastalynk_sbm_email_subject );
                                $coastalynk_sbm_email_subject = str_replace( "[draught]", $current_draught, $coastalynk_sbm_email_subject );
                                $coastalynk_sbm_email_subject = str_replace( "[country_flag]", $siteurl.'/flags/'.strtolower($v1['country_iso']).'.jpg', $coastalynk_sbm_email_subject );
                                $coastalynk_sbm_email_subject = str_replace( "[sbm-page-url]", $siteurl.'//', $coastalynk_sbm_email_subject );            
                                $coastalynk_sbm_email_subject = str_replace( "[distance]", $dist, $coastalynk_sbm_email_subject );
                                $coastalynk_sbm_email_subject = str_replace( "[port]", $obj->title, $coastalynk_sbm_email_subject );
                                $coastalynk_sbm_email_subject = str_replace( "[port_id]", $obj->port_id, $coastalynk_sbm_email_subject );
                                $coastalynk_sbm_email_subject = str_replace( "[last_updated]", date('Y-m-d H:i:s', strtotime($v1['last_position_UTC'])), $coastalynk_sbm_email_subject ); 

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
                                    $mail->addAddress($coatalynk_site_admin_email, 'CoastaLynk');
                                    $mail->addAddress($coatalynk_npa_admin_email, 'NPA');
                                    $mail->addAddress($coatalynk_finance_admin_email, 'Finance Department');
                                    $mail->addAddress($coatalynk_nimasa_admin_email, 'NIMASA');


                                    // Content
                                    $mail->isHTML(true); // Set email format to HTML
                                    $mail->Subject = $coastalynk_sbm_email_subject;
                                    $mail->Body    = $coastalynk_sbm_body;
                                    $mail->AltBody = strip_tags($coastalynk_sbm_body);

                                    $mail->send();

                                    $sql = "update ".$table_name_sbm." set is_start_email_sent='Yes' where id='".$insert_id."'";
                                    $mysqli->query($sql);
                                    echo 'Email sent successfully!';
                                } catch (Exception $e) {
                                    echo "Email could not be sent. Error: {$mail->ErrorInfo}";
                                }
                            }
                    } else{
                        $row = mysqli_fetch_assoc($checkresult);
                        $array_ids[] = $row['id'];
                        $array_uidds[] = $row['uuid'];
                        if( intval( $row['id'] ) > 0 ) {
                            $sql = "update ".$table_name_sbm." set lat='".$v1['lat']."', lon='".$v1['lon']."' where id='".$row['id']."'";
                            $mysqli->query($sql);
                        }
                    }

                    $checkresult->free(); // Free the result set
                }
            }
            sleep(1);
        }
    }

    $sql = "select * from ".$table_name_sbm." where port_type='".$mysqli->real_escape_string($_REQUEST['port_type'])."' and is_offloaded='No'";
    $result2 = $mysqli->query($sql);
    $num_rows = mysqli_num_rows($result2);
    if( count($array_ids) > 0 ) {
        $array_ids = implode(',', $array_ids);
        $sql = "select * from ".$table_name_sbm." where port_type='".$mysqli->real_escape_string($_REQUEST['port_type'])."' and is_offloaded='No' and id not in (".$array_ids.")";
    }
    $result2->free();

    $result3 = $mysqli->query($sql);
    $num_rows = mysqli_num_rows($result3);
    if( $num_rows > 0 ) {

        while ( $v1 = mysqli_fetch_assoc($result3) ) {
            $current_draught = get_datalastic_field($v1['uuid'], 'current_draught');
            $sql = "update ".$table_name_sbm." set is_offloaded='Yes', completed_draught='".$current_draught."' where id = '".$v1['id']."'";
            $mysqli->query($sql);
            
            $subject = '';
            $body = '';

            if( floatval( $v1['draught'] ) == floatval( $current_draught ) ) {
                
                $coastalynk_sbm_no_opt_body = str_replace( "[uuid]", $v1['uuid'], $coastalynk_sbm_no_opt_body_original );
                $coastalynk_sbm_no_opt_body = str_replace( "[name]", $v1['name'], $coastalynk_sbm_no_opt_body );
                $coastalynk_sbm_no_opt_body = str_replace( "[mmsi]", $v1['mmsi'], $coastalynk_sbm_no_opt_body );
                $coastalynk_sbm_no_opt_body = str_replace( "[imo]", $v1['imo'], $coastalynk_sbm_no_opt_body );
                $coastalynk_sbm_no_opt_body = str_replace( "[country_iso]", $v1['country_iso'], $coastalynk_sbm_no_opt_body );
                $coastalynk_sbm_no_opt_body = str_replace( "[type]", $v1['type'], $coastalynk_sbm_no_opt_body );
                $coastalynk_sbm_no_opt_body = str_replace( "[type_specific]", $v1['type_specific'], $coastalynk_sbm_no_opt_body );
                $coastalynk_sbm_no_opt_body = str_replace( "[lat]", $v1['lat'], $coastalynk_sbm_no_opt_body );
                $coastalynk_sbm_no_opt_body = str_replace( "[lon]", $v1['lon'], $coastalynk_sbm_no_opt_body );
                $coastalynk_sbm_no_opt_body = str_replace( "[speed]", $v1['speed'], $coastalynk_sbm_no_opt_body );
                $coastalynk_sbm_no_opt_body = str_replace( "[navigation_status]", $v1['navigation_status'], $coastalynk_sbm_no_opt_body );
                $coastalynk_sbm_no_opt_body = str_replace( "[draught]", $v1['draught'], $coastalynk_sbm_no_opt_body );
                $coastalynk_sbm_no_opt_body = str_replace( "[country_flag]", $siteurl.'/flags/'.strtolower($v1['country_iso']).'.jpg', $coastalynk_sbm_no_opt_body );
                $coastalynk_sbm_no_opt_body = str_replace( "[sbm-page-url]", $siteurl.'/sbm-map/', $coastalynk_sbm_no_opt_body );            
                $coastalynk_sbm_no_opt_body = str_replace( "[distance]", $v1['distance'], $coastalynk_sbm_no_opt_body );
                $coastalynk_sbm_no_opt_body = str_replace( "[port]", $v1['port'], $coastalynk_sbm_no_opt_body );
                $coastalynk_sbm_no_opt_body = str_replace( "[port_id]", $v1['port_id'], $coastalynk_sbm_no_opt_body );
                $coastalynk_sbm_no_opt_body = str_replace( "[last_updated]", date('Y-m-d H:i:s', strtotime($v1['last_position_UTC'])), $coastalynk_sbm_no_opt_body ); 
                $body = $coastalynk_sbm_no_opt_body;

                $coastalynk_sbm_no_opt_email_subject = str_replace( "[name]", $v1['name'], $coastalynk_sbm_no_opt_email_subject_original );
                $coastalynk_sbm_no_opt_email_subject = str_replace( "[mmsi]", $v1['mmsi'], $coastalynk_sbm_no_opt_email_subject );
                $coastalynk_sbm_no_opt_email_subject = str_replace( "[imo]", $v1['imo'], $coastalynk_sbm_no_opt_email_subject );
                $coastalynk_sbm_no_opt_email_subject = str_replace( "[country_iso]", $v1['country_iso'], $coastalynk_sbm_no_opt_email_subject );
                $coastalynk_sbm_no_opt_email_subject = str_replace( "[type]", $v1['type'], $coastalynk_sbm_no_opt_email_subject );
                $coastalynk_sbm_no_opt_email_subject = str_replace( "[type_specific]", $v1['type_specific'], $coastalynk_sbm_no_opt_email_subject );
                $coastalynk_sbm_no_opt_email_subject = str_replace( "[lat]", $v1['lat'], $coastalynk_sbm_no_opt_email_subject );
                $coastalynk_sbm_no_opt_email_subject = str_replace( "[lon]", $v1['lon'], $coastalynk_sbm_no_opt_email_subject );
                $coastalynk_sbm_no_opt_email_subject = str_replace( "[speed]", $v1['speed'], $coastalynk_sbm_no_opt_email_subject );
                $coastalynk_sbm_no_opt_email_subject = str_replace( "[navigation_status]", $v1['navigation_status'], $coastalynk_sbm_no_opt_email_subject );
                $coastalynk_sbm_no_opt_email_subject = str_replace( "[draught]", $v1['draught'], $coastalynk_sbm_no_opt_email_subject );
                $coastalynk_sbm_no_opt_email_subject = str_replace( "[country_flag]", $siteurl.'/flags/'.strtolower($v1['country_iso']).'.jpg', $coastalynk_sbm_no_opt_email_subject );
                $coastalynk_sbm_no_opt_email_subject = str_replace( "[sbm-page-url]", $siteurl.'/sbm-map/', $coastalynk_sbm_no_opt_email_subject );            
                $coastalynk_sbm_no_opt_email_subject = str_replace( "[distance]", $v1['distance'], $coastalynk_sbm_no_opt_email_subject );
                $coastalynk_sbm_no_opt_email_subject = str_replace( "[port]", $v1['port'], $coastalynk_sbm_no_opt_email_subject );
                $coastalynk_sbm_no_opt_email_subject = str_replace( "[port_id]", $v1['port_id'], $coastalynk_sbm_no_opt_email_subject );
                $coastalynk_sbm_no_opt_email_subject = str_replace( "[last_updated]", date('Y-m-d H:i:s', strtotime($v1['last_position_UTC'])), $coastalynk_sbm_no_opt_email_subject ); 
                $subject = $coastalynk_sbm_no_opt_email_subject;
            } else {

                $coastalynk_sbm_complete_body = str_replace( "[uuid]", $v1['uuid'], $coastalynk_sbm_complete_body_original );
                $coastalynk_sbm_complete_body = str_replace( "[name]", $v1['name'], $coastalynk_sbm_complete_body );
                $coastalynk_sbm_complete_body = str_replace( "[mmsi]", $v1['mmsi'], $coastalynk_sbm_complete_body );
                $coastalynk_sbm_complete_body = str_replace( "[imo]", $v1['imo'], $coastalynk_sbm_complete_body );
                $coastalynk_sbm_complete_body = str_replace( "[country_iso]", $v1['country_iso'], $coastalynk_sbm_complete_body );
                $coastalynk_sbm_complete_body = str_replace( "[type]", $v1['type'], $coastalynk_sbm_complete_body );
                $coastalynk_sbm_complete_body = str_replace( "[type_specific]", $v1['type_specific'], $coastalynk_sbm_complete_body );
                $coastalynk_sbm_complete_body = str_replace( "[lat]", $v1['lat'], $coastalynk_sbm_complete_body );
                $coastalynk_sbm_complete_body = str_replace( "[lon]", $v1['lon'], $coastalynk_sbm_complete_body );
                $coastalynk_sbm_complete_body = str_replace( "[speed]", $v1['speed'], $coastalynk_sbm_complete_body );
                $coastalynk_sbm_complete_body = str_replace( "[navigation_status]", $v1['navigation_status'], $coastalynk_sbm_complete_body );
                $coastalynk_sbm_complete_body = str_replace( "[before_draught]", $v1['draught'], $coastalynk_sbm_complete_body );
                $coastalynk_sbm_complete_body = str_replace( "[after_draught]", $current_draught, $coastalynk_sbm_complete_body );
                $coastalynk_sbm_complete_body = str_replace( "[country_flag]", $siteurl.'/flags/'.strtolower($v1['country_iso']).'.jpg', $coastalynk_sbm_complete_body );
                $coastalynk_sbm_complete_body = str_replace( "[sbm-page-url]", $siteurl.'/sbm-map/', $coastalynk_sbm_complete_body );            
                $coastalynk_sbm_complete_body = str_replace( "[distance]", $v1['distance'], $coastalynk_sbm_complete_body );
                $coastalynk_sbm_complete_body = str_replace( "[port]", $v1['port'], $coastalynk_sbm_complete_body );
                $coastalynk_sbm_complete_body = str_replace( "[port_id]", $v1['port_id'], $coastalynk_sbm_complete_body );
                $coastalynk_sbm_complete_body = str_replace( "[last_updated]", date('Y-m-d H:i:s', strtotime($v1['last_position_UTC'])), $coastalynk_sbm_complete_body ); 
                

                $coastalynk_sbm_complete_email_subject = str_replace( "[name]", $v1['name'], $coastalynk_sbm_complete_email_subject_original );
                $coastalynk_sbm_complete_email_subject = str_replace( "[mmsi]", $v1['mmsi'], $coastalynk_sbm_complete_email_subject );
                $coastalynk_sbm_complete_email_subject = str_replace( "[imo]", $v1['imo'], $coastalynk_sbm_complete_email_subject );
                $coastalynk_sbm_complete_email_subject = str_replace( "[country_iso]", $v1['country_iso'], $coastalynk_sbm_complete_email_subject );
                $coastalynk_sbm_complete_email_subject = str_replace( "[type]", $v1['type'], $coastalynk_sbm_complete_email_subject );
                $coastalynk_sbm_complete_email_subject = str_replace( "[type_specific]", $v1['type_specific'], $coastalynk_sbm_complete_email_subject );
                $coastalynk_sbm_complete_email_subject = str_replace( "[lat]", $v1['lat'], $coastalynk_sbm_complete_email_subject );
                $coastalynk_sbm_complete_email_subject = str_replace( "[lon]", $v1['lon'], $coastalynk_sbm_complete_email_subject );
                $coastalynk_sbm_complete_email_subject = str_replace( "[speed]", $v1['speed'], $coastalynk_sbm_complete_email_subject );
                $coastalynk_sbm_complete_email_subject = str_replace( "[navigation_status]", $v1['navigation_status'], $coastalynk_sbm_complete_email_subject );
                $coastalynk_sbm_complete_email_subject = str_replace( "[before_draught]", $v1['draught'], $coastalynk_sbm_complete_email_subject );
                $coastalynk_sbm_complete_email_subject = str_replace( "[after_draught]", $current_draught, $coastalynk_sbm_complete_email_subject );
                $coastalynk_sbm_complete_email_subject = str_replace( "[country_flag]", $siteurl.'/flags/'.strtolower($v1['country_iso']).'.jpg', $coastalynk_sbm_complete_email_subject );
                $coastalynk_sbm_complete_email_subject = str_replace( "[sbm-page-url]", $siteurl.'/sbm-map/', $coastalynk_sbm_complete_email_subject );            
                $coastalynk_sbm_complete_email_subject = str_replace( "[distance]", $v1['distance'], $coastalynk_sbm_complete_email_subject );
                $coastalynk_sbm_complete_email_subject = str_replace( "[port]", $v1['port'], $coastalynk_sbm_complete_email_subject );
                $coastalynk_sbm_complete_email_subject = str_replace( "[port_id]", $v1['port_id'], $coastalynk_sbm_complete_email_subject );
                $coastalynk_sbm_complete_email_subject = str_replace( "[last_updated]", date('Y-m-d H:i:s', strtotime($v1['last_position_UTC'])), $coastalynk_sbm_complete_email_subject ); 
                $subject = $coastalynk_sbm_complete_email_subject;

                $draught = abs( floatval( $v1['draught'] ) - floatval( $current_draught ) );
                $leavy_data = '';
                $total = 0;
                $cargo_dues = ( $draught * 6.79 );

                $total += $cargo_dues;
                $leavy_data .= 'NPA cargo dues (liquid bulk): $'.$cargo_dues.'/ton<br>';

                $sbm_spm_harbour = ( $draught * 1.39 );
                $total += $sbm_spm_harbour;

                $leavy_data .= 'SBM/SPM harbour dues: $'.$sbm_spm_harbour.'/ton<br>';

                $env_leavy = ( $draught * 0.12 );
                $total += $env_leavy;
                $leavy_data .= 'Environmental levy: $'. $env_leavy.'/ton<br>';

                $polution_leavy = ( $draught * 0.10 );
                $total += $polution_leavy;
                $leavy_data .= 'NIMASA pollution levy: $'.$polution_leavy.'/ton<br>';
                $leavy_data .= 'NIMASA wet cargo levy: 3% of freight value<br>';
                $leavy_data .= 'Total levy: $'.$total.'/ton<br>';

                $coastalynk_sbm_complete_body = str_replace( "[Leavy_data]", $leavy_data, $coastalynk_sbm_complete_body ); 
                $body = $coastalynk_sbm_complete_body;
            }

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
                $mail->addAddress($coatalynk_site_admin_email, 'CoastaLynk');
                $mail->addAddress($coatalynk_npa_admin_email, 'NPA');
                $mail->addAddress($coatalynk_finance_admin_email, 'Finance Department');
                $mail->addAddress($coatalynk_nimasa_admin_email, 'NIMASA');

                // Content
                $mail->isHTML(true); // Set email format to HTML
                $mail->Subject = $subject;
                $mail->Body    = $body;
                $mail->AltBody = strip_tags($body);

                $mail->send();

                $sql = "update ".$table_name_sbm." set is_complete_email_sent='Yes' where id='".$v1['id']."'";
                $mysqli->query($sql);
                echo 'Email sent successfully!';
            } catch (Exception $e) {
                echo "Email could not be sent. Error: {$mail->ErrorInfo}";
            }
            
        }
    }

    $result3->free();
}

$result->free(); // Free the result set
$sql = "SELECT id as total FROM $table_name_sbm  where last_updated = (select max(last_updated) from $table_name_sbm)";
$result = $mysqli->query($sql);
$num_rows = mysqli_num_rows($result);
coastalynk_update_summary('SBM', $num_rows);

$mysqli->close(); // Close the database connection