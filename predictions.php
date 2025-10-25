<?php
class NigerianPortsPredictor {
    
    private $nigerianPorts = [
        'LAGOS' => ['apapa', 'tin can', 'lilypond', 'kirikiri', 'roro'],
        'PORT HARCOURT' => ['ph', 'onne', 'fot', 'federal ocean terminal'],
        'CALABAR' => ['calabar'],
        'WARRI' => ['warri', 'excravos', 'burutu'],
        'BONNY' => ['bonny', 'nlng'],
        'BRASS' => ['brass']
    ];
    
    private $commonImports = [
        'LAGOS' => ['containers', 'vehicles', 'machinery', 'manufactured_goods', 'food_products'],
        'PORT HARCOURT' => ['oil_equipment', 'chemicals', 'containers', 'vehicles'],
        'CALABAR' => ['containers', 'agricultural_equipment', 'general_cargo'],
        'WARRI' => ['oil_equipment', 'pipe_materials', 'general_cargo'],
        'BONNY' => ['lng_equipment', 'oil_equipment', 'specialized_cargo'],
        'BRASS' => ['oil_equipment', 'offshore_supplies']
    ];
    
    private $commonExports = [
        'LAGOS' => ['agricultural_products', 'cashew', 'cocoa', 'processed_goods'],
        'PORT HARCOURT' => ['crude_oil', 'lng', 'petrochemicals'],
        'CALABAR' => ['timber', 'agricultural_products', 'rubber'],
        'WARRI' => ['crude_oil', 'petroleum_products'],
        'BONNY' => ['lng', 'crude_oil'],
        'BRASS' => ['crude_oil', 'gas']
    ];
    
    /**
     * Predict cargo type for Nigerian ports with high accuracy
     */
    public function predictCargoTypeForNigeria($vesselData, $aisData, $portCallData = null) {
        $vesselType = strtolower($vesselData['type_specific'] ?? $vesselData['type'] ?? '');
        $destination = strtoupper($aisData['destination'] ?? '');
        $lastPort = strtoupper($portCallData['last_port'] ?? '');
        $draught = $aisData['draught'] ?? 0;
        $maxDraught = $vesselData['draught'] ?? 0;
        
        // Determine if vessel is arriving or departing Nigerian port
        $isArriving = $this->isArrivingAtNigerianPort($destination);
        $isDeparting = $this->isDepartingFromNigerianPort($lastPort);
        
        // Get specific Nigerian port
        $nigerianPort = $this->identifyNigerianPort($isArriving ? $destination : $lastPort);
        
        // Vessel type based predictions
        if (str_contains($vesselType, 'container')) {
            return $this->predictContainerCargo($isArriving, $nigerianPort);
        }
        
        if (str_contains($vesselType, 'tanker')) {
            return $this->predictTankerCargo($vesselType, $isArriving, $nigerianPort, $draught, $maxDraught);
        }
        
        if (str_contains($vesselType, 'bulk')) {
            return $this->predictBulkCargo($isArriving, $nigerianPort, $lastPort);
        }
        
        if (str_contains($vesselType, 'vehicle')) {
            return 'Vehicles';
        }
        
        if (str_contains($vesselType, 'gas')) {
            return $isArriving ? 'LNG Equipment' : 'LNG';
        }
        
        return $this->predictGeneralCargo($isArriving, $nigerianPort);
    }
    
    /**
     * Generate remarks specific to Nigerian port operations
     */
    public function generateNigeriaRemarks($vesselData, $aisData, $portData, $historicalData = []) {
        $remarks = [];
        
        $portName = $portData['name'] ?? '';
        $speed = $aisData['speed'] ?? 0;
        $draught = $aisData['draught'] ?? 0;
        $maxDraught = $vesselData['draught'] ?? 0;
        
        // Port-specific remarks
        $portRemark = $this->getNigeriaPortRemark($portName, $speed);
        if ($portRemark) $remarks[] = $portRemark;
        
        // Congestion remarks (common in Nigerian ports)
        $congestionRemark = $this->getCongestionRemark($portName, $historicalData);
        if ($congestionRemark) $remarks[] = $congestionRemark;
        
        // Security level remarks
        $securityRemark = $this->getSecurityRemark($portName);
        if ($securityRemark) $remarks[] = $securityRemark;
        
        // Operational remarks
        $opRemark = $this->getOperationalRemark($vesselData, $draught, $maxDraught);
        if ($opRemark) $remarks[] = $opRemark;
        
        // Waiting time remarks
        $waitingRemark = $this->getWaitingRemark($aisData, $portData);
        if ($waitingRemark) $remarks[] = $waitingRemark;
        
        if (empty($remarks)) {
            $remarks[] = $this->getGeneralNigeriaStatus($speed, $portName);
        }
        
        return implode(', ', $remarks);
    }
    
