<?php

class NigerianSTSPredictor {
    
    private $stsZones = [
        'LAGOS_STS' => [
            'position' => '06°24′N 003°23′E',
            'radius_nm' => 5,
            'max_draught' => 16,
            'common_cargo' => ['crude_oil', 'diesel', 'gasoline', 'jet_fuel'],
            'risk_level' => 'MEDIUM'
        ],
        'BONNY_STS' => [
            'position' => '04°15′N 007°08′E', 
            'radius_nm' => 3,
            'max_draught' => 22,
            'common_cargo' => ['crude_oil', 'lng', 'condensate'],
            'risk_level' => 'HIGH'
        ],
        'ESCRAVOS_STS' => [
            'position' => '05°32′N 005°23′E',
            'radius_nm' => 4,
            'max_draught' => 18,
            'common_cargo' => ['crude_oil', 'diesel', 'fuel_oil'],
            'risk_level' => 'HIGH'
        ],
        'PENNINGTON_STS' => [
            'position' => '04°02′N 005°28′E',
            'radius_nm' => 5,
            'max_draught' => 20,
            'common_cargo' => ['crude_oil', 'lng', 'lpg'],
            'risk_level' => 'MEDIUM'
        ],
        'QUA_IBOE_STS' => [
            'position' => '04°33′N 008°16′E',
            'radius_nm' => 4,
            'max_draught' => 17,
            'common_cargo' => ['crude_oil', 'condensate'],
            'risk_level' => 'MEDIUM'
        ]
    ];
    
    private $stsServiceProviders = [
        'LAMPRELL' => true,
        'BOURBON' => true,
        'TIDEWATER' => true,
        'SEACOR' => true,
        'EDDY' => true
    ];
    
    /**
     * Detect if vessel is engaged in STS operations in Nigerian waters
     */
    public function detectSTSOperation($vesselData, $aisData, $position) {
        $speed = $aisData['speed'] ?? 0;
        $course = $aisData['course'] ?? 0;
        $navStatus = $aisData['nav_status'] ?? '';
        $draught = $aisData['draught'] ?? 0;
        
        // Check if in known STS zone
        $stsZone = $this->identifySTSZone($position);
        
        // STS behavioral patterns
        $isSlowSpeed = ($speed >= 0.1 && $speed <= 2.0);
        $isStationary = ($speed < 0.5);
        $isInSTSZone = ($stsZone !== null);
        $hasTankerProfile = $this->isTankerVessel($vesselData);
        $isEngagedInFishing = ($navStatus === 'engaged in fishing');
        
        // Exclude fishing vessels
        if ($isEngagedInFishing) {
            return false;
        }
        
        // Strong indicators of STS operations
        if ($isInSTSZone && $hasTankerProfile && $isSlowSpeed) {
            return [
                'is_sts' => true,
                'zone' => $stsZone,
                'confidence' => 0.85,
                'activity' => $this->determineSTSActivity($speed, $draught, $vesselData)
            ];
        }
        
        // Check for vessel proximity (simplified - would normally use AIS data of nearby vessels)
        $hasCloseProximity = $this->checkVesselProximity($position, $vesselData['mmsi'] ?? 0);
        
        if ($hasCloseProximity && $hasTankerProfile && $isStationary) {
            return [
                'is_sts' => true,
                'zone' => $stsZone ?? 'UNKNOWN_ZONE',
                'confidence' => 0.75,
                'activity' => 'STS Transfer in Progress'
            ];
        }
        
        return ['is_sts' => false, 'confidence' => 0.0];
    }
    
    /**
     * Predict cargo type for STS operations
     */
    public function predictSTSCargoType($vesselData, $stsData, $operationType = 'transfer') {
        $vesselType = strtolower($vesselData['type_specific'] ?? '');
        $zone = $stsData['zone'] ?? '';
        $draught = $vesselData['draught'] ?? 0;
        
        // Zone-specific cargo predictions
        if (isset($this->stsZones[$zone])) {
            $zoneCargo = $this->stsZones[$zone]['common_cargo'][0] ?? 'crude_oil';
            
            if (str_contains($vesselType, 'lng') || str_contains($vesselType, 'gas')) {
                return 'LNG';
            }
            
            if (str_contains($vesselType, 'chemical')) {
                return 'Chemicals';
            }
            
            if (str_contains($vesselType, 'product')) {
                return 'Diesel/Gasoline';
            }
            
            // Default to zone-specific prediction
            return ucfirst(str_replace('_', ' ', $zoneCargo));
        }
        
        // Fallback based on vessel type
        return $this->getTankerCargoByType($vesselType, $draught);
    }
    
