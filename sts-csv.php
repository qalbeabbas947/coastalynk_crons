<?php
class MaritimeVesselExtractor {
    
    private $apiKey;
    private $kplerApiUrl = "https://api.kpler.com/v2/maritime/ais-historical";
    
    // Lagos Outer Anchorage polygon (based on your shared polygon)
    private $lagosAnchoragePolygon1 = [
        ['lat' => 6.3500, 'lng' => 3.4000],
        ['lat' => 6.3500, 'lng' => 3.5000],
        ['lat' => 6.2500, 'lng' => 3.5000],
        ['lat' => 6.2500, 'lng' => 3.4000],
        ['lat' => 6.3500, 'lng' => 3.4000]
    ];
    
private $lagosAnchoragePolygon = [['lat' => 2.75, 'lng' => 6.4],['lat' => 3.6, 'lng' => 6.5],['lat' => 4.4, 'lng' => 6.3],['lat' => 5.3, 'lng' => 5.85],['lat' => 6.2, 'lng' => 4.8],['lat' => 7.15, 'lng' => 4.55],['lat' => 8, 'lng' => 4.7],['lat' => 8.5, 'lng' => 5.05],['lat' => 8.3, 'lng' => 3.7],['lat' => 5.5, 'lng' => 3.4],['lat' => 2.75, 'lng' => 3.5],['lat' => 2.75, 'lng' => 6.4]];

    // Dawes Creek polygon (simplified - based on typical anchorage area)
    private $dawesCreekPolygon = [
        ['lat' => 6.4200, 'lng' => 3.3500],
        ['lat' => 6.4200, 'lng' => 3.4000],
        ['lat' => 6.3700, 'lng' => 3.4000],
        ['lat' => 6.3700, 'lng' => 3.3500],
        ['lat' => 6.4200, 'lng' => 3.3500]
    ];
    
    // Barge vessel type codes
    private $bargeTypeCodes = [
        20, 21, 22, 23, 24, 25, 26, 27, 28, 29,  // Towing and pushing vessels
        30, 31, 32, 33, 34, 35, 36, 37, 38, 39,  // Barges and deck cargo
        40, 41, 42, 43, 44, 45, 46, 47, 48, 49,  // Barges (continued)
        60, 61, 62, 63, 64, 65, 66, 67, 68, 69,  // Tank barges
        80, 81, 82, 83, 84, 85, 86, 87, 88, 89   // Other barges
    ];
    
    // Tanker vessel type codes
    private $tankerTypeCodes = [
        70, 71, 72, 73, 74, 75, 76, 77, 78, 79,  // Oil tankers
        80, 81, 82, 83, 84, 85, 86, 87, 88, 89,  // Chemical tankers
        90, 91, 92, 93, 94, 95, 96, 97, 98, 99   // Other tankers
    ];
    
    public function __construct($apiKey) {
        $this->apiKey = $apiKey;
    }
    
    /**
     * Check if a point is inside a polygon
     */
    private function isPointInPolygon($latitude, $longitude, $polygon) {
        $inside = false;
        $n = count($polygon);
        
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $lat_i = $polygon[$i]['lat'];
            $lng_i = $polygon[$i]['lng'];
            $lat_j = $polygon[$j]['lat'];
            $lng_j = $polygon[$j]['lng'];
            
            $intersect = (($lng_i > $longitude) != ($lng_j > $longitude)) &&
                ($latitude < ($lat_j - $lat_i) * ($longitude - $lng_i) / ($lng_j - $lng_i) + $lat_i);
            
            if ($intersect) {
                $inside = !$inside;
            }
        }
        
