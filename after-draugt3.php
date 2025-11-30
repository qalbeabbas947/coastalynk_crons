<?php

class TankerSpecifications {
    
    // Comprehensive Tanker Specifications
    private $tankerSpecs = [
        'MR' => [
            'name' => 'Medium Range',
            'dwt_range' => '35,000-55,000 DWT',
            'typical_cargo' => ['PMS', 'AGO', 'DPK', 'Jet A1', 'Condensate'],
            'laden_draught' => 12.0,
            'ballast_draught' => 7.0,
            'max_draught_change' => 2.5,
            'displacement_mt_m' => 8000,
            'common_ports' => ['Apapa Circle', 'TinCan Circle', 'Atlas Cove']
        ],
        
        'LR1' => [
            'name' => 'Long Range 1',
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
            'dwt_range' => '200,000-320,000 DWT',
            'typical_cargo' => ['Crude Light', 'Crude Medium'],
            'laden_draught' => 20.5,
            'ballast_draught' => 12.0,
            'max_draught_change' => 5.0,
            'displacement_mt_m' => 30000,
            'common_ports' => ['Bonny Access'],
            'description' => 'Ultra-large tankers for long-haul crude transportation'
        ],
        
        'Chemical' => [
            'name' => 'Chemical Tanker',
            'dwt_range' => '5,000-40,000 DWT',
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
    
    public function getTankerDetails($tankerType) {
        if (!isset($this->tankerSpecs[$tankerType])) {
            return "Tanker type '$tankerType' not found";
        }
        
        return $this->tankerSpecs[$tankerType];
    }
    
    public function getAllTankerTypes() {
        return array_keys($this->tankerSpecs);
    }
    
    public function getTankersByDWT($minDWT = 0, $maxDWT = 999999) {
        $filtered = [];
        
        foreach ($this->tankerSpecs as $type => $specs) {
            // Extract DWT range from string (e.g., "35,000-55,000 DWT")
            $dwtRange = str_replace([' DWT', ','], '', $specs['dwt_range']);
            list($min, $max) = explode('-', $dwtRange);
            
            if ($min >= $minDWT && $max <= $maxDWT) {
                $filtered[$type] = $specs;
            }
        }
        
        return $filtered;
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

class NigerianPortsAfterDraught {
    
    private $tankerSpecs;
    
    // Tanker Draught Behavior Table (from original dataset)
    private $tankerDraughts = [
        'MR' => ['laden' => 12.0, 'ballast' => 7.0, 'change' => 2.5],
        'LR1' => ['laden' => 13.5, 'ballast' => 8.0, 'change' => 3.0],
        'LR2' => ['laden' => 15.0, 'ballast' => 9.5, 'change' => 3.5],
        'Aframax' => ['laden' => 14.8, 'ballast' => 9.0, 'change' => 3.0],
        'Suezmax' => ['laden' => 17.0, 'ballast' => 10.0, 'change' => 4.0],
        'VLCC' => ['laden' => 20.5, 'ballast' => 12.0, 'change' => 5.0],
        'Chemical' => ['laden' => 10.0, 'ballast' => 6.0, 'change' => 1.5],
        'LPG' => ['laden' => 9.0, 'ballast' => 6.0, 'change' => 1.0],
        'LNG' => ['laden' => 12.0, 'ballast' => 9.0, 'change' => 1.0]
    ];
    
    // Displacement Curves (MT per meter)
    private $displacementCurves = [
        'MR' => 8000,
        'LR1' => 12000,
        'LR2' => 16000,
        'Aframax' => 18000,
        'Suezmax' => 22000,
        'VLCC' => 30000,
        'Chemical' => 4000,
        'LPG' => 2500,
        'LNG' => 3500
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
    
    // Permissible Drafts (Extract)
    private $permissibleDrafts = [
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
    
    private $noiseFloor = 0.1; // meters
    private $stsDetected = false;
    
    public function __construct($stsDetected = false) {
        $this->stsDetected = $stsDetected;
        $this->tankerSpecs = new TankerSpecifications();
    }
    
    public function setSTSDetection($detected) {
        $this->stsDetected = $detected;
    }
    
    public function calculateAfterDraught($tankerType, $beforeDraught, $afterDraught, $location, $productType = null) {
        // Check if STS detection is active
        if (!$this->stsDetected) {
            return "STS detection not active";
        }
        
        // Validate tanker type
        if (!isset($this->tankerDraughts[$tankerType])) {
            return "Invalid tanker type: $tankerType";
        }
        
        // Validate location
        if (!isset($this->permissibleDrafts[$location])) {
            return "Invalid location: $location";
        }
        
        // Calculate draught change
        $draughtChange = $afterDraught - $beforeDraught;
        
        // Check against noise floor
        if (abs($draughtChange) < $this->noiseFloor) {
            return "No significant change yet (change: " . round($draughtChange, 2) . "m)";
        }
        
        // Check permissible draft
        $maxPermissible = $this->permissibleDrafts[$location];
        if ($afterDraught > $maxPermissible) {
            return "Draft exceeds permissible limit for $location (Max: {$maxPermissible}m, Actual: {$afterDraught}m)";
        }
        
        // Get displacement and calculate cargo
        $mtPerMeter = $this->displacementCurves[$tankerType];
        $density = 1.0; // Default density for water
        
        if ($productType && isset($this->productDensity[$productType])) {
            $density = $this->productDensity[$productType];
        }
        
        $cargoMT = $draughtChange * $mtPerMeter * $density;
        
        // Check minimum cargo threshold
        if (abs($cargoMT) < 50) {
            return "No significant change yet (cargo: " . round($cargoMT, 1) . " MT)";
        }
        
        // Determine operation type
        $operation = $draughtChange > 0 ? "Loading" : "Discharging";
        
        return [
            'tanker_type' => $tankerType,
            'tanker_name' => $this->tankerSpecs->getTankerDetails($tankerType)['name'],
            'location' => $location,
            'before_draught' => $beforeDraught,
            'after_draught' => $afterDraught,
            'draught_change' => round($draughtChange, 2),
            'operation' => $operation,
            'cargo_mt' => round(abs($cargoMT), 1),
            'product_type' => $productType,
            'density_used' => $density,
            'permissible_check' => "OK (Max: {$maxPermissible}m)",
            'status' => 'Calculated'
        ];
    }
    
    public function getTankerInfo($tankerType) {
        return $this->tankerSpecs->getTankerDetails($tankerType);
    }
    
    public function getLocationInfo($location) {
        if (!isset($this->permissibleDrafts[$location])) {
            return "Location not found";
        }
        
        return [
            'location' => $location,
            'max_permissible_draft' => $this->permissibleDrafts[$location] . 'm',
            'suitable_tankers' => $this->tankerSpecs->getTankersForPort($location)
        ];
    }
    
    public function getAllTankerTypes() {
        return $this->tankerSpecs->getAllTankerTypes();
    }
    
    public function getAllLocations() {
        return array_keys($this->permissibleDrafts);
    }
    
    public function getAllProducts() {
        return array_keys($this->productDensity);
    }
    
    public function suggestOptimalTanker($cargoType, $cargoVolumeMT, $destinationPort) {
        $suitableTankers = [];
        
        foreach ($this->tankerSpecs->getAllTankerTypes() as $tankerType) {
            $specs = $this->tankerSpecs->getTankerDetails($tankerType);
            
            // Check if tanker can carry this cargo type
            if (in_array($cargoType, $specs['typical_cargo'])) {
                // Check if tanker can access the port
                if (in_array($destinationPort, $specs['common_ports'])) {
                    $suitableTankers[$tankerType] = $specs;
                }
            }
        }
        
        return $suitableTankers;
    }
    
    public function displayTankerSummary() {
        $this->tankerSpecs->displayTankerSummary();
    }
}

// Demonstration function
function demonstrateCombinedSystem() {
    $calculator = new NigerianPortsAfterDraught(true);
    
    echo "<pre>=== NIGERIAN PORTS AFTER-DRAUGHT LOGIC SYSTEM ===\n\n";
    
    // Display tanker summary
    $calculator->displayTankerSummary();
    
    echo "\n=== AFTER-DRAUGHT CALCULATIONS ===\n\n";
    
    // Example 1: VLCC loading crude at Bonny
    echo "Example 1: VLCC loading crude at Bonny Access\n";
    $result1 = $calculator->calculateAfterDraught(
        'VLCC', 
        12.0, 
        17.5, 
        'Bonny Access', 
        'Crude Light'
    );
    print_r($result1);
    echo "\n";
    
    // Example 2: MR discharging at Apapa
    echo "Example 2: MR discharging at Apapa Circle\n";
    $result2 = $calculator->calculateAfterDraught(
        'MR', 
        10.0, 
        8.5, 
        'Apapa Circle', 
        'PMS'
    );
    print_r($result2);
    echo "\n";
    
    // Example 3: Tanker information
    echo "Example 3: Suezmax Tanker Details\n";
    $suezmaxInfo = $calculator->getTankerInfo('Suezmax');
    print_r($suezmaxInfo);
    echo "\n";
    
    // Example 4: Location information
    echo "Example 4: Bonny Access Location Details\n";
    $bonnyInfo = $calculator->getLocationInfo('Bonny Access');
    print_r($bonnyInfo);
    echo "\n";
    
    // Example 5: Tanker suggestion
    echo "Example 5: Tanker suggestion for Crude Light to Bonny Access\n";
    $suggestedTankers = $calculator->suggestOptimalTanker('Crude Light', 100000, 'Bonny Access');
    foreach ($suggestedTankers as $type => $details) {
        echo " - $type: {$details['dwt_range']} ({$details['name']})\n";
    }
}

// Uncomment to run demonstration
demonstrateCombinedSystem();