<?php

class STSTransferDetector {
    private $apiKey;
    private $baseUrl = 'https://api.datalastic.com/api/v0';
    
    // Constants for AIS gap tolerance (in minutes)
    private const AIS_GAP_TOLERANCE_MINUTES = 30;
    private const EVENT_WINDOW_HOURS = 6; // Window to analyze for AIS continuity
    
    // New constants for Nigerian STS calibration
    private const PROXIMITY_THRESHOLD_KM = 1; // < 1 km sustained
    private const PROXIMITY_THRESHOLD_NM = 0.54; // ~1 km in nautical miles
    private const HIGH_CONFIDENCE_DURATION_HOURS = 2; // ≥ 2 hours for High confidence
    private const MODERATE_CONFIDENCE_DURATION_MINUTES = 30; // Minimum for Moderate confidence
    private const SPEED_THRESHOLD_KNOTS = 1; // 0-1 knots for both vessels
    private const HEADING_VARIANCE_THRESHOLD = 15; // < 15 degrees
    private const POSITIONAL_DRIFT_THRESHOLD_M = 200; // < 200m from median position
    private const AIS_GAP_NO_PENALTY_MINUTES = 20; // < 20 min - no penalty
    private const AIS_GAP_MILD_PENALTY_MINUTES = 60; // 20-60 min - mild penalty if inconsistent
    private const PARALLEL_ALIGNMENT_MINUTES = 10; // ≥ 10 minutes for parallel alignment
    private const STABLE_INTERVAL_MINUTES = 15; // ≥ 15 minutes for stable interaction

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
        $ch = curl_init();
        
        $filter = "posDt BETWEEN '" . date('Y-m-d H:i:s', strtotime("-$hours hours")) . "' AND '" . date('Y-m-d H:i:s') . "' and mmsi=" . $mmsi;
        
        curl_setopt_array($ch, [
            CURLOPT_URL            => "https://api.kpler.com/v2/maritime/ais-historical?filter=" . urlencode($filter),
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
                'vesselUid'=> $properties['vesselUid'] ?? null, 
                'vesselName'=> $properties['vesselName'] ?? null, 
                'navigation_status'=> $properties['navStatus'] ?? null, 
                'sog'=> $properties['sog'] ?? null, 
                'dwt'=> $properties['dwt'] ?? null, 
                'mmsi'=> $properties['mmsi'] ?? null, 
                'length'=> $properties['length'] ?? null, 
                'imo'=> $properties['imo'] ?? null, 
                'longitude'=> $properties['longitude'] ?? null, 
                'latitude'=> $properties['latitude'] ?? null,  
                'cog'=> $properties['cog'] ?? null, 
                'rot'=> $properties['rot'] ?? null,  
                'heading'=> $properties['heading'] ?? null, 
                'navStatus'=> $properties['navStatus'] ?? null,  
                'posMsgType'=> $properties['posMsgType'] ?? null,  
                'posSrc'=> $properties['posSrc'] ?? null,  
                'callsign'=> $properties['callsign'] ?? null,  
                'flag'=> $properties['flag'] ?? null,  
                'vesselTypeAis'=> $properties['vesselTypeAis'] ?? null,  
                'vesselType'=> $properties['vesselType'] ?? null,  
                'width'=> $properties['width'] ?? null, 
                'grt'=> $properties['grt'] ?? null,  
                'destination'=> $properties['destination'] ?? null, 
                'eta'=> $properties['eta'] ?? null,  
                'draught'=> $properties['draught'] ?? null,  
                'staticMsgType'=> $properties['staticMsgType'] ?? null,  
                'staticSrc'=> $properties['staticSrc'] ?? null,  
                'timestamp' => $properties['posDt'] ?? null, 
                'posDt'=> $properties['posDt'] ?? null,
                'posDt_time'=> strtotime($properties['posDt']) ?? 0
            ];
        }
        
        // Sort by timestamp
        usort($positions, function($a, $b) {
            return strtotime($a['timestamp']) <=> strtotime($b['timestamp']);
        });
       
        return $positions;

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
        // Duration factor
        if ($stationaryHours >= 6) return 'HIGH';
        elseif ($stationaryHours >= 4) return 'MEDIUM';
        else return 'LOW';
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
        $speed = $vesselData['sog'] ?? 0;
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

            // Get historical data (last 24 hours)
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
     * Calculate confidence level based on evidence (Calibrated for Nigerian waters)
     * Values: High, Moderate, Low (display) / high, medium, low (internal)
     * 
     * Logic:
     * - Step 1: Evaluate core behavioural conditions (distance, speed, duration, heading)
     * - Step 2: If core conditions met → assign provisional Medium confidence
     * - Step 3: Check supporting signals (draught, zone, pattern) → one or more upgrades to High
     * - Step 4: Evaluate AIS gap severity → downgrade only if gaps break pattern confirmation
     * - Step 5: Assign final confidence tier
     * 
     * Safeguard: Low confidence only if core behavioural conditions are not met
     */
    private function calculateConfidenceString($analysis) {
        // Step 1: Evaluate core behavioural conditions
        $coreConditionsMet = $this->evaluateCoreConditions($analysis);
        
        if (!$coreConditionsMet) {
            return 'Low'; // Core conditions not met → Low confidence
        }
        
        // Step 2: Core conditions met → provisional Medium
        $provisionalConfidence = 'Medium';
        
        // Step 3: Check for supporting signals
        $supportingSignals = $this->countSupportingSignals($analysis);
        
        // Special case: dark_event_during_interaction counts as two signals
        if (!empty($analysis['dark_event_during_interaction']) && $analysis['dark_event_during_interaction'] === true) {
            $supportingSignals += 2;
        }
        
        // Upgrade to High if at least one supporting signal present
        if ($supportingSignals >= 1) {
            $provisionalConfidence = 'High';
        }
        
        // Step 4: Evaluate AIS gap severity (downgrade only if gaps break pattern)
        $finalConfidence = $this->applyAISGapPenalty($provisionalConfidence, $analysis);
        
        return $finalConfidence;
    }
    
    /**
     * Evaluate core behavioural conditions for STS
     */
    private function evaluateCoreConditions($analysis) {
        // Check distance threshold (< 1 km sustained)
        $distanceThresholdMet = $analysis['current_distance_nm'] <= self::PROXIMITY_THRESHOLD_NM;
        
        // Check speed threshold (both vessels 0-1 knots, time-averaged)
        $speedThresholdMet = $this->checkSpeedThreshold($analysis);
        
        // Check duration threshold (≥ 2 hours for High, ≥ 30 min for Moderate)
        // For core conditions evaluation, we check if duration meets minimum for Moderate
        $durationMinutes = $analysis['stationary_hours'] * 60;
        $durationMet = $durationMinutes >= self::MODERATE_CONFIDENCE_DURATION_MINUTES;
        
        // Check heading stability
        $headingStabilityMet = $this->checkHeadingStability($analysis);
        
        // Core conditions require distance, speed, and either duration or heading stability
        return $distanceThresholdMet && $speedThresholdMet && ($durationMet || $headingStabilityMet);
    }
    
