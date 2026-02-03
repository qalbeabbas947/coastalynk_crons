<?php



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
     * Detect end conditions for STS transfer
     */
    private function detectEndConditions($history1, $history2) {
       
        if (empty($history1) || empty($history2)) {
            return null;
        }
        
        // Find when vessels start moving away from each other
        $endTime = null;
        
        // Sort by timestamp (newest first)
        usort($history1, function($a, $b) {
            return strtotime($b['last_position_UTC'] ?? 0) <=> strtotime($a['last_position_UTC'] ?? 0);
        });
        
        usort($history2, function($a, $b) {
            return strtotime($b['last_position_UTC'] ?? 0) <=> strtotime($a['last_position_UTC'] ?? 0);
        });
        
        // Check for movement or distance increase
        for ($i = 0; $i < min(count($history1), count($history2)) - 1; $i++) {
            $current1 = $history1[$i];
            $current2 = $history2[$i];
            $next1 = $history1[$i + 1];
            $next2 = $history2[$i + 1];
            
            $currentDistance = $this->calculateDistanceNM(
                $current1['lat'], $current1['lon'],
                $current2['lat'], $current2['lon']
            );
            
            $nextDistance = $this->calculateDistanceNM(
                $next1['lat'], $next1['lon'],
                $next2['lat'], $next2['lon']
            );
            
            // Check if vessels are moving apart
            if ($nextDistance > $currentDistance + 0.1) { // Increased by more than 0.1 NM
                $endTime = $next1['last_position_UTC'];
                break;
            }
            
            // Check if either vessel starts moving
            $currentSpeedAvg = (($current1['speed'] ?? 0) + ($current2['speed'] ?? 0)) / 2;
            $nextSpeedAvg = (($next1['speed'] ?? 0) + ($next2['speed'] ?? 0)) / 2;
            
            if ($currentSpeedAvg < 1 && $nextSpeedAvg >= 1) {
                $endTime = $next1['last_position_UTC'];
                break;
            }
        }
        
        return $endTime;
        
    }

    private function findSTSTransferPeriod($stationaryPeriods) {
        // Find the longest stationary period
        $longestPeriod = null;
        $maxDuration = 0;
        
        foreach ($stationaryPeriods as $period) {
            if ($period['duration_hours'] > $maxDuration) {
                $maxDuration = $period['duration_hours'];
                $longestPeriod = $period;
            }
        }
        
        // Check if it meets STS criteria
        if ($longestPeriod && $longestPeriod['duration_hours'] >= 6) {
            return $longestPeriod;
        }
        
        return null;
    }

    /**
     * Analyze vessel behavior for STS patterns
     */
    private function analyzeVesselBehavior($history1, $history2, $vessel1, $vessel2) {
        $analysis = [
            'current_distance_nm' => 0,
            'stationary_hours' => 0,
            'lock_time' => '',
            'start_date' => '',
            'end_date' => '',
            'distance' => '',
            'proximity_consistency' => 0,
            'data_points_analyzed' => min(count($history1), count($history2)),
            'sts_detected' => false,
            'stationary_periods' => []
        ];
        
        if (empty($history1) || empty($history2)) {
            return $analysis;
        }
        
        $analysis['current_distance_nm'] = $this->calculateDistanceNM(
            $vessel1['lat'], $vessel1['lon'],
            $vessel2['lat'], $vessel2['lon']
        );
        
        // Calculate stationary hours properly
        $stationaryAnalysis = $this->calculateStationaryHours(
            $history1['positions'] ?? [],
            $history2['positions'] ?? []
        );
        
        $analysis['stationary_hours'] = $stationaryAnalysis['total_stationary_hours'];
        $analysis['stationary_periods'] = $stationaryAnalysis['stationary_periods'];
        
        // Find STS transfer period
        $stsPeriod = $this->findSTSTransferPeriod($analysis['stationary_periods']);
        
        if ($stsPeriod) {
            $analysis['start_date'] = $stsPeriod['start'];
            $analysis['end_date'] = $stsPeriod['end'];
            $analysis['lock_time'] = $stsPeriod['start'];
        }
        
        // Analyze proximity consistency
        $closeProximityCount = 0;
        $totalComparisons = 0;
        
        foreach ($history1['positions'] as $point1) {
            foreach ($history2['positions'] as $point2) {
                if (is_array($point1) && is_array($point2)) {
                    $timeDiff = abs(strtotime($point1['last_position_epoch'] ?? 0) - strtotime($point2['last_position_epoch'] ?? 0));
                    
                    if ($timeDiff <= 600) { // 10 minutes
                        $distance = $this->calculateDistanceNM(
                            $point1['lat'], $point1['lon'],
                            $point2['lat'], $point2['lon']
                        );
                        
                        if ($distance <= ALLOWED_STS_RANGE_NM) {
                            $closeProximityCount++;
                            
                            // Set start date if not already set
                            if (empty($analysis['start_date'])) {
                                $analysis['start_date'] = $point1['last_position_UTC'];
                                $analysis['lock_time'] = $point2['last_position_epoch'];
                            }
                        }
                        
                        $analysis['distance'] = $distance; 
                        $totalComparisons++;
                    }
                }
            }
        }
        
        // Calculate proximity consistency
        if ($totalComparisons > 0) {
            $analysis['proximity_consistency'] = $closeProximityCount / $totalComparisons;
            if ($analysis['stationary_hours'] > ALLOWED_STS_MAX_TRANSFER_HOURS) {
                //$analysis['stationary_hours'] = ALLOWED_STS_MAX_TRANSFER_HOURS;
                $analysis['end_date'] = date('Y-m-d\TH:i:s\Z', strtotime($analysis['start_date']) + (ALLOWED_STS_MAX_TRANSFER_HOURS * 3600));
            }
            // Detect STS
            $analysis['sts_detected'] = (
                $analysis['current_distance_nm'] <= ALLOWED_STS_RANGE_NM &&
                $analysis['stationary_hours'] >= 6 &&
                $analysis['proximity_consistency'] >= 0.7
            );
        }
        
        echo '<pre>';print_r($analysis);echo '</pre>';
        return $analysis;
    }

    /**
     * Calculate transfer signal based on draught changes
     */
    public function calculateTransferSignal($vessel1, $vessel2, $initialDraught1, $initialDraught2) {
        // Get current draughts

        $vessel1 = get_datalastic_field( $vessel1['uuid']);
        $vessel2 = get_datalastic_field( $vessel2['uuid']); 

        $currentDraught1 = $vessel1['current_draught'] ?? 0;
        $currentDraught2 = $vessel2['current_draught'] ?? 0;

        // Calculate draught changes
        $draughtChange1 = $currentDraught1 - $initialDraught1;
        $draughtChange2 = $currentDraught2 - $initialDraught2;
        
        // Determine transfer direction with confidence
        $draughtDifference1 = abs($draughtChange1);
        $draughtDifference2 = abs($draughtChange2);
        
        // Set threshold for significant draught change (in meters)
        $significantChange = 0.5;
        
        // Determine signal
        if ($draughtDifference1 >= $significantChange || $draughtDifference2 >= $significantChange) {
            if ($draughtChange1 > 0 && $draughtChange2 < 0) {
                // Vessel1 increased draught, Vessel2 decreased - Vessel1 loading, Vessel2 discharging
                return [
                    'signal' => 'Likely Loading',
                    'confidence' => $this->calculateTransferSignalConfidence($draughtDifference1, $draughtDifference2),
                    'details' => "Vessel 1: +" . round($draughtChange1, 2) . "m, Vessel 2: " . round($draughtChange2, 2) . "m"
                ];
            } elseif ($draughtChange1 < 0 && $draughtChange2 > 0) {
                // Vessel1 decreased draught, Vessel2 increased - Vessel1 discharging, Vessel2 loading
                return [
                    'signal' => 'Likely Discharge',
                    'confidence' => $this->calculateTransferSignalConfidence($draughtDifference1, $draughtDifference2),
                    'details' => "Vessel 1: " . round($draughtChange1, 2) . "m, Vessel 2: +" . round($draughtChange2, 2) . "m"
                ];
            } elseif ($draughtChange1 > 0 && $draughtChange2 > 0) {
                // Both increased draught - inconclusive
                return [
                    'signal' => 'Inconclusive',
                    'confidence' => $this->calculateTransferSignalConfidence($draughtDifference1, $draughtDifference2, false),
                    'details' => "Both vessels increased draught"
                ];
            } elseif ($draughtChange1 < 0 && $draughtChange2 < 0) {
                // Both decreased draught - inconclusive
                return [
                    'signal' => 'Inconclusive',
                    'confidence' => $this->calculateTransferSignalConfidence($draughtDifference1, $draughtDifference2, false),
                    'details' => "Both vessels decreased draught"
                ];
            }
        }
        
        // No significant draught change
        return [
            'signal' => 'Inconclusive',
            'confidence' => 'Low',
            'details' => 'No significant draught changes detected'
        ];
    }

    /**
     * Calculate transfer signal confidence
     */
    private function calculateTransferSignalConfidence($draughtDiff1, $draughtDiff2, $clearSignal = true) {
        $avgChange = ($draughtDiff1 + $draughtDiff2) / 2;
        
        if ($clearSignal) {
            if ($avgChange >= 2.0) return 'High';
            if ($avgChange >= 1.0) return 'Medium';
            return 'Low';
        } else {
            // For inconclusive signals, confidence is generally lower
            if ($avgChange >= 2.0) return 'Medium';
            if ($avgChange >= 1.0) return 'Low';
            return 'Low';
        }
    }
    private function calculateStationaryHours($history1, $history2, $startDate = null, $endDate = null) {
        $stationaryPeriods = [];
        
        // Filter data points within the specified time range
        if ($startDate && $endDate) {
            $history1 = array_filter($history1, function($point) use ($startDate, $endDate) {
                $pointTime = strtotime($point['last_position_UTC'] ?? 0);
                return $pointTime >= strtotime($startDate) && $pointTime <= strtotime($endDate);
            });
            
            $history2 = array_filter($history2, function($point) use ($startDate, $endDate) {
                $pointTime = strtotime($point['last_position_UTC'] ?? 0);
                return $pointTime >= strtotime($startDate) && $pointTime <= strtotime($endDate);
            });
        }
        
        // Sort by timestamp
        usort($history1, function($a, $b) {
            return strtotime($a['last_position_UTC'] ?? 0) <=> strtotime($b['last_position_UTC'] ?? 0);
        });
        
        usort($history2, function($a, $b) {
            return strtotime($a['last_position_UTC'] ?? 0) <=> strtotime($b['last_position_UTC'] ?? 0);
        });
        
        // Group timestamps where both vessels are stationary
        $stationaryStart = null;
        $stationaryDuration = 0;
        
        $minPoints = min(count($history1), count($history2));
        
        for ($i = 0; $i < $minPoints; $i++) {
            $point1 = $history1[$i];
            $point2 = $history2[$i];
            
            // Check if both vessels are stationary
            $isStationary1 = $this->isStationary($point1['speed'] ?? 0);
            $isStationary2 = $this->isStationary($point2['speed'] ?? 0);
            
            if ($isStationary1 && $isStationary2) {
                if ($stationaryStart === null) {
                    $stationaryStart = strtotime($point1['last_position_UTC']);
                }
            } else {
                if ($stationaryStart !== null) {
                    $stationaryEnd = strtotime($point1['last_position_UTC']);
                    $duration = ($stationaryEnd - $stationaryStart) / 3600; // Convert to hours
                    
                    if ($duration >= 0.5) { // Consider periods of at least 30 minutes
                        $stationaryPeriods[] = [
                            'start' => date('Y-m-d H:i:s', $stationaryStart),
                            'end' => date('Y-m-d H:i:s', $stationaryEnd),
                            'duration_hours' => round($duration, 2)
                        ];
                    }
                    
                    $stationaryStart = null;
                }
            }
        }
        
        // If still stationary at the end
        if ($stationaryStart !== null && $minPoints > 0) {
            $lastPoint = $history1[$minPoints - 1];
            $stationaryEnd = strtotime($lastPoint['last_position_UTC']);
            $duration = ($stationaryEnd - $stationaryStart) / 3600;
            
            if ($duration >= 0.5) {
                $stationaryPeriods[] = [
                    'start' => date('Y-m-d H:i:s', $stationaryStart),
                    'end' => date('Y-m-d H:i:s', $stationaryEnd),
                    'duration_hours' => round($duration, 2)
                ];
            }
        }
        
        // Calculate total stationary hours
        $totalStationaryHours = array_sum(array_column($stationaryPeriods, 'duration_hours'));
        
        return [
            'total_stationary_hours' => $totalStationaryHours,
            'stationary_periods' => $stationaryPeriods,
            'period_count' => count($stationaryPeriods)
        ];
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
            'start_date' => empty($analysis['start_date'])?date('Y-m-d H:i:s'):$analysis['start_date'],
            'distance' => $analysis['distance'],
            'end_date' => $analysis['end_date'],
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