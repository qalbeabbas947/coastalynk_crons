<?php

ini_set("display_errors", "On");
error_reporting(E_ALL);

class STSTransferDetector {
    private $apiKey;
    private $baseUrl = 'https://api.datalastic.com/api/v0';
    
    // STS Configuration
    private $config = [
        'distance_threshold' => 0.108,           // 200 meters in nautical miles
        'distance_end_threshold' => 0.162,       // 300 meters in nautical miles
        'heading_threshold' => 25,               // degrees
        'heading_end_threshold' => 40,           // degrees
        'sog_threshold' => 1.0,                  // knots
        'sog_end_threshold' => 3.0,              // knots
        'activation_hours' => 6,                 // hours to become active
        'end_hours' => 1,                        // hours to end
        'rolling_window_minutes' => 10,          // rolling average window
        'gap_tolerance_hours' => 3,              // AIS gap tolerance
        'min_data_points' => 5                   // minimum data points for analysis
    ];
    
    // In-memory storage for active events
    private $activeEvents = [];
    private $activeDaughters = [];
    private $auditLog = [];
    private $vesselCache = [];
    
    public function __construct($apiKey) {
        $this->apiKey = $apiKey;
    }
    
    /**
     * Main processing function for multiple vessels
     */
    public function processVessels($vesselMMSIs, $hoursToAnalyze = 24) {
        $results = [
            'events_created' => [],
            'events_updated' => [],
            'events_ended' => [],
            'errors' => []
        ];
        
        // Get vessel data
        $vesselsData = [];
        foreach ($vesselMMSIs as $mmsi) {
            $vesselData = $this->getVesselInfo($mmsi);
            if ($vesselData) {
                $vesselsData[$mmsi] = $vesselData;
                $this->cacheVesselData($mmsi, $vesselData);
            } else {
                $results['errors'][] = "Could not retrieve data for MMSI: $mmsi";
            }
        }
        
        if (count($vesselsData) < 2) {
            $results['errors'][] = "Need at least 2 vessels for STS detection";
            return $results;
        }
        
        // Get historical data
        $historicalData = [];
        foreach ($vesselsData as $mmsi => $data) {
            $historicalData[$mmsi] = $this->getVesselHistory($mmsi, $hoursToAnalyze);
        }
        
        // Find potential STS candidates
        $potentialPairs = $this->findPotentialSTSPairs($vesselsData, $historicalData);
        
        // Resolve multi-mother conflicts
        $resolvedPairs = $this->resolveMotherConflicts($potentialPairs);
        
        // Group by mother vessel
        $motherGroups = $this->groupByMother($resolvedPairs);
        
        // Process each mother group
        foreach ($motherGroups as $motherMmsi => $daughters) {
            $eventResult = $this->processMotherGroup($motherMmsi, $daughters, $vesselsData, $historicalData);
            
            if ($eventResult['event_created']) {
                $results['events_created'][] = $eventResult;
            } elseif ($eventResult['event_updated']) {
                $results['events_updated'][] = $eventResult;
            }
        }
        
        // Check for event completions
        $endedEvents = $this->checkEventCompletions();
        $results['events_ended'] = $endedEvents;
        
        // Add audit trail to results
        $results['audit_trail'] = array_slice($this->auditLog, -20); // Last 20 audit entries
        
        return $results;
    }
    
    /**
     * Find potential STS pairs between vessels
     */
    private function findPotentialSTSPairs($vesselsData, $historicalData) {
        $potentialPairs = [];
        $vesselList = array_keys($vesselsData);
        
        for ($i = 0; $i < count($vesselList); $i++) {
            for ($j = $i + 1; $j < count($vesselList); $j++) {
                $mmsi1 = $vesselList[$i];
                $mmsi2 = $vesselList[$j];
                
                // Skip if either vessel is already in an active event
                if ($this->isVesselInActiveEvent($mmsi1) || $this->isVesselInActiveEvent($mmsi2)) {
                    continue;
                }
                
                $pairAnalysis = $this->analyzeVesselPair(
                    $mmsi1, $mmsi2,
                    $vesselsData[$mmsi1], $vesselsData[$mmsi2],
                    $historicalData[$mmsi1] ?? [], $historicalData[$mmsi2] ?? []
                );
                
                if ($pairAnalysis['meets_conditions']) {
                    // Determine mother based on priority
                    $motherInfo = $this->determineMother(
                        $vesselsData[$mmsi1], $vesselsData[$mmsi2],
                        $mmsi1, $mmsi2
                    );
                    
                    $potentialPairs[] = [
                        'mother_mmsi' => $motherInfo['mmsi'],
                        'daughter_mmsi' => ($motherInfo['mmsi'] == $mmsi1) ? $mmsi2 : $mmsi1,
                        'mother_data' => $motherInfo['data'],
                        'daughter_data' => ($motherInfo['mmsi'] == $mmsi1) ? $vesselsData[$mmsi2] : $vesselsData[$mmsi1],
                        'analysis' => $pairAnalysis,
                        'persistence_start' => time(),
                        'resolution_basis' => $motherInfo['basis'],
                        'confidence' => $this->calculateConfidenceLevel($pairAnalysis)
                    ];
                }
            }
        }
        
        return $potentialPairs;
    }
    