    /**
     * Check speed threshold (time-averaged 0-1 knots for both vessels)
     */
    private function checkSpeedThreshold($analysis) {
        if (empty($analysis['vessel1_speeds']) || empty($analysis['vessel2_speeds'])) {
            // Use stationary_hours as proxy if detailed speed data not available
            return $analysis['stationary_hours'] > 0;
        }
        
        $avgSpeed1 = array_sum($analysis['vessel1_speeds']) / count($analysis['vessel1_speeds']);
        $avgSpeed2 = array_sum($analysis['vessel2_speeds']) / count($analysis['vessel2_speeds']);
        
        // Allow brief manoeuvring (transient 1-3 kn tolerated on one vessel)
        $transientTolerance = 3.0;
        
        return ($avgSpeed1 <= self::SPEED_THRESHOLD_KNOTS || $avgSpeed1 <= $transientTolerance) &&
               ($avgSpeed2 <= self::SPEED_THRESHOLD_KNOTS || $avgSpeed2 <= $transientTolerance);
    }
    
    /**
     * Check heading stability (< 15 degrees variance)
     */
    private function checkHeadingStability($analysis) {
        if (empty($analysis['vessel1_headings']) || empty($analysis['vessel2_headings'])) {
            return true; // Can't assess, assume stable
        }
        
        $variance1 = $this->calculateHeadingVariance($analysis['vessel1_headings']);
        $variance2 = $this->calculateHeadingVariance($analysis['vessel2_headings']);
        
        return $variance1 <= self::HEADING_VARIANCE_THRESHOLD && 
               $variance2 <= self::HEADING_VARIANCE_THRESHOLD;
    }
    
    /**
     * Calculate heading variance (simplified)
     */
    private function calculateHeadingVariance($headings) {
        if (empty($headings)) {
            return 0;
        }
        
        $mean = array_sum($headings) / count($headings);
        $variance = 0;
        
        foreach ($headings as $heading) {
            $variance += pow($heading - $mean, 2);
        }
        
        return sqrt($variance / count($headings));
    }
    
    /**
     * Count supporting signals for confidence upgrade
     */
    private function countSupportingSignals($analysis) {
        $signals = 0;
        
        // Draught change present (if available) - confirming signal
        if (!empty($analysis['draught_change_detected']) && $analysis['draught_change_detected']) {
            $signals++;
        }
        
        // Zone overlap (STS zone or designated anchorage)
        if (!empty($analysis['in_sts_zone']) && $analysis['in_sts_zone']) {
            $signals++;
        }
        
        // Vessel type supports STS (tanker-to-tanker, FSO, FSU, shuttle)
        if (!empty($analysis['vessel_types_support_sts']) && $analysis['vessel_types_support_sts']) {
            $signals++;
        }
        
        // Parallel/loitering pattern
        if (!empty($analysis['parallel_pattern_detected']) && $analysis['parallel_pattern_detected']) {
            $signals++;
        }
        
        // Historical STS behaviour
        if (!empty($analysis['historical_sts']) && $analysis['historical_sts']) {
            $signals++;
        }
        
        // Dark in STS zone (if available from external source)
        if (!empty($analysis['dark_in_sts_zone']) && $analysis['dark_in_sts_zone']) {
            $signals++;
        }
        
        return $signals;
    }
    
    /**
     * Apply AIS gap penalty (only if gaps break pattern confirmation)
     */
    private function applyAISGapPenalty($currentConfidence, $analysis) {
        $maxGapMinutes = $analysis['max_ais_gap_minutes'] ?? 0;
        
        // No penalty for minor gaps (< 20 min)
        if ($maxGapMinutes < self::AIS_GAP_NO_PENALTY_MINUTES) {
            return $currentConfidence;
        }
        
        // Mild penalty for moderate gaps (20-60 min) if pre/post tracks inconsistent
        if ($maxGapMinutes >= self::AIS_GAP_NO_PENALTY_MINUTES && 
            $maxGapMinutes < self::AIS_GAP_MILD_PENALTY_MINUTES) {
            
            // Check if pre-gap and post-gap tracks are consistent
            if (!$this->areTracksConsistent($analysis)) {
                // Downgrade only if tracks are inconsistent
                return ($currentConfidence === 'High') ? 'Medium' : $currentConfidence;
            }
            return $currentConfidence; // No penalty if tracks consistent
        }
        
        // Large gaps (> 60 min) - downgrade to Low if pattern cannot be reconstructed
        if ($maxGapMinutes >= self::AIS_GAP_MILD_PENALTY_MINUTES) {
            if (!$this->canReconstructPattern($analysis)) {
                return 'Low'; // Can't reconstruct → Low confidence
            }
            // If can reconstruct, maintain current confidence (might downgrade High→Medium)
            return ($currentConfidence === 'High') ? 'Medium' : $currentConfidence;
        }
        
        return $currentConfidence;
    }
    
    /**
     * Check if pre-gap and post-gap tracks are consistent
     */
    private function areTracksConsistent($analysis) {
        // Check if pre-gap and post-gap positions exist and are consistent
        if (empty($analysis['pre_gap_positions']) || empty($analysis['post_gap_positions'])) {
            return false;
        }
        
        // Verify positions before and after gap are within proximity threshold
        $preDistance = $analysis['pre_gap_distance'] ?? PHP_INT_MAX;
        $postDistance = $analysis['post_gap_distance'] ?? PHP_INT_MAX;
        
        return $preDistance <= self::PROXIMITY_THRESHOLD_NM && 
               $postDistance <= self::PROXIMITY_THRESHOLD_NM;
    }
    
