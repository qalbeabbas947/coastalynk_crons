<?php



class STSTransferDetector {
    private $apiKey;
    private $baseUrl = 'https://api.datalastic.com/api/v0';
    
    // Constants for AIS gap tolerance (in minutes)
    private const AIS_GAP_TOLERANCE_MINUTES = 30;
    private const EVENT_WINDOW_HOURS = 6; // Window to analyze for AIS continuity

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
            echo '<pre>';print_r($stsReport);echo '</pre>';
            return $stsReport;
            
        } catch (Exception $e) {
            return [
                'error' => $e->getMessage(),
                'timestamp' => date('c')
            ];
        }
    }
   
    /**
     * Calculate confidence level based on evidence
     * Enum: High, Medium, Low
     * Logic:
     * - High = no AIS gaps + sustained proximity
     * - Medium = minor AIS gaps OR weak draught data
     * - Low = significant AIS gaps OR incomplete proximity window
     */
    private function calculateConfidenceString($analysis) {
        $aisContinuity1 = $analysis['ais_continuity_v1'];
        $aisContinuity2 = $analysis['ais_continuity_v2'];
        $proximitySignal = $analysis['proximity_signal'];
        $draughtEvidence = $analysis['draught_evidence'] ?? 'AIS-Limited';
        
        // Check for High confidence conditions
        $noAISGaps = ($aisContinuity1 === 'Good' && $aisContinuity2 === 'Good');
        $sustainedProximity = ($proximitySignal === 'Sustained');
        
        if ($noAISGaps && $sustainedProximity) {
            return 'High';
        }
        
        // Check for Low confidence conditions
        $significantAISGaps = ($aisContinuity1 === 'Limited' || $aisContinuity2 === 'Limited');
        $incompleteProximity = ($proximitySignal === 'Interrupted');
        
        if ($significantAISGaps || $incompleteProximity) {
            return 'Low';
        }
        
        // Check for Medium confidence conditions
        $minorAISGaps = ($aisContinuity1 === 'Intermittent' || $aisContinuity2 === 'Intermittent');
        $weakProximity = ($proximitySignal === 'Weak');
        $weakDraughtData = ($draughtEvidence === 'AIS-Limited');
        
        if ($minorAISGaps || $weakProximity || $weakDraughtData) {
            return 'Medium';
        }
        
        // Default to Medium if no specific conditions met
        return 'Medium';
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
     * 2.1 AIS Continuity Assessment
     * Values: Good, Intermittent, Limited
     * Logic:
     * - Good → no AIS gaps during event window
     * - Intermittent → gaps below tolerance (≤ 30 min)
     * - Limited → gaps exceed tolerance (> 30 min)
     */
    private function assessAISContinuity($positions, $startTime = null, $endTime = null) {
        if (empty($positions)) {
            return 'Limited'; // No data = limited continuity
        }
        
        // Sort positions by timestamp
        usort($positions, function($a, $b) {
            $timeA = strtotime($a['last_position_UTC'] ?? $a['last_position_epoch'] ?? 0);
            $timeB = strtotime($b['last_position_UTC'] ?? $b['last_position_epoch'] ?? 0);
            return $timeA <=> $timeB;
        });
        
        // If no time window specified, analyze the entire dataset
        if (!$startTime || !$endTime) {
            $startTime = strtotime($positions[0]['last_position_UTC'] ?? $positions[0]['last_position_epoch'] ?? 'now');
            $endTime = strtotime(end($positions)['last_position_UTC'] ?? end($positions)['last_position_epoch'] ?? 'now');
        } else {
            $startTime = strtotime($startTime);
            $endTime = strtotime($endTime);
        }
        
        $eventWindowSeconds = $endTime - $startTime;
        $maxGapSeconds = self::AIS_GAP_TOLERANCE_MINUTES * 60;
        
        // Find gaps in AIS data
        $totalGapSeconds = 0;
        $maxSingleGap = 0;
        
        for ($i = 0; $i < count($positions) - 1; $i++) {
            $currentTime = strtotime($positions[$i]['last_position_UTC'] ?? $positions[$i]['last_position_epoch'] ?? 0);
            $nextTime = strtotime($positions[$i + 1]['last_position_UTC'] ?? $positions[$i + 1]['last_position_epoch'] ?? 0);
            
            $gap = $nextTime - $currentTime;
            
            // Consider gaps only if they're significant (> 5 minutes)
            if ($gap > 300) { // 5 minutes in seconds
                $totalGapSeconds += $gap;
                $maxSingleGap = max($maxSingleGap, $gap);
            }
        }
        
        // Calculate coverage percentage
        $coverageSeconds = $eventWindowSeconds - $totalGapSeconds;
        $coveragePercentage = ($eventWindowSeconds > 0) ? ($coverageSeconds / $eventWindowSeconds) * 100 : 0;
        
        // Determine AIS Continuity value
        if ($coveragePercentage >= 95 && $maxSingleGap <= $maxGapSeconds) {
            return 'Good';
        } elseif ($coveragePercentage >= 80 && $maxSingleGap <= $maxGapSeconds * 2) {
            return 'Intermittent';
        } else {
            return 'Limited';
        }
    }
    
    /**
     * 2.3 Proximity Signal Assessment
     * Values: Sustained, Weak, Interrupted
     * Logic:
     * - Sustained → continuous proximity above threshold
     * - Weak → proximity present but inconsistent
     * - Interrupted → proximity broken by AIS gaps
     */
    private function assessProximitySignal($vessel1Positions, $vessel2Positions, $startTime = null, $endTime = null) {
        if (empty($vessel1Positions) || empty($vessel2Positions)) {
            return 'Interrupted';
        }
        
        // Sort positions by timestamp
        usort($vessel1Positions, function($a, $b) {
            $timeA = strtotime($a['last_position_UTC'] ?? $a['last_position_epoch'] ?? 0);
            $timeB = strtotime($b['last_position_UTC'] ?? $b['last_position_epoch'] ?? 0);
            return $timeA <=> $timeB;
        });
        
        usort($vessel2Positions, function($a, $b) {
            $timeA = strtotime($a['last_position_UTC'] ?? $a['last_position_epoch'] ?? 0);
            $timeB = strtotime($b['last_position_UTC'] ?? $b['last_position_epoch'] ?? 0);
            return $timeA <=> $timeB;
        });
        
        // Create time-indexed arrays for easier comparison
        $v1ByTime = [];
        foreach ($vessel1Positions as $pos) {
            $time = strtotime($pos['last_position_UTC'] ?? $pos['last_position_epoch'] ?? 0);
            $v1ByTime[$time] = $pos;
        }
        
        $v2ByTime = [];
        foreach ($vessel2Positions as $pos) {
            $time = strtotime($pos['last_position_UTC'] ?? $pos['last_position_epoch'] ?? 0);
            $v2ByTime[$time] = $pos;
        }
        
        // Get common timestamps (within 5 minutes tolerance)
        $commonTimes = [];
        foreach ($v1ByTime as $time1 => $pos1) {
            foreach ($v2ByTime as $time2 => $pos2) {
                if (abs($time1 - $time2) <= 300) { // 5 minutes tolerance
                    $commonTime = min($time1, $time2);
                    if (!in_array($commonTime, $commonTimes)) {
                        $commonTimes[] = $commonTime;
                    }
                }
            }
        }
        
        sort($commonTimes);
        
        if (empty($commonTimes)) {
            return 'Interrupted';
        }
        
        // Analyze proximity consistency
        $proximityThresholdNM = ALLOWED_STS_RANGE_NM;
        $proximityEvents = 0;
        $totalComparisons = 0;
        $proximityStreak = 0;
        $maxProximityStreak = 0;
        $gapDetected = false;
        
        foreach ($commonTimes as $index => $time) {
            if (isset($v1ByTime[$time]) && isset($v2ByTime[$time])) {
                $pos1 = $v1ByTime[$time];
                $pos2 = $v2ByTime[$time];
                if( !isset($pos1['lat']) || !isset($pos1['lon']) || !isset($pos2['lat']) || !isset($pos2['lon']) ){
                    continue;
                }
                $distance = $this->calculateDistanceNM(
                    $pos1['lat'], $pos1['lon'],
                    $pos2['lat'], $pos2['lon']
                );
                
                $totalComparisons++;
                
                if ($distance <= $proximityThresholdNM) {
                    $proximityEvents++;
                    $proximityStreak++;
                    $maxProximityStreak = max($maxProximityStreak, $proximityStreak);
                } else {
                    $proximityStreak = 0;
                }
                
                // Check for gaps between consecutive common times
                if ($index > 0) {
                    $timeGap = $time - $commonTimes[$index - 1];
                    if ($timeGap > self::AIS_GAP_TOLERANCE_MINUTES * 60) {
                        $gapDetected = true;
                    }
                }
            }
        }
        
        // Calculate proximity percentage
        $proximityPercentage = ($totalComparisons > 0) ? ($proximityEvents / $totalComparisons) * 100 : 0;
        
        // Determine Proximity Signal value
        if ($proximityPercentage >= 90 && $maxProximityStreak >= 10 && !$gapDetected) {
            return 'Sustained';
        } elseif ($proximityPercentage >= 50 && $maxProximityStreak >= 3) {
            return 'Weak';
        } else {
            return 'Interrupted';
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
            'start_date' => '',
            'end_date' => '', 
            'distance' => '',
            'proximity_consistency' => 0,
            'data_points_analyzed' => min(count($history1), count($history2)),
            'sts_detected' => false,
            'stationary_periods' => [],
            'ais_continuity_v1' => 'Limited',
            'ais_continuity_v2' => 'Limited',
            'proximity_signal' => 'Interrupted',
            'draught_evidence' => 'AIS-Limited' // Initialize with default
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

            // Assess AIS Continuity for both vessels
            $analysis['ais_continuity_v1'] = $this->assessAISContinuity($history1, $analysis['start_date'], $analysis['end_date']);
            $analysis['ais_continuity_v2'] = $this->assessAISContinuity($history2, $analysis['start_date'], $analysis['end_date']);
            
            // Assess Proximity Signal
            $analysis['proximity_signal'] = $this->assessProximitySignal(
                $history1, 
                $history2, 
                $analysis['start_date'], 
                $analysis['end_date']
            );
            
            // Detect STS
            $analysis['sts_detected'] = (
                $analysis['current_distance_nm'] <= ALLOWED_STS_RANGE_NM &&
                $analysis['stationary_hours'] >= 6 &&
                $analysis['proximity_consistency'] >= 0.7
            );
        }
        
        
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
                    'details' => "Vessel 1: +" . round($draughtChange1, 2) . "m, Vessel 2: " . round($draughtChange2, 2) . "m",
                    'initial_draughts' => [
                        'vessel1' => round($initialDraught1, 2),
                        'vessel2' => round($initialDraught2, 2)
                    ],
                    'current_draughts' => [
                        'vessel1' => round($currentDraught1, 2),
                        'vessel2' => round($currentDraught2, 2)
                    ]
                ];
            } elseif ($draughtChange1 < 0 && $draughtChange2 > 0) {
                // Vessel1 decreased draught, Vessel2 increased - Vessel1 discharging, Vessel2 loading
                return [
                    'signal' => 'Likely Discharge',
                    'confidence' => $this->calculateTransferSignalConfidence($draughtDifference1, $draughtDifference2),
                    'details' => "Vessel 1: " . round($draughtChange1, 2) . "m, Vessel 2: +" . round($draughtChange2, 2) . "m",
                    'initial_draughts' => [
                        'vessel1' => round($initialDraught1, 2),
                        'vessel2' => round($initialDraught2, 2)
                    ],
                    'current_draughts' => [
                        'vessel1' => round($currentDraught1, 2),
                        'vessel2' => round($currentDraught2, 2)
                    ]
                ];
            } elseif ($draughtChange1 > 0 && $draughtChange2 > 0) {
                // Both increased draught - inconclusive
                return [
                    'signal' => 'Inconclusive',
                    'confidence' => $this->calculateTransferSignalConfidence($draughtDifference1, $draughtDifference2, false),
                    'details' => "Both vessels increased draught",
                    'initial_draughts' => [
                        'vessel1' => round($initialDraught1, 2),
                        'vessel2' => round($initialDraught2, 2)
                    ],
                    'current_draughts' => [
                        'vessel1' => round($currentDraught1, 2),
                        'vessel2' => round($currentDraught2, 2)
                    ]
                ];
            } elseif ($draughtChange1 < 0 && $draughtChange2 < 0) {
                // Both decreased draught - inconclusive
                return [
                    'signal' => 'Inconclusive',
                    'confidence' => $this->calculateTransferSignalConfidence($draughtDifference1, $draughtDifference2, false),
                    'details' => "Both vessels decreased draught",
                    'initial_draughts' => [
                        'vessel1' => round($initialDraught1, 2),
                        'vessel2' => round($initialDraught2, 2)
                    ],
                    'current_draughts' => [
                        'vessel1' => round($currentDraught1, 2),
                        'vessel2' => round($currentDraught2, 2)
                    ]
                ];
            }
        }
        
        // No significant draught change
        return [
            'signal' => 'Inconclusive',
            'confidence' => 'Low',
            'details' => 'No significant draught changes detected',
            'initial_draughts' => [
                'vessel1' => round($initialDraught1, 2),
                'vessel2' => round($initialDraught2, 2)
            ],
            'current_draughts' => [
                'vessel1' => round($currentDraught1, 2),
                'vessel2' => round($currentDraught2, 2)
            ]
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
     * 2.4 Draught Evidence Assessment
     * Values: Available, AIS-Limited
     * Logic:
     * - Available → Both before/after draught values exist
     * - AIS-Limited → Missing either before or after draught values
     */
    private function assessDraughtEvidence($vessel1Data, $vessel2Data) {
        // Try to get current draught values
        $currentDraught1 = $vessel1Data['draught'] ?? 0;
        $currentDraught2 = $vessel2Data['draught'] ?? 0;
        
        // Get initial draught values from historical data (6 hours ago)
        $initialDraught1 = $this->getInitialDraught($vessel1Data['mmsi'] ?? null);
        $initialDraught2 = $this->getInitialDraught($vessel2Data['mmsi'] ?? null);
        
        // Check if we have both before and after values for either vessel
        $vessel1Evidence = ($initialDraught1 > 0 && $currentDraught1 > 0);
        $vessel2Evidence = ($initialDraught2 > 0 && $currentDraught2 > 0);
        
        if ($vessel1Evidence || $vessel2Evidence) {
            return 'Available';
        }
        
        return 'AIS-Limited';
    }

    /**
     * Get initial draught value from 6 hours ago
     */
    private function getInitialDraught($mmsi) {
        if (!$mmsi) {
            return 0;
        }
        
        // Get historical data from 6 hours ago
        $endpoint = $this->baseUrl . '/vessel_history';
        $params = [
            'api-key' => $this->apiKey,
            'mmsi' => $mmsi,
            'from' => date('Y-m-d\TH:i:s\Z', strtotime('-6 hours')),
            'to' => date('Y-m-d\TH:i:s\Z', strtotime('-5 hours')), // 1-hour window
            'limit' => 1
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
            if (!empty($data['data'][0]['draught'])) {
                return floatval($data['data'][0]['draught']);
            }
        }
        
        return 0;
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

        $confidenceString = $this->calculateConfidenceString($analysis);
        
        // Assess Draught Evidence
        $draughtEvidence = $this->assessDraughtEvidence($vessel1, $vessel2);
        
        // Get transfer signal if draught evidence is available
        $transferSignal = null;
        if ($draughtEvidence === 'Available') {
            $initialDraught1 = $this->getInitialDraught($vessel1['mmsi'] ?? null);
            $initialDraught2 = $this->getInitialDraught($vessel2['mmsi'] ?? null);
            $transferSignal = $this->calculateTransferSignal($vessel1, $vessel2, $initialDraught1, $initialDraught2);
        }

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
                'vessel_owner' => $vesselOwner1,
                'ais_continuity' => $analysis['ais_continuity_v1']
            ],
            'vessel_2' => [
                'name' => $vessel2['name'] ?? 'Unknown',
                'mmsi' => $vessel2['mmsi'] ?? 'Unknown',
                'type' => $vessel2['type'] ?? 'Unknown',
                'predicted_cargo' => $cargoType2,
                'current_speed' => $vessel2['speed'] ?? 0,
                'vessel_condition' => $vesselCondition2,
                'cargo_eta' => $cargoETA2,
                'vessel_owner' => $vesselOwner2,
                'ais_continuity' => $analysis['ais_continuity_v2']
            ],
            'proximity_analysis' => [
                'current_distance_nm' => round($analysis['current_distance_nm'], 3),
                'stationary_duration_hours' => $analysis['stationary_hours'],
                'proximity_consistency' => number_format(round($analysis['proximity_consistency'] * 100, 1), 2,'.', '') . '%',
                'data_points_analyzed' => $analysis['data_points_analyzed'],
                'proximity_signal' => $analysis['proximity_signal']
            ],
            'evidence_assessment' => [
                'ais_continuity_v1' => $analysis['ais_continuity_v1'],
                'ais_continuity_v2' => $analysis['ais_continuity_v2'],
                'proximity_signal' => $analysis['proximity_signal'],
                'draught_evidence' => $draughtEvidence
            ],
            'transfer_signal' => $transferSignal,
            'risk_assessment' => [
                'risk_level' => $riskLevel,
                'confidence' => number_format($confidence, 2,'.', ''),
                'confidence_string' => $confidenceString,
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

        // Add AIS continuity remarks
        if ($analysis['ais_continuity_v1'] === 'Good' && $analysis['ais_continuity_v2'] === 'Good') {
            $remarks[] = "Both vessels have good AIS continuity";
        } elseif ($analysis['ais_continuity_v1'] === 'Limited' || $analysis['ais_continuity_v2'] === 'Limited') {
            $remarks[] = "Limited AIS continuity may affect accuracy";
        }
        
        // Add proximity signal remarks
        if ($analysis['proximity_signal'] === 'Sustained') {
            $remarks[] = "Sustained proximity signal detected";
        } elseif ($analysis['proximity_signal'] === 'Interrupted') {
            $remarks[] = "Proximity signal interrupted - possible AIS gaps";
        }

        // Add confidence-based remarks
        $confidence = $this->calculateConfidenceString($analysis);
        switch ($confidence) {
            case 'High':
                $remarks[] = "High confidence due to continuous AIS data and sustained proximity";
                break;
            case 'Medium':
                $remarks[] = "Medium confidence due to intermittent AIS data or weak evidence";
                break;
            case 'Low':
                $remarks[] = "Low confidence due to significant AIS gaps or interrupted proximity";
                break;
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