    /**
     * Generate specialized STS remarks for Nigerian operations
     */
    public function generateSTSRemarks($vesselData, $stsData, $aisData, $operationPhase = 'unknown') {
        $remarks = [];
        
        $zone = $stsData['zone'] ?? '';
        $speed = $aisData['speed'] ?? 0;
        $draught = $aisData['draught'] ?? 0;
        $maxDraught = $vesselData['draught'] ?? 0;
        
        // STS Operation phase remarks
        $phaseRemark = $this->getSTSPhaseRemark($operationPhase, $speed, $draught, $maxDraught);
        if ($phaseRemark) $remarks[] = $phaseRemark;
        
        // Zone-specific remarks
        if ($zone) {
            $zoneRemark = $this->getZoneRemark($zone);
            if ($zoneRemark) $remarks[] = $zoneRemark;
        }
        
        // Security remarks for high-risk zones
        $securityRemark = $this->getSTSSecurityRemark($zone);
        if ($securityRemark) $remarks[] = $securityRemark;
        
        // Service provider detection
        $serviceRemark = $this->detectServiceProvider($vesselData);
        if ($serviceRemark) $remarks[] = $serviceRemark;
        
        // Regulatory compliance
        $regulatoryRemark = $this->getRegulatoryRemark($zone);
        if ($regulatoryRemark) $remarks[] = $regulatoryRemark;
        
        // Weather/sea state (simulated)
        $weatherRemark = $this->getWeatherRemark($zone);
        if ($weatherRemark) $remarks[] = $weatherRemark;
        
        if (empty($remarks)) {
            $remarks[] = 'STS Operations - Monitoring';
        }
        
        return implode(', ', $remarks);
    }
    
    /**
     * Identify STS zone based on position
     */
    private function identifySTSZone($position) {
        // Simplified position checking - in real implementation, use proper coordinate calculations
        $lat = $position['latitude'] ?? 0;
        $lon = $position['longitude'] ?? 0;
        
        foreach ($this->stsZones as $zoneName => $zoneData) {
            // This is simplified - real implementation would calculate distance
            $zoneLat = $this->parseCoordinate($zoneData['position'])[0];
            $zoneLon = $this->parseCoordinate($zoneData['position'])[1];
            
            $distance = $this->calculateDistance($lat, $lon, $zoneLat, $zoneLon);
            
            if ($distance <= $zoneData['radius_nm']) {
                return $zoneName;
            }
        }
        
        return null;
    }
    
    /**
     * Determine specific STS activity
     */
    private function determineSTSActivity($speed, $draught, $vesselData) {
        $maxDraught = $vesselData['draught'] ?? 0;
        
        if ($speed < 0.5) {
            if ($maxDraught > 0) {
                $loadRatio = $draught / $maxDraught;
                
                if ($loadRatio < 0.3) {
                    return 'Waiting for STS - Light Ballast';
                } elseif ($loadRatio > 0.7) {
                    return 'Waiting for STS - Nearly Full';
                } else {
                    return 'STS Transfer in Progress';
                }
            }
            return 'STS Operations - Stationary';
        } elseif ($speed <= 2.0) {
            return 'Maneuvering for STS';
        }
        
        return 'Approaching STS Zone';
    }
    
    /**
     * Check for vessel proximity (simplified simulation)
     */
    private function checkVesselProximity($position, $mmsi) {
        // In real implementation, this would query AIS data for nearby vessels
        // For simulation, return random but weighted toward true in STS zones
        $zone = $this->identifySTSZone($position);
        
        if ($zone) {
            // Higher probability of STS activity in known zones
            return (rand(1, 100) > 60);
        }
        
        return (rand(1, 100) > 80);
    }
    
    /**
     * Get STS operation phase remark
     */
    private function getSTSPhaseRemark($phase, $speed, $draught, $maxDraught) {
        $loadRatio = $maxDraught > 0 ? $draught / $maxDraught : 0;
        
        switch ($phase) {
            case 'waiting':
                return 'Awiting STS Partner';
            case 'approach':
                return 'Maneuvering for STS Connection';
            case 'transfer':
                if ($loadRatio < 0.3) return 'STS Loading - Early Stage';
                if ($loadRatio > 0.7) return 'STS Loading - Final Stage';
                return 'STS Transfer Active';
            case 'disconnect':
                return 'STS Disconnection in Progress';
            case 'complete':
                return 'STS Operations Complete';
            default:
                if ($speed < 0.5) {
                    if ($loadRatio < 0.2) return 'STS - Preparing for Loading';
                    if ($loadRatio > 0.8) return 'STS - Preparing to Depart';
                    return 'STS Operations Ongoing';
                }
                return 'STS Operations';
        }
    }
    