    /**
     * Analyze vessel pair for STS conditions
     */
    private function analyzeVesselPair($mmsi1, $mmsi2, $vessel1, $vessel2, $history1, $history2) {
        $analysis = [
            'meets_conditions' => false,
            'current_distance_nm' => 0,
            'avg_distance_nm' => 0,
            'heading_difference' => 0,
            'avg_sog_v1' => 0,
            'avg_sog_v2' => 0,
            'data_points' => 0,
            'stationary_percentage' => 0,
            'consistency_score' => 0,
            'rolling_average_valid' => false
        ];
        
        // Calculate current distance
        $analysis['current_distance_nm'] = $this->calculateDistanceNM(
            $vessel1['lat'] ?? 0, $vessel1['lon'] ?? 0,
            $vessel2['lat'] ?? 0, $vessel2['lon'] ?? 0
        );
        
        // Calculate heading difference
        $analysis['heading_difference'] = $this->calculateHeadingDifference(
            $vessel1['heading'] ?? 0,
            $vessel2['heading'] ?? 0
        );
        
        // Analyze historical data for rolling averages
        if (!empty($history1) && !empty($history2)) {
            $historicalAnalysis = $this->analyzeHistoricalData($history1, $history2);
            $analysis = array_merge($analysis, $historicalAnalysis);
            $analysis['rolling_average_valid'] = true;
        }
        
        // Check STS conditions (use rolling averages if available)
        $analysis['meets_conditions'] = $this->checkSTSConditions($analysis, $vessel1, $vessel2);
        
        return $analysis;
    }
    
    /**
     * Analyze historical data for rolling averages
     */
    private function analyzeHistoricalData($history1, $history2) {
        $windowSeconds = $this->config['rolling_window_minutes'] * 60;
        $currentTime = time();
        $windowStart = $currentTime - $windowSeconds;
        
        // Filter recent data points
        $filtered1 = array_filter($history1, function($point) use ($windowStart) {
            $pointTime = $point['last_position_epoch'] ?? 
                        (isset($point['timestamp']) ? strtotime($point['timestamp']) : 0);
            return $pointTime >= $windowStart;
        });
        
        $filtered2 = array_filter($history2, function($point) use ($windowStart) {
            $pointTime = $point['last_position_epoch'] ?? 
                        (isset($point['timestamp']) ? strtotime($point['timestamp']) : 0);
            return $pointTime >= $windowStart;
        });
        
        if (empty($filtered1) || empty($filtered2)) {
            return [
                'avg_distance_nm' => 0,
                'avg_sog_v1' => 0,
                'avg_sog_v2' => 0,
                'data_points' => 0,
                'stationary_percentage' => 0,
                'consistency_score' => 0
            ];
        }
        
        // Calculate averages and consistency
        $distances = [];
        $sogs1 = [];
        $sogs2 = [];
        $stationaryCount = 0;
        $totalComparisons = 0;
        
        // Match timestamps within 10 minutes
        foreach ($filtered1 as $point1) {
            $time1 = $point1['last_position_epoch'] ?? 
                    (isset($point1['timestamp']) ? strtotime($point1['timestamp']) : 0);
            
            foreach ($filtered2 as $point2) {
                $time2 = $point2['last_position_epoch'] ?? 
                        (isset($point2['timestamp']) ? strtotime($point2['timestamp']) : 0);
                
                if (abs($time1 - $time2) <= 600) { // 10 minutes
                    $distance = $this->calculateDistanceNM(
                        $point1['lat'], $point1['lon'],
                        $point2['lat'], $point2['lon']
                    );
                    
                    $distances[] = $distance;
                    $sogs1[] = $point1['speed'] ?? 0;
                    $sogs2[] = $point2['speed'] ?? 0;
                    
                    if ($this->isStationary($point1['speed'] ?? 0) && 
                        $this->isStationary($point2['speed'] ?? 0)) {
                        $stationaryCount++;
                    }
                    
                    $totalComparisons++;
                }
            }
        }
        
        if ($totalComparisons == 0) {
            return [
                'avg_distance_nm' => 0,
                'avg_sog_v1' => 0,
                'avg_sog_v2' => 0,
                'data_points' => $totalComparisons,
                'stationary_percentage' => 0,
                'consistency_score' => 0
            ];
        }
        
        // Calculate averages
        $avgDistance = !empty($distances) ? array_sum($distances) / count($distances) : 0;
        $avgSog1 = !empty($sogs1) ? array_sum($sogs1) / count($sogs1) : 0;
        $avgSog2 = !empty($sogs2) ? array_sum($sogs2) / count($sogs2) : 0;
        
        // Calculate consistency (percentage of points within threshold)
        $withinThreshold = array_filter($distances, function($d) {
            return $d <= $this->config['distance_threshold'];
        });
        $consistencyScore = count($withinThreshold) / max(1, count($distances));
        
        return [
            'avg_distance_nm' => round($avgDistance, 4),
            'avg_sog_v1' => round($avgSog1, 2),
            'avg_sog_v2' => round($avgSog2, 2),
            'data_points' => $totalComparisons,
            'stationary_percentage' => $stationaryCount / $totalComparisons,
            'consistency_score' => $consistencyScore
        ];
    }
    
    /**
     * Check if vessel pair meets STS conditions
     */
    private function checkSTSConditions($analysis, $vessel1, $vessel2) {
        // Use rolling averages if available, otherwise use current values
        $distance = $analysis['rolling_average_valid'] ? 
                   $analysis['avg_distance_nm'] : $analysis['current_distance_nm'];
        
        $sog1 = $analysis['rolling_average_valid'] ? 
               $analysis['avg_sog_v1'] : ($vessel1['speed'] ?? 0);
        
        $sog2 = $analysis['rolling_average_valid'] ? 
               $analysis['avg_sog_v2'] : ($vessel2['speed'] ?? 0);
        
        // Check navigation status
        $navStatus1 = strtolower($vessel1['navigation_status'] ?? '');
        $navStatus2 = strtolower($vessel2['navigation_status'] ?? '');
        $anchoredOrMoored = in_array($navStatus1, ['anchored', 'moored', 'at anchor', 'aground']) ||
                           in_array($navStatus2, ['anchored', 'moored', 'at anchor', 'aground']);
        
        // Check all conditions
        $distanceOk = $distance <= $this->config['distance_threshold'];
        $headingOk = $analysis['heading_difference'] <= $this->config['heading_threshold'];
        $stationaryOk = ($sog1 <= $this->config['sog_threshold'] && 
                        $sog2 <= $this->config['sog_threshold']) || $anchoredOrMoored;
        
        return $distanceOk && $headingOk && $stationaryOk;
    }
    
