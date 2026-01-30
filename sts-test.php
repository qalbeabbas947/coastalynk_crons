<?php


ini_set("display_errors", "On");
error_reporting(E_ALL);

class STSTransferDetector {
    private $apiKey;
    private $baseUrl = 'https://api.datalastic.com/api/v0';
    
    // Constants for STS detection rules
    private const DISTANCE_THRESHOLD_START = 1200; // meters
    private const DISTANCE_THRESHOLD_END = 1500; // meters
    private const SPEED_THRESHOLD = 0.5; // knots for start condition
    private const SPEED_THRESHOLD_END = 1.0; // knots for end condition
    private const MIN_CONTINUOUS_TIME = 600; // 10 minutes in seconds
    private const END_DISTANCE_TIME = 1200; // 20 minutes in seconds
    private const END_SPEED_TIME = 900; // 15 minutes in seconds
    private const MAX_STS_DURATION = 21600; // 6 hours in seconds (max cap)
    
    public function __construct($apiKey) {
        $this->apiKey = $apiKey;
    }
    
    /**
     * Calculate distance between two coordinates in meters
     */
    private function calculateDistanceM($lat1, $lon1, $lat2, $lon2) {
        $earthRadius = 6371000; // Earth radius in meters
        
        $lat1 = deg2rad($lat1);
        $lon1 = deg2rad($lon1);
        $lat2 = deg2rad($lat2);
        $lon2 = deg2rad($lon2);
        
        $latDelta = $lat2 - $lat1;
        $lonDelta = $lon2 - $lon1;
        
        $a = sin($latDelta / 2) * sin($latDelta / 2) +
             cos($lat1) * cos($lat2) *
             sin($lonDelta / 2) * sin($lonDelta / 2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        
        return $earthRadius * $c;
    }
    
    /**
     * Convert meters to nautical miles
     */
    private function metersToNauticalMiles($meters) {
        return $meters / 1852;
    }
    
    /**
     * Get vessel historical positions with timestamps
     */
    private function getVesselHistory($mmsi, $hours = 48) {
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
     * Synchronize timestamps between two vessel histories
     */
    private function synchronizeHistories($history1, $history2) {
        $synchronized = [];
        
        foreach ($history1['positions'] as $point1) {
            $time1 = ($point1['last_position_epoch'] ?? '');
            if (!$time1) continue;
            
            // Find closest point in history2 within 30 seconds
            $closestPoint2 = null;
            $minTimeDiff = PHP_INT_MAX;
            
            foreach ($history2['positions'] as $point2) {
                $time2 = ($point2['last_position_epoch'] ?? '');
                if (!$time2) continue;
                
                $timeDiff = abs($time1 - $time2);
                if ($timeDiff < $minTimeDiff && $timeDiff <= 30) {
                    $minTimeDiff = $timeDiff;
                    $closestPoint2 = $point2;
                }
            }
            
            if ($closestPoint2) {
                $synchronized[] = [
                    'timestamp' => $point1['last_position_epoch'],
                    'time_epoch' => $time1,
                    'vessel1' => $point1,
                    'vessel2' => $closestPoint2
                ];
            }
        }
        
        return $synchronized;
    }
    
    /**
     * Detect STS events from synchronized history
     */
    public function detectSTSEvents($mmsi1, $mmsi2) {
        try {
            // Get historical data (48 hours to ensure we capture the entire event)
            $history1 = $this->getVesselHistory($mmsi1, 48);
            $history2 = $this->getVesselHistory($mmsi2, 48);
            
            if (empty($history1) || empty($history2)) {
                return [
                    'events' => [],
                    'error' => 'Insufficient historical data'
                ];
            }
            
            // Synchronize histories
            $syncedData = $this->synchronizeHistories($history1, $history2);
            echo '<hr><pre>';print_r($syncedData);echo '</pre>';
            if (empty($syncedData)) {
                return [
                    'events' => [],
                    'error' => 'No synchronized data points found'
                ];
            }
            
            // Detect STS events
            $events = $this->analyzeForSTSEvents($syncedData);
            
            // Get vessel info for reporting
            $vessel1 = $this->getVesselInfo($mmsi1);
            $vessel2 = $this->getVesselInfo($mmsi2);
            
            return [
                'events' => $events,
                'vessel1_info' => $vessel1,
                'vessel2_info' => $vessel2,
                'data_points' => count($syncedData),
                'analysis_period' => [
                    'start' => $syncedData[0]['timestamp'] ?? '',
                    'end' => $syncedData[count($syncedData)-1]['timestamp'] ?? ''
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'events' => [],
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Analyze synchronized data for STS events
     */
    private function analyzeForSTSEvents($syncedData) {
        $events = [];
        $inEvent = false;
        $eventStartTime = null;
        $eventStartIndex = null;
        $continuousStartConditions = 0;
        $endConditionCounters = [
            'distance' => 0,
            'speed_a' => 0,
            'speed_b' => 0
        ];
        
        for ($i = 0; $i < count($syncedData); $i++) {
            $point = $syncedData[$i];
            
            // Extract data
            $timestamp = $point['timestamp'];
            $timeEpoch = $point['time_epoch'];
            $v1 = $point['vessel1'];
            $v2 = $point['vessel2'];
            
            $lat1 = $v1['lat'] ?? 0;
            $lon1 = $v1['lon'] ?? 0;
            $lat2 = $v2['lat'] ?? 0;
            $lon2 = $v2['lon'] ?? 0;
            $speed1 = $v1['speed'] ?? 0;
            $speed2 = $v2['speed'] ?? 0;
            
            // Calculate distance in meters
            $distance = $this->calculateDistanceM($lat1, $lon1, $lat2, $lon2);
            
            // Check if STS conditions are met for this point
            $conditionsMet = (
                $distance <= self::DISTANCE_THRESHOLD_START &&
                $speed1 <= self::SPEED_THRESHOLD &&
                $speed2 <= self::SPEED_THRESHOLD
            );
            
            if (!$inEvent) {
                // Not currently in an event
                if ($conditionsMet) {
                    $continuousStartConditions++;
                    
                    // Check if we've had continuous conditions for ≥10 minutes
                    // (Assuming data points are at regular intervals - adjust based on actual frequency)
                    if ($continuousStartConditions >= 10) { // Adjust this based on your data frequency
                        // Found STS start
                        $inEvent = true;
                        $eventStartTime = $syncedData[$i - 9]['timestamp']; // Go back 10 points
                        $eventStartIndex = $i - 9;
                        $continuousStartConditions = 0;
                        
                        // Initialize end condition counters
                        $endConditionCounters = [
                            'distance' => 0,
                            'speed_a' => 0,
                            'speed_b' => 0
                        ];
                    }
                } else {
                    // Reset counter if conditions not met
                    $continuousStartConditions = 0;
                }
            } else {
                // Currently in an event - check for end conditions
                $endConditionsMet = false;
                
                // Check distance condition
                if ($distance > self::DISTANCE_THRESHOLD_END) {
                    $endConditionCounters['distance']++;
                } else {
                    $endConditionCounters['distance'] = 0;
                }
                
                // Check speed conditions
                if ($speed1 > self::SPEED_THRESHOLD_END) {
                    $endConditionCounters['speed_a']++;
                } else {
                    $endConditionCounters['speed_a'] = 0;
                }
                
                if ($speed2 > self::SPEED_THRESHOLD_END) {
                    $endConditionCounters['speed_b']++;
                } else {
                    $endConditionCounters['speed_b'] = 0;
                }
                
                // Check if any end condition has been met for required time
                if ($endConditionCounters['distance'] >= 20 || // 20 minutes of distance > 1500m
                    $endConditionCounters['speed_a'] >= 15 ||  // 15 minutes of speed A > 1.0kn
                    $endConditionCounters['speed_b'] >= 15) {  // 15 minutes of speed B > 1.0kn
                    $endConditionsMet = true;
                }
                
                // Check max duration cap (6 hours)
                $eventDuration = $timeEpoch - strtotime($eventStartTime);
                if ($eventDuration >= self::MAX_STS_DURATION) {
                    $endConditionsMet = true;
                }
                
                if ($endConditionsMet) {
                    // End the event
                    $eventEndTime = $timestamp;
                    $eventEndIndex = $i;
                    
                    // Calculate stationary duration (time when all conditions were met)
                    $stationaryDuration = $this->calculateStationaryDuration(
                        array_slice($syncedData, $eventStartIndex, $eventEndIndex - $eventStartIndex + 1)
                    );
                    
                    // Calculate event duration (End - Start)
                    $durationSeconds = strtotime($eventEndTime) - strtotime($eventStartTime);
                    
                    // Add event to results
                    $events[] = $this->createEventRecord(
                        $eventStartTime,
                        $eventEndTime,
                        $durationSeconds,
                        $stationaryDuration,
                        array_slice($syncedData, $eventStartIndex, $eventEndIndex - $eventStartIndex + 1)
                    );
                    
                    // Reset for next event
                    $inEvent = false;
                    $eventStartTime = null;
                    $eventStartIndex = null;
                    $continuousStartConditions = 0;
                }
            }
        }
        
        // Handle event that's still ongoing at the end of data
        if ($inEvent && $eventStartTime) {
            $eventEndTime = $syncedData[count($syncedData)-1]['timestamp'];
            $stationaryDuration = $this->calculateStationaryDuration(
                array_slice($syncedData, $eventStartIndex)
            );
            $durationSeconds = strtotime($eventEndTime) - strtotime($eventStartTime);
            
            $events[] = $this->createEventRecord(
                $eventStartTime,
                $eventEndTime,
                $durationSeconds,
                $stationaryDuration,
                array_slice($syncedData, $eventStartIndex),
                true // Mark as ongoing
            );
        }
        
        return $events;
    }
    
    /**
     * Calculate stationary duration within an event
     */
    private function calculateStationaryDuration($eventPoints) {
        $stationarySeconds = 0;
        $lastStationaryTime = null;
        
        foreach ($eventPoints as $point) {
            $v1 = $point['vessel1'];
            $v2 = $point['vessel2'];
            
            $lat1 = $v1['lat'] ?? 0;
            $lon1 = $v1['lon'] ?? 0;
            $lat2 = $v2['lat'] ?? 0;
            $lon2 = $v2['lon'] ?? 0;
            $speed1 = $v1['speed'] ?? 0;
            $speed2 = $v2['speed'] ?? 0;
            
            $distance = $this->calculateDistanceM($lat1, $lon1, $lat2, $lon2);
            
            $isStationary = (
                $distance <= self::DISTANCE_THRESHOLD_START &&
                $speed1 <= self::SPEED_THRESHOLD &&
                $speed2 <= self::SPEED_THRESHOLD
            );
            
            if ($isStationary) {
                if ($lastStationaryTime === null) {
                    $lastStationaryTime = $point['time_epoch'];
                }
                // Add time since last point (assuming 1-minute intervals)
                if ($lastStationaryTime) {
                    $stationarySeconds += 60; // Add 60 seconds per data point
                }
            } else {
                $lastStationaryTime = null;
            }
        }
        
        return $stationarySeconds;
    }
    
    /**
     * Create event record for reporting
     */
    private function createEventRecord($startTime, $endTime, $durationSeconds, $stationarySeconds, $eventPoints, $ongoing = false) {
        // Calculate average position
        $avgLat1 = 0; $avgLon1 = 0; $avgLat2 = 0; $avgLon2 = 0;
        $pointCount = count($eventPoints);
        
        foreach ($eventPoints as $point) {
            $avgLat1 += $point['vessel1']['lat'] ?? 0;
            $avgLon1 += $point['vessel1']['lon'] ?? 0;
            $avgLat2 += $point['vessel2']['lat'] ?? 0;
            $avgLon2 += $point['vessel2']['lon'] ?? 0;
        }
        
        if ($pointCount > 0) {
            $avgLat1 /= $pointCount;
            $avgLon1 /= $pointCount;
            $avgLat2 /= $pointCount;
            $avgLon2 /= $pointCount;
        }
        
        return [
            'start_date' => $startTime,
            'end_date' => $endTime,
            'duration' => [
                'seconds' => $durationSeconds,
                'formatted' => $this->formatDuration($durationSeconds)
            ],
            'stationary_duration' => [
                'seconds' => $stationarySeconds,
                'formatted' => $this->formatDuration($stationarySeconds)
            ],
            'average_distance' => [
                'meters' => $this->calculateDistanceM($avgLat1, $avgLon1, $avgLat2, $avgLon2),
                'nautical_miles' => $this->metersToNauticalMiles(
                    $this->calculateDistanceM($avgLat1, $avgLon1, $avgLat2, $avgLon2)
                )
            ],
            'event_location' => [
                'vessel1_avg_position' => ['lat' => $avgLat1, 'lon' => $avgLon1],
                'vessel2_avg_position' => ['lat' => $avgLat2, 'lon' => $avgLon2]
            ],
            'data_points' => $pointCount,
            'status' => $ongoing ? 'ongoing' : 'completed',
            'max_duration_capped' => $durationSeconds >= self::MAX_STS_DURATION
        ];
    }
    
    /**
     * Format duration in hours:minutes:seconds
     */
    private function formatDuration($seconds) {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $seconds = $seconds % 60;
        
        return sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);
    }
    
    /**
     * Get vessel information
     */
    private function getVesselInfo($mmsi) {
        $endpoint = $this->baseUrl . '/vessel';
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
     * Generate STS report for a specific event
     */
    public function generateSTSReport($mmsi1, $mmsi2, $eventIndex = 0) {
        $detectionResult = $this->detectSTSEvents($mmsi1, $mmsi2);
        
        if (isset($detectionResult['error'])) {
            return ['error' => $detectionResult['error']];
        }
        
        if (empty($detectionResult['events'])) {
            return ['message' => 'No STS events detected'];
        }
        
        if (!isset($detectionResult['events'][$eventIndex])) {
            return ['error' => 'Event index not found'];
        }
        
        $event = $detectionResult['events'][$eventIndex];
        $vessel1 = $detectionResult['vessel1_info'];
        $vessel2 = $detectionResult['vessel2_info'];
        
        // Generate comprehensive report
        return [
            'sts_event' => $event,
            'vessel_1' => [
                'name' => $vessel1['name'] ?? 'Unknown',
                'mmsi' => $mmsi1,
                'type' => $vessel1['type'] ?? 'Unknown',
                'length' => $vessel1['length'] ?? 0,
                'width' => $vessel1['width'] ?? 0,
                'draught' => $vessel1['draught'] ?? 0
            ],
            'vessel_2' => [
                'name' => $vessel2['name'] ?? 'Unknown',
                'mmsi' => $mmsi2,
                'type' => $vessel2['type'] ?? 'Unknown',
                'length' => $vessel2['length'] ?? 0,
                'width' => $vessel2['width'] ?? 0,
                'draught' => $vessel2['draught'] ?? 0
            ],
            'detection_parameters' => [
                'start_conditions' => [
                    'max_distance' => self::DISTANCE_THRESHOLD_START . ' meters',
                    'max_speed' => self::SPEED_THRESHOLD . ' knots',
                    'min_continuous_time' => self::MIN_CONTINUOUS_TIME . ' seconds'
                ],
                'end_conditions' => [
                    'distance_trigger' => self::DISTANCE_THRESHOLD_END . ' meters for ' . 
                                        self::END_DISTANCE_TIME . ' seconds',
                    'speed_trigger' => self::SPEED_THRESHOLD_END . ' knots for ' . 
                                      self::END_SPEED_TIME . ' seconds',
                    'max_duration' => self::MAX_STS_DURATION . ' seconds (' . 
                                    $this->formatDuration(self::MAX_STS_DURATION) . ')'
                ]
            ],
            'analysis_summary' => [
                'data_points_analyzed' => $detectionResult['data_points'],
                'analysis_period' => $detectionResult['analysis_period'],
                'total_events_detected' => count($detectionResult['events'])
            ]
        ];
    }
}

// Usage example
$apiKey = '15df4420-d28b-4b26-9f01-13cca621d55e';
$detector = new STSTransferDetector($apiKey);

// Detect STS events between two vessels
$mmsi1 = '657274100';
$mmsi2 = '657816000';

$result = $detector->detectSTSEvents($mmsi1, $mmsi2);

echo "<pre>";
print_r($result);
echo "</pre>";
$report = $detector->generateSTSReport($mmsi1, $mmsi2, 0);
    echo "<h2>STS Event Report</h2>";
    echo "<pre>";
    print_r($report);
    echo "</pre>";
// Generate a detailed report for the first event
if (!empty($result['events'])) {
    $report = $detector->generateSTSReport($mmsi1, $mmsi2, 0);
    echo "<h2>STS Event Report</h2>";
    echo "<pre>";
    print_r($report);
    echo "</pre>";
}