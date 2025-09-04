<?php
ini_set( "display_errors", "On" );
error_reporting(E_ALL);
exit;
require_once __DIR__ . '/common.php';
global $table_prefix, $api_key;

function get_ports( $param, $value  ) {
    global $api_key;
    $url = sprintf(
        "https://api.datalastic.com/api/v0/port_find?api-key=%s&%s=%s",
        urlencode($api_key),
        $param
        urlencode($value)
    );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Enable in production

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        echo "cURL Error for: " . curl_error($ch) . "\n";
        curl_close($ch);
    }
    $data = json_decode( $response, true );
    curl_close($ch);

    return $data;
}

// Connect to database directly
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

$table_name = $table_prefix . 'coastalynk_ports';

// Create table if not exists
$sql = "CREATE TABLE IF NOT EXISTS $table_name (
    port_id VARCHAR(255) PRIMARY KEY,
    title VARCHAR(255),
    country_iso VARCHAR(255),
    lat VARCHAR(255),
    lon VARCHAR(255),
    port_type VARCHAR(255)
)";
if ($mysqli->query($sql) !== TRUE) {
    echo "Error: " . $sql . "<br>" . $mysqli->error;
}

$countries = ['NG'];
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

$names = ['Apapa','Tin Can Island','Lomé','Tema' ];
foreach( $names as $name ) {
    
    $data = get_ports( 'name', $name  );
    
    echo '<pre>';print_r($data); echo '</pre>';
    
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
    echo '<pre>';
    print_r( $data );
}