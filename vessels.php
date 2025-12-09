<?php
set_time_limit(0);
// ini_set( "display_errors", "On" );
// error_reporting(E_ALL);

require_once __DIR__ . '/common.php';
global $table_prefix;
// Connect to database directly
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

$api_key = get_option_data('coatalynk_datalastic_apikey');

// Connect to database directly
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

$table_name = $table_prefix . 'coastalynk_ports';
$sql = "select * from ".$table_name." where country_iso='NG' and port_type='Port' order by title";
$idx = 0;
if ($result = $mysqli->query( $sql ) ) {

    $table_name_vessel = $table_prefix . 'coastalynk_vessels';

    // Create table if not exists
    $sql = "CREATE TABLE IF NOT EXISTS $table_name_vessel (
        uuid VARCHAR(50) default '',
        name VARCHAR(255) default '',
        mmsi VARCHAR(50) default '',
        eni VARCHAR(50) default '',
        imo VARCHAR(50) default '',
        country_iso VARCHAR(2) default '',
        type VARCHAR(50) default '',
        type_specific VARCHAR(255) default '',
        lat VARCHAR(50) default '',
        lon VARCHAR(50) default '',
        speed float default 0,
        course VARCHAR(50) default '',
        heading VARCHAR(50) default '',
        destination VARCHAR(50) default '',
        last_position_epoch VARCHAR(50) default '',
        last_position_UTC VARCHAR(50) default '',
        eta_epoch VARCHAR(50) default '',
        eta_UTC VARCHAR(50) default '',
        distance VARCHAR(50) default '',
        navigation_status VARCHAR(50) default '',
        current_draught VARCHAR(50) default '',
        dest_port VARCHAR(50) default '',
        dest_port_unlocode VARCHAR(50) default '',
        dep_port VARCHAR(255) default '',
        dep_port_unlocode VARCHAR(50) default '',
        atd_epoch VARCHAR(50) default '',
        atd_UTC VARCHAR(50) default '',
        last_updated TIMESTAMP,
        PRIMARY KEY (uuid)
    )";
    
    if ($mysqli->query($sql) !== TRUE) {
        echo "Error: " . $sql . "<br>" . $mysqli->error;
    }

    if ($mysqli->query("TRUNCATE TABLE ".$table_name_vessel.";") !== TRUE) {
        echo "Error: " . $sql . "<br>" . $mysqli->error;
    }
    while ( $obj = $result->fetch_object() ) {
            
        if( ! empty($obj->lat) && !empty($obj->lon)) {
            echo '<br>'.($idx++).":".$obj->title;

            echo '<br>'.$url = sprintf(
                "https://api.datalastic.com/api/v0/vessel_inradius?api-key=%s&lat=%f&lon=%f&radius=%f",
                urlencode($api_key),
                $obj->lat,
                $obj->lon,
                10
            );

            // Fetch vessels in area
            $response = file_get_contents($url);
            $data = json_decode($response, true);
            $vessels = $data['data']['vessels'];

            // Check proximity
            foreach ($vessels as $vessel) {
                $name = $vessel['name']??"";   
                $uuid = $vessel['uuid']??"";                         
                $mmsi = $vessel['mmsi']??"";
                $imo = $vessel['imo']??"";
                $eni = $vessel['eni']??"";
                $country_iso = $vessel['country_iso']??"";
                $type = $vessel['type']??"";
                $type_specific = $vessel['type_specific']??"";
                $lat = $vessel['lat']??"";
                $lon = $vessel['lon']??"";
                $speed = $vessel['speed']??0;
                $course = $vessel['course']??"";
                $heading = $vessel['heading']??"";
                $destination = $vessel['destination']??"";
                $last_position_epoch = $vessel['last_position_epoch']??"";
                $last_position_UTC = $vessel['last_position_UTC']??"";
                $eta_epoch = $vessel['eta_epoch']??"";
                $eta_UTC = $vessel['eta_UTC']??"";
                $distance = $vessel['distance']??"";

                $url = sprintf(
                        "https://api.datalastic.com/api/v0/vessel_pro?api-key=%s&uuid=%s",
                        urlencode($api_key),
                        $vessel['uuid']
                    );
                // Fetch vessels in area
                $response = file_get_contents($url);
                $pro = json_decode($response, true);
                $current_draught = '';
                $navigation_status = '';
                $dest_port = '';
                $dest_port_unlocode = '';
                $dep_port = '';
                $dep_port_unlocode = '';
                $atd_epoch = '';
                $atd_UTC = '';

                if( $pro ) {
                    $pro = $pro['data'];
                    $current_draught = $pro['current_draught']??"";
                    $navigation_status = $pro['navigation_status']??"";
                    $dest_port = $pro['dest_port']??"";
                    $dest_port_unlocode = $pro['dest_port_unlocode']??"";
                    $dep_port = $pro['dep_port']??"";
                    $dep_port_unlocode = $pro['dep_port_unlocode']??"";
                    $atd_epoch = $pro['atd_epoch']??"";
                    $atd_UTC = $pro['atd_UTC']??"";
                }


                $sql = "Replace INTO $table_name_vessel (uuid, name, mmsi, imo, eni, country_iso, type, type_specific, lat,lon,speed, course, heading, 
                destination, last_position_epoch, last_position_UTC, eta_epoch, eta_UTC, distance, navigation_status, current_draught, dest_port, dest_port_unlocode, 
                dep_port, dep_port_unlocode, atd_epoch, atd_UTC, last_updated)
                VALUES (
                        '" . $mysqli->real_escape_string($uuid) . "',
                        '" . $mysqli->real_escape_string($name) . "',
                        '" . $mysqli->real_escape_string($mmsi) . "',
                        '" . $mysqli->real_escape_string($imo) . "',
                        '" . $mysqli->real_escape_string($eni) . "',
                        '" . $mysqli->real_escape_string($country_iso) . "',
                        '" . $mysqli->real_escape_string($type) . "',
                        '" . $mysqli->real_escape_string($type_specific) . "',
                        '" . $mysqli->real_escape_string($lat) . "',
                        '" . $mysqli->real_escape_string($lon) . "',
                        '" . $mysqli->real_escape_string($speed) . "',
                        '" . $mysqli->real_escape_string($course) . "',
                        '" . $mysqli->real_escape_string($heading) . "',
                        '" . $mysqli->real_escape_string($destination) . "',
                        '" . $mysqli->real_escape_string($last_position_epoch) . "',
                        '" . $mysqli->real_escape_string($last_position_UTC) . "',
                        '" . $mysqli->real_escape_string($eta_epoch) . "',
                        '" . $mysqli->real_escape_string($eta_UTC) . "',
                        '" . $mysqli->real_escape_string($distance) . "',
                        '" . $mysqli->real_escape_string($navigation_status) . "',
                        '" . $mysqli->real_escape_string($current_draught) . "',
                        '" . $mysqli->real_escape_string($dest_port) . "',
                        '" . $mysqli->real_escape_string($dest_port_unlocode) . "',
                        '" . $mysqli->real_escape_string($dep_port) . "',
                        '" . $mysqli->real_escape_string($dep_port_unlocode) . "',
                        '" . $mysqli->real_escape_string($atd_epoch) . "',
                        '" . $mysqli->real_escape_string($atd_UTC) . "',
                        NOW())";
                if ($mysqli->query($sql) !== TRUE) {
                    echo "Error: " . $sql . "<br>" . $mysqli->error;
                }
            }
            
            $sql = "SELECT uuid as total FROM $table_name_vessel";
            $result_vessel = $mysqli->query($sql);
            $num_rows_vessel = mysqli_num_rows($result_vessel);
            coastalynk_update_summary('Vessels', $num_rows_vessel);
        }
    }
}