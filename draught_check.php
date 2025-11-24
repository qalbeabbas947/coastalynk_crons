<?php

class CoastalynkAfterDraughtCalculator {
    private $threshold = 0.3; // noise floor in meters
    private $vesselTypes;
    private $productDensity;
    private $permissibleDrafts;
    
    public function __construct() {
        $this->initializeDatasets();
    }
    
    private function initializeDatasets() {
        // 1. Tanker Draught Behavior Table
        $this->vesselTypes = [
            'MR' => [
                'laden_draught' => 12.0,
                'ballast_draught' => 7.0,
                'typical_change' => 2.5,
                'mt_per_meter' => 8000
            ],
            'LR1' => [
                'laden_draught' => 13.5,
                'ballast_draught' => 8.0,
                'typical_change' => 3.0,
                'mt_per_meter' => 12000
            ],
            'LR2' => [
                'laden_draught' => 15.0,
                'ballast_draught' => 9.5,
                'typical_change' => 3.5,
                'mt_per_meter' => 16000
            ],
            'Aframax' => [
                'laden_draught' => 14.8,
                'ballast_draught' => 9.0,
                'typical_change' => 3.0,
                'mt_per_meter' => 18000
            ],
            'Suezmax' => [
                'laden_draught' => 17.0,
                'ballast_draught' => 10.0,
                'typical_change' => 4.0,
                'mt_per_meter' => 22000
            ],
            'VLCC' => [
                'laden_draught' => 20.5,
                'ballast_draught' => 12.0,
                'typical_change' => 5.0,
                'mt_per_meter' => 30000
            ],
            'Chemical' => [
                'laden_draught' => 10.0,
                'ballast_draught' => 6.0,
                'typical_change' => 1.5,
                'mt_per_meter' => 4000
            ],
            'LPG' => [
                'laden_draught' => 9.0,
                'ballast_draught' => 6.0,
                'typical_change' => 1.0,
                'mt_per_meter' => 2500
            ],
            'LNG' => [
                'laden_draught' => 12.0,
                'ballast_draught' => 9.0,
                'typical_change' => 1.0,
                'mt_per_meter' => 3500
            ]
        ];
        
        // 2. Product Density Table
        $this->productDensity = [
            'Crude Light' => 0.82,
            'Crude Medium' => 0.86,
            'Crude Heavy' => 0.93,
            'AGO' => 0.82,
            'PMS' => 0.74,
            'DPK' => 0.80,
            'Jet A1' => 0.80,
            'Base Oil' => 0.88,
            'Condensate' => 0.78,
            'LPG' => 0.54,
            'LNG' => 0.45,
            'Bitumen' => 1.10
        ];
        
        // 3. Permissible Drafts
        $this->permissibleDrafts = [
            'Bonny Access' => 13.3,
            'Bonny-Onne' => 11.3,
            'Onne-FOT' => 11.3,
            'FOT-FLT TB' => 10.4,
            'FOT-FLT3' => 9.7,
            'Atlas Cove' => 14.5,
            'Lagos Fairway' => 14.5,
            'Apapa Circle' => 13.5,
            'TinCan Circle' => 13.5
        ];
    }
    
    public function calculateAfterDraught($stsEvent, $vesselData) {
        // Developer Note 1: After-Draught logic activates only after STS detection
        if (!$stsEvent || !$stsEvent['sts_detected']) {
            return ['error' => 'After-Draught logic requires STS detection first'];
        }
        
        $result = [
            'before_draught' => $vesselData['draught_before'],
            'after_draught' => $vesselData['draught_after'],
            'draught_change' => 0,
            'status' => 'no_significant_change',
            'zone_name' => $vesselData['zone'],
            'vessel_type' => $vesselData['vessel_type'],
            'product_type' => $vesselData['product_type'] ?? 'Crude Medium',
            'timestamp' => $vesselData['timestamp'],
            'ais_source' => $vesselData['ais_source'],
            'confidence' => 0,
            'exceeded_permissible' => false,
            'cargo_mt' => 0,
            'sts_event_id' => $stsEvent['id']
        ];
        
        // Developer Note 2: draught_change = after - before
        $draughtChange = $vesselData['draught_after'] - $vesselData['draught_before'];
        $result['draught_change'] = $draughtChange;
        
        // Developer Note 3: Ignore if change < noise_floor or exceeds permissible draft
        if (abs($draughtChange) < $this->threshold) {
            $result['status'] = 'below_noise_floor';
            return $result;
        }
        
        // Check permissible draft for zone
        $zonePermissible = $this->getZonePermissibleDraft($vesselData['zone']);
        if ($zonePermissible && $vesselData['draught_after'] > $zonePermissible) {
            $result['exceeded_permissible'] = true;
            $result['status'] = 'exceeded_permissible_draft';
            return $result;
        }
        
        // Get vessel characteristics
        $vesselInfo = $this->getVesselInfo($vesselData['vessel_type']);
        if (!$vesselInfo) {
            $result['status'] = 'unknown_vessel_type';
            return $result;
        }
        
        // Determine vessel status and event type
        $eventAnalysis = $this->analyzeDraughtEvent($draughtChange, $vesselData, $vesselInfo);
        $result = array_merge($result, $eventAnalysis);
        
        // Developer Note 4: cargo_mt = change * mt_per_meter * density
        $cargoMT = $this->calculateCargoMT($draughtChange, $vesselInfo, $vesselData['product_type']);
        $result['cargo_mt'] = $cargoMT;
        
        // Developer Note 5: If cargo_mt < 50 MT → "No significant change yet"
        if (abs($cargoMT) < 50) {
            $result['status'] = 'no_significant_change_yet';
            $result['cargo_mt'] = 0;
        }
        
        // Calculate confidence
        $result['confidence'] = $this->calculateConfidence($result, $vesselInfo, $draughtChange);
        
        return $result;
    }
    
