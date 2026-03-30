<?php
/**
 * MarineTraffic Vessel Position Monitor
 * Monitors vessel positions in specified bounding box and analyzes tracking gaps
 * Stores data in MySQL database using MySQLi
 * 
 * @author Your Name
 * @version 2.1
 */

class MarineTrafficMonitor {
    private $apiKey;
    private $apiUrl;
    private $db;
    private $boundingBox;
    private $pollingInterval;
    private $maxRuntime;
    private $debugMode;
    private $runId;
    
    /**
     * Constructor
     * 
     * @param string $apiKey Your MarineTraffic API key
     * @param array $dbConfig Database configuration
     * @param array $boundingBox Bounding box coordinates [minLon, maxLon, minLat, maxLat]
     * @param int $pollingInterval Polling interval in seconds
     * @param int $maxRuntime Maximum runtime in seconds
     */
    public function __construct($apiKey, $dbConfig, $boundingBox, $pollingInterval = 300, $maxRuntime = 86400) {
        $this->apiKey = $apiKey;
        $this->apiUrl = "https://api.kpler.com/v2/maritime/ais-latest"; // Update with actual endpoint
        $this->boundingBox = $boundingBox;
        $this->pollingInterval = $pollingInterval;
        $this->maxRuntime = $maxRuntime;
        $this->debugMode = true;
        
        // Generate unique run ID
        $this->runId = date('Ymd_His') . '_' . uniqid();
        echo 'd1';
        // Connect to database using MySQLi
        $this->connectDatabase($dbConfig);
        echo 'd2';
        // Initialize database tables
        $this->initializeDatabase();echo 'd3';
    }
    
    /**
     * Connect to MySQL database using MySQLi
     */
    private function connectDatabase($dbConfig) {
        try {
            $this->db = new mysqli(
                $dbConfig['host'],
                $dbConfig['username'],
                $dbConfig['password'],
                $dbConfig['database'],
                $dbConfig['port']
            );
            
            if ($this->db->connect_error) {
                throw new Exception("Connection failed: " . $this->db->connect_error);
            }
            
            // Set charset to utf8mb4
            $this->db->set_charset("utf8mb4");
            
            $this->debug("Database connection established using MySQLi");
            
        } catch (Exception $e) {
            die("Database connection failed: " . $e->getMessage() . "\n");
        }
    }
    