    /**
     * Check if pattern can be reconstructed across gap
     */
    private function canReconstructPattern($analysis) {
        // Check if we can interpolate behaviour through gap
        return $this->areTracksConsistent($analysis) && 
               ($analysis['position_drift_m'] ?? 0) <= self::POSITIONAL_DRIFT_THRESHOLD_M &&
               $this->checkHeadingStability($analysis);
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
            return strtotime($b['posDt'] ?? 0) <=> strtotime($a['posDt'] ?? 0);
        });
        
        usort($history2, function($a, $b) {
            return strtotime($b['posDt'] ?? 0) <=> strtotime($a['posDt'] ?? 0);
        });
        
        // Check for movement or distance increase
        for ($i = 0; $i < min(count($history1), count($history2)) - 1; $i++) {
            $current1 = $history1[$i];
            $current2 = $history2[$i];
            $next1 = $history1[$i + 1];
            $next2 = $history2[$i + 1];
            
            $currentDistance = $this->calculateDistanceNM(
                $current1['latitude'], $current1['longitude'],
                $current2['latitude'], $current2['longitude']
            );
            
            $nextDistance = $this->calculateDistanceNM(
                $next1['latitude'], $next1['longitude'],
                $next2['latitude'], $next2['longitude']
            );
            
            // Check if vessels are moving apart
            if ($nextDistance > $currentDistance + 0.1) { // Increased by more than 0.1 NM
                $endTime = $next1['posDt'];
                break;
            }
            
            // Check if either vessel starts moving
            $currentSpeedAvg = (($current1['sog'] ?? 0) + ($current2['sog'] ?? 0)) / 2;
            $nextSpeedAvg = (($next1['sog'] ?? 0) + ($next2['sog'] ?? 0)) / 2;
            
            if ($currentSpeedAvg < 1 && $nextSpeedAvg >= 1) {
                $endTime = $next1['posDt'];
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
     * Values: Good, Delayed, Weak, Lost/Dark
     * Logic based on signal freshness (time since last position):
     * - Good → < 10 minutes
     * - Delayed → 10–60 minutes
     * - Weak → 1–24 hours
     * - Lost/Dark → > 24 hours
     */
    private function assessAISContinuity($positions, $startTime = null, $endTime = null) {
        if (empty($positions)) {
            return 'Lost/Dark'; // No data = lost/dark
        }
        
        // Sort positions by timestamp (newest first to get latest position)
        usort($positions, function($a, $b) {
            $timeA = strtotime($a['posDt'] ?? $a['posDt_time'] ?? 0);
            $timeB = strtotime($b['posDt'] ?? $b['posDt_time'] ?? 0);
            return $timeB <=> $timeA; // Descending order (newest first)
        });
        
        // Get the most recent position timestamp
        $latestPositionTime = strtotime($positions[0]['posDt'] ?? $positions[0]['posDt_time'] ?? 0);
        
        // If no time window specified, use current time as reference
        if (!$endTime) {
            $referenceTime = time();
        } else {
            $referenceTime = strtotime($endTime);
        }
        
        // Calculate time difference in minutes
        $timeDiffMinutes = ($referenceTime - $latestPositionTime) / 60;
        
        // Determine AIS Continuity based on signal freshness
        if ($timeDiffMinutes < 10) {
            return 'Good';
        } elseif ($timeDiffMinutes >= 10 && $timeDiffMinutes < 60) {
            return 'Delayed';
        } elseif ($timeDiffMinutes >= 60 && $timeDiffMinutes < 1440) { // 1440 minutes = 24 hours
            return 'Weak';
        } else {
            return 'Lost/Dark';
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
            $timeA = strtotime($a['posDt'] ?? $a['posDt_time'] ?? 0);
            $timeB = strtotime($b['posDt'] ?? $b['posDt_time'] ?? 0);
            return $timeA <=> $timeB;
        });
        
        usort($vessel2Positions, function($a, $b) {
            $timeA = strtotime($a['posDt'] ?? $a['posDt_time'] ?? 0);
            $timeB = strtotime($b['posDt'] ?? $b['posDt_time'] ?? 0);
            return $timeA <=> $timeB;
        });
        
        // Create time-indexed arrays for easier comparison
        $v1ByTime = [];
        foreach ($vessel1Positions as $pos) {
            $time = strtotime($pos['posDt'] ?? $pos['posDt_time'] ?? 0);
            $v1ByTime[$time] = $pos;
        }
        
        $v2ByTime = [];
        foreach ($vessel2Positions as $pos) {
            $time = strtotime($pos['posDt'] ?? $pos['posDt_time'] ?? 0);
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
        $proximityThresholdNM = self::PROXIMITY_THRESHOLD_NM;
        $proximityEvents = 0;
        $totalComparisons = 0;
        $proximityStreak = 0;
        $maxProximityStreak = 0;
        $gapDetected = false;
        
        foreach ($commonTimes as $index => $time) {
            if (isset($v1ByTime[$time]) && isset($v2ByTime[$time])) {
                $pos1 = $v1ByTime[$time];
                $pos2 = $v2ByTime[$time];
                if( !isset($pos1['latitude']) || !isset($pos1['longitude']) || !isset($pos2['latitude']) || !isset($pos2['longitude']) ){
                    continue;
                }
                $distance = $this->calculateDistanceNM(
                    $pos1['latitude'], $pos1['longitude'],
                    $pos2['latitude'], $pos2['longitude']
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
                    $timeGap = ($time - $commonTimes[$index - 1]) / 60; // Convert to minutes
                    if ($timeGap > self::AIS_GAP_TOLERANCE_MINUTES) {
                        $gapDetected = true;
                    }
                }
            }
        }
        
        // Calculate proximity percentage
        $proximityPercentage = ($totalComparisons > 0) ? ($proximityEvents / $totalComparisons) * 100 : 0;
        
        // Determine Proximity Signal value (calibrated for Nigerian waters)
        if ($proximityPercentage >= 80 && $maxProximityStreak >= 5 && !$gapDetected) {
            return 'Sustained';
        } elseif ($proximityPercentage >= 50 && $maxProximityStreak >= 3) {
            return 'Weak';
        } else {
            return 'Interrupted';
        }
    }

    /**
     * Analyze vessel behavior for STS patterns (Calibrated for Nigerian waters)
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
            'ais_continuity_v1' => 'Weak',
            'ais_continuity_v2' => 'Weak',
            'proximity_signal' => 'Interrupted',
            'draught_evidence' => 'AIS-Limited',
            
            // New fields for calibrated confidence
            'vessel1_speeds' => [],
            'vessel2_speeds' => [],
            'vessel1_headings' => [],
            'vessel2_headings' => [],
            'max_ais_gap_minutes' => 0,
            'draught_change_detected' => false,
            'in_sts_zone' => false,
            'vessel_types_support_sts' => false,
            'parallel_pattern_detected' => false,
            'historical_sts' => false,
            'position_drift_m' => 0,
            'pre_gap_positions' => [],
            'post_gap_positions' => [],
            'pre_gap_distance' => null,
            'post_gap_distance' => null,
            'dark_event_during_interaction' => null,
            'dark_in_sts_zone' => false,
            
            // Timeline phase timestamps
            'proximity_start' => null,
            'slow_speed_start' => null,
            'alignment_start' => null,
            'stable_start' => null,
            'manoeuvre_start' => null,
            'separation_confirmed' => null,
            
            // Engine output fields
            'confidence_level' => 'Low',
            'transfer_signal' => 'Possible STS',
            'ais_integrity' => 'Stable',
            'interaction_window_duration' => null,
            'interaction_stability' => 'Not available'
        ];
        
        if (empty($history1) || empty($history2)) {
            return $analysis;
        }
        
        $analysis['current_distance_nm'] = $this->calculateDistanceNM(
            $vessel1['latitude'], $vessel1['longitude'],
            $vessel2['latitude'], $vessel2['longitude']
        );
        
        // Calculate stationary hours properly
        $stationaryAnalysis = $this->calculateStationaryHours(
            $history1 ?? [],
            $history2 ?? []
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
        
        // Analyze proximity consistency and collect behavioural data
        $closeProximityCount = 0;
        $totalComparisons = 0;
        $alignmentDuration = 0;
        $alignmentStartTime = null;
        
        // Track timeline phases
        $proximityEstablished = false;
        $slowSpeedConfirmed = false;
        $alignmentConfirmed = false;
        $stableConfirmed = false;
        $manoeuvreDetected = false;
        
        // Track positions for gap analysis
        $allPositions1 = [];
        $allPositions2 = [];
        
        foreach ($history1 as $point1) {
            $allPositions1[] = $point1;
            foreach ($history2 as $point2) {
                $allPositions2[] = $point2;

                if (is_array($point1) && is_array($point2)) {
                    $timeDiff = abs(strtotime($point1['posDt_time'] ?? 0) - strtotime($point2['posDt_time'] ?? 0));
                    
                    if ($timeDiff <= 600) { // 10 minutes
                        $distance = $this->calculateDistanceNM(
                            $point1['latitude'], $point1['longitude'],
                            $point2['latitude'], $point2['longitude']
                        );
                        
                        // Collect behavioural data
                        $analysis['vessel1_speeds'][] = $point1['sog'] ?? 0;
                        $analysis['vessel2_speeds'][] = $point2['sog'] ?? 0;
                        $analysis['vessel1_headings'][] = $point1['heading'] ?? 0;
                        $analysis['vessel2_headings'][] = $point2['heading'] ?? 0;
                        
                        // Track proximity
                        if ($distance <= self::PROXIMITY_THRESHOLD_NM) {
                            $closeProximityCount++;
                            
                            // Set start date if not already set
                            if (empty($analysis['start_date'])) {
                                $analysis['start_date'] = $point1['posDt'];
                                $analysis['lock_time'] = $point2['posDt_time'];
                                
                                // Timeline: Proximity established
                                if (!$proximityEstablished) {
                                    $analysis['proximity_start'] = $point1['posDt'];
                                    $proximityEstablished = true;
                                }
                            }
                            
                            // Check for slow speed
                            if (($point1['sog'] ?? 0) <= self::SPEED_THRESHOLD_KNOTS && 
                                ($point2['sog'] ?? 0) <= self::SPEED_THRESHOLD_KNOTS && 
                                !$slowSpeedConfirmed) {
                                $analysis['slow_speed_start'] = $point1['posDt'];
                                $slowSpeedConfirmed = true;
                            }
                            
                            // Check for parallel alignment
                            $headingDiff = abs(($point1['heading'] ?? 0) - ($point2['heading'] ?? 0));
                            $headingDiff = min($headingDiff, 360 - $headingDiff);
                            if ($headingDiff <= self::HEADING_VARIANCE_THRESHOLD) {
                                if ($alignmentStartTime === null) {
                                    $alignmentStartTime = strtotime($point1['posDt']);
                                } else {
                                    $alignmentDuration = (strtotime($point1['posDt']) - $alignmentStartTime) / 60;
                                    if ($alignmentDuration >= self::PARALLEL_ALIGNMENT_MINUTES && !$alignmentConfirmed) {
                                        $analysis['alignment_start'] = $point1['posDt'];
                                        $alignmentConfirmed = true;
                                        $analysis['parallel_pattern_detected'] = true;
                                    }
                                }
                            } else {
                                $alignmentStartTime = null;
                            }
                            
                            // Check for stable interaction
                            if ($proximityEstablished && $slowSpeedConfirmed && $alignmentConfirmed && !$stableConfirmed) {
                                $stableDuration = (strtotime($point1['posDt']) - strtotime($analysis['proximity_start'])) / 60;
                                if ($stableDuration >= self::STABLE_INTERVAL_MINUTES) {
                                    $analysis['stable_start'] = $analysis['proximity_start']; // From first confirmed position
                                    $stableConfirmed = true;
                                }
                            }
                        }
                        
                        $analysis['distance'] = $distance; 
                        $totalComparisons++;
                    }
                }
            }
        }
        
        // Detect manoeuvre start (after stable phase)
        if ($stableConfirmed && !$manoeuvreDetected) {
            foreach ($history1 as $point1) {
                if (strtotime($point1['posDt'] ?? 0) > strtotime($analysis['stable_start'])) {
                    if (($point1['sog'] ?? 0) > self::SPEED_THRESHOLD_KNOTS) {
                        $analysis['manoeuvre_start'] = $point1['posDt'];
                        $manoeuvreDetected = true;
                        break;
                    }
                }
            }
        }
        
        // Detect separation
        if ($stableConfirmed) {
            $separationTime = $this->detectSeparation($history1, $history2, $analysis['stable_start']);
            if ($separationTime) {
                $analysis['separation_confirmed'] = $separationTime;
            }
        }
        
        // Calculate proximity consistency
        if ($totalComparisons > 0) {
            $analysis['proximity_consistency'] = $closeProximityCount / $totalComparisons;
            if ($analysis['stationary_hours'] > ALLOWED_STS_MAX_TRANSFER_HOURS) {
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
            
            // Check supporting signals
            $analysis['draught_change_detected'] = $this->checkDraughtChange($vessel1, $vessel2);
            $analysis['in_sts_zone'] = $this->checkSTSZone($vessel1['latitude'] ?? 0, $vessel1['longitude'] ?? 0) || 
                                        $this->checkSTSZone($vessel2['latitude'] ?? 0, $vessel2['longitude'] ?? 0);
            $analysis['vessel_types_support_sts'] = $this->checkVesselTypesSupportSTS($vessel1, $vessel2);
            $analysis['historical_sts'] = $this->checkHistoricalSTS($vessel1, $vessel2);
            
            // Calculate max AIS gap
            $analysis['max_ais_gap_minutes'] = $this->calculateMaxAISGap($history1, $history2);
            
            // Calculate position drift
            $analysis['position_drift_m'] = $this->calculatePositionDrift($history1, $history2);
            
            // Get pre/post gap positions for reconstruction
            $gapInfo = $this->getGapPositionInfo($history1, $history2);
            $analysis['pre_gap_positions'] = $gapInfo['pre_gap'] ?? [];
            $analysis['post_gap_positions'] = $gapInfo['post_gap'] ?? [];
            $analysis['pre_gap_distance'] = $gapInfo['pre_gap_distance'] ?? null;
            $analysis['post_gap_distance'] = $gapInfo['post_gap_distance'] ?? null;
            
            // Check for dark event during interaction (would come from Dark Detection engine)
            // This would be populated by external data - placeholder for now
            $analysis['dark_event_during_interaction'] = null;
            $analysis['dark_in_sts_zone'] = false;
            
            // Detect STS using calibrated logic (based on core conditions, not strict criteria)
            $coreConditionsMet = $this->evaluateCoreConditions($analysis);
            $analysis['sts_detected'] = $coreConditionsMet;
            
            // Calculate confidence using calibrated logic
            $confidenceEnum = $this->calculateConfidenceString($analysis);
            $analysis['confidence_level'] = ($confidenceEnum === 'Medium') ? 'Moderate' : $confidenceEnum;
            
            // Map to transfer signal
            $transferSignalMap = [
                'High' => 'STS Confirmed',
                'Medium' => 'STS Probable',
                'Low' => 'Possible STS'
            ];
            $analysis['transfer_signal'] = $transferSignalMap[$confidenceEnum] ?? 'Possible STS';
            
            // Calculate AIS integrity
            $analysis['ais_integrity'] = $this->calculateAISIntegrity($analysis);
            
            // Calculate interaction window duration
            if ($analysis['stable_start'] && $analysis['separation_confirmed']) {
                $analysis['interaction_window_duration'] = (strtotime($analysis['separation_confirmed']) - strtotime($analysis['stable_start'])) / 60;
            }
            
            // Calculate interaction stability (UI only)
            $analysis['interaction_stability'] = $this->calculateInteractionStability($analysis);
        }
        
        return $analysis;
    }

    /**
     * Detect separation between vessels
     */
    private function detectSeparation($history1, $history2, $stableStart) {
        $stableStartTime = strtotime($stableStart);
        $separationDuration = 0;
        $separationStartTime = null;
        
        // Sort positions chronologically
        usort($history1, function($a, $b) {
            return strtotime($a['posDt'] ?? 0) <=> strtotime($b['posDt'] ?? 0);
        });
        
        usort($history2, function($a, $b) {
            return strtotime($a['posDt'] ?? 0) <=> strtotime($b['posDt'] ?? 0);
        });
        
        foreach ($history1 as $point1) {
            $pointTime = strtotime($point1['posDt'] ?? 0);
            if ($pointTime <= $stableStartTime) continue;
            
            foreach ($history2 as $point2) {
                if (abs(strtotime($point2['posDt'] ?? 0) - $pointTime) > 600) continue;
                
                $distance = $this->calculateDistanceNM(
                    $point1['latitude'], $point1['longitude'],
                    $point2['latitude'], $point2['longitude']
                );
                
                if ($distance > self::PROXIMITY_THRESHOLD_NM) {
                    if ($separationStartTime === null) {
                        $separationStartTime = $pointTime;
                    }
                    $separationDuration = ($pointTime - $separationStartTime) / 60;
                    if ($separationDuration >= 10) { // ≥ 10 continuous minutes
                        return $point1['posDt'];
                    }
                } else {
                    $separationStartTime = null;
                    $separationDuration = 0;
                }
            }
        }
        
        return null;
    }

    /**
     * Calculate maximum AIS gap in minutes
     */
    private function calculateMaxAISGap($history1, $history2) {
        $maxGap = 0;
        
        foreach ([$history1, $history2] as $history) {
            $timestamps = [];
            foreach ($history as $pos) {
                $timestamps[] = strtotime($pos['posDt'] ?? 0);
            }
            sort($timestamps);
            
            for ($i = 1; $i < count($timestamps); $i++) {
                $gap = ($timestamps[$i] - $timestamps[$i - 1]) / 60;
                $maxGap = max($maxGap, $gap);
            }
        }
        
        return $maxGap;
    }

    /**
     * Calculate position drift from median position
     */
    private function calculatePositionDrift($history1, $history2) {
        $positions = [];
        
        foreach ($history1 as $pos) {
            $positions[] = ['latitude' => $pos['latitude'], 'longitude' => $pos['longitude']];
        }
        foreach ($history2 as $pos) {
            $positions[] = ['latitude' => $pos['latitude'], 'longitude' => $pos['longitude']];
        }
        
        if (empty($positions)) {
            return 0;
        }
        
        // Calculate median position (simplified - average of all positions)
        $sumLat = 0;
        $sumLon = 0;
        foreach ($positions as $pos) {
            $sumLat += $pos['latitude'];
            $sumLon += $pos['longitude'];
        }
        $medianLat = $sumLat / count($positions);
        $medianLon = $sumLon / count($positions);
        
        // Calculate max drift from median
        $maxDrift = 0;
        foreach ($positions as $pos) {
            $distance = $this->calculateDistanceNM($medianLat, $medianLon, $pos['latitude'], $pos['longitude']) * 1852; // Convert to meters
            $maxDrift = max($maxDrift, $distance);
        }
        
        return $maxDrift;
    }

    /**
     * Get pre-gap and post-gap position information for reconstruction
     */
    private function getGapPositionInfo($history1, $history2) {
        $result = [
            'pre_gap' => [],
            'post_gap' => [],
            'pre_gap_distance' => null,
            'post_gap_distance' => null
        ];
        
        // Find the largest gap
        $maxGap = 0;
        $gapStart = null;
        $gapEnd = null;
        
        $allTimestamps = [];
        foreach ($history1 as $pos) {
            $allTimestamps[strtotime($pos['posDt'] ?? 0)] = 'v1';
        }
        foreach ($history2 as $pos) {
            $allTimestamps[strtotime($pos['posDt'] ?? 0)] = 'v2';
        }
        
        ksort($allTimestamps);
        $timestamps = array_keys($allTimestamps);
        
        for ($i = 1; $i < count($timestamps); $i++) {
            $gap = ($timestamps[$i] - $timestamps[$i - 1]) / 60;
            if ($gap > $maxGap) {
                $maxGap = $gap;
                $gapStart = $timestamps[$i - 1];
                $gapEnd = $timestamps[$i];
            }
        }
        
        if ($maxGap > self::AIS_GAP_NO_PENALTY_MINUTES && $gapStart && $gapEnd) {
            // Get positions before gap
            foreach ($history1 as $pos) {
                $posTime = strtotime($pos['posDt'] ?? 0);
                if ($posTime <= $gapStart && $posTime >= $gapStart - 3600) { // Within 1 hour before gap
                    $result['pre_gap'][] = $pos;
                }
            }
            foreach ($history2 as $pos) {
                $posTime = strtotime($pos['posDt'] ?? 0);
                if ($posTime <= $gapStart && $posTime >= $gapStart - 3600) {
                    $result['pre_gap'][] = $pos;
                }
            }
            
            // Get positions after gap
            foreach ($history1 as $pos) {
                $posTime = strtotime($pos['posDt'] ?? 0);
                if ($posTime >= $gapEnd && $posTime <= $gapEnd + 3600) { // Within 1 hour after gap
                    $result['post_gap'][] = $pos;
                }
            }
            foreach ($history2 as $pos) {
                $posTime = strtotime($pos['posDt'] ?? 0);
                if ($posTime >= $gapEnd && $posTime <= $gapEnd + 3600) {
                    $result['post_gap'][] = $pos;
                }
            }
            
            // Calculate pre-gap and post-gap distances
            if (!empty($result['pre_gap'])) {
                $v1Pre = null;
                $v2Pre = null;
                foreach ($result['pre_gap'] as $pos) {
                    if (isset($pos['mmsi']) && $pos['mmsi'] == $history1[0]['mmsi']) {
                        $v1Pre = $pos;
                    } else {
                        $v2Pre = $pos;
                    }
                }
                if ($v1Pre && $v2Pre) {
                    $result['pre_gap_distance'] = $this->calculateDistanceNM(
                        $v1Pre['latitude'], $v1Pre['longitude'],
                        $v2Pre['latitude'], $v2Pre['longitude']
                    );
                }
            }
            
            if (!empty($result['post_gap'])) {
                $v1Post = null;
                $v2Post = null;
                foreach ($result['post_gap'] as $pos) {
                    if (isset($pos['mmsi']) && $pos['mmsi'] == $history1[0]['mmsi']) {
                        $v1Post = $pos;
                    } else {
                        $v2Post = $pos;
                    }
                }
                if ($v1Post && $v2Post) {
                    $result['post_gap_distance'] = $this->calculateDistanceNM(
                        $v1Post['latitude'], $v1Post['longitude'],
                        $v2Post['latitude'], $v2Post['longitude']
                    );
                }
            }
        }
        
        return $result;
    }

    /**
     * Check for draught change
     */
    private function checkDraughtChange($vessel1, $vessel2) {
        $initialDraught1 = $this->getInitialDraught($vessel1['mmsi'] ?? null);
        $initialDraught2 = $this->getInitialDraught($vessel2['mmsi'] ?? null);
        
        $currentDraught1 = $vessel1['draught'] ?? 0;
        $currentDraught2 = $vessel2['draught'] ?? 0;
        
        $change1 = abs($currentDraught1 - $initialDraught1);
        $change2 = abs($currentDraught2 - $initialDraught2);
        
        // Significant change threshold: 0.5 meters
        return ($change1 >= 0.5 || $change2 >= 0.5);
    }

    /**
     * Check if vessels are in STS zone
     */
    private function checkSTSZone($lat, $lon) {
        // Nigerian STS zones (Bonny, PHC anchorage, Dawes Island)
        $stsZones = [
            ['lat_min' => 4.2, 'lat_max' => 4.5, 'lon_min' => 7.0, 'lon_max' => 7.3], // Bonny
            ['lat_min' => 4.7, 'lat_max' => 4.9, 'lon_min' => 7.1, 'lon_max' => 7.3], // PHC
            ['lat_min' => 4.5, 'lat_max' => 4.7, 'lon_min' => 7.2, 'lon_max' => 7.4]  // Dawes Island
        ];
        
        foreach ($stsZones as $zone) {
            if ($lat >= $zone['lat_min'] && $lat <= $zone['lat_max'] &&
                $lon >= $zone['lon_min'] && $lon <= $zone['lon_max']) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if vessel types support STS
     */
    private function checkVesselTypesSupportSTS($vessel1, $vessel2) {
        $type1 = $vessel1['type'] ?? '';
        $type2 = $vessel2['type'] ?? '';
        
        $stsCapableTypes = ['tanker', 'cargo', 'fso', 'fsu', 'shuttle', 'crude', 'oil'];
        
        $type1Support = false;
        $type2Support = false;
        
        foreach ($stsCapableTypes as $capableType) {
            if (stripos($type1, $capableType) !== false) $type1Support = true;
            if (stripos($type2, $capableType) !== false) $type2Support = true;
        }
        
        return $type1Support && $type2Support;
    }

    /**
     * Check historical STS behaviour
     */
    private function checkHistoricalSTS($vessel1, $vessel2) {
        // In production, this would query a database of historical STS events
        // Placeholder implementation
        return false;
    }

    /**
     * Calculate transfer signal based on draught changes
     */
    public function calculateTransferSignal($vessel1, $vessel2, $initialDraught1, $initialDraught2) {
        // Get current draughts
        $currentDraught1 = $vessel1['draught'] ?? 0;
        $currentDraught2 = $vessel2['draught'] ?? 0;

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

    /**
     * Get position freshness description
     */
    private function getPositionFreshness($minutes) {
        if ($minutes <= 10) return 'Live';
        if ($minutes <= 60) return 'Recent';
        if ($minutes <= 1440) return 'Stale';
        return 'Outdated';
    }

    private function calculateStationaryHours($history1, $history2, $startDate = null, $endDate = null) {
        $stationaryPeriods = [];
        
        // Filter data points within the specified time range
        if ($startDate && $endDate) {
            $history1 = array_filter($history1, function($point) use ($startDate, $endDate) {
                $pointTime = strtotime($point['posDt'] ?? 0);
                return $pointTime >= strtotime($startDate) && $pointTime <= strtotime($endDate);
            });
            
            $history2 = array_filter($history2, function($point) use ($startDate, $endDate) {
                $pointTime = strtotime($point['posDt'] ?? 0);
                return $pointTime >= strtotime($startDate) && $pointTime <= strtotime($endDate);
            });
        }
        
        // Sort by timestamp
        usort($history1, function($a, $b) {
            return strtotime($a['posDt'] ?? 0) <=> strtotime($b['posDt'] ?? 0);
        });
        
        usort($history2, function($a, $b) {
            return strtotime($a['posDt'] ?? 0) <=> strtotime($b['posDt'] ?? 0);
        });
        
        // Group timestamps where both vessels are stationary
        $stationaryStart = null;
        $stationaryDuration = 0;
        
        $minPoints = min(count($history1), count($history2));
        
        for ($i = 0; $i < $minPoints; $i++) {
            $point1 = $history1[$i];
            $point2 = $history2[$i];
            
            // Check if both vessels are stationary
            $isStationary1 = $this->isStationary($point1['sog'] ?? 0);
            $isStationary2 = $this->isStationary($point2['sog'] ?? 0);
            
            if ($isStationary1 && $isStationary2) {
                if ($stationaryStart === null) {
                    $stationaryStart = strtotime($point1['posDt']);
                }
            } else {
                if ($stationaryStart !== null) {
                    $stationaryEnd = strtotime($point1['posDt']);
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
            $stationaryEnd = strtotime($lastPoint['posDt']);
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

        $ch = curl_init();
        
        $filter = "posDt BETWEEN '" . date('Y-m-d\TH:i:s\Z', strtotime('-6 hours')) . "' AND '" . date('Y-m-d\TH:i:s\Z', strtotime('-5 hours')) . "' 
                   and mmsi=" . $mmsi;
        
        curl_setopt_array($ch, [
            CURLOPT_URL            => "https://api.kpler.com/v2/maritime/ais-historical?filter=" . urlencode($filter),
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
        
        $draught = 0;
        foreach ($features as $feature) {
            $properties = $feature['properties'] ?? [];
            
            $draught = $properties['draught'];
            
        }
        
        return $draught;
    }

     private $activeSTSVessels = [];
    
    /**
     * Force vessel visibility on map for active STS transfers
     */
    public function getVesselsForMapDisplay($vessel1MMSI, $vessel2MMSI) {
        $vesselsToDisplay = [];
        
        // Get vessel info
        $vessel1 = $this->getVesselInfo($vessel1MMSI);
        $vessel2 = $this->getVesselInfo($vessel2MMSI);
        
        if (!$vessel1 || !$vessel2) {
            return $vesselsToDisplay;
        }
        
        // Check if this is an active STS transfer
        $analysis = $this->detectSTSTransfer($vessel1, $vessel2);
        
        if ($analysis['sts_detected']) {
            // Force both vessels visible
            $vesselsToDisplay[] = $this->prepareVesselForMap($vessel1, true);
            $vesselsToDisplay[] = $this->prepareVesselForMap($vessel2, true);
            
            // Track active STS vessels
            $this->trackActiveSTSVessels($vessel1MMSI, $vessel2MMSI);
        }
        
        return $vesselsToDisplay;
    }
    
    /**
     * Prepare vessel data for map display
     */
    private function prepareVesselForMap($vesselData, $forceVisible = false) {
        $latestTimestamp = strtotime($vesselData['posDt'] ?? 0);
        $currentTime = time();
        $minutesSinceLastSignal = ($currentTime - $latestTimestamp) / 60;
        
        $vesselForMap = [
            'mmsi' => $vesselData['mmsi'] ?? 'Unknown',
            'name' => $vesselData['name'] ?? 'Unknown',
            'type' => $vesselData['type'] ?? 'Unknown',
            'latitude' => $vesselData['latitude'] ?? 0,
            'longitude' => $vesselData['longitude'] ?? 0,
            'sog' => $vesselData['sog'] ?? 0,
            'course' => $vesselData['course'] ?? 0,
            'heading' => $vesselData['heading'] ?? 0,
            'status' => $vesselData['status'] ?? 0,
            'last_position' => $vesselData['posDt'] ?? '',
            'force_visible' => $forceVisible,
            'ais_status' => $this->getAISStatus($minutesSinceLastSignal),
            'sts_active' => $forceVisible,
            'marker_color' => $forceVisible ? 'red' : 'blue',
            'popup_content' => $this->generateVesselPopup($vesselData, $forceVisible)
        ];
        
        return $vesselForMap;
    }
    
    /**
     * Get AIS status based on signal freshness
     */
    private function getAISStatus($minutesSinceLastSignal) {
        if ($minutesSinceLastSignal <= 10) {
            return ['status' => 'Good', 'icon' => 'green', 'message' => 'Live signal'];
        } elseif ($minutesSinceLastSignal <= 60) {
            return ['status' => 'Delayed', 'icon' => 'yellow', 'message' => 'Signal delayed'];
        } elseif ($minutesSinceLastSignal <= 1440) {
            return ['status' => 'Weak', 'icon' => 'orange', 'message' => 'Weak signal'];
        } else {
            return ['status' => 'Lost/Dark', 'icon' => 'gray', 'message' => 'AIS signal lost'];
        }
    }
    
    /**
     * Track active STS vessels
     */
    private function trackActiveSTSVessels($mmsi1, $mmsi2) {
        $this->activeSTSVessels[$mmsi1] = [
            'mmsi' => $mmsi1,
            'partner_mmsi' => $mmsi2,
            'detected_at' => time(),
            'last_updated' => time()
        ];
        
        $this->activeSTSVessels[$mmsi2] = [
            'mmsi' => $mmsi2,
            'partner_mmsi' => $mmsi1,
            'detected_at' => time(),
            'last_updated' => time()
        ];
    }

    /**
     * Generate popup content for vessel on map
     */
    private function generateVesselPopup($vesselData, $stsActive = false) {
        $minutesSinceLastSignal = $this->getMinutesSinceLastSignal($vesselData);
        $aisStatus = $this->getAISStatus($minutesSinceLastSignal);
        
        $popup = "<div class='vessel-popup'>";
        $popup .= "<h4>{$vesselData['name']} ({$vesselData['mmsi']})</h4>";
        $popup .= "<p><strong>Type:</strong> {$vesselData['type']}</p>";
        $popup .= "<p><strong>Speed:</strong> " . round($vesselData['sog'] ?? 0, 1) . " knots</p>";
        $popup .= "<p><strong>Course:</strong> " . round($vesselData['course'] ?? 0, 1) . "°</p>";
        $popup .= "<p><strong>Last Position:</strong> {$vesselData['posDt']}</p>";
        $popup .= "<p><strong>AIS Status:</strong> <span class='ais-{$aisStatus['status']}'>{$aisStatus['message']}</span></p>";
        
        if ($stsActive) {
            $popup .= "<p class='sts-warning'><strong>⚠️ ACTIVE STS TRANSFER</strong></p>";
        }
        
        if ($aisStatus['status'] === 'Lost/Dark') {
            $popup .= "<p class='ais-lost'>⚠️ Displaying last known position</p>";
        }
        
        $popup .= "</div>";
        
        return $popup;
    }
    
    /**
     * Get all vessels that should be forced visible
     */
    public function getForceVisibleVessels() {
        $forceVisible = [];
        $expiryTime = time() - (24 * 3600); // 24 hours ago
        
        foreach ($this->activeSTSVessels as $mmsi => $data) {
            if ($data['last_updated'] > $expiryTime) {
                $vessel = $this->getVesselInfo($mmsi);
                if ($vessel) {
                    $forceVisible[] = $this->prepareVesselForMap($vessel, true);
                }
            } else {
                // Remove expired entries
                unset($this->activeSTSVessels[$mmsi]);
            }
        }
        
        return $forceVisible;
    }

    /**
     * Get minutes since last signal
     */
    private function getMinutesSinceLastSignal($vesselData) {
        $latestTimestamp = strtotime($vesselData['posDt'] ?? 0);
        $currentTime = time();
        return ($currentTime - $latestTimestamp) / 60;
    }

    /**
     * Calculate interaction stability (UI display only - does not affect confidence)
     */
    private function calculateInteractionStability($analysis) {
        if (empty($analysis['vessel1_headings']) || empty($analysis['vessel2_headings'])) {
            return 'Not available';
        }
        
        // Calculate heading variance
        $headingStability = $this->checkHeadingStability($analysis);
        
        // Calculate positional drift
        $driftWithinThreshold = ($analysis['position_drift_m'] ?? 0) <= self::POSITIONAL_DRIFT_THRESHOLD_M;
        
        // Calculate speed variation
        $speedStable = $this->checkSpeedThreshold($analysis);
        
        if ($headingStability && $driftWithinThreshold && $speedStable) {
            return 'High';
        } elseif ($headingStability || $driftWithinThreshold || $speedStable) {
            return 'Moderate';
        } else {
            return 'Low';
        }
    }

    /**
     * Calculate AIS integrity
     */
    private function calculateAISIntegrity($analysis) {
        $maxGap = $analysis['max_ais_gap_minutes'] ?? 0;
        
        if ($maxGap < self::AIS_GAP_NO_PENALTY_MINUTES) {
            return 'Stable';
        } elseif ($maxGap < self::AIS_GAP_MILD_PENALTY_MINUTES) {
            return 'Minor gaps';
        } else {
            return 'Reconstructed';
        }
    }

    /**
     * Generate comprehensive STS report with calibrated confidence
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
        
        // Use pre-calculated confidence values from analysis
        $confidenceLevel = $analysis['confidence_level'] ?? 'Low';
        $confidenceEnum = ($confidenceLevel === 'Moderate') ? 'Medium' : $confidenceLevel;
        $transferSignal = $analysis['transfer_signal'] ?? 'Possible STS';
        
        // Assess Draught Evidence
        $draughtEvidence = $this->assessDraughtEvidence($vessel1, $vessel2);
        
        // Get transfer signal if draught evidence is available
        $transferSignalDetail = null;
        if ($draughtEvidence === 'Available') {
            $initialDraught1 = $this->getInitialDraught($vessel1['mmsi'] ?? null);
            $initialDraught2 = $this->getInitialDraught($vessel2['mmsi'] ?? null);
            $transferSignalDetail = $this->calculateTransferSignal($vessel1, $vessel2, $initialDraught1, $initialDraught2);
        }

        $vesselCondition1 = $this->getVesselCondition($vessel1);
        $vesselCondition2 = $this->getVesselCondition($vessel2);
        
        $cargoETA1 = $this->estimateCargoETA($vessel1);
        $cargoETA2 = $this->estimateCargoETA($vessel2);
        
        $vesselOwner1 = $this->getVesselOwner($vessel1);
        $vesselOwner2 = $this->getVesselOwner($vessel2);
        
        $operationMode = $this->getOperationMode($vessel1, $vessel2, $analysis);
        $operationStatus = $this->getOperationStatus($analysis);
        
        $remarks = $this->generateRemarks($analysis, $vessel1, $vessel2, $cargoType1, $cargoType2);
        
        // Get minutes since last signal
        $minutesSinceSignal1 = $this->getMinutesSinceLastSignal($vessel1);
        $minutesSinceSignal2 = $this->getMinutesSinceLastSignal($vessel2);
        
        // Get detailed AIS status
        $aisStatus1 = $this->getAISStatus($minutesSinceSignal1);
        $aisStatus2 = $this->getAISStatus($minutesSinceSignal2);
        
        return [
            'sts_transfer_detected' => $analysis['sts_detected'],
            'operation_mode' => $operationMode,
            'status' => $operationStatus,
            'vessel_1' => [
                'name' => $vessel1['name'] ?? 'Unknown',
                'mmsi' => $vessel1['mmsi'] ?? 'Unknown',
                'type' => $vessel1['type'] ?? 'Unknown',
                'predicted_cargo' => $cargoType1,
                'current_speed' => $vessel1['sog'] ?? 0,
                'vessel_condition' => $vesselCondition1,
                'cargo_eta' => $cargoETA1,
                'vessel_owner' => $vesselOwner1,
                'ais_continuity' => $analysis['ais_continuity_v1'],
                'ais_status' => $aisStatus1,
                'last_signal_age_minutes' => round($minutesSinceSignal1, 1),
                'position_freshness' => $this->getPositionFreshness($minutesSinceSignal1),
                'force_visible' => $analysis['sts_detected']
            ],
            'vessel_2' => [
                'name' => $vessel2['name'] ?? 'Unknown',
                'mmsi' => $vessel2['mmsi'] ?? 'Unknown',
                'type' => $vessel2['type'] ?? 'Unknown',
                'predicted_cargo' => $cargoType2,
                'current_speed' => $vessel2['sog'] ?? 0,
                'vessel_condition' => $vesselCondition2,
                'cargo_eta' => $cargoETA2,
                'vessel_owner' => $vesselOwner2,
                'ais_continuity' => $analysis['ais_continuity_v2'],
                'ais_status' => $aisStatus2,
                'last_signal_age_minutes' => round($minutesSinceSignal2, 1),
                'position_freshness' => $this->getPositionFreshness($minutesSinceSignal2),
                'force_visible' => $analysis['sts_detected']
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
            'transfer_signal' => $transferSignalDetail,
            'risk_assessment' => [
                'risk_level' => $riskLevel,
                'confidence' => $confidenceLevel, // Display label (High/Moderate/Low)
                'confidence_enum' => $confidenceEnum, // Internal enum (high/medium/low)
                'transfer_signal' => $transferSignal, // STS Confirmed / STS Probable / Possible STS
                'remarks' => $remarks
            ],
            'timeline' => [
                'proximity_start' => $analysis['proximity_start'],
                'slow_speed_start' => $analysis['slow_speed_start'],
                'alignment_start' => $analysis['alignment_start'],
                'stable_start' => $analysis['stable_start'],
                'manoeuvre_start' => $analysis['manoeuvre_start'],
                'separation_confirmed' => $analysis['separation_confirmed']
            ],
            'engine_outputs' => [
                'confidence_level' => $confidenceLevel,
                'transfer_signal' => $transferSignal,
                'stable_start' => $analysis['stable_start'],
                'separation_confirmed' => $analysis['separation_confirmed'],
                'ais_integrity' => $analysis['ais_integrity'],
                'interaction_window_duration' => $analysis['interaction_window_duration'],
                'interaction_stability' => $analysis['interaction_stability'],
                'dark_event_during_interaction' => $analysis['dark_event_during_interaction']
            ],
            'lock_time' => $analysis['lock_time'],
            'start_date' => empty($analysis['start_date'])?date('Y-m-d H:i:s'):$analysis['start_date'],
            'distance' => $analysis['distance'],
            'end_date' => $analysis['end_date'],
            'timestamp' => date('c'),
            'criteria_met' => [
                'distance_≤_1_km' => $analysis['current_distance_nm'] <= self::PROXIMITY_THRESHOLD_NM,
                'stationary_≥_2_hours' => $analysis['stationary_hours'] >= self::HIGH_CONFIDENCE_DURATION_HOURS,
                'consistent_proximity' => $analysis['proximity_consistency'] >= 0.7
            ]
        ];
    }
    
    /**
     * Get vessel current information
     */
    private function getVesselInfo($mmsi) {
        sleep(1); 
        $params = [
            "limit" => 1,
            "filter" => 
                "mmsi =  ".$mmsi
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://api.kpler.com/v2/maritime/ais-latest",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($params),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Basic dnh6YU1yelh0bXdxZ09EYldqem9ZSnhLN2ExdmpIc1k6RFo2YUoyeEU3YTlVZW5mbUw3VS1VMGI5c2czUTVDMUg5M1o0ZGVSVDhmenFvOERVeFgxZTdIWGxUMHVBTHpjYQ==',
            ],
        ]);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new Exception('cURL Error: ' . $error);
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
        
        if( array_key_exists( 'features', $data ) ) {
            if( count( $data['features'] ) > 0 ) {
                if( array_key_exists( 'properties', $data['features'][0] ) ) {
                    return $data['features'][0]['properties'];
                }
            }
            
        }
        return false;
    }
    
    /**
     * Generate remarks based on analysis
     */
    private function generateRemarks($analysis, $vessel1, $vessel2, $cargo1, $cargo2) {
        $remarks = [];
        
        if ($analysis['sts_detected']) {
            $remarks[] = "STS TRANSFER LIKELY IN PROGRESS";
        }
        
        if ($analysis['current_distance_nm'] <= self::PROXIMITY_THRESHOLD_NM) {
            $remarks[] = "Vessels within STS operational distance (<1 km)";
        } else {
            $remarks[] = "Vessels outside typical STS distance";
        }
        
        $durationHours = $analysis['stationary_hours'];
        if ($durationHours >= self::HIGH_CONFIDENCE_DURATION_HOURS) {
            $remarks[] = "Extended stationary period (≥2 hours) suggests transfer operations";
        } elseif ($durationHours >= (self::MODERATE_CONFIDENCE_DURATION_MINUTES / 60)) {
            $remarks[] = "Moderate stationary period suggests possible transfer operations";
        }
        
        if ($cargo1 === 'Crude Oil' && $cargo2 === 'Crude Oil') {
            $remarks[] = "Crude oil transfer - high value cargo";
        }
        
        if ($analysis['proximity_consistency'] >= 0.8) {
            $remarks[] = "Consistent proximity supports STS hypothesis";
        }

        // Add AIS continuity remarks (calibrated for Nigerian waters)
        if ($analysis['ais_continuity_v1'] === 'Good' && $analysis['ais_continuity_v2'] === 'Good') {
            $remarks[] = "Both vessels have good AIS continuity";
        } elseif ($analysis['max_ais_gap_minutes'] < self::AIS_GAP_NO_PENALTY_MINUTES) {
            $remarks[] = "Minor AIS gaps detected - within acceptable range for Nigerian waters";
        } elseif ($analysis['max_ais_gap_minutes'] < self::AIS_GAP_MILD_PENALTY_MINUTES) {
            $remarks[] = "Moderate AIS gaps - behavioural pattern remains consistent";
        } elseif ($analysis['max_ais_gap_minutes'] >= self::AIS_GAP_MILD_PENALTY_MINUTES) {
            $remarks[] = "Significant AIS gaps - pattern reconstructed from surrounding data";
        }
        
        // Add proximity signal remarks
        if ($analysis['proximity_signal'] === 'Sustained') {
            $remarks[] = "Sustained proximity signal detected";
        } elseif ($analysis['proximity_signal'] === 'Interrupted') {
            $remarks[] = "Proximity signal interrupted - possible AIS gaps (expected in Nigerian waters)";
        }

        // Add confidence-based remarks
        $confidence = $analysis['confidence_level'] ?? 'Low';
        $confidenceEnum = ($confidence === 'Moderate') ? 'Medium' : $confidence;
        
        switch ($confidenceEnum) {
            case 'High':
                $remarks[] = "High confidence - core behavioural conditions met with supporting signals";
                break;
            case 'Medium':
                $remarks[] = "Moderate confidence - core behavioural conditions met, limited supporting signals";
                break;
            case 'Low':
                $remarks[] = "Low confidence - insufficient behavioural evidence";
                break;
        }
        
        // Add draught remark (neutral when absent)
        if (!$analysis['draught_change_detected']) {
            $remarks[] = "No draught change data - neutral factor (common in Nigerian waters)";
        } else {
            $remarks[] = "Draught change detected - confirming signal";
        }
        
        // Add zone remark if applicable
        if ($analysis['in_sts_zone']) {
            $remarks[] = "Vessels within designated STS zone/anchorage";
        }
        
        // Add vessel type remark
        if ($analysis['vessel_types_support_sts']) {
            $remarks[] = "Vessel types support STS operation";
        }
        
        // Add reconstruction remark if applicable
        if ($analysis['ais_integrity'] === 'Reconstructed') {
            $remarks[] = "Interaction window reconstructed across AIS gap";
        }
        
        return implode('. ', $remarks);
    }
}