<?php

class NigerianPortsAfterDraught {
    
    // Coastalynk After-Draught Dataset v1.0 - Zone Data

    private $zoneData = [
        'BONNY' => [
            'permissible_draught' => 13.3,
            'ballast_range' => [6.0, 8.0],
            'intermediate_range' => [8.0, 11.0],
            'laden_range' => [11.0, 13.3],
            'change_threshold' => 2.5
        ],
        'ONNE' => [
            'permissible_draught' => 11.3,
            'ballast_range' => [5.5, 7.5],
            'intermediate_range' => [7.5, 9.5],
            'laden_range' => [9.5, 11.3],
            'change_threshold' => 2.0
        ],
        'Onne-FOT' => [
            'permissible_draught' => 11.3,
            'ballast_range' => [5.5, 7.5],
            'intermediate_range' => [7.5, 9.5],
            'laden_range' => [9.5, 11.3],
            'change_threshold' => 2.0
        ],
        'FOT-FLT TB' => [
            'permissible_draught' => 10.4,
            'ballast_range' => [5.0, 7.0],
            'intermediate_range' => [7.0, 8.5],
            'laden_range' => [8.5, 10.4],
            'change_threshold' => 1.8
        ],
        'FOT-FLT3' => [
            'permissible_draught' => 9.7,
            'ballast_range' => [4.5, 6.5],
            'intermediate_range' => [6.5, 8.0],
            'laden_range' => [8.0, 9.7],
            'change_threshold' => 1.5
        ],
        'Lagos Offshore Zone' => [
            'permissible_draught' => 14.5,
            'ballast_range' => [6.5, 9.0],
            'intermediate_range' => [9.0, 12.0],
            'laden_range' => [12.0, 14.5],
            'change_threshold' => 3.0
        ],
        'LAGOS' => [
            'permissible_draught' => 14.5,
            'ballast_range' => [6.5, 9.0],
            'intermediate_range' => [9.0, 12.0],
            'laden_range' => [12.0, 14.5],
            'change_threshold' => 3.0
        ],
        'Lagos Complex' => [
            'permissible_draught' => 13.5,
            'ballast_range' => [6.0, 8.5],
            'intermediate_range' => [8.5, 11.0],
            'laden_range' => [11.0, 13.5],
            'change_threshold' => 2.5
        ],
        'Tin-Can Island Complex' => [
            'permissible_draught' => 13.5,
            'ballast_range' => [6.0, 8.5],
            'intermediate_range' => [8.5, 11.0],
            'laden_range' => [11.0, 13.5],
            'change_threshold' => 2.5
        ]
    ];