    private function analyzeDraughtEvent($draughtChange, $vesselData, $vesselInfo) {
        $before = $vesselData['draught_before'];
        $after = $vesselData['draught_after'];
        
        $analysis = [
            'event_type' => 'unknown',
            'status_change' => 'none',
            'expected_change' => $vesselInfo['typical_change']
        ];
        
        // Determine if this is load or discharge
        if ($draughtChange > 0) {
            $analysis['event_type'] = 'load_event';
            $analysis['status_change'] = 'ballast_to_laden';
            
            // Check if change matches expected load pattern
            if (abs($draughtChange - $vesselInfo['typical_change']) <= 1.0) {
                $analysis['pattern_match'] = 'high';
            } elseif (abs($draughtChange - $vesselInfo['typical_change']) <= 2.0) {
                $analysis['pattern_match'] = 'medium';
            } else {
                $analysis['pattern_match'] = 'low';
            }
        } elseif ($draughtChange < 0) {
            $analysis['event_type'] = 'discharge_event';
            $analysis['status_change'] = 'laden_to_ballast';
            
            // Check if change matches expected discharge pattern
            if (abs(abs($draughtChange) - $vesselInfo['typical_change']) <= 1.0) {
                $analysis['pattern_match'] = 'high';
            } elseif (abs(abs($draughtChange) - $vesselInfo['typical_change']) <= 2.0) {
                $analysis['pattern_match'] = 'medium';
            } else {
                $analysis['pattern_match'] = 'low';
            }
        }
        
        // Check if draught values are within expected ranges
        $analysis['draught_validation'] = $this->validateDraughtRanges($before, $after, $vesselInfo);
        
        return $analysis;
    }
    
    private function validateDraughtRanges($before, $after, $vesselInfo) {
        $validation = [
            'before_status' => 'unknown',
            'after_status' => 'unknown',
            'range_consistency' => 'inconsistent'
        ];
        
        // Determine status based on draught ranges
        $ballastThreshold = $vesselInfo['ballast_draught'] + 1.0;
        $ladenThreshold = $vesselInfo['laden_draught'] - 1.0;
        
        $validation['before_status'] = $before <= $ballastThreshold ? 'ballast' : 
                                     ($before >= $ladenThreshold ? 'laden' : 'transition');
        $validation['after_status'] = $after <= $ballastThreshold ? 'ballast' : 
                                    ($after >= $ladenThreshold ? 'laden' : 'transition');
        
        // Check consistency
        if (($validation['before_status'] === 'ballast' && $validation['after_status'] === 'laden') ||
            ($validation['before_status'] === 'laden' && $validation['after_status'] === 'ballast')) {
            $validation['range_consistency'] = 'consistent';
        }
        
        return $validation;
    }
    
    private function calculateCargoMT($draughtChange, $vesselInfo, $productType) {
        $density = $this->productDensity[$productType] ?? 0.86; // Default to Crude Medium
        
        // Developer Note 4: cargo_mt = change * mt_per_meter * density
        $cargoMT = abs($draughtChange) * $vesselInfo['mt_per_meter'] * $density;
        
        return round($cargoMT, 2);
    }
    
    private function getVesselInfo($vesselType) {
        return $this->vesselTypes[$vesselType] ?? null;
    }
    