    /**
     * Determine mother vessel based on priority rules
     */
    private function determineMother($vessel1, $vessel2, $mmsi1, $mmsi2) {
        // Priority 1: Higher DWT
        $dwt1 = $vessel1['dwt'] ?? $vessel1['deadweight'] ?? 0;
        $dwt2 = $vessel2['dwt'] ?? $vessel2['deadweight'] ?? 0;
        
        if ($dwt1 > 0 && $dwt2 > 0) {
            if ($dwt1 > $dwt2) {
                return [
                    'mmsi' => $mmsi1,
                    'data' => $vessel1,
                    'basis' => 'dwt'
                ];
            } elseif ($dwt2 > $dwt1) {
                return [
                    'mmsi' => $mmsi2,
                    'data' => $vessel2,
                    'basis' => 'dwt'
                ];
            }
        }
        
        // Priority 2: Higher LOA (Length Overall)
        $loa1 = $vessel1['length'] ?? $vessel1['loa'] ?? 0;
        $loa2 = $vessel2['length'] ?? $vessel2['loa'] ?? 0;
        
        if ($loa1 > 0 && $loa2 > 0) {
            if ($loa1 > $loa2) {
                return [
                    'mmsi' => $mmsi1,
                    'data' => $vessel1,
                    'basis' => 'loa'
                ];
            } elseif ($loa2 > $loa1) {
                return [
                    'mmsi' => $mmsi2,
                    'data' => $vessel2,
                    'basis' => 'loa'
                ];
            }
        }
        
        // Priority 3: Earlier anchorage arrival (simplified - use current time)
        // In production, you'd analyze historical data for when vessel arrived
        $arrival1 = $this->getAnchorageArrivalTime($mmsi1, $vessel1);
        $arrival2 = $this->getAnchorageArrivalTime($mmsi2, $vessel2);
        
        if ($arrival1 < $arrival2) {
            return [
                'mmsi' => $mmsi1,
                'data' => $vessel1,
                'basis' => 'arrival'
            ];
        } else {
            return [
                'mmsi' => $mmsi2,
                'data' => $vessel2,
                'basis' => 'arrival'
            ];
        }
    }
    
    /**
     * Resolve conflicts when a daughter has multiple mother candidates
     */
    private function resolveMotherConflicts($potentialPairs) {
        $resolved = [];
        $daughterMap = [];
        
        foreach ($potentialPairs as $pair) {
            $daughterMmsi = $pair['daughter_mmsi'];
            
            if (!isset($daughterMap[$daughterMmsi])) {
                // First candidate for this daughter
                $daughterMap[$daughterMmsi] = $pair;
            } else {
                // Conflict - resolve using priority rules
                $existing = $daughterMap[$daughterMmsi];
                
                // Rule 1: Longest persistence (if we have persistence data)
                if ($pair['persistence_start'] < $existing['persistence_start']) {
                    $daughterMap[$daughterMmsi] = $pair;
                    $pair['resolution_basis'] = 'persistence';
                }
                // Rule 2: Closest distance
                elseif ($pair['analysis']['current_distance_nm'] < $existing['analysis']['current_distance_nm']) {
                    $daughterMap[$daughterMmsi] = $pair;
                    $pair['resolution_basis'] = 'distance';
                }
                // Rule 3: Lower movement variance (simplified)
                elseif ($this->calculateMovementVariance($pair) < $this->calculateMovementVariance($existing)) {
                    $daughterMap[$daughterMmsi] = $pair;
                    $pair['resolution_basis'] = 'variance';
                }
            }
        }
        
        return array_values($daughterMap);
    }
    
    /**
     * Calculate movement variance (simplified)
     */
    private function calculateMovementVariance($pair) {
        // Simplified variance calculation based on speed changes
        $analysis = $pair['analysis'];
        $variance = 0;
        
        if ($analysis['avg_sog_v1'] > 0 && $analysis['avg_sog_v2'] > 0) {
            $variance = abs($analysis['avg_sog_v1'] - $analysis['avg_sog_v2']);
        }
        
        return $variance;
    }
    
    /**
     * Group potential pairs by mother vessel
     */
    private function groupByMother($pairs) {
        $groups = [];
        
        foreach ($pairs as $pair) {
            $motherMmsi = $pair['mother_mmsi'];
            if (!isset($groups[$motherMmsi])) {
                $groups[$motherMmsi] = [];
            }
            $groups[$motherMmsi][] = $pair;
        }
        
        return $groups;
    }
    