    // Comprehensive Tanker Specifications
    private $tankerSpecs = [
        'Coastal' => [
                'min' => 1,
                'max' => 5000,
                'name' => 'Small coastal tankers'
            ],
        'MR' => [
            'name' => 'Medium Range',
            'dwt_range' => '35,000-55,000 DWT',
            'min' => 35000,
            'max' => 55000,
            'typical_cargo' => ['PMS', 'AGO', 'DPK', 'Jet A1', 'Condensate'],
            'laden_draught' => 12.0,
            'ballast_draught' => 7.0,
            'max_draught_change' => 2.5,
            'displacement_mt_m' => 8000,
            'common_ports' => ['Apapa Circle', 'TinCan Circle', 'Atlas Cove']
        ],
        
        'LR1' => [
            'name' => 'Long Range 1',
            'min' => 55000,
            'max' => 80000,
            'dwt_range' => '55,000-80,000 DWT',
            'typical_cargo' => ['Crude Light', 'AGO', 'PMS', 'Base Oil'],
            'laden_draught' => 13.5,
            'ballast_draught' => 8.0,
            'max_draught_change' => 3.0,
            'displacement_mt_m' => 12000,
            'common_ports' => ['Bonny Access', 'Atlas Cove', 'Lagos Fairway']
        ],
        
        'LR2' => [
            'name' => 'Long Range 2',
            'min' => 80000,
            'max' => 160000,
            'dwt_range' => '80,000-160,000 DWT',
            'typical_cargo' => ['Crude Light', 'Crude Medium', 'AGO'],
            'laden_draught' => 15.0,
            'ballast_draught' => 9.5,
            'max_draught_change' => 3.5,
            'displacement_mt_m' => 16000,
            'common_ports' => ['Bonny Access', 'Lagos Fairway']
        ],
        
        'Aframax' => [
            'name' => 'Aframax',
            'min' => 80000,
            'max' => 120000,
            'dwt_range' => '80,000-120,000 DWT',
            'typical_cargo' => ['Crude Light', 'Crude Medium'],
            'laden_draught' => 14.8,
            'ballast_draught' => 9.0,
            'max_draught_change' => 3.0,
            'displacement_mt_m' => 18000,
            'common_ports' => ['Bonny Access', 'Lagos Fairway'],
            'description' => 'Standard tanker for crude oil transportation, named after AFRA (Average Freight Rate Assessment)'
        ],
        
        'Suezmax' => [
            'name' => 'Suezmax',
            'min' => 120000,
            'max' => 200000,
            'dwt_range' => '120,000-200,000 DWT',
            'typical_cargo' => ['Crude Light', 'Crude Medium', 'Crude Heavy'],
            'laden_draught' => 17.0,
            'ballast_draught' => 10.0,
            'max_draught_change' => 4.0,
            'displacement_mt_m' => 22000,
            'common_ports' => ['Bonny Access'],
            'description' => 'Largest tanker that can transit the Suez Canal fully loaded'
        ],
        
        'VLCC' => [
            'name' => 'Very Large Crude Carrier',
            'min' => 200000,
            'max' => 320000,
            'dwt_range' => '200,000-320,000 DWT',
            'typical_cargo' => ['Crude Light', 'Crude Medium'],
            'laden_draught' => 20.5,
            'ballast_draught' => 12.0,
            'max_draught_change' => 5.0,
            'displacement_mt_m' => 30000,
            'common_ports' => ['Bonny Access'],
            'description' => 'Ultra-large tankers for long-haul crude transportation'
        ],
        'ULCC' => [
            'name' => 'Ultra Large Crude Carrier',
            'dwt_range' => '320,000-550,000 DWT',
            'min' => 320000,
            'max' => 550000,
            'typical_cargo' => ['Crude Light', 'Crude Medium'],
            'laden_draught' => 20.5,
            'ballast_draught' => 12.0,
            'max_draught_change' => 5.0,
            'displacement_mt_m' => 30000,
            'common_ports' => ['Bonny Access'],
            'description' => 'Ultra Large Crude Carrier'
        ],
        'Chemical' => [
            'name' => 'Chemical Tanker',
            'dwt_range' => '5,000-40,000 DWT',
            'min' => 5000,
            'max' => 40000,
            'typical_cargo' => ['Base Oil', 'Chemicals', 'Specialty Products'],
            'laden_draught' => 10.0,
            'ballast_draught' => 6.0,
            'max_draught_change' => 1.5,
            'displacement_mt_m' => 4000,
            'common_ports' => ['Apapa Circle', 'TinCan Circle', 'Onne-FOT'],
            'description' => 'Specialized tankers with coated tanks for chemical transportation'
        ],
        
        'LPG' => [
            'name' => 'Liquefied Petroleum Gas Carrier',
            'min' => 10000,
            'max' => 80000,
            'dwt_range' => '10,000-80,000 DWT',
            'typical_cargo' => ['LPG', 'Propane', 'Butane'],
            'laden_draught' => 9.0,
            'ballast_draught' => 6.0,
            'max_draught_change' => 1.0,
            'displacement_mt_m' => 2500,
            'common_ports' => ['Atlas Cove', 'Lagos Fairway'],
            'description' => 'Pressurized or refrigerated carriers for LPG transportation'
        ],
        
        'LNG' => [
            'name' => 'Liquefied Natural Gas Carrier',
            'min' => 120000,
            'max' => 260000,
            'dwt_range' => '120,000-260,000 DWT',
            'typical_cargo' => ['LNG'],
            'laden_draught' => 12.0,
            'ballast_draught' => 9.0,
            'max_draught_change' => 1.0,
            'displacement_mt_m' => 3500,
            'common_ports' => ['Bonny Access', 'Lagos Fairway'],
            'description' => 'Advanced cryogenic tankers for LNG transportation at -162°C'
        ]
    ];