    private function getZonePermissibleDraft($zone) {
        return $this->permissibleDrafts[$zone] ?? null;
    }
    
    private function calculateConfidence($result, $vesselInfo, $draughtChange) {
        $confidence = 0.5; // Base confidence
        
        // Factor 1: Pattern match with vessel type expectations
        if (isset($result['pattern_match'])) {
            switch ($result['pattern_match']) {
                case 'high': $confidence += 0.3; break;
                case 'medium': $confidence += 0.15; break;
                case 'low': $confidence += 0.05; break;
            }
        }
        
        // Factor 2: Draught range consistency
        if ($result['draught_validation']['range_consistency'] === 'consistent') {
            $confidence += 0.2;
        }
        
        // Factor 3: Cargo quantity significance
        if (abs($result['cargo_mt']) >= 1000) {
            $confidence += 0.1;
        }
        
        // Factor 4: Change exceeds noise floor significantly
        if (abs($draughtChange) > $this->threshold * 2) {
            $confidence += 0.1;
        }
        
        return min(round($confidence, 2), 1.0);
    }
    
    public function saveAfterDraughtEvent($eventData) {
        // Save complete after-draught event for voyage reconciliation
        $savedEvent = [
            'event_id' => 'AD_' . uniqid(),
            'sts_reference' => $eventData['sts_event_id'],
            'timestamp' => $eventData['timestamp'],
            'zone' => $eventData['zone_name'],
            'vessel_type' => $eventData['vessel_type'],
            'before_draught' => $eventData['before_draught'],
            'after_draught' => $eventData['after_draught'],
            'draught_change' => $eventData['draught_change'],
            'cargo_mt' => $eventData['cargo_mt'],
            'event_type' => $eventData['event_type'],
            'status_change' => $eventData['status_change'],
            'product_type' => $eventData['product_type'],
            'confidence' => $eventData['confidence'],
            'exceeded_permissible' => $eventData['exceeded_permissible'],
            'ais_source' => $eventData['ais_source'],
            'calculated_at' => date('Y-m-d H:i:s')
        ];
        
        // Database save operation would go here
        // Example: $this->db->insert('after_draught_events', $savedEvent);
        
        return $savedEvent;
    }
}

// Usage Example:
$calculator = new CoastalynkAfterDraughtCalculator();

// Simulate STS detection event
$stsEvent = [
    'id' => 'STS_001',
    'sts_detected' => true,
    'timestamp' => '2024-01-15 14:30:00',
    'location' => 'Bonny Access'
];

// Vessel data from AIS/Datalastic after STS
$vesselData = [
    'draught_before' => 8.5,  // Ballast state for VLCC
    'draught_after' => 13.2,  // After loading
    'zone' => 'Bonny Access',
    'vessel_type' => 'VLCC',
    'product_type' => 'Crude Medium',
    'timestamp' => '2024-01-15 16:45:00',
    'ais_source' => 'AIS_Receiver_001'
];

// Calculate After-Draught
$result = $calculator->calculateAfterDraught($stsEvent, $vesselData);

// Save the event
if (!isset($result['error'])) {
    $savedEvent = $calculator->saveAfterDraughtEvent($result);
}

// Output results
echo "Coastalynk After-Draught Analysis v1.0\n";
echo "=====================================\n";
echo "Vessel: {$result['vessel_type']} | Product: {$result['product_type']}\n";
echo "Zone: {$result['zone_name']} | Confidence: " . ($result['confidence'] * 100) . "%\n";
echo "Draught Change: {$result['before_draught']}m → {$result['after_draught']}m (Δ: {$result['draught_change']}m)\n";
echo "Event Type: {$result['event_type']} | Status: {$result['status_change']}\n";
echo "Cargo Quantity: {$result['cargo_mt']} MT\n";
echo "Permissible Check: " . ($result['exceeded_permissible'] ? 'EXCEEDED' : 'Within limits') . "\n";
echo "Pattern Match: {$result['pattern_match']} | Consistency: {$result['draught_validation']['range_consistency']}\n";

// Voyage Reconciliation Benefits
echo "\nVoyage Reconciliation Output:\n";
echo "✓ STS Event: {$result['sts_event_id']}\n";
echo "✓ Cargo Transfer: " . abs($result['cargo_mt']) . " MT {$result['product_type']}\n";
echo "✓ Load/Discharge: " . ucfirst(str_replace('_', ' ', $result['event_type'])) . "\n";
echo "✓ Zone Compliance: " . ($result['exceeded_permissible'] ? 'VIOLATION' : 'COMPLIANT') . "\n";
?>