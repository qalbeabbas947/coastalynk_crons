<?php

define( 'DB_NAME', "mydb" );

/** Database username */
define( 'DB_USER', "root" );

/** Database password */
define( 'DB_PASSWORD', "root" );
define( 'DB_HOST', 'db:3306' );

define( 'smtp_user_name', 'noreplywebsitesmtp@gmail.com' );
define( 'smtp_password', 'tbbwozxclpncuukn' );

$table_prefix = 'wp_';

$vessel_product_type = [];

$vessel_product_type['Coastal'] = 'LPG'; 
$vessel_product_type['Chemical'] = 'PMS'; 
$vessel_product_type['LPG'] = 'LPG';
$vessel_product_type['LNG'] = 'LNG';
$vessel_product_type['MR'] = 'Crude Light';
$vessel_product_type['LR1'] = 'Crude Light';
$vessel_product_type['LR2'] = 'Crude Light';
$vessel_product_type['Aframax'] = 'Crude Light';
$vessel_product_type['Suezmax'] = 'Crude Light';
$vessel_product_type['VLCC'] = 'Crude Light';

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
 * Returns the value of wordpress options
 */
function total_sts_daughter_vessels( $event_id, $step_id = 0  ) {

    global $mysqli, $table_prefix;

    $event_table_daughter = $table_prefix . 'coastalynk_sts_event_detail';

    $step_where = '';
    if( $step_id > 0 ) {
        $step_where = ' and step = '.$step_id;
    }

    $sql = "select id from ".$event_table_daughter." where event_id='".$event_id."' and is_complete = 'Yes'".$step_where;
    $result5 = $mysqli->query( $sql );
    
    return  mysqli_num_rows( $result5 );
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
 
/**
 * create summary table
 */
function coastalynk_summary_table() {
    global $table_prefix;
    $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    $sql_create_table = "CREATE TABLE IF NOT EXISTS ".$table_prefix."coastalynk_summary (
        label_key VARCHAR(50) NULL,
        total_figure VARCHAR(100) NULL,
        updated_at datetime Not Null,
        PRIMARY KEY (label_key)
    )";

    if ($mysqli->query($sql_create_table) === TRUE) {
        echo "Table created successfully or already exists.<br>";
    } else {
        echo "Error creating table: " . $mysqli->error . "<br>";
    }
}

/**
 * create summary table
 */
function coastalynk_update_summary($label, $total_figure) {
    global $table_prefix;
    
    $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    $table_coastalynk_summary = $table_prefix . 'coastalynk_summary';
    $sql = "select label_key from ".$table_coastalynk_summary." where label_key ='".$mysqli->real_escape_string($label)."'";
    $result = $mysqli->query($sql);
    $num_rows = mysqli_num_rows($result);
    if( $num_rows == 0 ) {
        
         $sql = "INSERT INTO $table_coastalynk_summary ( label_key , total_figure, updated_at)
                VALUES (
                    '" . (!empty($label)?$mysqli->real_escape_string($label):'') . "',
                    '" . (!empty($total_figure)?$mysqli->real_escape_string($total_figure):'') . "',
                    NOW())";
        if ($mysqli->query($sql) !== TRUE) {
            echo "Error: " . $sql . "<br>" . $mysqli->error;
        }
    } else {
        $sql = "Update $table_coastalynk_summary set label_key='".(!empty($label)?$mysqli->real_escape_string($label):'')."' , total_figure='".(!empty($total_figure)?$mysqli->real_escape_string($total_figure):'')."', updated_at= now() where label_key= '".$label."'";
        if ($mysqli->query($sql) !== TRUE) {
            echo "Error: " . $sql . "<br>" . $mysqli->error;
        }
    }
}

/**
 * create log entry
 */
function coastalynk_log_entry($id, $description, $type='sts') {
    global $table_prefix;
    
    $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    $table_sts_log = $table_prefix . 'coastalynk_sts_log';
    $sql = "INSERT INTO $table_sts_log ( sts_id , description, vtype, date_added)
                VALUES (
                    '" . (!empty($id)?$mysqli->real_escape_string($id):'0') . "',
                    '" . (!empty($description)?$mysqli->real_escape_string($description):'') . "',
                    '" . (!empty($type)?$mysqli->real_escape_string($type):'') . "',
                    NOW())";
    if ($mysqli->query($sql) !== TRUE) {
        echo "Error: " . $sql . "<br>" . $mysqli->error;
    }
}

/**
 * Get signal status based on the dates
 * 
 * @param $startDate 
 * @param $endDate
 *  
 * @return signal
 */
function coastalynk_signal_status( $startDate, $endDate ) {
    
    $startDate = new DateTime($startDate);
    $endDate = new DateTime($endDate);

    $interval = $startDate->diff($endDate);
    $signal = '';
    if( floatval( $interval->h ) <= 2 ) {
        $signal = 'AIS Consistent';
    } else {
        $signal = 'AIS Gap';
    }

    return $signal;
}