    /**
     * Process mother group and manage STS events
     */
    private function processMotherGroup($motherMmsi, $daughters, $vesselsData, $historicalData) {
        $result = [
            'event_created' => false,
            'event_updated' => false,
            'event_id' => null,
            'mother_mmsi' => $motherMmsi,
            'mother_name' => $vesselsData[$motherMmsi]['name'] ?? 'Unknown',
            'daughters_added' => [],
            'daughters_updated' => [],
            'daughters_ended' => [],
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        // Check if mother already has an active event
        $activeEvent = $this->getActiveEventForMother($motherMmsi);
        
        if (!$activeEvent) {
            // Create new event
            $eventId = $this->createSTSEvent($motherMmsi, $vesselsData[$motherMmsi]);
            $result['event_created'] = true;
            $result['event_id'] = $eventId;
            $activeEvent = [
                'event_id' => $eventId, 
                'mother_mmsi' => $motherMmsi,
                'start_time' => time()
            ];
        } else {
            $result['event_updated'] = true;
            $result['event_id'] = $activeEvent['event_id'];
        }
        
        // Process each daughter
        foreach ($daughters as $daughterPair) {
            $daughterResult = $this->processDaughterParticipation(
                $activeEvent,
                $daughterPair,
                $vesselsData,
                $historicalData
            );
            
            if ($daughterResult['status'] == 'added') {
                $result['daughters_added'][] = $daughterResult;
            } elseif ($daughterResult['status'] == 'updated') {
                $result['daughters_updated'][] = $daughterResult;
            } elseif ($daughterResult['status'] == 'ended') {
                $result['daughters_ended'][] = $daughterResult;
            }
        }
        
        return $result;
    }
    
    /**
     * Process daughter participation
     */
    private function processDaughterParticipation($event, $daughterPair, $vesselsData, $historicalData) {
        $daughterMmsi = $daughterPair['daughter_mmsi'];
        $currentTime = time();
        
        // Check if daughter is already participating in this event
        $participation = $this->getDaughterParticipation($event['event_id'], $daughterMmsi);
        
        if (!$participation) {
            // Start new participation (tentative)
            $participationId = $this->startDaughterParticipation($event, $daughterPair);
            
            $this->logAudit($event['event_id'], $daughterMmsi, 'daughter_joined', [
                'status' => 'tentative',
                'confidence' => $daughterPair['confidence'],
                'resolution_basis' => $daughterPair['resolution_basis']
            ]);
            
            return [
                'daughter_mmsi' => $daughterMmsi,
                'daughter_name' => $vesselsData[$daughterMmsi]['name'] ?? 'Unknown',
                'status' => 'added',
                'participation_status' => 'tentative',
                'confidence' => $daughterPair['confidence']
            ];
        } else {
            // Update existing participation
            return $this->updateDaughterParticipation(
                $participation,
                $event,
                $daughterPair,
                $vesselsData,
                $historicalData,
                $currentTime
            );
        }
    }
    
    /**
     * Update existing daughter participation
     */
    private function updateDaughterParticipation($participation, $event, $daughterPair, $vesselsData, $historicalData, $currentTime) {
        $daughterMmsi = $daughterPair['daughter_mmsi'];
        $motherMmsi = $event['mother_mmsi'];
        
        // Check if conditions are still met
        $conditionsMet = $this->checkCurrentConditions(
            $motherMmsi,
            $daughterMmsi,
            $vesselsData,
            $historicalData
        );
        
        if ($conditionsMet) {
            // Conditions are met - continue participation
            if ($participation['status'] == 'tentative') {
                // Check if 6 hours have passed
                $elapsedHours = ($currentTime - $participation['join_time']) / 3600;
                
                if ($elapsedHours >= $this->config['activation_hours']) {
                    // Upgrade to active
                    $this->upgradeToActive($participation, $currentTime);
                    
                    $this->logAudit($event['event_id'], $daughterMmsi, 'status_changed', [
                        'from' => 'tentative',
                        'to' => 'active',
                        'elapsed_hours' => round($elapsedHours, 1)
                    ]);
                    
                    return [
                        'daughter_mmsi' => $daughterMmsi,
                        'daughter_name' => $vesselsData[$daughterMmsi]['name'] ?? 'Unknown',
                        'status' => 'updated',
                        'participation_status' => 'active',
                        'elapsed_hours' => round($elapsedHours, 1)
                    ];
                }
            }
            
            // Reset end conditions timer
            $this->updateParticipationRecord($daughterMmsi, [
                'last_conditions_met' => $currentTime,
                'end_conditions_start' => null
            ]);
            
            return [
                'daughter_mmsi' => $daughterMmsi,
                'daughter_name' => $vesselsData[$daughterMmsi]['name'] ?? 'Unknown',
                'status' => 'updated',
                'participation_status' => $participation['status'],
                'elapsed_hours' => round(($currentTime - $participation['join_time']) / 3600, 1)
            ];
            
        } else {
            // Conditions not met
            if ($participation['status'] == 'tentative') {
                // Drop tentative daughter
                $this->dropTentativeDaughter($event['event_id'], $daughterMmsi);
                
                $this->logAudit($event['event_id'], $daughterMmsi, 'daughter_dropped', [
                    'reason' => 'conditions_not_met',
                    'previous_status' => 'tentative'
                ]);
                
                return [
                    'daughter_mmsi' => $daughterMmsi,
                    'daughter_name' => $vesselsData[$daughterMmsi]['name'] ?? 'Unknown',
                    'status' => 'ended',
                    'participation_status' => 'ended',
                    'reason' => 'conditions_not_met'
                ];
            } else {
                // Check end conditions for active daughter
                $ended = $this->checkEndConditions(
                    $participation,
                    $daughterPair,
                    $vesselsData,
                    $currentTime
                );
                
                if ($ended) {
                    return [
                        'daughter_mmsi' => $daughterMmsi,
                        'daughter_name' => $vesselsData[$daughterMmsi]['name'] ?? 'Unknown',
                        'status' => 'ended',
                        'participation_status' => 'ended',
                        'reason' => 'end_conditions_met'
                    ];
                } else {
                    return [
                        'daughter_mmsi' => $daughterMmsi,
                        'daughter_name' => $vesselsData[$daughterMmsi]['name'] ?? 'Unknown',
                        'status' => 'updated',
                        'participation_status' => 'active',
                        'warning' => 'conditions_not_met_but_grace_period'
                    ];
                }
            }
        }
    }
    
    /**
     * Check current conditions for daughter
     */
    private function checkCurrentConditions($motherMmsi, $daughterMmsi, $vesselsData, $historicalData) {
        if (!isset($vesselsData[$motherMmsi]) || !isset($vesselsData[$daughterMmsi])) {
            return false;
        }
        
        $analysis = $this->analyzeVesselPair(
            $motherMmsi, $daughterMmsi,
            $vesselsData[$motherMmsi], $vesselsData[$daughterMmsi],
            $historicalData[$motherMmsi] ?? [], $historicalData[$daughterMmsi] ?? []
        );
        
        return $analysis['meets_conditions'];
    }
    
    /**
     * Check end conditions for active daughter
     */
    private function checkEndConditions($participation, $daughterPair, $vesselsData, $currentTime) {
        $motherMmsi = $daughterPair['mother_mmsi'];
        $daughterMmsi = $daughterPair['daughter_mmsi'];
        
        if (!isset($vesselsData[$motherMmsi]) || !isset($vesselsData[$daughterMmsi])) {
            return true; // End if data is missing
        }
        
        $mother = $vesselsData[$motherMmsi];
        $daughter = $vesselsData[$daughterMmsi];
        
        // Calculate current metrics
        $distance = $this->calculateDistanceNM(
            $mother['lat'] ?? 0, $mother['lon'] ?? 0,
            $daughter['lat'] ?? 0, $daughter['lon'] ?? 0
        );
        
        $headingDiff = $this->calculateHeadingDifference(
            $mother['heading'] ?? 0,
            $daughter['heading'] ?? 0
        );
        
        // Check end conditions
        $endCondition = (
            $distance > $this->config['distance_end_threshold'] ||
            ($daughter['speed'] ?? 0) >= $this->config['sog_end_threshold'] ||
            $headingDiff > $this->config['heading_end_threshold']
        );
        
        if ($endCondition) {
            if (empty($participation['end_conditions_start'])) {
                // Start end conditions timer
                $this->updateParticipationRecord($daughterMmsi, [
                    'end_conditions_start' => $currentTime
                ]);
                return false;
            } else {
                // Check if end conditions persisted for required time
                $elapsedHours = ($currentTime - $participation['end_conditions_start']) / 3600;
                if ($elapsedHours >= $this->config['end_hours']) {
                    $this->endDaughterParticipation($participation, $daughterMmsi);
                    return true;
                }
            }
        } else {
            // Reset end conditions timer
            $this->updateParticipationRecord($daughterMmsi, [
                'end_conditions_start' => null
            ]);
        }
        
        return false;
    }
    
    /**
     * Create STS event
     */
    private function createSTSEvent($motherMmsi, $motherData) {
        $eventId = 'STS_' . uniqid();
        $currentTime = time();
        
        $this->activeEvents[$eventId] = [
            'event_id' => $eventId,
            'mother_mmsi' => $motherMmsi,
            'mother_name' => $motherData['name'] ?? 'Unknown',
            'start_time' => $currentTime,
            'status' => 'active',
            'daughters' => []
        ];
        
        $this->logAudit($eventId, $motherMmsi, 'event_created', [
            'reason' => 'new_mother_detected',
            'mother_name' => $motherData['name'] ?? 'Unknown'
        ]);
        
        return $eventId;
    }
    
    /**
     * Start daughter participation
     */
    private function startDaughterParticipation($event, $daughterPair) {
        $participationId = 'PART_' . uniqid();
        $currentTime = time();
        
        $this->activeDaughters[$daughterPair['daughter_mmsi']] = [
            'participation_id' => $participationId,
            'event_id' => $event['event_id'],
            'daughter_mmsi' => $daughterPair['daughter_mmsi'],
            'daughter_name' => $daughterPair['daughter_data']['name'] ?? 'Unknown',
            'join_time' => $currentTime,
            'lock_time' => null,
            'status' => 'tentative',
            'persistence_start' => $daughterPair['persistence_start'],
            'last_conditions_met' => $currentTime,
            'end_conditions_start' => null,
            'confidence_level' => $daughterPair['confidence'],
            'resolution_basis' => $daughterPair['resolution_basis']
        ];
        
        // Add daughter to event
        $this->activeEvents[$event['event_id']]['daughters'][] = $daughterPair['daughter_mmsi'];
        
        return $participationId;
    }
    
    /**
     * Upgrade daughter to active status
     */
    private function upgradeToActive($participation, $currentTime) {
        $this->activeDaughters[$participation['daughter_mmsi']]['status'] = 'active';
        $this->activeDaughters[$participation['daughter_mmsi']]['lock_time'] = $currentTime;
    }
    
    /**
     * End daughter participation
     */
    private function endDaughterParticipation($participation, $daughterMmsi) {
        $eventId = $participation['event_id'];
        
        // Update daughter status
        $this->activeDaughters[$daughterMmsi]['status'] = 'ended';
        $this->activeDaughters[$daughterMmsi]['leave_time'] = time();
        
        // Remove from event's active daughters list
        if (isset($this->activeEvents[$eventId])) {
            $eventDaughters = &$this->activeEvents[$eventId]['daughters'];
            $key = array_search($daughterMmsi, $eventDaughters);
            if ($key !== false) {
                unset($eventDaughters[$key]);
                $eventDaughters = array_values($eventDaughters); // Reindex
            }
        }
        
        $this->logAudit($eventId, $daughterMmsi, 'daughter_ended', [
            'reason' => 'end_conditions_met',
            'duration_hours' => round((time() - $participation['join_time']) / 3600, 1)
        ]);
        
        // Check if event should end
        $this->checkEventEnd($eventId);
    }
    
    /**
     * Drop tentative daughter
     */
    private function dropTentativeDaughter($eventId, $daughterMmsi) {
        // Remove from active daughters
        unset($this->activeDaughters[$daughterMmsi]);
        
        // Remove from event's daughters list
        if (isset($this->activeEvents[$eventId])) {
            $eventDaughters = &$this->activeEvents[$eventId]['daughters'];
            $key = array_search($daughterMmsi, $eventDaughters);
            if ($key !== false) {
                unset($eventDaughters[$key]);
                $eventDaughters = array_values($eventDaughters);
            }
        }
    }
    
    /**
     * Check if event should end (no active daughters)
     */
    private function checkEventEnd($eventId) {
        if (!isset($this->activeEvents[$eventId])) {
            return;
        }
        
        $event = $this->activeEvents[$eventId];
        
        // Count active daughters in this event
        $activeDaughtersCount = 0;
        foreach ($this->activeDaughters as $daughter) {
            if ($daughter['event_id'] == $eventId && $daughter['status'] == 'active') {
                $activeDaughtersCount++;
            }
        }
        
        if ($activeDaughtersCount == 0) {
            $this->endSTSEvent($eventId);
        }
    }
    
    /**
     * End STS event
     */
    private function endSTSEvent($eventId) {
        if (!isset($this->activeEvents[$eventId])) {
            return;
        }
        
        $this->activeEvents[$eventId]['status'] = 'ended';
        $this->activeEvents[$eventId]['end_time'] = time();
        
        $this->logAudit($eventId, null, 'event_ended', [
            'reason' => 'no_active_daughters',
            'duration_hours' => round((time() - $this->activeEvents[$eventId]['start_time']) / 3600, 1)
        ]);
    }
    
    /**
     * Check event completions
     */
    private function checkEventCompletions() {
        $endedEvents = [];
        
        foreach ($this->activeEvents as $eventId => $event) {
            if ($event['status'] == 'active') {
                $this->checkEventEnd($eventId);
                
                if ($this->activeEvents[$eventId]['status'] == 'ended') {
                    $endedEvents[] = [
                        'event_id' => $eventId,
                        'mother_mmsi' => $event['mother_mmsi'],
                        'duration_hours' => round((time() - $event['start_time']) / 3600, 1)
                    ];
                }
            }
        }
        
        return $endedEvents;
    }
    
    /**
     * Check if vessel is in active event
     */
    private function isVesselInActiveEvent($mmsi) {
        // Check if daughter in active event
        if (isset($this->activeDaughters[$mmsi])) {
            $status = $this->activeDaughters[$mmsi]['status'];
            if (in_array($status, ['tentative', 'active'])) {
                return true;
            }
        }
        
        // Check if mother in active event
        foreach ($this->activeEvents as $event) {
            if ($event['mother_mmsi'] == $mmsi && $event['status'] == 'active') {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get active event for mother
     */
    private function getActiveEventForMother($motherMmsi) {
        foreach ($this->activeEvents as $event) {
            if ($event['mother_mmsi'] == $motherMmsi && $event['status'] == 'active') {
                return $event;
            }
        }
        return null;
    }
    
    /**
     * Get daughter participation
     */
    private function getDaughterParticipation($eventId, $daughterMmsi) {
        if (isset($this->activeDaughters[$daughterMmsi]) && 
            $this->activeDaughters[$daughterMmsi]['event_id'] == $eventId) {
            return $this->activeDaughters[$daughterMmsi];
        }
        return null;
    }
    
    /**
     * Update participation record
     */
    private function updateParticipationRecord($daughterMmsi, $updates) {
        if (isset($this->activeDaughters[$daughterMmsi])) {
            $this->activeDaughters[$daughterMmsi] = array_merge(
                $this->activeDaughters[$daughterMmsi],
                $updates
            );
        }
    }
    
    /**
     * Calculate confidence level
     */
    private function calculateConfidenceLevel($analysis) {
        $score = 0;
        
        // Data points factor
        if ($analysis['data_points'] >= 20) $score += 40;
        elseif ($analysis['data_points'] >= 10) $score += 30;
        elseif ($analysis['data_points'] >= $this->config['min_data_points']) $score += 20;
        
        // Consistency factor
        $score += ($analysis['consistency_score'] * 30);
        
        // Stationary factor
        $score += ($analysis['stationary_percentage'] * 30);
        
        if ($score >= 80) return 'high';
        if ($score >= 60) return 'medium';
        return 'low';
    }
    
    /**
     * Calculate heading difference with circular correction
     */
    private function calculateHeadingDifference($heading1, $heading2) {
        if ($heading1 == 0 || $heading2 == 0) {
            return 0; // Assume aligned if no heading data
        }
        
        $diff = abs($heading1 - $heading2);
        $diff = min($diff, 360 - $diff); // Handle circular nature
        return $diff;
    }
    
    /**
     * Get anchorage arrival time (simplified)
     */
    private function getAnchorageArrivalTime($mmsi, $vesselData) {
        // Simplified: Use current time minus random offset for demo
        // In production, analyze historical speed/position data
        $speed = $vesselData['speed'] ?? 0;
        $navStatus = strtolower($vesselData['navigation_status'] ?? '');
        
        if ($speed < 1.0 || in_array($navStatus, ['anchored', 'moored', 'at anchor'])) {
            return time() - rand(3600, 86400); // 1-24 hours ago
        }
        
        return time(); // Still moving
    }
    
    /**
     * Cache vessel data
     */
    private function cacheVesselData($mmsi, $data) {
        $this->vesselCache[$mmsi] = [
            'data' => $data,
            'timestamp' => time()
        ];
        
        // Clean old cache entries (older than 1 hour)
        foreach ($this->vesselCache as $key => $entry) {
            if (time() - $entry['timestamp'] > 3600) {
                unset($this->vesselCache[$key]);
            }
        }
    }
    
    /**
     * Log audit trail
     */
    private function logAudit($eventId, $vesselMmsi, $action, $details) {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'event_id' => $eventId,
            'vessel_mmsi' => $vesselMmsi,
            'action' => $action,
            'details' => $details
        ];
        
        $this->auditLog[] = $logEntry;
        
        // Keep only last 1000 audit entries
        if (count($this->auditLog) > 1000) {
            $this->auditLog = array_slice($this->auditLog, -1000);
        }
    }
    
    /**
     * Get current state summary
     */
    public function getStateSummary() {
        $activeEventsCount = 0;
        $tentativeDaughtersCount = 0;
        $activeDaughtersCount = 0;
        
        foreach ($this->activeEvents as $event) {
            if ($event['status'] == 'active') {
                $activeEventsCount++;
            }
        }
        
        foreach ($this->activeDaughters as $daughter) {
            if ($daughter['status'] == 'tentative') {
                $tentativeDaughtersCount++;
            } elseif ($daughter['status'] == 'active') {
                $activeDaughtersCount++;
            }
        }
        
        return [
            'active_events' => $activeEventsCount,
            'tentative_daughters' => $tentativeDaughtersCount,
            'active_daughters' => $activeDaughtersCount,
            'total_audit_entries' => count($this->auditLog),
            'vessels_in_cache' => count($this->vesselCache)
        ];
    }
    
    /**
     * Get active events details
     */
    public function getActiveEventsDetails() {
        $details = [];
        
        foreach ($this->activeEvents as $eventId => $event) {
            if ($event['status'] == 'active') {
                $eventDetails = [
                    'event_id' => $eventId,
                    'mother_mmsi' => $event['mother_mmsi'],
                    'mother_name' => $event['mother_name'],
                    'start_time' => date('Y-m-d H:i:s', $event['start_time']),
                    'duration_hours' => round((time() - $event['start_time']) / 3600, 1),
                    'daughters' => []
                ];
                
                // Add daughter details
                foreach ($this->activeDaughters as $daughter) {
                    if ($daughter['event_id'] == $eventId && 
                        in_array($daughter['status'], ['tentative', 'active'])) {
                        
                        $eventDetails['daughters'][] = [
                            'mmsi' => $daughter['daughter_mmsi'],
                            'name' => $daughter['daughter_name'],
                            'status' => $daughter['status'],
                            'join_time' => date('Y-m-d H:i:s', $daughter['join_time']),
                            'confidence' => $daughter['confidence_level'] ?? 'medium'
                        ];
                    }
                }
                
                $details[] = $eventDetails;
            }
        }
        
        return $details;
    }
    
    /**
     * ORIGINAL METHODS FROM EXISTING CLASS (preserved for backward compatibility)
     */
    
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
     * Check if vessel is stationary
     */
    private function isStationary($speed) {
        return $speed < 0.5;
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
     * Get vessel current information
     */
    private function getVesselInfo($mmsi) {
        // Check cache first
        if (isset($this->vesselCache[$mmsi]) && 
            (time() - $this->vesselCache[$mmsi]['timestamp']) < 300) { // 5 minutes cache
            return $this->vesselCache[$mmsi]['data'];
        }
         
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
            $vesselData = $data['data'] ?? null;
            
            if ($vesselData) {
                $this->cacheVesselData($mmsi, $vesselData);
            }
            
            return $vesselData;
        }
        
        return null;
    }
    
    /**
     * Predict cargo type based on vessel characteristics
     */
    private function predictCargoType($vesselData) {
        $type = $vesselData['type'] ?? '';
        $length = $vesselData['length'] ?? 0;
        $draught = $vesselData['draught'] ?? 0;
        
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
        $riskScore = 0;
        
        if ($distanceNM <= 0.1) $riskScore += 3;
        elseif ($distanceNM <= 0.2) $riskScore += 2;
        elseif ($distanceNM <= 0.3) $riskScore += 1;
        
        if ($stationaryHours >= 6) $riskScore += 3;
        elseif ($stationaryHours >= 4) $riskScore += 2;
        elseif ($stationaryHours >= 3) $riskScore += 1;
        
        $type1 = $vessel1['type'] ?? '';
        $type2 = $vessel2['type'] ?? '';
        
        if (stripos($type1, 'tanker') !== false && stripos($type2, 'tanker') !== false) {
            $riskScore += 2;
        }
        
        if ($riskScore >= 5) return 'HIGH';
        if ($riskScore >= 3) return 'MEDIUM';
        return 'LOW';
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
        $speed = $vesselData['speed'] ?? 0;
        $draught = $vesselData['draught'] ?? 0;
        
        if ($speed <= 0) return 0;
        
        $baseETA = 24;
        $speedFactor = max(1, 15 / $speed);
        $draughtFactor = $draught > 10 ? 1.5 : 1.0;
        
        return round($baseETA * $speedFactor * $draughtFactor, 1);
    }
    
    /**
     * Get vessel owner information
     */
    private function getVesselOwner($vesselData) {
        return $vesselData['owner'] ?? $vesselData['operator'] ?? 'Unknown';
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
        
        if(isset($data['data']) && is_array($data['data']) && count($data['data']) > 0) {
            return $data['data'][0]['port_name'];
        }
        
        return '';
    }
    
    /**
     * Generate remarks based on analysis
     */
    private function generateRemarks($analysis, $vessel1, $vessel2, $cargo1, $cargo2) {
        $remarks = [];
        
        if ($analysis['meets_conditions']) {
            $remarks[] = "STS TRANSFER CONDITIONS MET";
        }
        
        if ($analysis['current_distance_nm'] <= $this->config['distance_threshold']) {
            $remarks[] = "Vessels within STS operational distance";
        }
        
        if ($analysis['stationary_percentage'] >= 0.5) {
            $remarks[] = "Significant stationary period detected";
        }
        
        if ($cargo1 === 'Crude Oil' && $cargo2 === 'Crude Oil') {
            $remarks[] = "Crude oil transfer - high value cargo";
        }
        
        if ($analysis['consistency_score'] >= 0.8) {
            $remarks[] = "High proximity consistency supports STS hypothesis";
        }
        
        return implode('. ', $remarks);
    }
    
    /**
     * Backward compatible detectSTSTransfer method
     */
    public function detectSTSTransfer($mmsi1, $mmsi2) {
        try {
            $vessel1 = $this->getVesselInfo($mmsi1);
            $vessel2 = $this->getVesselInfo($mmsi2);
            
            if (!$vessel1 || !$vessel2) {
                throw new Exception("Could not retrieve vessel information");
            }
            
            // Get historical data
            $history1 = $this->getVesselHistory($mmsi1, 6);
            $history2 = $this->getVesselHistory($mmsi2, 6);
            
            // Analyze using new method
            $analysis = $this->analyzeVesselPair($mmsi1, $mmsi2, $vessel1, $vessel2, $history1, $history2);
            
            // Generate STS report in legacy format
            $stsReport = $this->generateSTSReport($analysis, $vessel1, $vessel2);
            
            // Add multi-daughter info if available
            if ($analysis['meets_conditions']) {
                // Check if these vessels are part of any active events
                foreach ($this->activeEvents as $event) {
                    if ($event['mother_mmsi'] == $mmsi1 || $event['mother_mmsi'] == $mmsi2) {
                        $stsReport['multi_daughter_info'] = [
                            'event_id' => $event['event_id'],
                            'mother_mmsi' => $event['mother_mmsi'],
                            'total_daughters' => count($event['daughters'])
                        ];
                        break;
                    }
                }
            }
            
            return $stsReport;
            
        } catch (Exception $e) {
            return [
                'error' => $e->getMessage(),
                'timestamp' => date('c')
            ];
        }
    }
    
    /**
     * Generate STS report (legacy format)
     */
    private function generateSTSReport($analysis, $vessel1, $vessel2) {
        $cargoType1 = $this->predictCargoType($vessel1);
        $cargoType2 = $this->predictCargoType($vessel2);
        
        // Estimate stationary hours from percentage
        $stationaryHours = $analysis['stationary_percentage'] * 6;
        
        $riskLevel = $this->calculateRiskLevel(
            $vessel1, 
            $vessel2, 
            $stationaryHours, 
            $analysis['current_distance_nm']
        );
        
        $confidence = $this->calculateConfidenceLevel($analysis);
        $confidencePercentage = $confidence == 'high' ? 85 : ($confidence == 'medium' ? 65 : 45);
        
        $operationMode = 'STS';
        $operationStatus = '';
        
        if ($analysis['meets_conditions']) {
            $operationStatus = 'Detected';
            if ($stationaryHours >= 3) {
                $operationStatus = 'Ongoing';
            }
            if ($stationaryHours >= 6) {
                $operationStatus = 'Active';
            }
        }
        
        return [
            'sts_transfer_detected' => $analysis['meets_conditions'],
            'operation_mode' => $operationMode,
            'status' => $operationStatus,
            'vessel_1' => [
                'name' => $vessel1['name'] ?? 'Unknown',
                'mmsi' => $vessel1['mmsi'] ?? 'Unknown',
                'type' => $vessel1['type'] ?? 'Unknown',
                'predicted_cargo' => $cargoType1,
                'current_speed' => $vessel1['speed'] ?? 0,
                'vessel_condition' => $this->getVesselCondition($vessel1),
                'cargo_eta' => $this->estimateCargoETA($vessel1),
                'vessel_owner' => $this->getVesselOwner($vessel1)
            ],
            'vessel_2' => [
                'name' => $vessel2['name'] ?? 'Unknown',
                'mmsi' => $vessel2['mmsi'] ?? 'Unknown',
                'type' => $vessel2['type'] ?? 'Unknown',
                'predicted_cargo' => $cargoType2,
                'current_speed' => $vessel2['speed'] ?? 0,
                'vessel_condition' => $this->getVesselCondition($vessel2),
                'cargo_eta' => $this->estimateCargoETA($vessel2),
                'vessel_owner' => $this->getVesselOwner($vessel2)
            ],
            'proximity_analysis' => [
                'current_distance_nm' => round($analysis['current_distance_nm'], 3),
                'average_distance_nm' => round($analysis['avg_distance_nm'], 3),
                'heading_difference' => round($analysis['heading_difference'], 1),
                'stationary_percentage' => number_format($analysis['stationary_percentage'] * 100, 1) . '%',
                'estimated_stationary_hours' => round($stationaryHours, 1),
                'data_points_analyzed' => $analysis['data_points'],
                'consistency_score' => number_format($analysis['consistency_score'] * 100, 1) . '%',
                'rolling_average_used' => $analysis['rolling_average_valid']
            ],
            'risk_assessment' => [
                'risk_level' => $riskLevel,
                'confidence' => number_format($confidencePercentage, 1) . '%',
                'confidence_level' => $confidence,
                'remarks' => $this->generateRemarks($analysis, $vessel1, $vessel2, $cargoType1, $cargoType2)
            ],
            'sts_criteria' => [
                'distance_threshold_nm' => $this->config['distance_threshold'],
                'heading_threshold_deg' => $this->config['heading_threshold'],
                'sog_threshold_knots' => $this->config['sog_threshold'],
                'activation_hours' => $this->config['activation_hours']
            ],
            'timestamp' => date('c'),
            'criteria_met' => [
                'distance_≤_' . $this->config['distance_threshold'] . '_nm' => 
                    $analysis['avg_distance_nm'] <= $this->config['distance_threshold'],
                'heading_difference_≤_' . $this->config['heading_threshold'] . '°' => 
                    $analysis['heading_difference'] <= $this->config['heading_threshold'],
                'stationary_or_anchored' => 
                    ($analysis['avg_sog_v1'] <= $this->config['sog_threshold'] && 
                     $analysis['avg_sog_v2'] <= $this->config['sog_threshold']) ||
                    (strpos(strtolower($vessel1['navigation_status'] ?? ''), 'anchor') !== false ||
                     strpos(strtolower($vessel2['navigation_status'] ?? ''), 'anchor') !== false)
            ]
        ];
    }
}

// // Usage examples
// $apiKey = '15df4420-d28b-4b26-9f01-13cca621d55e';
// $detector = new STSTransferDetector($apiKey);   

// // 1. Legacy usage (single pair - backward compatible)
// echo "<h2>Legacy Single Pair Detection:</h2>";
// $result = $detector->detectSTSTransfer('123456789', '987654321');
// echo "<pre>";
// print_r($result);
// echo "</pre>";

// // 2. New multi-vessel processing
// echo "<h2>Multi-Vessel Processing:</h2>";
// $vessels = ['123456789', '987654321', '555555555', '666666666'];
// $multiResult = $detector->processVessels($vessels);
// echo "<pre>";
// print_r($multiResult);
// echo "</pre>";

// // 3. Get state summary
// echo "<h2>Current State Summary:</h2>";
// $state = $detector->getStateSummary();
// echo "<pre>";
// print_r($state);
// echo "</pre>";

// // 4. Get active events details
// echo "<h2>Active Events Details:</h2>";
// $activeEvents = $detector->getActiveEventsDetails();
// echo "<pre>";
// print_r($activeEvents);
// echo "</pre>";