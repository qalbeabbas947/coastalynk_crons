<?php
ini_set( "display_errors", "On" );
error_reporting(E_ALL);
require_once __DIR__ . '/common.php';

$countries = ['NG'];
foreach( $countries as $country ) {
    $url = sprintf(
        "https://api.datalastic.com/api/v0/port_find?api-key=%s&country_iso=%s",
        urlencode('15df4420-d28b-4b26-9f01-13cca621d55e'),
        urlencode($country)
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

    curl_close($ch);

    $data = json_decode( $response, true );

    $table_prefix = 'staging_';
    // Connect to database directly
    $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

    if ($mysqli->connect_error) {
        die("Connection failed: " . $mysqli->connect_error);
    } else {
        echo 'connected successfully!';
    }
    $table_name = $table_prefix . 'ports';
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