    /**
     * Get zone-specific remarks
     */
    private function getZoneRemark($zone) {
        $zoneRemarks = [
            'LAGOS_STS' => 'Lagos Offshore Transfer Zone',
            'BONNY_STS' => 'Bonny Deepwater STS - High Security',
            'ESCRAVOS_STS' => 'Escravos River Mouth - Shallow Draft',
            'PENNINGTON_STS' => 'Pennington FPSO Transfer Area',
            'QUA_IBOE_STS' => 'Qua Iboe Terminal Offshore'
        ];
        
        return $zoneRemarks[$zone] ?? 'Nigerian Offshore STS';
    }
    
    /**
     * Get security remarks for STS zones
     */
    private function getSTSSecurityRemark($zone) {
        if (!$zone) return null;
        
        $riskLevel = $this->stsZones[$zone]['risk_level'] ?? 'MEDIUM';
        
        switch ($riskLevel) {
            case 'HIGH':
                return 'High Risk Area - Naval Escort Recommended';
            case 'MEDIUM':
                return 'Enhanced Security Monitoring';
            case 'LOW':
                return 'Standard Security Protocols';
            default:
                return 'Security Assessment Required';
        }
    }
    
    /**
     * Detect STS service providers
     */
    private function detectServiceProvider($vesselData) {
        $vesselName = strtoupper($vesselData['name'] ?? '');
        
        foreach ($this->stsServiceProviders as $provider => $active) {
            if (str_contains($vesselName, $provider)) {
                return "STS Service Provider: {$provider}";
            }
        }
        
        // Check for common STS vessel name patterns
        if (str_contains($vesselName, 'STS') || 
            str_contains($vesselName, 'TRANSFER') ||
            str_contains($vesselName, 'TUG')) {
            return 'Dedicated STS Vessel';
        }
        
        return null;
    }
    
    /**
     * Get regulatory compliance remarks
     */
    private function getRegulatoryRemark($zone) {
        $highRiskZones = ['BONNY_STS', 'ESCRAVOS_STS'];
        
        if (in_array($zone, $highRiskZones)) {
            return 'NNPC Approval Required';
        }
        
        return 'NIMASA Compliant Operation';
    }
    
    /**
     * Get weather/sea state remarks
     */
    private function getWeatherRemark($zone) {
        // Simulated weather conditions based on zone
        $weatherConditions = [
            'LAGOS_STS' => 'Moderate Swell',
            'BONNY_STS' => 'Calm Seas', 
            'ESCRAVOS_STS' => 'River Currents',
            'PENNINGTON_STS' => 'Open Ocean Swell',
            'QUA_IBOE_STS' => 'Protected Waters'
        ];
        
        $condition = $weatherConditions[$zone] ?? 'Favorable Conditions';
        return "Sea State: {$condition}";
    }
    