    /**
     * Initialize database tables using MySQLi
     */
    private function initializeDatabase() {
        
        try {
            // Create monitoring_runs table
            $sql = "CREATE TABLE IF NOT EXISTS monitoring_runs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                run_id VARCHAR(50) NOT NULL UNIQUE,
                start_time DATETIME NOT NULL,
                end_time DATETIME,
                bounding_box_min_lon DECIMAL(10,6),
                bounding_box_max_lon DECIMAL(10,6),
                bounding_box_min_lat DECIMAL(10,6),
                bounding_box_max_lat DECIMAL(10,6),
                polling_interval INT,
                total_iterations INT DEFAULT 0,
                status ENUM('running', 'completed', 'failed') DEFAULT 'running',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_run_id (run_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            if (!$this->db->query($sql)) {
                throw new Exception("Error creating monitoring_runs table: " . $this->db->error);
            }
           
            // Create vessel_positions table
            $sql = "CREATE TABLE IF NOT EXISTS vessel_positions (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                run_id VARCHAR(50) NOT NULL,
                call_timestamp DATETIME NOT NULL,
                mmsi VARCHAR(20) NOT NULL,
                vessel_name VARCHAR(255),
                latitude DECIMAL(10,6),
                longitude DECIMAL(10,6),
                speed DECIMAL(5,2),
                status VARCHAR(100),
                dsrc VARCHAR(10),
                position_timestamp DATETIME,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_run_id (run_id),
                INDEX idx_mmsi (mmsi),
                INDEX idx_call_timestamp (call_timestamp),
                INDEX idx_position_timestamp (position_timestamp),
                INDEX idx_dsrc (dsrc),
                INDEX idx_speed (speed)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            if (!$this->db->query($sql)) {
                throw new Exception("Error creating vessel_positions table: " . $this->db->error);
            }
            
            // Add foreign key constraint
            $sql = "ALTER TABLE vessel_positions 
                    ADD CONSTRAINT fk_vessel_positions_run_id 
                    FOREIGN KEY (run_id) REFERENCES monitoring_runs(run_id) ON DELETE CASCADE";
            
            // Ignore error if constraint already exists
           //  $this->db->query($sql);
            
            // Create analysis_results table
            $sql = "CREATE TABLE IF NOT EXISTS analysis_results (
                id INT AUTO_INCREMENT PRIMARY KEY,
                run_id VARCHAR(50) NOT NULL UNIQUE,
                total_vessels INT,
                total_positions INT,
                stationary_gaps_calculated INT,
                median_gap_minutes DECIMAL(10,2),
                median_gap_status VARCHAR(20),
                dsrc_ter_count INT,
                dsrc_sat_count INT,
                dsrc_majority VARCHAR(10),
                dsrc_status VARCHAR(20),
                overall_status VARCHAR(20),
                gap_percentile_25 DECIMAL(10,2),
                gap_percentile_50 DECIMAL(10,2),
                gap_percentile_75 DECIMAL(10,2),
                gap_percentile_95 DECIMAL(10,2),
                analyzed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            if (!$this->db->query($sql)) {
                throw new Exception("Error creating analysis_results table: " . $this->db->error);
            }
            
            // Add foreign key constraint for analysis_results
            $sql = "ALTER TABLE analysis_results 
                    ADD CONSTRAINT fk_analysis_results_run_id 
                    FOREIGN KEY (run_id) REFERENCES monitoring_runs(run_id) ON DELETE CASCADE";
            
            // Ignore error if constraint already exists
            //$this->db->query($sql);
            
            // Insert monitoring run record using prepared statement
            $sql = "INSERT INTO monitoring_runs 
                    (run_id, start_time, bounding_box_min_lon, bounding_box_max_lon, 
                     bounding_box_min_lat, bounding_box_max_lat, polling_interval) 
                    VALUES ('".$this->runId."', NOW(), '".$this->boundingBox['minLon']."', '".$this->boundingBox['maxLon']."', '".$this->boundingBox['minLat']."', '". $this->boundingBox['maxLat']."', '".$this->pollingInterval."')";
            
            $stmt = $this->db->prepare($sql);
            
            if (!$stmt->execute()) {
               throw new Exception("12Execute failed: " . $stmt->error);
            }
            
            $stmt->close();
            
            $this->debug("Database tables initialized successfully");
            
        } catch (Exception $e) {
            $this->debug("Database initialization error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Enable or disable debug mode
     */
    public function setDebugMode($debug) {
        $this->debugMode = $debug;
    }
    
    /**
     * Log message to console if debug mode is enabled
     */
    private function debug($message) {
        if ($this->debugMode) {
            $timestamp = date('Y-m-d H:i:s');
            echo "[{$timestamp}] {$message}\n";
        }
    }
    
    /**
     * Make API request to MarineTraffic
     */
    private function makeApiRequest() {

        // $apiUrl = "https://api.kpler.com/v2/maritime/ais-latest";
        // $apiKey = "dnh6YU1yelh0bXdxZ09EYldqem9ZSnhLN2ExdmpIc1k6RFo2YUoyeEU3YTlVZW5mbUw3VS1VMGI5c2czUTVDMUg5M1o0ZGVSVDhmenFvOERVeFgxZTdIWGxUMHVBTHpjYQ==";

        $params = [
            "limit" => 500,
            // Example: only return specific fields in the response. This query parameter can be removed to return all the available fields.
            "filter" => "bbox(position, ".$this->boundingBox['minLon'].", ".$this->boundingBox['minLat'].", ".$this->boundingBox['maxLon'].", ".$this->boundingBox['maxLat'].")"
        ];


        // Initialize cURL
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => json_encode($params),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Basic '.$this->apiKey,
            ],
        ]);

        // Execute request
        $response = curl_exec($ch);

        // Check for cURL errors
        if (curl_errno($ch)) {
            throw new Exception('cURL Error: ' . curl_error($ch));
        }

        // Get HTTP status code
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // Close cURL
        curl_close($ch);

        // Check if request was successful
        if ($httpCode >= 400) {
            throw new Exception("HTTP Error: " . $httpCode . " - " . $response);
        }

        // Decode JSON response
        $data = json_decode($response, true);

        // Check for JSON decoding errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("JSON Decode Error: " . json_last_error_msg());
        }

        // Output the features
        $features = $data["features"] ?? [];

        return $features;
        // $params = [
        //     'apiKey' => $this->apiKey,
        //     'min_lon' => $this->boundingBox['minLon'],
        //     'max_lon' => $this->boundingBox['maxLon'],
        //     'min_lat' => $this->boundingBox['minLat'],
        //     'max_lat' => $this->boundingBox['maxLat'],
        //     'format' => 'json',
        // ];
        
        // $url = $this->apiUrl . '?' . http_build_query($params);
        // $this->debug("Making API request");
        
        // $ch = curl_init();
        // curl_setopt_array($ch, [
        //     CURLOPT_URL => $url,
        //     CURLOPT_RETURNTRANSFER => true,
        //     CURLOPT_TIMEOUT => 30,
        //     CURLOPT_USERAGENT => 'MarineTraffic-Monitor/2.0',
        //     CURLOPT_SSL_VERIFYPEER => true
        // ]);
        
        // $response = curl_exec($ch);
        // $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        // if (curl_error($ch)) {
        //     $this->debug("CURL Error: " . curl_error($ch));
        //     curl_close($ch);
        //     return false;
        // }
        
        // curl_close($ch);
        
        // if ($httpCode !== 200) {
        //     $this->debug("HTTP Error: {$httpCode}");
        //     return false;
        // }
        
        // $data = json_decode($response, true);
        
        // if (json_last_error() !== JSON_ERROR_NONE) {
        //     $this->debug("JSON Parse Error: " . json_last_error_msg());
        //     return false;
        // }
        
        // return $data;
    }
    