    // Product Density Table
    private $productDensity = [
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

    private $noiseFloor = 0.1; // meters for small fluctuations
    private $stsDetected = false;

    public function __construct($stsDetected = false) {
        $this->stsDetected = $stsDetected;
    }

    public function setSTSDetection($detected) {
        $this->stsDetected = $detected;
    }

    public function getTankerTypeByDWT($dwt) {
        if (!is_numeric($dwt) || $dwt <= 0) {
            return [
                'error' => 'Invalid DWT value',
                'valid_range' => 'DWT must be positive number'
            ];
        }
        
        $matches = [];
        
        foreach ($this->tankerSpecs as $type => $range) {
            if ($dwt >= $range['min'] && $dwt <= $range['max']) {
                $matches[] = [
                    'type' => $type,
                    'dwt_range' => "{$range['min']} - {$range['max']}",
                    'description' => $range['name'],
                    'confidence' => $this->calculateConfidence($dwt, $range)
                ];
            }
        }
        
        if (empty($matches)) {
            return [
                'error' => 'No tanker type found for given DWT',
                'dwt' => $dwt,
                'suggestion' => $dwt > 550000 ? 'ULCC (largest classification)' : 'Check DWT value'
            ];
        }
        
        // Sort by confidence (highest first)
        usort($matches, function($a, $b) {
            return $b['confidence'] <=> $a['confidence'];
        });
        
        return [
            'dwt' => $dwt,
            'primary_type' => $matches[0],
            'possible_types' => $matches,
            'all_matches' => count($matches)
        ];
    }
    
    private function calculateConfidence($dwt, $range) {
        $rangeWidth = $range['max'] - $range['min'];
        $positionInRange = ($dwt - $range['min']) / $rangeWidth;
        
        // Higher confidence for values in the middle of the range
        if ($positionInRange >= 0.3 && $positionInRange <= 0.7) {
            return 'high';
        } elseif ($positionInRange >= 0.2 && $positionInRange <= 0.8) {
            return 'medium';
        } else {
            return 'low';
        }
    }

    // MAIN AFTER-DRAUGHT CALCULATION METHOD
    public function calculateAfterDraught($vesselData) {
        /*
        Expected vesselData structure:
        {
            'previous_draught': float,
            'current_draught': float,
            'zone': string,
            'tanker_type': string,
            'product_type': string (optional),
            'timestamp': string,
            'ais_source': string,
            'mmsi': string
        }
        */

        // Check if STS detection is active (if required)
        if ($this->stsDetected && !$this->stsDetected) {
            return "STS detection not active";
        }

        // Step 1: Validate input data
        $validation = $this->validateInput($vesselData);
        if ($validation !== true) {
            return $validation;
        }

        $zone = $vesselData['zone'];
        $previousDraught = $vesselData['previous_draught'];
        $currentDraught = $vesselData['current_draught'];
        $tankerType = $vesselData['tanker_type'];

        // Step 2: Calculate draught change
        $draughtChange = $currentDraught - $previousDraught;
        $absChange = abs($draughtChange);

        // Step 3: Identify vessel status using draught difference
        $zoneThreshold = $this->zoneData[$zone]['change_threshold'];
        
        $previousStatus = $this->determineVesselStatus($previousDraught, $zone);
        $currentStatus = $this->determineVesselStatus($currentDraught, $zone);

        // Step 4: Check for small fluctuations
        if ($absChange < $this->noiseFloor) {
            return $this->createResult(
                $vesselData,
                $draughtChange,
                'No significant change',
                'Small fluctuation below noise floor',
                0,
                0.1
            );
        }

        // Step 5: Check permissible draught
        $permissibleCheck = $this->checkPermissibleDraught($currentDraught, $zone);
        if ($permissibleCheck !== true) {
            return $this->createResult(
                $vesselData,
                $draughtChange,
                'Violation',
                $permissibleCheck,
                0,
                0.9
            );
        }

        // Step 6: Apply After-Draught logic
        $afterDraughtEvent = $this->analyzeAfterDraughtEvent(
            $previousStatus,
            $currentStatus,
            $draughtChange,
            $zoneThreshold,
            $previousDraught,
            $currentDraught
        );

        // Step 7: Calculate cargo estimation if applicable
        $cargoMT = 0;
        if ($afterDraughtEvent['status'] !== 'No significant change') {
            $cargoMT = $this->calculateCargoMT(
                $draughtChange,
                $tankerType,
                $vesselData['product_type'] ?? null
            );
        }

        // Step 8: Return complete event
        return $this->createResult(
            $vesselData,
            $draughtChange,
            $afterDraughtEvent['status'],
            $afterDraughtEvent['description'],
            $cargoMT,
            $afterDraughtEvent['confidence'],
            $previousStatus,
            $currentStatus
        );
    }

    private function determineVesselStatus($draught, $zone) {
        $zoneInfo = $this->zoneData[$zone];
        
        if ($draught <= $zoneInfo['ballast_range'][1]) {
            return 'Ballast';
        } elseif ($draught > $zoneInfo['intermediate_range'][0] && $draught < $zoneInfo['intermediate_range'][1] ) {
            return 'Intermediate';
        } elseif ($draught >= $zoneInfo['laden_range'][0]) {
            return 'Laden';
        } else {
            return 'Intermediate';
        }
    }

    private function analyzeAfterDraughtEvent($previousStatus, $currentStatus, $draughtChange, $zoneThreshold, $prevDraught, $currDraught) {
        $absChange = abs($draughtChange);

        // Check if change meets threshold for significant event
        if ($absChange < $zoneThreshold) {
            return [
                'status' => 'No significant change',
                'description' => "Draught change {$absChange}m below zone threshold {$zoneThreshold}m",
                'confidence' => 0.3
            ];
        }

        // After-Draught logic
        if ($previousStatus === 'Ballast' && $draughtChange > 0) {
            return [
                'status' => 'After-Laden',
                'description' => "Vessel was Ballast → now increased to Laden state",
                'confidence' => 0.95
            ];
        } elseif ($previousStatus === 'Laden' && $draughtChange < 0) {
            return [
                'status' => 'After-Ballast',
                'description' => "Vessel was Laden → now decreased to Ballast state", 
                'confidence' => 0.95
            ];
        } elseif ($previousStatus === 'Intermediate' && $absChange >= $zoneThreshold) {
            if ($draughtChange > 0) {
                return [
                    'status' => 'After-Laden',
                    'description' => "Vessel from Intermediate → Laden state",
                    'confidence' => 0.8
                ];
            } else {
                return [
                    'status' => 'After-Ballast', 
                    'description' => "Vessel from Intermediate → Ballast state",
                    'confidence' => 0.8
                ];
            }
        } else {
            return [
                'status' => 'Status maintained',
                'description' => "Vessel maintained {$previousStatus} state with significant change",
                'confidence' => 0.6
            ];
        }
    }

    private function calculateCargoMT($draughtChange, $tankerType, $productType = null) {
        if (!isset($this->tankerSpecs[$tankerType])) {
            return 0;
        }

        $mtPerMeter = $this->tankerSpecs[$tankerType]['displacement_mt_m'];
        $density = 1.0; // Default salt water density

        if ($productType && isset($this->productDensity[$productType])) {
            $density = $this->productDensity[$productType];
        }

        $cargoMT = abs($draughtChange) * $mtPerMeter * $density;
        
        // Only return if significant cargo (above 50 MT)
        return $cargoMT >= 50 ? round($cargoMT, 1) : 0;
    }

    private function checkPermissibleDraught($draught, $zone) {
        $maxPermissible = $this->zoneData[$zone]['permissible_draught'];
        
        if ($draught > $maxPermissible) {
            return "Exceeded NPA permissible draught: {$draught}m > {$maxPermissible}m";
        }
        
        return true;
    }

    private function validateInput($vesselData) {
        $requiredFields = ['previous_draught', 'current_draught', 'zone', 'tanker_type', 'timestamp', 'ais_source'];
        
        foreach ($requiredFields as $field) {
            if (!isset($vesselData[$field])) {
                return "Missing required field: {$field}";
            }
        }

        if (!isset($this->zoneData[$vesselData['zone']])) {
            return "Invalid zone: {$vesselData['zone']}";
        }

        if (!isset($this->tankerSpecs[$vesselData['tanker_type']])) {
            return "Invalid tanker type: {$vesselData['tanker_type']}";
        }

        if ($vesselData['previous_draught'] <= 0 || $vesselData['current_draught'] <= 0) {
            return "Invalid draught values: must be positive";
        }

        return true;
    }

    private function createResult($vesselData, $draughtChange, $status, $description, $cargoMT, $confidence, $previousStatus = null, $currentStatus = null) {
        $zoneInfo = $this->zoneData[$vesselData['zone']];
        $tankerInfo = $this->tankerSpecs[$vesselData['tanker_type']];
        
        if ($previousStatus === null) {
            $previousStatus = $this->determineVesselStatus($vesselData['previous_draught'], $vesselData['zone']);
        }
        if ($currentStatus === null) {
            $currentStatus = $this->determineVesselStatus($vesselData['current_draught'], $vesselData['zone']);
        }

        return [
            // Core identification
            'mmsi' => $vesselData['mmsi'] ?? 'Unknown',
            'ais_source' => $vesselData['ais_source'],
            'timestamp' => $vesselData['timestamp'],
            
            // Location context
            'zone' => $vesselData['zone'],
            'permissible_draught' => $zoneInfo['permissible_draught'],
            
            // Draught measurements
            'before_draught' => round($vesselData['previous_draught'], 2),
            'after_draught' => round($vesselData['current_draught'], 2),
            'draught_change' => round($draughtChange, 2),
            'abs_draught_change' => round(abs($draughtChange), 2),
            
            // Status analysis
            'previous_status' => $previousStatus,
            'current_status' => $currentStatus,
            'event_status' => $status,
            'event_description' => $description,
            
            // Cargo estimation
            'cargo_mt' => $cargoMT,
            'tanker_type' => $vesselData['tanker_type'],
            'tanker_name' => $tankerInfo['name'],
            'product_type' => $vesselData['product_type'] ?? 'Unknown',
            
            // Quality metrics
            'confidence' => $confidence,
            'zone_threshold' => $zoneInfo['change_threshold'],
            
            // Voyage reconciliation data
            'ballast_range' => $zoneInfo['ballast_range'],
            'laden_range' => $zoneInfo['laden_range'],
            'intermediate_range' => $zoneInfo['intermediate_range'],
            // Timestamps
            'processed_at' => date('Y-m-d H:i:s')
        ];
    }

    // PUBLIC UTILITY METHODS
    public function getTankerDetails($tankerType) {
        return isset($this->tankerSpecs[$tankerType]) ? $this->tankerSpecs[$tankerType] : "Tanker type '$tankerType' not found";
    }

    public function getZoneInfo($zone) {
        return isset($this->zoneData[$zone]) ? $this->zoneData[$zone] : null;
    }

    public function getAllTankerTypes() {
        return array_keys($this->tankerSpecs);
    }

    public function getAllZones() {
        return array_keys($this->zoneData);
    }

    public function getAllProducts() {
        return array_keys($this->productDensity);
    }

    public function getTankersForPort($port) {
        $suitableTankers = [];
        
        foreach ($this->tankerSpecs as $type => $specs) {
            if (in_array($port, $specs['common_ports'])) {
                $suitableTankers[$type] = $specs;
            }
        }
        
        return $suitableTankers;
    }

    public function suggestOptimalTanker($cargoType, $cargoVolumeMT, $destinationPort) {
        $suitableTankers = [];
        
        foreach ($this->tankerSpecs as $type => $specs) {
            // Check if tanker can carry this cargo type
            if (in_array($cargoType, $specs['typical_cargo'])) {
                // Check if tanker can access the port
                if (in_array($destinationPort, $specs['common_ports'])) {
                    $suitableTankers[$type] = $specs;
                }
            }
        }
        
        return $suitableTankers;
    }

    public function displayTankerSummary() {
        echo "=== TANKER SPECIFICATIONS SUMMARY ===\n\n";
        
        foreach ($this->tankerSpecs as $type => $specs) {
            echo "{$type} ({$specs['name']})\n";
            echo "DWT Range: {$specs['dwt_range']}\n";
            echo "Draught: {$specs['ballast_draught']}m (ballast) → {$specs['laden_draught']}m (laden)\n";
            echo "Max Change: {$specs['max_draught_change']}m\n";
            echo "Displacement: {$specs['displacement_mt_m']} MT/m\n";
            echo "Typical Cargo: " . implode(', ', $specs['typical_cargo']) . "\n";
            echo "Common Ports: " . implode(', ', $specs['common_ports']) . "\n";
            
            if (isset($specs['description'])) {
                echo "Description: {$specs['description']}\n";
            }
            echo "----------------------------------------\n";
        }
    }
}

// DEMONSTRATION FUNCTION
function demonstrateCompleteSystem() {
    $calculator = new NigerianPortsAfterDraught(true);
    
    echo "<pre>=== NPA AFTER-DRAUGHT LOGIC SYSTEM - COMPLETE DEMO ===\n\n";

    // Display tanker summary
    $calculator->displayTankerSummary();

    echo "\n=== AFTER-DRAUGHT CALCULATIONS ===\n\n";

    // Example 1: VLCC loading at Bonny Access (Ballast → Laden)
    $example1 = [
        'previous_draught' => 8.5,
        'current_draught' => 12.8,
        'zone' => 'Bonny Access',
        'tanker_type' => 'VLCC',
        'product_type' => 'Crude Light',
        'timestamp' => '2024-01-15 14:30:00',
        'ais_source' => 'AIS Station Bonny',
        'mmsi' => '123456789'
    ];

    echo "Example 1: VLCC Loading at Bonny Access\n";
    $result1 = $calculator->calculateAfterDraught($example1);
    print_r($result1);
    echo "\n";

    // Example 2: MR discharging at Apapa (Laden → Ballast)
    $example2 = [
        'previous_draught' => 11.5,
        'current_draught' => 8.0,
        'zone' => 'Apapa Circle',
        'tanker_type' => 'MR',
        'product_type' => 'PMS',
        'timestamp' => '2024-01-15 16:45:00',
        'ais_source' => 'AIS Station Lagos',
        'mmsi' => '987654321'
    ];

    echo "Example 2: MR Discharging at Apapa\n";
    $result2 = $calculator->calculateAfterDraught($example2);
    print_r($result2);
    echo "\n";

    // Example 3: Tanker information query
    echo "Example 3: Suezmax Tanker Details\n";
    $suezmaxInfo = $calculator->getTankerDetails('Suezmax');
    print_r($suezmaxInfo);
    echo "\n";

    // Example 4: Location information
    echo "Example 4: Bonny Access Location Details\n";
    $bonnyInfo = $calculator->getZoneInfo('Bonny Access');
    print_r($bonnyInfo);
    echo "\n";

    // Example 5: Tanker suggestion
    echo "Example 5: Tanker suggestion for Crude Light to Bonny Access\n";
    $suggestedTankers = $calculator->suggestOptimalTanker('Crude Light', 100000, 'Bonny Access');
    foreach ($suggestedTankers as $type => $details) {
        echo " - $type: {$details['dwt_range']} ({$details['name']})\n";
    }
    echo "\n";

    // Example 6: Available zones
    echo "Example 6: All Available Zones\n";
    $zones = $calculator->getAllZones();
    print_r($zones);
}

// Uncomment to run demonstration
// demonstrateCompleteSystem();