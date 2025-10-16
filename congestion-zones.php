<?php
set_time_limit(0);
ini_set( "display_errors", "On" );
error_reporting(E_ALL);

require_once __DIR__ . '/common.php';
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
coastalynk_summary_table();
wpdocs_show_vessels_congestion( ) ;
function wpdocs_show_vessels_congestion(  ) {

    global $mysqli, $table_prefix;

    // Connect to database directly
    
    $api_key  = get_option_data('coatalynk_datalastic_apikey');
    
    if ($mysqli->connect_error) {
        die("Connection failed: " . $mysqli->connect_error);
    } else {
        echo 'connected successfully!';
    }

    $sql_create_table = "CREATE TABLE IF NOT EXISTS ".$table_prefix."coastalynk_port_congestion (
        id INT(11) NOT NULL AUTO_INCREMENT,
        port_id VARCHAR(50) NULL,
        port VARCHAR(100) NULL,
        percentage int(4)  Not Null Default 0,
        updated_at datetime Not Null,
        PRIMARY KEY (id)
    )";
    if ($mysqli->query($sql_create_table) === TRUE) {
        echo "Table created successfully or already exists.<br>";
    } else {
        echo "Error creating table: " . $mysqli->error . "<br>";
    }
    
    $sql_create_table = "CREATE TABLE IF NOT EXISTS ".$table_prefix."coastalynk_port_congestion_vessels (
        id INT(11) NOT NULL AUTO_INCREMENT,
        uuid VARCHAR(50) NULL,
        name VARCHAR(50) NULL,
        mmsi VARCHAR(100) NULL,
        eni VARCHAR(50) NULL,
        imo VARCHAR(50) NULL,
        type VARCHAR(50) NULL,
        type_specific VARCHAR(50) NULL,
        country_iso VARCHAR(50) NULL,
        congestion_id INT(11) NOT NULL,
        navigation_status VARCHAR(50) NULL,
        lat INT(11) Default '0',
        lon INT(11) Default '0',
        speed INT(3) Default '0',
        course INT(3) Default '0',
        heading INT(3) Default '0',
        current_draught Float Default '0',
        dest_port_uuid VARCHAR(50) NULL,
        dest_port VARCHAR(50) NULL,
        dest_port_unlocode VARCHAR(50) NULL,
        dep_port VARCHAR(50) NULL,
        dep_port_uuid VARCHAR(50) NULL,
        dep_port_unlocode VARCHAR(50) NULL,
        last_position_epoch VARCHAR(50) NULL,
        last_position_UTC VARCHAR(50) NULL,
        atd_epoch VARCHAR(50) NULL,
        atd_UTC VARCHAR(50) NULL,
        eta_epoch VARCHAR(50) NULL,
        eta_UTC VARCHAR(50) NULL,
        destination VARCHAR(50) NULL,
        PRIMARY KEY (id)
    )";

    if ($mysqli->query($sql_create_table) === TRUE) {

        $index_query = "CREATE INDEX IF NOT EXISTS coastalynk_port_congestion_vessels_index ON ".$table_prefix."coastalynk_port_congestion_vessels (congestion_id)";
        $mysqli->query($index_query);

        echo "Table created successfully or already exists.<br>";
    } else {
        echo "Error creating table: " . $mysqli->error . "<br>";
    }

    $table_name = $table_prefix . 'coastalynk_ports';
    $sql = "select port_id, title, lat, lon,port_type, capacity from ".$table_name." where country_iso='NG' and port_type='Port'";
    $result = $mysqli->query($sql);
    $num_rows = mysqli_num_rows($result);
    if( $num_rows > 0 ) {

        $nigerian_zones = [];
        $nigerian_zones[] = ['Lagos Coastal Waters', 6.4, 3.4];
        $nigerian_zones[] = ['Badagry Coastal Area', 6.3, 2.7];
        $nigerian_zones[] = ['Escravos River Mouth', 5.5, 5.0];
        $nigerian_zones[] = ['Forcados Terminal', 5.2, 5.4];
        $nigerian_zones[] = ['Brass Oil Terminal', 4.3, 6.2];
        $nigerian_zones[] = ['Brass Oil Terminal', 4.2, 5.9];
        $nigerian_zones[] = ['Bonny LNG Terminal', 4.4, 7.1];
        $nigerian_zones[] = ['Calabar Port Approach', 4.9, 8.3];

        // ========== TERRITORIAL WATERS POINTS (12-24 NM) ==========
        $nigerian_zones[] = ['Lagos Offshore Zone', 6.0, 3.0];
        $nigerian_zones[] = ['Western Approaches', 5.7, 2.5];
        $nigerian_zones[] = ['Central Offshore', 4.8, 4.8];
        $nigerian_zones[] = ['Niger Delta Offshore', 4.5, 5.8];
        $nigerian_zones[] = ['Eastern Approaches', 4.2, 7.5];
        $nigerian_zones[] = ['Calabar Offshore', 4.5, 8.0];
        $nigerian_zones[] = ['territorial water 1', 5.0, 3.4];
        $nigerian_zones[] = ['territorial water 2', 5.9, 4.2];

        // ========== EEZ POINTS (24-200 NM) ==========
        $nigerian_zones[] = ['Western EEZ Deep', 5.0, 2.0];
        $nigerian_zones[] = ['Bonga FPSO Area', 4.2, 3.8];
        $nigerian_zones[] = ['Western EEZ Central', 4.0, 3.0];
        $nigerian_zones[] = ['Western EEZ Central', 3.7, 5.5];
        $nigerian_zones[] = ['Central EEZ North', 3.8, 4.6];
        $nigerian_zones[] = ['Agbami FPSO Area', 3.5, 5.4];
        $nigerian_zones[] = ['Akpo FPSO Area', 3.3, 6.2];
        $nigerian_zones[] = ['Eastern EEZ Central', 3.2, 7.0];
        $nigerian_zones[] = ['Eastern EEZ Deep', 3.0, 8.0];
        $nigerian_zones[] = ['Calabar EEZ Offshore', 3.5, 8.5];
        $nigerian_zones[] = ['Deepwater West', 3.0, 3.5];
        $nigerian_zones[] = ['Deepwater Central', 2.5, 5.5];
        $nigerian_zones[] = ['Deepwater East', 2.2, 7.5,];
        

        $now = date('Y-m-d H:i:s');
        //while ($row = mysqli_fetch_assoc($result)) {
        echo '<pre>';
        foreach($nigerian_zones as $row ) {
        //echo "<br>ID: " . $row['port_id'] . ", Name: " . $row['title'] . "<br>";

            // $name = $row['title'];
            // $port_id = $row['port_id'];
            $capacity = 5;// $row['capacity'];
            if( intval( $capacity ) <= 0 ) {
                $capacity = 5;
            }
            $lat = $row[1];//$row['lat'];
            $lon =  $row[2];// $row['lon'];

            echo $url = sprintf(
                "https://api.datalastic.com/api/v0/vessel_inradius?api-key=%s&lat=%f&lon=%f&radius=%d",
                urlencode($api_key),
                $lat,
                $lon,
                50
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
            print_r($data['data']['vessels']);
        }
        
            exit;
        { 
            // Check if we got data
            if (isset($data['data']['vessels'])) {
                $vessels = $data['data']['vessels'];
                
                // Add each vessel's position to our master list and update the overall bounding box
                if( count( $vessels ) > 0 ) {
                    $percentage = ( count($vessels) / $capacity ) * 100;
                    $mysqli->query("Insert into ".$table_prefix."coastalynk_port_congestion( `updated_at`, port_id, `port`, percentage ) Values( '".$now."', '".$port_id."', '".$name."', '$percentage' )");
                    
                    coastalynk_update_summary($name. ' Congestion', $percentage.'%');
                    $last_port_id = $mysqli->insert_id;
                    foreach ($vessels as $vessel) {

                        if (isset($vessel['lat']) && isset($vessel['lon'])) {
                            $prodata = get_vessal_data( $vessel['uuid'] );
                            $prodata = $prodata['data'];
                            $mysqli->query("Insert into ".$table_prefix."coastalynk_port_congestion_vessels( `congestion_id`, uuid, name, mmsi, eni, imo, type_specific, country_iso, type, `navigation_status`, `lat`, `lon`, `speed`, `course`, `destination`,dest_port_uuid, dest_port, dest_port_unlocode, dep_port, dep_port_uuid, dep_port_unlocode, last_position_epoch, last_position_UTC, atd_epoch, atd_UTC, eta_epoch, eta_UTC, heading, current_draught ) 
                            Values( '".$last_port_id."', '".$vessel['uuid']."', '".$vessel['name']."', '".$vessel['mmsi']."', '".$vessel['eni']."', '".$vessel['imo']."', '".$vessel['type_specific']."', '".$vessel['country_iso']."', '".$vessel['type']."', '".$prodata['navigation_status']."', '".$vessel['lat']."' , '".$vessel['lon']."' , '".intval($vessel['speed'])."' , '".intval($vessel['course'])."', '".$vessel['destination']."', '".$prodata['dest_port_uuid']."', '".$prodata['dest_port']."', '".$prodata['dest_port_unlocode']."', '".$prodata['dep_port']."', '".$prodata['dep_port_uuid']."', '".$prodata['dep_port_unlocode']."', '".$prodata['last_position_epoch']."', '".$prodata['last_position_UTC']."', '".$prodata['atd_epoch']."', '".$prodata['atd_UTC']."', '".$prodata['eta_epoch']."', '".$prodata['eta_UTC']."', '".intval($prodata['heading'])."', '".floatval($prodata['current_draught'])."' )");
                        }
                    }
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