    /**
     * Parse vessel data from API response
     */
    private function parseVesselData($apiResponse) {
        $vessels = [];
        if (is_array($apiResponse) && isset($apiResponse[0])) {
            foreach ($apiResponse as $vessel) {
                $vessels[] = [
                    'mmsi' => $vessel['properties']['mmsi'] ?? $vessel['properties']['mmsi'] ?? null,
                    'name' => $vessel['properties']['vesselName'] ?? $vessel['properties']['vesselName'] ?? 'Unknown',
                    'lat' => $vessel['properties']['latitude'] ?? $vessel['properties']['latitude'] ?? null,
                    'lon' => $vessel['properties']['longitude'] ?? $vessel['properties']['longitude'] ?? null,
                    'speed' => $vessel['properties']['sog'] ?? $vessel['properties']['sog'] ?? null,
                    'status' => $vessel['properties']['navStatus'] ?? $vessel['properties']['navStatus'] ?? null,
                    'dsrc' => $vessel['properties']['staticSrc'] ?? $vessel['properties']['staticSrc'] ?? 'TER',
                    'timestamp' => $vessel['properties']['posDt'] ?? $vessel['properties']['posDt'] ?? date('Y-m-d H:i:s')
                ];
            }
        }
        elseif (isset($apiResponse['data']) || isset($apiResponse['rows'])) {
            $rows = $apiResponse['data'] ?? $apiResponse['rows'] ?? [];
            foreach ($rows as $vessel) {
                $vessels[] = [
                    'mmsi' => $vessel['properties']['mmsi'] ?? $vessel['properties']['mmsi'] ?? null,
                    'name' => $vessel['properties']['vesselName'] ?? $vessel['properties']['vesselName'] ?? 'Unknown',
                    'lat' => $vessel['properties']['latitude'] ?? $vessel['properties']['latitude'] ?? null,
                    'lon' => $vessel['properties']['longitude'] ?? $vessel['properties']['longitude'] ?? null,
                    'speed' => $vessel['properties']['sog'] ?? $vessel['properties']['sog'] ?? null,
                    'status' => $vessel['properties']['navStatus'] ?? $vessel['properties']['navStatus'] ?? null,
                    'dsrc' => $vessel['properties']['staticSrc'] ?? $vessel['properties']['staticSrc'] ?? 'TER',
                    'timestamp' => $vessel['properties']['posDt'] ?? $vessel['properties']['posDt'] ?? date('Y-m-d H:i:s')
                ];
            }
        }
        
        return $vessels;
    }
    