    /**
     * Check if vessel is a tanker
     */
    private function isTankerVessel($vesselData) {
        $vesselType = strtolower($vesselData['type'] ?? '');
        $typeSpecific = strtolower($vesselData['type_specific'] ?? '');
        
        $tankerTypes = ['tanker', 'chemical', 'oil', 'product', 'lng', 'lpg', 'gas'];
        
        foreach ($tankerTypes as $tankerType) {
            if (str_contains($vesselType, $tankerType) || str_contains($typeSpecific, $tankerType)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get tanker cargo by type
     */
    private function getTankerCargoByType($vesselType, $draught) {
        if (str_contains($vesselType, 'lng')) return 'LNG';
        if (str_contains($vesselType, 'lpg')) return 'LPG';
        if (str_contains($vesselType, 'chemical')) return 'Chemicals';
        if (str_contains($vesselType, 'product')) {
            return $draught > 10 ? 'Fuel Oil' : 'Diesel/Gasoline';
        }
        return 'Crude Oil';
    }
    
    /**
     * Calculate distance between coordinates (simplified)
     */
    private function calculateDistance($lat1, $lon1, $lat2, $lon2) {
        // Simplified calculation - in real implementation use Haversine formula
        return abs($lat1 - $lat2) + abs($lon1 - $lon2);
    }
    
    /**
     * Parse coordinate string to decimal
     */
    private function parseCoordinate($coordString) {
        // Simplified parsing - real implementation would handle DMS format
        preg_match('/(\d+)°(\d+)′([NS]) (\d+)°(\d+)′([EW])/', $coordString, $matches);
        
        if (count($matches) >= 7) {
            $lat = (float)$matches[1] + (float)$matches[2] / 60;
            $lon = (float)$matches[4] + (float)$matches[5] / 60;
            
            if ($matches[3] === 'S') $lat = -$lat;
            if ($matches[6] === 'W') $lon = -$lon;
            
            return [$lat, $lon];
        }
        
        return [0, 0];
    }
    
    /**
     * Get STS risk assessment
     */
    public function getSTSRiskAssessment($zone, $vesselData) {
        if (!$zone) return 'UNKNOWN';
        
        $baseRisk = $this->stsZones[$zone]['risk_level'] ?? 'MEDIUM';
        $vesselType = $vesselData['type_specific'] ?? '';
        
        // Adjust risk based on vessel type
        if (str_contains(strtolower($vesselType), 'lng')) {
            return 'VERY HIGH'; // LNG transfers are high risk
        }
        
        if (str_contains(strtolower($vesselType), 'chemical')) {
            return 'HIGH'; // Chemical cargoes are high risk
        }
        
        return $baseRisk;
    }
}

// Usage Examples for Nigerian STS Operations:

// $stsPredictor = new NigerianSTSPredictor();

// // Example 1: Tanker in Bonny STS zone
// $vessel1 = [
//     'name' => 'MT ATLANTIC TRADER',
//     'type' => 'Tanker',
//     'type_specific' => 'Crude Oil Tanker',
//     'draught' => 18.5,
//     'mmsi' => 123456789
// ];

// $ais1 = [
//     'speed' => 0.3,
//     'course' => 152,
//     'nav_status' => 'under way using engine',
//     'draught' => 18.2
// ];

// $position1 = ['latitude' => 4.25, 'longitude' => 7.13]; // Bonny STS zone

// // Detect STS operation
// $stsDetection1 = $stsPredictor->detectSTSOperation($vessel1, $ais1, $position1);

// if ($stsDetection1['is_sts']) {
//     $cargoType = $stsPredictor->predictSTSCargoType($vessel1, $stsDetection1);
//     $remarks = $stsPredictor->generateSTSRemarks($vessel1, $stsDetection1, $ais1, 'transfer');
//     $risk = $stsPredictor->getSTSRiskAssessment($stsDetection1['zone'], $vessel1);
    
//     echo "<br>=== NIGERIAN STS OPERATION DETECTED ===\n";
//     echo "<br>Zone: {$stsDetection1['zone']}\n";
//     echo "<br>Activity: {$stsDetection1['activity']}\n";
//     echo "<br>Predicted Cargo: {$cargoType}\n";
//     echo "<br>Remarks: {$remarks}\n";
//     echo "<br>Risk Level: {$risk}\n";
//     echo "<br>Confidence: " . ($stsDetection1['confidence'] * 100) . "%\n\n";
// }

// // Example 2: LNG Carrier in Pennington STS zone
// $vessel2 = [
//     'name' => 'LNG BOURBON PROVIDER',
//     'type' => 'Tanker',
//     'type_specific' => 'LNG Carrier',
//     'draught' => 11.2,
//     'mmsi' => 987654321
// ];

// $ais2 = [
//     'speed' => 0.1,
//     'course' => 0,
//     'nav_status' => 'moored',
//     'draught' => 5.8
// ];

// $position2 = ['latitude' => 4.05, 'longitude' => 5.45]; // Pennington zone

// $stsDetection2 = $stsPredictor->detectSTSOperation($vessel2, $ais2, $position2);

// if ($stsDetection2['is_sts']) {
//     $cargoType2 = $stsPredictor->predictSTSCargoType($vessel2, $stsDetection2);
//     $remarks2 = $stsPredictor->generateSTSRemarks($vessel2, $stsDetection2, $ais2, 'transfer');
//     $risk2 = $stsPredictor->getSTSRiskAssessment($stsDetection2['zone'], $vessel2);
    
//     echo "<br><br><br>=== LNG STS OPERATION DETECTED ===\n";
//     echo "<br>Zone: {$stsDetection2['zone']}\n";
//     echo "<br>Activity: {$stsDetection2['activity']}\n";
//     echo "<br>Predicted Cargo: {$cargoType2}\n";
//     echo "<br>Remarks: {$remarks2}\n";
//     echo "<br>Risk Level: {$risk2}\n";
//     echo "<br>Confidence: " . ($stsDetection2['confidence'] * 100) . "%\n\n";
// }

// // Example 3: Vessel not in STS operation
// $vessel3 = [
//     'name' => 'MV GENERAL CARGO',
//     'type' => 'Cargo',
//     'type_specific' => 'Container Ship',
//     'draught' => 8.5,
//     'mmsi' => 555666777
// ];

// $ais3 = [
//     'speed' => 14.2,
//     'course' => 185,
//     'nav_status' => 'under way using engine',
//     'draught' => 8.3
// ];

// $position3 = ['latitude' => 6.0, 'longitude' => 3.0]; // Not in STS zone

// $stsDetection3 = $stsPredictor->detectSTSOperation($vessel3, $ais3, $position3);

// if (!$stsDetection3['is_sts']) {
//     echo "<br><br><br>=== NO STS OPERATION DETECTED ===\n";
//     echo "<br>Vessel is not engaged in Ship-to-Ship operations\n";
//     echo "<br>Confidence: " . ((1 - $stsDetection3['confidence']) * 100) . "%\n";
// }