<?php
ini_set( "display_errors", "On" );
error_reporting(E_ALL);

require_once __DIR__ . '/common.php';
global $table_prefix;
coastalynk_summary_table();
// Connect to database directly
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

$api_key                        = get_option_data('coatalynk_datalastic_apikey');

$table_name = $table_prefix . 'coastalynk_ports';

// Create table if not exists
$sql = "CREATE TABLE IF NOT EXISTS $table_name (
    port_id VARCHAR(255) PRIMARY KEY,
    title VARCHAR(255),
    country_iso VARCHAR(255),
    lat VARCHAR(255),
    lon VARCHAR(255),
    capacity int(4)  Not Null Default 50,
    port_type VARCHAR(255),
   `radius` float NOT NULL DEFAULT 10,
   `port_area` polygon NOT NULL
)";
if ($mysqli->query($sql) !== TRUE) {
    echo "Error: " . $sql . "<br>" . $mysqli->error;
}


$countries = [ 'NG', 'BJ', 'TG', 'GH', 'CI', 'LR', 'SL', 'GN', 'GW', 'GM', 'SN', 'MR', 'CV' ];
foreach( $countries as $country ) {
    
    $data = get_ports( 'country_iso', $country  );
    
    // Insert data into table
    foreach ($data['data'] as $port) {
       $sql = "select port_id from ".$table_name." where port_id='".$port['uuid']."'";
       $result = $mysqli->query($sql);
        $num_rows = mysqli_num_rows($result);
        if( $num_rows == 0 ) {
            $sql = "INSERT INTO $table_name (port_id , title, country_iso, lat, lon, port_type)
            VALUES ('{$port['uuid']}', '{$port['port_name']}', '{$port['country_iso']}', '{$port['lat']}', '{$port['lon']}', '{$port['port_type']}')";
            if ($mysqli->query($sql) !== TRUE) {
                echo "Error: " . $sql . "<br>" . $mysqli->error;
            }
        }
    }
}


$sql =  "SELECT port_id FROM $table_name";
$result = $mysqli->query($sql);
$num_rows = mysqli_num_rows($result);
coastalynk_update_summary('Ports', $num_rows);