    /**
     * Store vessel data in database using MySQLi
     */
    private function storeVesselData($vessels, $callTimestamp) {
        try {
            
            
            $count = 0;
            
            foreach ($vessels as $vessel) {
                // Skip vessels with missing critical data
                if (empty($vessel['mmsi']) || empty($vessel['lat']) || empty($vessel['lon'])) {
                    continue;
                }
                
                $mmsi = $vessel['mmsi'];
                $vesselName = $vessel['name'];
                $latitude = $vessel['lat'];
                $longitude = $vessel['lon'];
                $speed = $vessel['speed'] !== null ? floatval($vessel['speed']) : null;
                $status = $vessel['status'];
                $dsrc = $vessel['dsrc'];
                $positionTimestamp = $vessel['timestamp'];
                
                $sql = "INSERT INTO vessel_positions 
                    (run_id, call_timestamp, mmsi, vessel_name, latitude, longitude, speed, status, dsrc, position_timestamp) 
                    VALUES ('".$this->runId."', '".$callTimestamp."', '".$mmsi."', '".$vesselName."', '".$latitude."', '".$longitude."', '".$speed."', '".$status."', '".$dsrc."', '".$positionTimestamp."')";
            
                $stmt = $this->db->prepare($sql);
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $this->db->error);
                }
               
                if ($stmt->execute()) {
                    $count++;
                } else {
                    $this->debug("Insert error for MMSI {$mmsi}: " . $stmt->error);
                }
            }
            
            $stmt->close();
            
            $this->debug("Stored {$count} vessel positions in database");
            