    /**
     * Predict container cargo for Nigerian ports
     */
    private function predictContainerCargo($isArriving, $port) {
        if ($isArriving) {
            $imports = $this->commonImports[$port] ?? ['manufactured_goods', 'machinery', 'consumer_goods'];
            return ucfirst(str_replace('_', ' ', $imports[0])) . ' (Containers)';
        } else {
            $exports = $this->commonExports[$port] ?? ['agricultural_products', 'raw_materials'];
            return ucfirst(str_replace('_', ' ', $exports[0])) . ' (Containers)';
        }
    }
    
    /**
     * Predict tanker cargo for Nigerian ports
     */
    private function predictTankerCargo($vesselType, $isArriving, $port, $draught, $maxDraught) {
        $loadFactor = $maxDraught > 0 ? $draught / $maxDraught : 0;
        
        if (str_contains($vesselType, 'crude') || str_contains($vesselType, 'oil')) {
            // For crude oil tankers in Nigeria
            if ($isArriving) {
                return 'Fuel Oil'; // Nigeria imports refined products
            } else {
                if ($loadFactor > 0.7) return 'Crude Oil';
                return 'In Ballast';
            }
        }
        
        if (str_contains($vesselType, 'chemical')) {
            return $isArriving ? 'Chemicals' : 'Petrochemicals';
        }
        
        if (str_contains($vesselType, 'product')) {
            return $isArriving ? 'Refined Products' : 'Diesel/Gasoline';
        }
        
        if (str_contains($vesselType, 'lng')) {
            return $isArriving ? 'LNG Equipment' : 'LNG';
        }
        
        return 'Petroleum Products';
    }
    
    /**
     * Predict bulk cargo for Nigerian trade
     */
    private function predictBulkCargo($isArriving, $port, $lastPort) {
        if ($isArriving) {
            // Imports to Nigeria
            if ($port === 'LAGOS') return 'Wheat/Rice';
            if ($port === 'PORT HARCOURT') return 'Cement/Clinker';
            if ($port === 'CALABAR') return 'Fertilizer';
            return 'General Bulk';
        } else {
            // Exports from Nigeria
            if ($port === 'LAGOS') return 'Cashew/Cocoa';
            if ($port === 'PORT HARCOURT') return 'Pet Coke';
            if ($port === 'CALABAR') return 'Timber/Rubber';
            return 'Agricultural Products';
        }
    }
    
    /**
     * Predict general cargo for Nigerian ports
     */
    private function predictGeneralCargo($isArriving, $port) {
        if ($isArriving) {
            return 'General Cargo (Import)';
        } else {
            return 'General Cargo (Export)';
        }
    }
    
    /**
     * Check if vessel is arriving at Nigerian port
     */
    private function isArrivingAtNigerianPort($destination) {
        foreach ($this->nigerianPorts as $port => $aliases) {
            foreach ($aliases as $alias) {
                if (stripos($destination, $alias) !== false) {
                    return true;
                }
            }
        }
        return false;
    }
    
    /**
     * Check if vessel is departing from Nigerian port
     */
    private function isDepartingFromNigerianPort($lastPort) {
        foreach ($this->nigerianPorts as $port => $aliases) {
            foreach ($aliases as $alias) {
                if (stripos($lastPort, $alias) !== false) {
                    return true;
                }
            }
        }
        return false;
    }
    