        return $inside;
    }
    
    /**
     * Get vessel historical positions from Kpler API
     */
    public function getVesselHistory($mmsi, $startDate, $endDate) {
        $ch = curl_init();
        
        $filter = "posDt BETWEEN '" . $startDate . "' AND '" . $endDate . "' AND mmsi=" . $mmsi;
        
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->kplerApiUrl . "?filter=" . urlencode($filter),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => 'GET',
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . $this->apiKey,
            ],
        ]);
        
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            throw new Exception('cURL Error: ' . curl_error($ch));
        }
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 400) {
            throw new Exception("HTTP Error: " . $httpCode . " - " . $response);
        }
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("JSON Decode Error: " . json_last_error_msg());
        }
        
        $features = $data["features"] ?? [];
        $positions = [];
        
        foreach ($features as $feature) {
            $properties = $feature['properties'] ?? [];
            $geometry = $feature['geometry'] ?? [];
            $coordinates = $geometry['coordinates'] ?? [];
            
            if (empty($properties['posDt']) || empty($coordinates)) {
                continue;
            }
            
            $positions[] = [
                'mmsi' => $properties['mmsi'] ?? null,
                'vesselName' => $properties['vesselName'] ?? null,
                'imo' => $properties['imo'] ?? null,
                'dwt' => $properties['dwt'] ?? null,
                'flag' => $properties['flag'] ?? null,
                'vesselType' => $properties['vesselType'] ?? null,
                'vesselTypeAis' => $properties['vesselTypeAis'] ?? null,
                'longitude' => $coordinates[0] ?? null,
                'latitude' => $coordinates[1] ?? null,
                'destination' => $properties['destination'] ?? null,
                'eta' => $properties['eta'] ?? null,
                'draught' => $properties['draught'] ?? null,
                'sog' => $properties['sog'] ?? null,
                'cog' => $properties['cog'] ?? null,
                'navStatus' => $properties['navStatus'] ?? null,
                'timestamp' => $properties['posDt'],
                'posDt_time' => strtotime($properties['posDt'])
            ];
        }
        
        // Sort by timestamp
        usort($positions, function($a, $b) {
            return $a['posDt_time'] <=> $b['posDt_time'];
        });
        
        return $positions;
    }
    
    /**
     * Calculate dwell time using session logic (not raw pings)
     * A session is defined as continuous presence in the zone with max 2 hour gap between positions
     */
    private function calculateDwellTimeWithSessions($positions, $maxGapMinutes = 120) {
        if (empty($positions)) {
            return 0;
        }
        
        $sessions = [];
        $currentSession = [
            'start' => $positions[0]['posDt_time'],
            'end' => $positions[0]['posDt_time'],
            'positions' => [$positions[0]]
        ];
        
        for ($i = 1; $i < count($positions); $i++) {
            $timeGap = $positions[$i]['posDt_time'] - $positions[$i-1]['posDt_time'];
            $timeGapMinutes = $timeGap / 60;
            
            if ($timeGapMinutes <= $maxGapMinutes) {
                // Continue current session
                $currentSession['end'] = $positions[$i]['posDt_time'];
                $currentSession['positions'][] = $positions[$i];
            } else {
                // End current session and start new one
                $sessions[] = $currentSession;
                $currentSession = [
                    'start' => $positions[$i]['posDt_time'],
                    'end' => $positions[$i]['posDt_time'],
                    'positions' => [$positions[$i]]
                ];
            }
        }
        
        // Add the last session
        $sessions[] = $currentSession;
        
        // Calculate total dwell time (sum of all sessions)
        $totalDwellHours = 0;
        foreach ($sessions as $session) {
            $dwellSeconds = $session['end'] - $session['start'];
            $totalDwellHours += $dwellSeconds / 3600;
        }
        
        return [
            'total_dwell_hours' => round($totalDwellHours, 2),
            'sessions' => $sessions,
            'entry_date' => date('Y-m-d H:i:s', $sessions[0]['start']),
            'exit_date' => date('Y-m-d H:i:s', end($sessions)['end'])
        ];
    }
    
    /**
     * Extract vessels by type with dwell time analysis
     */
    public function extractVesselsByType($vesselTypeCodes, $area, $startDate, $endDate, $minDwellDays = 10) {
        $results = [];
        
        // For each vessel type code, we need to query vessels
        // Note: In real implementation, you'd first get list of vessels from Kpler API
        // For this example, we'll demonstrate the logic with sample data
        
        // This is where you would:
        // 1. Query Kpler API to get all vessels in the area during the period
        // 2. Filter by vessel type codes
        // 3. Calculate dwell time using session logic
        // 4. Filter by min dwell time
        
        // Example implementation structure:
        foreach ($vesselTypeCodes as $typeCode) {
            // Query vessels of this type
            $vesselsInArea = $this->getVesselsByTypeAndArea($typeCode, $area, $startDate, $endDate);
            
            foreach ($vesselsInArea as $vessel) {
                // Get historical positions
                $positions = $this->getVesselHistory($vessel['mmsi'], $startDate, $endDate);
                
                // Filter positions within the area
                $positionsInArea = array_filter($positions, function($pos) use ($area) {
                    return $this->isPointInPolygon($pos['latitude'], $pos['longitude'], $area);
                });
                
                if (empty($positionsInArea)) {
                    continue;
                }
                
                // Calculate dwell time with session logic
                $dwellAnalysis = $this->calculateDwellTimeWithSessions(array_values($positionsInArea));
                
                // Check if dwell time exceeds minimum
                if ($dwellAnalysis['total_dwell_hours'] >= ($minDwellDays * 24)) {
                    $results[] = [
                        'name' => $vessel['vesselName'] ?? 'Unknown',
                        'imo' => $vessel['imo'] ?? 'N/A',
                        'dwt' => $vessel['dwt'] ?? 'N/A',
                        'flag' => $vessel['flag'] ?? 'Unknown',
                        'owner' => $vessel['owner'] ?? 'Unknown',
                        'entry_date' => $dwellAnalysis['entry_date'],
                        'dwell_hours' => $dwellAnalysis['total_dwell_hours'],
                        'destination' => $vessel['destination'] ?? 'Unknown',
                        'vessel_type' => $vessel['vesselType'] ?? 'Unknown',
                        'mmsi' => $vessel['mmsi']
                    ];
                }
            }
        }
        
        return $results;
    }
    
    /**
     * Get vessels by type and area (helper method - would need actual API implementation)
     */
    private function getVesselsByTypeAndArea($vesselTypeCode, $area, $startDate, $endDate) {
        // This is a placeholder - you would need to implement actual API call
        // to Kpler to get vessels by type and area
        
        // For demo purposes, returning empty array
        // In production, you would:
        // 1. Call Kpler API to get all vessels in bounding box
        // 2. Filter by vessel type
        // 3. Return vessel details
        
        return [];
    }
    
    /**
     * Extract barges for Lagos Outer Anchorage + Dawes Creek (Q1 2026)
     */
    public function extractBarges() {
        $startDate = '2026-01-01 00:00:00';
        $endDate = '2026-03-31 23:59:59';
        $minDwellDays = 10;
        
        $results = [
            'lagos_outer_anchorage' => [],
            'dawes_creek' => []
        ];
        
        // Extract from Lagos Outer Anchorage
        echo "Extracting barges from Lagos Outer Anchorage (Q1 2026)...\n";
        $results['lagos_outer_anchorage'] = $this->extractVesselsByType(
            $this->bargeTypeCodes,
            $this->lagosAnchoragePolygon,
            $startDate,
            $endDate,
            $minDwellDays
        );
        
        // Extract from Dawes Creek
        echo "Extracting barges from Dawes Creek (Q1 2026)...\n";
        $results['dawes_creek'] = $this->extractVesselsByType(
            $this->bargeTypeCodes,
            $this->dawesCreekPolygon,
            $startDate,
            $endDate,
            $minDwellDays
        );
        
        return $results;
    }
    
    /**
     * Extract tankers for Lagos Outer Anchorage + Dawes Creek (last 60 days)
     */
    public function extractTankers() {
        $endDate = date('Y-m-d H:i:s');
        $startDate = date('Y-m-d H:i:s', strtotime('-60 days'));
        $minDwellDays = 10;
        
        $results = [
            'lagos_outer_anchorage' => [],
            'dawes_creek' => []
        ];
        
        // Extract from Lagos Outer Anchorage
        echo "Extracting tankers from Lagos Outer Anchorage (last 60 days)...\n";
        $results['lagos_outer_anchorage'] = $this->extractVesselsByType(
            $this->tankerTypeCodes,
            $this->lagosAnchoragePolygon,
            $startDate,
            $endDate,
            $minDwellDays
        );
        
        // Extract from Dawes Creek
        echo "Extracting tankers from Dawes Creek (last 60 days)...\n";
        $results['dawes_creek'] = $this->extractVesselsByType(
            $this->tankerTypeCodes,
            $this->dawesCreekPolygon,
            $startDate,
            $endDate,
            $minDwellDays
        );
        
        return $results;
    }
    
    /**
     * Export results to CSV
     */
    public function exportToCSV($data, $filename, $areaName) {
        $file = fopen($filename, 'w');
        
        // Write headers
        $headers = ['Name', 'IMO', 'DWT', 'Flag', 'Owner', 'Entry Date', 'Dwell (Hours)', 'Destination', 'Vessel Type', 'MMSI'];
        fputcsv($file, $headers);
        
        // Write data for specific area
        if (isset($data[$areaName])) {
            foreach ($data[$areaName] as $vessel) {
                $row = [
                    $vessel['name'],
                    $vessel['imo'],
                    $vessel['dwt'],
                    $vessel['flag'],
                    $vessel['owner'],
                    $vessel['entry_date'],
                    $vessel['dwell_hours'],
                    $vessel['destination'],
                    $vessel['vessel_type'],
                    $vessel['mmsi']
                ];
                fputcsv($file, $row);
            }
        }
        
        fclose($file);
        
        return $filename;
    }
    
    /**
     * Export results to JSON
     */
    public function exportToJSON($data, $filename) {
        $jsonData = [
            'extraction_date' => date('Y-m-d H:i:s'),
            'data' => $data
        ];
        
        file_put_contents($filename, json_encode($jsonData, JSON_PRETTY_PRINT));
        
        return $filename;
    }
    
    /**
     * Generate summary report
     */
    public function generateSummaryReport($bargeData, $tankerData) {
        $report = [];
        
        // Barges summary
        $report['barges'] = [
            'lagos_outer_anchorage' => [
                'total_vessels' => count($bargeData['lagos_outer_anchorage']),
                'total_dwell_hours' => array_sum(array_column($bargeData['lagos_outer_anchorage'], 'dwell_hours')),
                'avg_dwell_hours' => count($bargeData['lagos_outer_anchorage']) > 0 ? 
                    array_sum(array_column($bargeData['lagos_outer_anchorage'], 'dwell_hours')) / count($bargeData['lagos_outer_anchorage']) : 0
            ],
            'dawes_creek' => [
                'total_vessels' => count($bargeData['dawes_creek']),
                'total_dwell_hours' => array_sum(array_column($bargeData['dawes_creek'], 'dwell_hours')),
                'avg_dwell_hours' => count($bargeData['dawes_creek']) > 0 ? 
                    array_sum(array_column($bargeData['dawes_creek'], 'dwell_hours')) / count($bargeData['dawes_creek']) : 0
            ]
        ];
        
        // Tankers summary
        $report['tankers'] = [
            'lagos_outer_anchorage' => [
                'total_vessels' => count($tankerData['lagos_outer_anchorage']),
                'total_dwell_hours' => array_sum(array_column($tankerData['lagos_outer_anchorage'], 'dwell_hours')),
                'avg_dwell_hours' => count($tankerData['lagos_outer_anchorage']) > 0 ? 
                    array_sum(array_column($tankerData['lagos_outer_anchorage'], 'dwell_hours')) / count($tankerData['lagos_outer_anchorage']) : 0
            ],
            'dawes_creek' => [
                'total_vessels' => count($tankerData['dawes_creek']),
                'total_dwell_hours' => array_sum(array_column($tankerData['dawes_creek'], 'dwell_hours')),
                'avg_dwell_hours' => count($tankerData['dawes_creek']) > 0 ? 
                    array_sum(array_column($tankerData['dawes_creek'], 'dwell_hours')) / count($tankerData['dawes_creek']) : 0
            ]
        ];
        
        return $report;
    }
}