            // Update iteration count in monitoring_runs
            $sql = "UPDATE monitoring_runs SET total_iterations = total_iterations + 1 WHERE run_id = '".$this->runId."'";
            $stmt = $this->db->prepare($sql);
            if ($stmt) {
                $stmt->execute();
                $stmt->close();
            }
            
        } catch (Exception $e) {
            $this->debug("Database insert error: " . $e->getMessage());
        }
    }
    
    /**
     * Analyze the collected data from database using MySQLi
     */
    public function analyzeData() {
        $this->debug("\n=== Starting Data Analysis ===\n");
        
        try {
            // Get total vessels and positions
            $sql = "SELECT 
                        COUNT(DISTINCT mmsi) as total_vessels,
                        COUNT(*) as total_positions
                    FROM vessel_positions 
                    WHERE run_id = '".$this->runId."'";
            
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->db->error);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            $stats = $result->fetch_assoc();
            $stmt->close();
            
            // Get DSRC counts
            $sql = "SELECT 
                        SUM(CASE WHEN dsrc = 'TER' THEN 1 ELSE 0 END) as ter_count,
                        SUM(CASE WHEN dsrc = 'SAT' THEN 1 ELSE 0 END) as sat_count
                    FROM vessel_positions 
                    WHERE run_id = '".$this->runId."'";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $result = $stmt->get_result();
            $dsrcCounts = $result->fetch_assoc();
            $stmt->close();
            
            // Calculate gaps for stationary vessels
            $sql = "SELECT 
                        mmsi,
                        AVG(speed) as avg_speed,
                        GROUP_CONCAT(position_timestamp ORDER BY position_timestamp SEPARATOR '|') as timestamps
                    FROM vessel_positions 
                    WHERE run_id = '".$this->runId."' 
                    GROUP BY mmsi";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $stationaryGaps = [];
            
            while ($vessel = $result->fetch_assoc()) {
                // Check if vessel is stationary (avg speed < 1)
                if (floatval($vessel['avg_speed']) < 1) {
                    $timestamps = explode('|', $vessel['timestamps']);
                    
                    for ($i = 1; $i < count($timestamps); $i++) {
                        $gap = (strtotime($timestamps[$i]) - strtotime($timestamps[$i-1])) / 60;
                        if ($gap > 0) {
                            $stationaryGaps[] = $gap;
                        }
                    }
                }
            }
            $stmt->close();
            
            // Calculate statistics
            $totalVessels = $stats['total_vessels'] ?? 0;
            $totalPositions = $stats['total_positions'] ?? 0;
            $stationaryGapsCount = count($stationaryGaps);
            
            // Calculate median gap
            $medianGap = null;
            $percentiles = null;
            
            if (!empty($stationaryGaps)) {
                sort($stationaryGaps);
                $mid = floor(count($stationaryGaps) / 2);
                $medianGap = count($stationaryGaps) % 2 == 0 ? 
                    ($stationaryGaps[$mid - 1] + $stationaryGaps[$mid]) / 2 : 
                    $stationaryGaps[$mid];
                
                $percentiles = [
                    '25th' => $stationaryGaps[floor(count($stationaryGaps) * 0.25)],
                    '50th' => $stationaryGaps[floor(count($stationaryGaps) * 0.5)],
                    '75th' => $stationaryGaps[floor(count($stationaryGaps) * 0.75)],
                    '95th' => $stationaryGaps[floor(count($stationaryGaps) * 0.95)]
                ];
            }
            
            // Determine pass/fail
            $dsrcMajority = ($dsrcCounts['ter_count'] > $dsrcCounts['sat_count']) ? 'TER' : 'SAT';
            $medianStatus = $medianGap !== null ? 
                ($medianGap <= 5 ? 'PASS' : ($medianGap > 15 ? 'FAIL' : 'BORDERLINE')) : 
                'INCONCLUSIVE';
            
            $dsrcStatus = $dsrcMajority == 'TER' ? 'PASS' : 'FAIL';
            $overallStatus = ($medianStatus == 'PASS' && $dsrcStatus == 'PASS') ? 'PASS' : 'FAIL';
            
            // Store analysis results
            $sql = "INSERT INTO analysis_results 
                    (run_id, total_vessels, total_positions, stationary_gaps_calculated, 
                     median_gap_minutes, median_gap_status, dsrc_ter_count, dsrc_sat_count,
                     dsrc_majority, dsrc_status, overall_status,
                     gap_percentile_25, gap_percentile_50, gap_percentile_75, gap_percentile_95)
                    VALUES ('".$this->runId."', '".$totalVessels."', '".$totalPositions."', '".$stationaryGapsCount."', '".$medianGap."', '".$medianStatus."', '".($dsrcCounts['ter_count'] ?? 0)."', '".($dsrcCounts['sat_count'] ?? 0)."', '".$dsrcMajority."', '".$dsrcStatus."', '".$overallStatus."', '".($percentiles['25th'] ?? null)."', '".($percentiles['50th'] ?? null)."', '".($percentiles['75th'] ?? null)."', '".($percentiles['95th'] ?? null)."')";
            
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                throw new Exception("Prepare failed for analysis insert: " . $this->db->error);
            }
            
            
            $stmt->execute();
            $stmt->close();
            
            // Update monitoring run status
            $sql = "UPDATE monitoring_runs SET end_time = NOW(), status = 'completed' WHERE run_id = '".$this->runId."'";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $stmt->close();
            
            // Prepare analysis array for return
            $analysis = [
                'timestamp' => date('Y-m-d H:i:s'),
                'total_vessels' => $totalVessels,
                'total_positions' => $totalPositions,
                'stationary_gaps_calculated' => $stationaryGapsCount,
                'median_gap_minutes' => $medianGap,
                'median_gap_status' => $medianStatus,
                'dsrc_counts' => [
                    'TER' => $dsrcCounts['ter_count'] ?? 0,
                    'SAT' => $dsrcCounts['sat_count'] ?? 0
                ],
                'dsrc_majority' => $dsrcMajority,
                'dsrc_status' => $dsrcStatus,
                'overall_status' => $overallStatus,
                'gap_percentiles' => $percentiles
            ];
            
            $this->debug("Analysis completed and stored in database");
            
            return $analysis;
            
        } catch (Exception $e) {
            $this->debug("Analysis error: " . $e->getMessage());
            
            // Update monitoring run status to failed
            $sql = "UPDATE monitoring_runs SET end_time = NOW(), status = 'failed' WHERE run_id = '".$this->runId."'";
            $stmt = $this->db->prepare($sql);
            if ($stmt) {
                $stmt->execute();
                $stmt->close();
            }
            
            return false;
        }
    }
    
    /**
     * Print analysis results to console
     */
    private function printAnalysis($analysis) {
        echo "\n";
        echo "╔════════════════════════════════════════════════════════════╗\n";
        echo "║               MARINETRAFFIC MONITORING REPORT              ║\n";
        echo "╚════════════════════════════════════════════════════════════╝\n\n";
        
        echo "📋 Run ID: {$this->runId}\n\n";
        
        echo "📊 STATISTICS\n";
        echo "   Total Vessels: " . $analysis['total_vessels'] . "\n";
        echo "   Total Records: " . $analysis['total_positions'] . "\n\n";
        
        echo "⏱️  GAP ANALYSIS\n";
        if ($analysis['median_gap_minutes'] !== null) {
            $gapColor = $analysis['median_gap_minutes'] <= 5 ? '✅' : ($analysis['median_gap_minutes'] > 15 ? '❌' : '⚠️');
            echo "   {$gapColor} Median Gap: " . number_format($analysis['median_gap_minutes'], 2) . " minutes\n";
            echo "     Status: " . $analysis['median_gap_status'] . "\n";
            
            if ($analysis['gap_percentiles']) {
                echo "     Percentiles:\n";
                echo "       25th: " . number_format($analysis['gap_percentiles']['25th'], 2) . " min\n";
                echo "       75th: " . number_format($analysis['gap_percentiles']['75th'], 2) . " min\n";
                echo "       95th: " . number_format($analysis['gap_percentiles']['95th'], 2) . " min\n";
            }
        } else {
            echo "   No gap data available\n";
        }
        echo "\n";
        
        echo "📡 DSRC ANALYSIS\n";
        $dsrcTotal = $analysis['dsrc_counts']['TER'] + $analysis['dsrc_counts']['SAT'];
        if ($dsrcTotal > 0) {
            $terPct = ($analysis['dsrc_counts']['TER'] / $dsrcTotal) * 100;
            $satPct = ($analysis['dsrc_counts']['SAT'] / $dsrcTotal) * 100;
            
            echo "   TER: " . $analysis['dsrc_counts']['TER'] . " (" . number_format($terPct, 1) . "%)\n";
            echo "   SAT: " . $analysis['dsrc_counts']['SAT'] . " (" . number_format($satPct, 1) . "%)\n";
            echo "   Majority: " . $analysis['dsrc_majority'] . " " . 
                 ($analysis['dsrc_majority'] == 'TER' ? '✅' : '❌') . "\n";
        } else {
            echo "   No DSRC data available\n";
        }
        echo "\n";
        
        echo "🎯 OVERALL RESULT\n";
        $resultColor = $analysis['overall_status'] == 'PASS' ? '✅' : '❌';
        echo "   {$resultColor} Status: " . $analysis['overall_status'] . "\n\n";
        
        echo "📁 Database Information:\n";
        echo "   Run ID: {$this->runId}\n";
        echo "   Tables: monitoring_runs, vessel_positions, analysis_results\n";
        echo "\n";
    }
    
    /**
     * Run the monitoring process
     */
    public function run() {
        $this->debug("Starting MarineTraffic Monitor");
        $this->debug("Run ID: {$this->runId}");
        $this->debug("Bounding Box: Lon {$this->boundingBox['minLon']} to {$this->boundingBox['maxLon']}, " .
                    "Lat {$this->boundingBox['minLat']} to {$this->boundingBox['maxLat']}");
        $this->debug("Polling Interval: {$this->pollingInterval} seconds");
        $this->debug("Max Runtime: " . ($this->maxRuntime / 3600) . " hours\n");
        echo 'run()';
        $startTime = time();
        $endTime = $startTime + $this->maxRuntime;
        $iteration = 0;
        
        while (time() < $endTime) {
            $iteration++;
            $callTimestamp = date('Y-m-d H:i:s');
            
            $this->debug("\n--- Iteration {$iteration} at {$callTimestamp} ---");
            
            // Make API request
            $apiResponse = $this->makeApiRequest();
            
            if ($apiResponse !== false) {
                // Parse and store vessel data
                $vessels = $this->parseVesselData($apiResponse);
                $this->storeVesselData($vessels, $callTimestamp);
                $this->debug("Successfully processed " . count($vessels) . " vessels");
            } else {
                $this->debug("Failed to get valid API response");
            }
            
            // Calculate next poll time
            $nextPoll = time() + $this->pollingInterval;
            $timeRemaining = $endTime - time();
            
            if ($timeRemaining > 0) {
                $hoursRemaining = floor($timeRemaining / 3600);
                $minutesRemaining = floor(($timeRemaining % 3600) / 60);
                $this->debug("Next poll at " . date('Y-m-d H:i:s', $nextPoll));
                $this->debug("Time remaining: {$hoursRemaining}h {$minutesRemaining}m");
                
                // Wait for next interval
                sleep($this->pollingInterval);
            }
        }
        
        $this->debug("\n=== Monitoring Complete ===");
        $this->debug("Total runtime: " . (time() - $startTime) . " seconds");
        $this->debug("Total iterations: {$iteration}");
        
        // Perform analysis
        $analysis = $this->analyzeData();
        
        if ($analysis) {
            $this->printAnalysis($analysis);
            
            // Verify database storage
            $this->verifyDatabaseStorage();
        }
        
        return $analysis;
    }
    
    /**
     * Verify database storage using MySQLi
     */
    private function verifyDatabaseStorage() {
        try {
            $sql = "SELECT 
                        COUNT(*) as position_count,
                        COUNT(DISTINCT mmsi) as vessel_count
                    FROM vessel_positions 
                    WHERE run_id = '".$this->runId."'";
            
            $stmt = $this->db->prepare($sql);
            if ($stmt) {
                $stmt->execute();
                $result = $stmt->get_result();
                $data = $result->fetch_assoc();
                $stmt->close();
                
                $this->debug("\n=== Database Storage Verification ===");
                $this->debug("Run ID: {$this->runId}");
                $this->debug("Vessels stored: " . ($data['vessel_count'] ?? 0));
                $this->debug("Positions stored: " . ($data['position_count'] ?? 0));
            }
            
        } catch (Exception $e) {
            $this->debug("Verification error: " . $e->getMessage());
        }
    }
    
    /**
     * Close database connection
     */
    public function __destruct() {
        if ($this->db) {
            $this->db->close();
        }
    }
    
    /**
     * Get run ID for reference
     */
    public function getRunId() {
        return $this->runId;
    }
}

