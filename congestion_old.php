<?php
set_time_limit(0);
ini_set( "display_errors", "On" );
error_reporting(E_ALL);

require_once __DIR__ . '/common.php';
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
wpdocs_show_vessels_congestion( ) ;
function wpdocs_show_vessels_congestion(  ) {

    global $mysqli;
    $ports = [
        'Apapa' => [6.45, 3.36],
        'TinCanIsland' => [6.44, 3.34],
        'Onne' => [4.71, 7.15],
        'Calabar' => [4.95, 8.32],
        'Lomé' => [6.1375, 1.2870],
        'Tema' => [5.6167, 0.0167],
    ];

    $table_prefix = 'staging_';
    // Connect to database directly
    
    $api_key  = get_option_data('coatalynk_datalastic_apikey');
    
    if ($mysqli->connect_error) {
        die("Connection failed: " . $mysqli->connect_error);
    } else {
        echo 'connected successfully!';
    }

    $sql_create_table = "CREATE TABLE IF NOT EXISTS ".$table_prefix."coastalynk_port_congestion (
        id INT(11)  PRIMARY KEY,
        updated_at datetime NULL,
        port VARCHAR(100) NULL,
        vessel_type VARCHAR(50) NULL,
        vessel_status VARCHAR(50) NULL,
        total INT(11) Default '0'
    )";

    if ($mysqli->query($sql_create_table) === TRUE) {
        echo "Table created successfully or already exists.<br>";
    } else {
        echo "Error creating table: " . $mysqli->error . "<br>";
    }
    
    if ($mysqli->query("Delete from ".$table_prefix."coastalynk_port_congestion where DATE(updated_at) < '".date('Y-m-d', strtotime( '-1 Month' ))."';") !== TRUE) {
        echo "Error: " . $sql . "<br>" . $mysqli->error;
    }

    $primary_key = 1;
    foreach( $ports as $name => $portdata ) {
        $lat = $portdata[0];
        $lon = $portdata[1];

        $url = sprintf(
            "https://api.datalastic.com/api/v0/vessel_inradius?api-key=%s&lat=%f&lon=%f&radius=%d",
            urlencode($api_key),
            $lat,
            $lon,
            10
        );

        // Make the API request
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
        
        // Decode the JSON response
        $data = json_decode($response, true);
        $port_congestion = [];
        // Check if we got data
        if (isset($data['data']['vessels'])) {
            $vessels = $data['data']['vessels'];
            
            // Add each vessel's position to our master list and update the overall bounding box
            foreach ($vessels as $vessel) {
                if (isset($vessel['lat']) && isset($vessel['lon'])) {

                    $speed = $vessel['speed'] ?? 99; // Get speed, default to high if missing
        
                    // Get the destination port name (crucial for confirmation)
                    $destination = strtoupper($vessel['destination'] ?? '');

                    $prodata = get_vessal_data( $vessel['uuid'] );
                    if( ! isset( $port_congestion[$vessel['type']][$vessel['type_specific']] ) ) {
                        $port_congestion[$vessel['type']] = [];
                    }

                    $prodata = $prodata['data'];
                    if( ! isset( $port_congestion[$vessel['type']][$prodata['navigation_status']] ) ) {
                        $port_congestion[$vessel['type']][$prodata['navigation_status']] = 0;
                    }

                    $port_congestion[$vessel['type']][$prodata['navigation_status']] += 1;
                }
            }
        }

        if( !empty( $port_congestion )  ) {
            
            foreach( $port_congestion as $key=>$item ) {
                foreach( $item as $subkey=>$subitem ) {
                    $mysqli->query("Insert into ".$table_prefix."coastalynk_port_congestion( id, `updated_at`, `port`, `vessel_type`, `vessel_status`, `total` ) Values( '".$primary_key."', now(), '".$name."', '".$key."', '".$subkey."', '".$subitem."' )");
                    $primary_key++;
                }
            }
        }
    
    }    
    
    $mysqli->close();
   
}

function get_vessal_data( $uuid ) {
    
    global $mysqli;
    
    $api_key                        = get_option_data('coatalynk_datalastic_apikey');
    $url = sprintf(
        "https://api.datalastic.com/api/v0/vessel_pro?api-key=%s&uuid=%s",
        urlencode($api_key),
        $uuid
    );

    // Make the API request
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

    // Decode the JSON response
    $data = json_decode($response, true);

    return $data;
}