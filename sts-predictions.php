<?php

ini_set( "display_errors", "On" );
error_reporting(E_ALL);

class STSTransferDetector {
    private $apiKey;
    private $baseUrl = 'https://api.datalastic.com/api/v0';
    
    public function __construct($apiKey) {
        $this->apiKey = $apiKey;
    }
    
    /**
     * Calculate distance between two coordinates in nautical miles
     */
    private function calculateDistanceNM($lat1, $lon1, $lat2, $lon2) {
        $earthRadius = 3440.065; // Earth radius in nautical miles
        
        $lat1 = deg2rad($lat1);
        $lon1 = deg2rad($lon1);
        $lat2 = deg2rad($lat2);
        $lon2 = deg2rad($lon2);
        
        $latDelta = $lat2 - $lat1;
        $lonDelta = $lon2 - $lon1;
        
        $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) + 
                cos($lat1) * cos($lat2) * pow(sin($lonDelta / 2), 2)));
        
        return $angle * $earthRadius;
    }
    
    /**
     * Check if vessel is stationary (speed < 0.5 knots)
     */
    private function isStationary($speed) {
        return $speed <= 1;
    }
    
    /**
     * Get vessel historical positions
     */
    private function getVesselHistory($mmsi, $hours = 24) {
        $endpoint = $this->baseUrl . '/vessel_history';
        $params = [
            'api-key' => $this->apiKey,
            'mmsi' => $mmsi,
            'from' => date('Y-m-d', strtotime("-$hours hours")),
            'to' => date('Y-m-d')
        ];
        
        $url = $endpoint . '?' . http_build_query($params);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            return $data['data'] ?? [];
        }
        
        return [];
    }
    
    /**
     * Predict cargo type based on vessel characteristics
     */
    private function predictCargoType($vesselData) {
        $type = $vesselData['type'] ?? '';
        $length = $vesselData['length'] ?? 0;
        $draught = $vesselData['draught'] ?? 0;
        
        // Cargo type prediction logic
        if (stripos($type, 'tanker') !== false) {
            if ($draught > 10) return 'Crude Oil';
            if ($draught > 8) return 'Refined Products';
            return 'Chemical';
        }
        
        if (stripos($type, 'cargo') !== false) {
            if ($length > 200) return 'Bulk Carrier';
            if ($length > 150) return 'Container Ship';
            return 'General Cargo';
        }
        
        return 'Unknown Cargo';
    }
    
    /**
     * Calculate risk level based on multiple factors
     */
    private function calculateRiskLevel($vessel1, $vessel2, $stationaryHours, $distanceNM) {
        // $riskScore = 0;
        
        // // Distance factor
        // if ($distanceNM <= 0.1) $riskScore += 3;
        // elseif ($distanceNM <= 0.2) $riskScore += 2;
        // elseif ($distanceNM <= ALLOWED_STS_RANGE_NM) $riskScore += 1;
        
        // Duration factor
        if ($stationaryHours >= 6) return 'HIGH';
        elseif ($stationaryHours >= 4) return 'MEDIUM';
        else return 'LOW';
        
        // Vessel type factor
        // $type1 = $vessel1['type'] ?? '';
        // $type2 = $vessel2['type'] ?? '';
        
        // if (stripos($type1, 'tanker') !== false && stripos($type2, 'tanker') !== false) {
        //     $riskScore += 2; // Tanker-to-tanker transfer
        // }
        
        // // Determine risk level
        // if ($riskScore >= 5) return 'HIGH';
        // if ($riskScore >= 3) return 'MEDIUM';
        // return 'LOW';
    }
    
    /**
     * Calculate confidence level
     */
    private function calculateConfidence($dataPoints, $consistency, $stationaryHours) {
        $confidence = 0;
        
        // Data points factor
        if ($dataPoints >= 20) $confidence += 40;
        elseif ($dataPoints >= 10) $confidence += 30;
        elseif ($dataPoints >= 5) $confidence += 20;
        
        // Consistency factor
        $confidence += ($consistency * 30);
        
        // Duration factor
        if ($stationaryHours >= 4) $confidence += 30;
        elseif ($stationaryHours >= 3) $confidence += 20;
        
        return min(100, $confidence);
    }

    /**
     * Get zone/terminal name based on position
     */
    public function getZoneTerminalName($lat, $lon) {
        
        $endpoint = $this->baseUrl . '/port_find';
        $params = [
            'api-key' => $this->apiKey,
            'lat' => $lat,
            'lon' => $lon,
            'radius' => 10
        ];
        
        $url = $endpoint . '?' . http_build_query($params);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $data = json_decode($response, true);
        
        if( isset( $data['data'] ) && is_array( $data['data'] ) && count( $data['data'] ) > 0 ) {
            return $data['data'][0]['port_name'];
        }
        
        return '';
    }
    
    /**
     * Determine vessel condition based on age and maintenance
     */
    private function getVesselCondition($vesselData) {
        $yearBuilt = $vesselData['year_built'] ?? 0;
        $currentYear = date('Y');
        
        if ($yearBuilt == 0) return 'Unknown';
        
        $age = $currentYear - $yearBuilt;
        
        if ($age <= 5) return 'Excellent';
        if ($age <= 10) return 'Good';
        if ($age <= 20) return 'Fair';
        return 'Poor';
    }
    
    /**
     * Estimate Cargo ETA based on current position and destination
     */
    private function estimateCargoETA($vesselData) {
        // Simplified ETA calculation
        $speed = $vesselData['speed'] ?? 0;
        $draught = $vesselData['draught'] ?? 0;
        
        if ($speed <= 0) return 0;
        
        // Basic ETA estimation (hours)
        $baseETA = 24; // Default 24 hours
        $speedFactor = max(1, 15 / $speed); // Adjust based on speed
        $draughtFactor = $draught > 10 ? 1.5 : 1.0; // Deeper draught = longer
        
        return round($baseETA * $speedFactor * $draughtFactor, 1);
    }
    
    /**
     * Get vessel owner information
     */
    private function getVesselOwner($vesselData) {
        return $vesselData['owner'] ?? $vesselData['operator'] ?? 'Unknown';
    }
    
    /**
     * Determine operation mode
     */
    private function getOperationMode($vessel1, $vessel2, $analysis) {
        if (!$analysis['sts_detected']) return '';
        
        $cargo1 = $this->predictCargoType($vessel1);
        $cargo2 = $this->predictCargoType($vessel2);
        
        // Simple logic to determine operation mode
        if (strpos($cargo1, 'Oil') !== false || strpos($cargo2, 'Oil') !== false) {
            return 'STS';
        }
        
        return 'STS'; // Default to STS for detected transfers
    }
    
    /**
     * Determine operation status
     */
    private function getOperationStatus($analysis) {
        if (!$analysis['sts_detected']) return '';
        
        $stationaryHours = $analysis['stationary_hours'];
        
        //if ($stationaryHours >= 6) return 'Completed';
        if ($stationaryHours >= 3) return 'Ongoing';
        
        return 'Detected';
    }

    /**
     * Detect STS transfers between two vessels
     */
    public function detectSTSTransfer($vessel1, $vessel2) {
        try {
            
            if (!$vessel1 || !$vessel2) {
                throw new Exception("Could not retrieve vessel information");
            }
            
            // Get historical data (last 6 hours)
            $history1 = $this->getVesselHistory($vessel1['mmsi'], 24);
            $history2 = $this->getVesselHistory($vessel2['mmsi'], 24);

            // Analyze proximity and movement patterns
            $analysis = $this->analyzeVesselBehavior($history1, $history2, $vessel1, $vessel2);
            
            // Generate STS report
            $stsReport = $this->generateSTSReport($analysis, $vessel1, $vessel2);
            
            return $stsReport;
            
        } catch (Exception $e) {
            return [
                'error' => $e->getMessage(),
                'timestamp' => date('c')
            ];
        }
    }
    
    /**
     * Analyze vessel behavior for STS patterns
     */
    private function analyzeVesselBehavior($history1, $history2, $vessel1, $vessel2) {
        $analysis = [
            'current_distance_nm' => 0,
            'stationary_hours' => 0,
            'lock_time' => '',
            'proximity_consistency' => 0,
            'data_points_analyzed' => min(count($history1), count($history2)),
            'sts_detected' => false
        ];
        
        if (empty($history1) || empty($history2)) {
            return $analysis;
        }
        
        $analysis['current_distance_nm'] = $this->calculateDistanceNM(
                $vessel1['lat'], $vessel1['lon'],
                $vessel2['lat'], $vessel2['lon']
            );
       
        // Analyze historical proximity and movement
        $closeProximityCount = 0;
        $stationaryCount = 0;
        $totalComparisons = 0;
        
        foreach ($history1['positions'] as $point1) {
            foreach ($history2['positions'] as $point2) {
                // Compare points within 10 minutes of each other
                if (is_array($point1) && is_array($point2)) {
                    $timeDiff = abs(strtotime($point1['last_position_epoch'] ?? 0) - strtotime($point2['last_position_epoch'] ?? 0));
                    
                    if ($timeDiff <= 600) { // 10 minutes
                        $distance = $this->calculateDistanceNM(
                            $point1['lat'], $point1['lon'],
                            $point2['lat'], $point2['lon']
                        );
                        $analysis['lock_time'] = $point2['last_position_epoch'];
                        $isStationary1 = $this->isStationary($point1['speed'] ?? 0);
                        $isStationary2 = $this->isStationary($point2['speed'] ?? 0);
                        
                        if ($distance <= ALLOWED_STS_RANGE_NM) {
                            $closeProximityCount++;
                        }
                        
                        if ($isStationary1 && $isStationary2) {
                            $stationaryCount++;
                        }
                        
                        $totalComparisons++;
                    }
                }
            }
        }
        
        // Calculate metrics
        if ($totalComparisons > 0) {
            $analysis['proximity_consistency'] = $closeProximityCount / $totalComparisons;
            $analysis['stationary_ratio'] = $stationaryCount / $totalComparisons;
            
            // Estimate stationary hours (simplified)
            $analysis['stationary_hours'] = round($analysis['stationary_ratio'] * 6, 1);
            
            // Detect STS based on criteria
            $analysis['sts_detected'] = (
                $analysis['current_distance_nm'] <= ALLOWED_STS_RANGE_NM &&
                $analysis['stationary_hours'] >= 6 &&
                $analysis['proximity_consistency'] >= 0.7
            );
        }
        
        return $analysis;
    }

    /**
     * Generate comprehensive STS report
     */
    private function generateSTSReport($analysis, $vessel1, $vessel2) {
        $cargoType1 = $this->predictCargoType($vessel1);
        $cargoType2 = $this->predictCargoType($vessel2);
        
        $riskLevel = $this->calculateRiskLevel(
            $vessel1, 
            $vessel2, 
            $analysis['stationary_hours'], 
            $analysis['current_distance_nm']
        );
        
        $confidence = $this->calculateConfidence(
            $analysis['data_points_analyzed'],
            $analysis['proximity_consistency'],
            $analysis['stationary_hours']
        );
        
        // Get additional required fields
        // $zoneTerminalName = $this->getZoneTerminalName(
        //     $vessel1['position']['lat'] ?? 0,
        //     $vessel1['position']['lon'] ?? 0
        // );
        
        $vesselCondition1 = $this->getVesselCondition($vessel1);
        $vesselCondition2 = $this->getVesselCondition($vessel2);
        
        $cargoETA1 = $this->estimateCargoETA($vessel1);
        $cargoETA2 = $this->estimateCargoETA($vessel2);
        
        $vesselOwner1 = $this->getVesselOwner($vessel1);
        $vesselOwner2 = $this->getVesselOwner($vessel2);
        
        $operationMode = $this->getOperationMode($vessel1, $vessel2, $analysis);
        $operationStatus = $this->getOperationStatus($analysis);
        
        $remarks = $this->generateRemarks($analysis, $vessel1, $vessel2, $cargoType1, $cargoType2);
        
        return [
            'sts_transfer_detected' => $analysis['sts_detected'],
            // 'zone_terminal_name' => $zoneTerminalName,
            'operation_mode' => $operationMode,
            'status' => $operationStatus,
            'vessel_1' => [
                'name' => $vessel1['name'] ?? 'Unknown',
                'mmsi' => $vessel1['mmsi'] ?? 'Unknown',
                'type' => $vessel1['type'] ?? 'Unknown',
                'predicted_cargo' => $cargoType1,
                'current_speed' => $vessel1['speed'] ?? 0,
                'vessel_condition' => $vesselCondition1,
                'cargo_eta' => $cargoETA1,
                'vessel_owner' => $vesselOwner1
            ],
            'vessel_2' => [
                'name' => $vessel2['name'] ?? 'Unknown',
                'mmsi' => $vessel2['mmsi'] ?? 'Unknown',
                'type' => $vessel2['type'] ?? 'Unknown',
                'predicted_cargo' => $cargoType2,
                'current_speed' => $vessel2['speed'] ?? 0,
                'vessel_condition' => $vesselCondition2,
                'cargo_eta' => $cargoETA2,
                'vessel_owner' => $vesselOwner2
            ],
            'proximity_analysis' => [
                'current_distance_nm' => round($analysis['current_distance_nm'], 3),
                'stationary_duration_hours' => $analysis['stationary_hours'],
                'proximity_consistency' => number_format(round($analysis['proximity_consistency'] * 100, 1), 2,'.', '') . '%',
                'data_points_analyzed' => $analysis['data_points_analyzed']
            ],
            'risk_assessment' => [
                'risk_level' => $riskLevel,
                'confidence' => number_format($confidence, 2,'.', ''),
                'remarks' => $remarks
            ],
            'lock_time' => $analysis['lock_time'],
            'timestamp' => date('c'),
            'criteria_met' => [
                'distance_≤_200_m' => $analysis['current_distance_nm'] <= ALLOWED_STS_RANGE_NM,
                'stationary_≥_6_hours' => $analysis['stationary_hours'] >= 6,
                'consistent_proximity' => $analysis['proximity_consistency'] >= 0.7
            ]
        ];
    }
    
    /**
     * Get vessel current information
     */
    private function getVesselInfo($mmsi) {
        $endpoint = $this->baseUrl . '/vessel_pro';
        $params = [
            'api-key' => $this->apiKey,
            'mmsi' => $mmsi
        ];
        
        $url = $endpoint . '?' . http_build_query($params);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            return $data['data'] ?? null;
        }
        
        return null;
    }
    
    /**
     * Generate remarks based on analysis
     */
    private function generateRemarks($analysis, $vessel1, $vessel2, $cargo1, $cargo2) {
        $remarks = [];
        
        if ($analysis['sts_detected']) {
            $remarks[] = "STS TRANSFER LIKELY IN PROGRESS";
        }
        
        if ($analysis['current_distance_nm'] <= ALLOWED_STS_RANGE_NM) {
            $remarks[] = "Vessels within STS operational distance";
        } else {
            $remarks[] = "Vessels outside typical STS distance";
        }
        
        if ($analysis['stationary_hours'] >= 5) {
            $remarks[] = "Extended stationary period suggests transfer operations";
        }
        
        if ($cargo1 === 'Crude Oil' && $cargo2 === 'Crude Oil') {
            $remarks[] = "Crude oil transfer - high value cargo";
        }
        
        if ($analysis['proximity_consistency'] >= 0.8) {
            $remarks[] = "Consistent proximity supports STS hypothesis";
        }
        
        return implode('. ', $remarks);
    }
}

// // Usage
// $apiKey = '15df4420-d28b-4b26-9f01-13cca621d55e';
// $detector = new STSTransferDetector($apiKey);   

// // Check for STS transfer between two vessels
// $result = $detector->detectSTSTransfer('123456789', '987654321');

// echo "<pre>";
// print_r($result);
// echo "</pre>";