// ============================================================================
// SCRIPT EXECUTION
// ============================================================================

// Configuration
$config = [
    // MarineTraffic API Configuration
    'api_key' => 'dnh6YU1yelh0bXdxZ09EYldqem9ZSnhLN2ExdmpIc1k6RFo2YUoyeEU3YTlVZW5mbUw3VS1VMGI5c2czUTVDMUg5M1o0ZGVSVDhmenFvOERVeFgxZTdIWGxUMHVBTHpjYQ==',
    
    // Database Configuration
    'database' => [
        'host' => 'db:3306',
        'port' => 3306,
        'database' => 'marinetraffic_monitor',
        'username' => 'root',
        'password' => 'root'
    ],
    
    // Bounding box coordinates
    'bounding_box' => [
        'minLon' => 3.0,
        'maxLon' => 3.5,
        'minLat' => 6.0,
        'maxLat' => 6.5
    ],
    
    // Polling interval in seconds (300 seconds = 5 minutes)
    'polling_interval' => 300,
    
    // Maximum runtime in seconds (43200 seconds = 12 hours, 86400 seconds = 24 hours)
    'max_runtime' => 43200, // Change to 86400 for 24 hours
    
    // Debug mode
    'debug_mode' => true
];

// Check if API key is set
if ($config['api_key'] === 'YOUR_API_KEY_HERE') {
    die("ERROR: Please set your MarineTraffic API key in the configuration.\n");
}

