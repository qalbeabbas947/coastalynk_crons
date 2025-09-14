<?php

define( 'DB_NAME', "mydb" );

/** Database username */
define( 'DB_USER', "root" );

/** Database password */
define( 'DB_PASSWORD', "root" );
define( 'DB_HOST', 'db:3306' );

define( 'smtp_user_name', 'noreplywebsitesmtp@gmail.com' );
define( 'smtp_password', 'tbbwozxclpncuukn' );

$table_prefix = 'staging_';

/**
 * Returns the value of wordpress options
 */
function get_option_data( $option_name ) {

    global $mysqli, $table_prefix;

    $table_name = $table_prefix . 'options';
    $sql = "select option_value from ".$table_name." where option_name='".$option_name."'";
    $result = $mysqli->query($sql);
    
    if ($result && $result->num_rows > 0) {
        $single_value = $result->fetch_column(); // Directly fetches the value of the first column
    } else {
       $single_value = '';
    }
    
    return $single_value;
}

/**
 * Returns the ports data
 */
function get_ports( $param, $value  ) {
    $api_key                        = get_option_data('coatalynk_datalastic_apikey');
    $url = "https://api.datalastic.com/api/v0/port_find?api-key=".urlencode($api_key)."&".$param."=".urlencode($value);

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