    /**
     * Identify specific Nigerian port
     */
    private function identifyNigerianPort($location) {
        foreach ($this->nigerianPorts as $port => $aliases) {
            foreach ($aliases as $alias) {
                if (stripos($location, $alias) !== false) {
                    return $port;
                }
            }
        }
        return 'LAGOS'; // Default to Lagos
    }
    
    /**
     * Get Nigeria-specific port remarks
     */
    private function getNigeriaPortRemark($portName, $speed) {
        $portName = strtoupper($portName);
        
        if ($speed < 0.5) {
            if (str_contains($portName, 'APAPA') || str_contains($portName, 'TIN CAN')) {
                return 'Berthed at Lagos Port (High Congestion Area)';
            }
            if (str_contains($portName, 'ONNE')) {
                return 'Berthed at Onne Port (Oil & Gas Hub)';
            }
            if (str_contains($portName, 'BONNY')) {
                return 'Berthed at Bonny (NLNG Terminal)';
            }
            return "Berthed at {$portName}";
        }
        
        if ($speed < 2) {
            return "Maneuvering at {$portName}";
        }
        
        return "At {$portName} Anchorage";
    }
    
    /**
     * Get congestion remarks for Nigerian ports
     */
    private function getCongestionRemark($portName, $historicalData) {
        $congestionLevel = $historicalData['congestion_level'] ?? 'medium';
        $waitingTime = $historicalData['avg_waiting_time'] ?? 0;
        
        $portName = strtoupper($portName);
        
        // Known congested ports in Nigeria
        $congestedPorts = ['APAPA', 'TIN CAN', 'LAGOS'];
        
        foreach ($congestedPorts as $congestedPort) {
            if (str_contains($portName, $congestedPort)) {
                if ($waitingTime > 48) {
                    return 'Severe Congestion (>48h wait)';
                } elseif ($waitingTime > 24) {
                    return 'High Congestion (>24h wait)';
                } else {
                    return 'Moderate Congestion';
                }
            }
        }
        
        return null;
    }
    
    /**
     * Get security level remarks for Nigerian waters
     */
    private function getSecurityRemark($portName) {
        $highRiskAreas = ['BONNY', 'BRASS', 'WARRI', 'ESCRAVOS'];
        
        foreach ($highRiskAreas as $area) {
            if (str_contains(strtoupper($portName), $area)) {
                return 'High Security Area - Piracy Risk';
            }
        }
        
        if (str_contains(strtoupper($portName), 'NIGERIA')) {
            return 'Enhanced Security Protocols';
        }
        
        return null;
    }
    
    /**
     * Get operational remarks
     */
    private function getOperationalRemark($vesselData, $draught, $maxDraught) {
        if ($maxDraught <= 0) return null;
        
        $ratio = $draught / $maxDraught;
        
        if ($ratio > 0.8) {
            return 'Fully Laden';
        } elseif ($ratio < 0.4) {
            return 'In Ballast';
        } elseif ($ratio > 0.9) {
            return 'Heavy Load - Draft Restrictions';
        }
        
        return null;
    }
    
    /**
     * Get waiting time remarks
     */
    private function getWaitingRemark($aisData, $portData) {
        $speed = $aisData['speed'] ?? 0;
        $isAtAnchorage = $portData['is_anchorage'] ?? false;
        
        if ($isAtAnchorage && $speed < 1) {
            $waitingHours = $portData['waiting_hours'] ?? 0;
            if ($waitingHours > 24) {
                return "Waiting Pilot {$waitingHours}h";
            } elseif ($waitingHours > 12) {
                return "Queuing for Berth";
            }
        }
        
        return null;
    }
    
    /**
     * Get general status for Nigerian context
     */
    private function getGeneralNigeriaStatus($speed, $portName) {
        if ($speed < 0.5) {
            return 'At Nigerian Port';
        } elseif ($speed < 5) {
            return 'In Nigerian Waters';
        } else {
            return 'Transiting Nigerian EEZ';
        }
    }
    