// Display configuration
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║         MARINETRAFFIC VESSEL POSITION MONITOR             ║\n";
echo "║                 (MySQLi Database Version)                  ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";
echo "Configuration:\n";
echo "  Database: {$config['database']['database']} on {$config['database']['host']}\n";
echo "  Bounding Box: Lon {$config['bounding_box']['minLon']} to {$config['bounding_box']['maxLon']}, ";
echo "Lat {$config['bounding_box']['minLat']} to {$config['bounding_box']['maxLat']}\n";
echo "  Polling Interval: " . ($config['polling_interval'] / 60) . " minutes\n";
echo "  Runtime: " . ($config['max_runtime'] / 3600) . " hours\n\n";

// Handle command line arguments for runtime override
if ($argc > 1) {
    if ($argv[1] === '--help' || $argv[1] === '-h') {
        echo "Usage: php marinetraffic_monitor.php [runtime_hours]\n";
        echo "  runtime_hours: Number of hours to run (default: 12)\n";
        exit(0);
    }
    
    if (is_numeric($argv[1])) {
        $config['max_runtime'] = floatval($argv[1]) * 3600;
        echo "Runtime overridden to: " . $argv[1] . " hours\n\n";
    }
}
echo "  s1";
// Handle CTRL+C gracefully
if (function_exists('pcntl_signal')) {
    declare(ticks = 1);
    pcntl_signal(SIGINT, function( $signo ) use ( &$monitor ) {
        echo "\n\n⚠️  Received interrupt signal. Performing analysis before exit...\n";
        if (isset($monitor)) {
            $analysis = $monitor->analyzeData();
            if ($analysis) {
                $monitor->printAnalysis($analysis);
            }
        }
        exit(0);
    });
}

// Create and run monitor
try {
    $monitor = new MarineTrafficMonitor(
        $config['api_key'],
        $config['database'],
        $config['bounding_box'],
        $config['polling_interval'],
        $config['max_runtime']
    );
    
    $monitor->setDebugMode($config['debug_mode']);
    $results = $monitor->run();
    echo 'sdfsdfsd';
    echo "\n✅ Monitoring completed successfully!\n";
    echo "   Run ID: " . $monitor->getRunId() . "\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}