// Cron job execution script
class CronExtractor {
    
    public function run() {
        try {
            // Initialize with your Kpler API key
            $apiKey = 'dnh6YU1yelh0bXdxZ09EYldqem9ZSnhLN2ExdmpIc1k6RFo2YUoyeEU3YTlVZW5mbUw3VS1VMGI5c2czUTVDMUg5M1o0ZGVSVDhmenFvOERVeFgxZTdIWGxUMHVBTHpjYQ==';
            $extractor = new MaritimeVesselExtractor($apiKey);
            
            // Create output directory if not exists
            $outputDir = __DIR__ . '/extractions';
            if (!file_exists($outputDir)) {
                mkdir($outputDir, 0777, true);
            }
            
            $timestamp = date('Ymd_His');
            
            // Extract barges (Q1 2026)
            echo "\n========================================\n";
            echo "Starting Barge Extraction (Q1 2026)\n";
            echo "========================================\n";
            $bargeData = $extractor->extractBarges();
            
            // Save barge results
            $bargeJSONFile = $outputDir . "/barges_q1_2026_{$timestamp}.json";
            $extractor->exportToJSON($bargeData, $bargeJSONFile);
            echo "Barge data saved to: $bargeJSONFile\n";
            
            // Export barges to CSV (separate files for each area)
            $bargeLagosCSV = $outputDir . "/barges_lagos_anchorage_q1_2026_{$timestamp}.csv";
            $extractor->exportToCSV($bargeData, $bargeLagosCSV, 'lagos_outer_anchorage');
            echo "Barge Lagos CSV saved to: $bargeLagosCSV\n";
            
            $bargeDawesCSV = $outputDir . "/barges_dawes_creek_q1_2026_{$timestamp}.csv";
            $extractor->exportToCSV($bargeData, $bargeDawesCSV, 'dawes_creek');
            echo "Barge Dawes CSV saved to: $bargeDawesCSV\n";
            
            // Extract tankers (last 60 days)
            echo "\n========================================\n";
            echo "Starting Tanker Extraction (Last 60 Days)\n";
            echo "========================================\n";
            $tankerData = $extractor->extractTankers();
            
            // Save tanker results
            $tankerJSONFile = $outputDir . "/tankers_last60days_{$timestamp}.json";
            $extractor->exportToJSON($tankerData, $tankerJSONFile);
            echo "Tanker data saved to: $tankerJSONFile\n";
            
            // Export tankers to CSV (separate files for each area)
            $tankerLagosCSV = $outputDir . "/tankers_lagos_anchorage_last60days_{$timestamp}.csv";
            $extractor->exportToCSV($tankerData, $tankerLagosCSV, 'lagos_outer_anchorage');
            echo "Tanker Lagos CSV saved to: $tankerLagosCSV\n";
            
            $tankerDawesCSV = $outputDir . "/tankers_dawes_creek_last60days_{$timestamp}.csv";
            $extractor->exportToCSV($tankerData, $tankerDawesCSV, 'dawes_creek');
            echo "Tanker Dawes CSV saved to: $tankerDawesCSV\n";
            
            // Generate summary report
            $summaryReport = $extractor->generateSummaryReport($bargeData, $tankerData);
            $summaryFile = $outputDir . "/summary_report_{$timestamp}.json";
            file_put_contents($summaryFile, json_encode($summaryReport, JSON_PRETTY_PRINT));
            echo "\nSummary report saved to: $summaryFile\n";
            
            // Display summary
            echo "\n========================================\n";
            echo "EXTRACTION SUMMARY\n";
            echo "========================================\n";
            echo "BARGES (Q1 2026):\n";
            echo "  Lagos Outer Anchorage: " . $summaryReport['barges']['lagos_outer_anchorage']['total_vessels'] . " vessels\n";
            echo "    Total Dwell Hours: " . round($summaryReport['barges']['lagos_outer_anchorage']['total_dwell_hours'], 2) . "\n";
            echo "    Avg Dwell Hours: " . round($summaryReport['barges']['lagos_outer_anchorage']['avg_dwell_hours'], 2) . "\n";
            echo "  Dawes Creek: " . $summaryReport['barges']['dawes_creek']['total_vessels'] . " vessels\n";
            echo "    Total Dwell Hours: " . round($summaryReport['barges']['dawes_creek']['total_dwell_hours'], 2) . "\n";
            echo "    Avg Dwell Hours: " . round($summaryReport['barges']['dawes_creek']['avg_dwell_hours'], 2) . "\n";
            
            echo "\nTANKERS (Last 60 Days):\n";
            echo "  Lagos Outer Anchorage: " . $summaryReport['tankers']['lagos_outer_anchorage']['total_vessels'] . " vessels\n";
            echo "    Total Dwell Hours: " . round($summaryReport['tankers']['lagos_outer_anchorage']['total_dwell_hours'], 2) . "\n";
            echo "    Avg Dwell Hours: " . round($summaryReport['tankers']['lagos_outer_anchorage']['avg_dwell_hours'], 2) . "\n";
            echo "  Dawes Creek: " . $summaryReport['tankers']['dawes_creek']['total_vessels'] . " vessels\n";
            echo "    Total Dwell Hours: " . round($summaryReport['tankers']['dawes_creek']['total_dwell_hours'], 2) . "\n";
            echo "    Avg Dwell Hours: " . round($summaryReport['tankers']['dawes_creek']['avg_dwell_hours'], 2) . "\n";
            
            echo "\n✅ Extraction completed successfully!\n";
            
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            echo "Stack trace: " . $e->getTraceAsString() . "\n";
            exit(1);
        }
    }
}

// Run the cron extraction
$cron = new CronExtractor();
$cron->run();