    /**
     * Get port efficiency rating (based on real-world Nigerian port performance)
     */
    public function getPortEfficiency($portName) {
        $efficiencyRatings = [
            'ONNE' => 'High Efficiency',
            'BONNY' => 'High Efficiency', 
            'NLNG' => 'High Efficiency',
            'APAPA' => 'Low Efficiency',
            'TIN CAN' => 'Low Efficiency',
            'LAGOS' => 'Medium Efficiency',
            'PORT HARCOURT' => 'Medium Efficiency',
            'CALABAR' => 'Medium Efficiency',
            'WARRI' => 'Low Efficiency'
        ];
        
        foreach ($efficiencyRatings as $port => $rating) {
            if (stripos($portName, $port) !== false) {
                return $rating;
            }
        }
        
        return 'Medium Efficiency';
    }
}

// Usage Example for Nigerian Ports:
$nigeriaPredictor = new NigerianPortsPredictor();

// Example 1: Container vessel arriving at Lagos
$vessel1 = [
    'type' => 'Cargo',
    'type_specific' => 'Container Ship',
    'draught' => 13.5
];

$ais1 = [
    'speed' => 0.2,
    'destination' => 'APAPA LAGOS',
    'draught' => 12.8,
    'nav_status' => 'moored'
];

$port1 = [
    'name' => 'APAPA TERMINAL LAGOS',
    'is_anchorage' => false
];

$cargo1 = $nigeriaPredictor->predictCargoTypeForNigeria($vessel1, $ais1);
$remarks1 = $nigeriaPredictor->generateNigeriaRemarks($vessel1, $ais1, $port1);
$efficiency1 = $nigeriaPredictor->getPortEfficiency($port1['name']);

echo "=== NIGERIAN PORT PREDICTION ===\n";
echo "<br>Vessel: Container Ship at Apapa\n";
echo "<br>Predicted Cargo: {$cargo1}\n";
echo "<br>Remarks: {$remarks1}\n";
echo "<br>Port Efficiency: {$efficiency1}\n\n";

// Example 2: Tanker departing from Bonny
$vessel2 = [
    'type' => 'Tanker',
    'type_specific' => 'Crude Oil Tanker',
    'draught' => 22.0
];

$ais2 = [
    'speed' => 8.5,
    'destination' => 'ROTTERDAM',
    'last_port' => 'BONNY',
    'draught' => 20.5,
    'nav_status' => 'under way using engine'
];

$port2 = [
    'name' => 'BONNY NLNG TERMINAL',
    'is_anchorage' => false
];

$historical2 = [
    'congestion_level' => 'low',
    'avg_waiting_time' => 6
];

$cargo2 = $nigeriaPredictor->predictCargoTypeForNigeria($vessel2, $ais2, ['last_port' => 'BONNY']);
$remarks2 = $nigeriaPredictor->generateNigeriaRemarks($vessel2, $ais2, $port2, $historical2);
$efficiency2 = $nigeriaPredictor->getPortEfficiency($port2['name']);

echo "<br><br><br>Vessel: Crude Oil Tanker from Bonny\n";
echo "<br>Predicted Cargo: {$cargo2}\n";
echo "<br>Remarks: {$remarks2}\n";
echo "<br>Port Efficiency: {$efficiency2}\n\n";

// Example 3: Bulk carrier waiting at anchorage
$vessel3 = [
    'type' => 'Cargo', 
    'type_specific' => 'Bulk Carrier',
    'draught' => 10.5
];

$ais3 = [
    'speed' => 0.1,
    'destination' => 'PORT HARCOURT',
    'draught' => 4.2,
    'nav_status' => 'at anchor'
];

$port3 = [
    'name' => 'ONNE PORT',
    'is_anchorage' => true,
    'waiting_hours' => 36
];

$cargo3 = $nigeriaPredictor->predictCargoTypeForNigeria($vessel3, $ais3);
$remarks3 = $nigeriaPredictor->generateNigeriaRemarks($vessel3, $ais3, $port3);
$efficiency3 = $nigeriaPredictor->getPortEfficiency($port3['name']);

echo "<br><br><br>Vessel: Bulk Carrier at Onne Anchorage\n";
echo "<br>Predicted Cargo: {$cargo3}\n";
echo "<br>Remarks: {$remarks3}\n";
echo "<br>Port Efficiency: {$efficiency3}\n";

