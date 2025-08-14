<?php
/**
 * GPS Data Processor
 * Processes GPS points, cleans data, splits into trips, and generates GeoJSON
 */

class GPSProcessor {
    private array $rejects = [];
    private array $validPoints = [];
    private array $trips = [];

    public function processFile(string $filename): void {
        $this->cleanData($filename);
        $this->orderPoints();
        $this->splitIntoTrips();
        $this->generateOutput();
    }

    private function cleanData(string $filename): void {
        $handle = fopen($filename, 'r');
        if (!$handle) {
            throw new Exception("Cannot open file: $filename");
        }

        $lineNumber = 0;
        while (($line = fgets($handle)) !== false) {
            $lineNumber++;
            $line = trim($line);
            if (empty($line)) continue;

            $parts = str_getcsv($line);
            if (count($parts) < 4) {
                $this->rejects[] = "Line $lineNumber: Insufficient columns - $line";
                continue;
            }

            $deviceId = trim($parts[0], '"');
            $lat = (float) trim($parts[1], '"');
            $lon = (float) trim($parts[2], '"');
            $timestamp = trim($parts[3], '"');

            if (!$this->isValidCoordinate($lat, $lon)) {
                $this->rejects[] = "Line $lineNumber: Invalid coordinates ($lat, $lon) - $line";
                continue;
            }

            if (!$this->isValidTimestamp($timestamp)) {
                $this->rejects[] = "Line $lineNumber: Invalid timestamp ($timestamp) - $line";
                continue;
            }

            $this->validPoints[] = [
                'device_id' => $deviceId,
                'lat' => $lat,
                'lon' => $lon,
                'timestamp' => $timestamp,
                'datetime' => new DateTime($timestamp)
            ];
        }

        fclose($handle);
    }

    private function isValidCoordinate(float $lat, float $lon): bool {
        return !is_nan($lat) && !is_nan($lon) && 
               $lat >= -90 && $lat <= 90 && 
               $lon >= -180 && $lon <= 180 &&
               ($lat != 0 || $lon != 0);
    }

    private function isValidTimestamp(string $timestamp): bool {
        try {
            new DateTime($timestamp);
            return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $timestamp) === 1;
        } catch (Exception $e) {
            return false;
        }
    }

    private function orderPoints(): void {
        usort($this->validPoints, function($a, $b) {
            return $a['datetime'] <=> $b['datetime'];
        });
    }

    private function splitIntoTrips(): void {
        if (empty($this->validPoints)) return;

        $currentTrip = [];
        $tripNumber = 1;

        foreach ($this->validPoints as $point) {
            if (empty($currentTrip)) {
                $currentTrip[] = $point;
            } else {
                $lastPoint = end($currentTrip);
                $timeDiff = $point['datetime']->getTimestamp() - $lastPoint['datetime']->getTimestamp();
                $distance = $this->haversineDistance(
                    $lastPoint['lat'], $lastPoint['lon'],
                    $point['lat'], $point['lon']
                );

                if ($timeDiff > 25 * 60 || $distance > 2) { // 25 minutes or 2km
                    if (count($currentTrip) > 1) {
                        $this->trips[] = [
                            'id' => "trip_$tripNumber",
                            'points' => $currentTrip
                        ];
                        $tripNumber++;
                    }
                    $currentTrip = [$point];
                } else {
                    $currentTrip[] = $point;
                }
            }
        }

        // Add final trip
        if (count($currentTrip) > 1) {
            $this->trips[] = [
                'id' => "trip_$tripNumber",
                'points' => $currentTrip
            ];
        }
    }

    private function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float {
        $earthRadius = 6371; // km
        
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        
        $a = sin($dLat/2) * sin($dLat/2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon/2) * sin($dLon/2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        
        return $earthRadius * $c;
    }

    private function calculateTripStats(array $trip): array {
        $points = $trip['points'];
        $totalDistance = 0;
        $maxSpeed = 0;
        $speeds = [];

        for ($i = 1; $i < count($points); $i++) {
            $p1 = $points[$i - 1];
            $p2 = $points[$i];
            
            $distance = $this->haversineDistance($p1['lat'], $p1['lon'], $p2['lat'], $p2['lon']);
            $timeDiff = ($p2['datetime']->getTimestamp() - $p1['datetime']->getTimestamp()) / 3600; // hours
            
            $totalDistance += $distance;
            
            if ($timeDiff > 0) {
                $speed = $distance / $timeDiff;
                $speeds[] = $speed;
                $maxSpeed = max($maxSpeed, $speed);
            }
        }

        $duration = ($points[count($points) - 1]['datetime']->getTimestamp() - 
                    $points[0]['datetime']->getTimestamp()) / 60; // minutes
        
        $avgSpeed = $duration > 0 ? ($totalDistance / ($duration / 60)) : 0;

        return [
            'total_distance' => round($totalDistance, 2),
            'duration' => round($duration, 1),
            'avg_speed' => round($avgSpeed, 2),
            'max_speed' => round($maxSpeed, 2)
        ];
    }

    private function generateOutput(): void {
        // Write rejects log
        file_put_contents('rejects.log', implode("\n", $this->rejects));

        // Generate GeoJSON
        $features = [];
        $colors = [];
        
        foreach ($this->trips as $index => $trip) {
            $stats = $this->calculateTripStats($trip);
            
            // Generate distinct color for each trip
            $hue = ($index * 137.5) % 360;
            $color = sprintf("hsl(%d, 70%%, 50%%)", $hue);
            $colors[] = $color;
            
            $coordinates = array_map(function($point) {
                return [$point['lon'], $point['lat']];
            }, $trip['points']);

            $features[] = [
                'type' => 'Feature',
                'properties' => [
                    'name' => $trip['id'],
                    'stroke' => $color,
                    'stroke-width' => 3,
                    'stroke-opacity' => 1,
                    'distance_km' => $stats['total_distance'],
                    'duration_min' => $stats['duration'],
                    'avg_speed_kmh' => $stats['avg_speed'],
                    'max_speed_kmh' => $stats['max_speed']
                ],
                'geometry' => [
                    'type' => 'LineString',
                    'coordinates' => $coordinates
                ]
            ];
        }

        $geojson = [
            'type' => 'FeatureCollection',
            'features' => $features
        ];

        file_put_contents('trips.geojson', json_encode($geojson, JSON_PRETTY_PRINT));

        // Print summary
        echo "Processing complete!\n";
        echo "Valid points processed: " . count($this->validPoints) . "\n";
        echo "Rejected rows: " . count($this->rejects) . "\n";
        echo "Trips generated: " . count($this->trips) . "\n\n";

        foreach ($this->trips as $index => $trip) {
            $stats = $this->calculateTripStats($trip);
            echo "{$trip['id']}:\n";
            echo "  Points: " . count($trip['points']) . "\n";
            echo "  Distance: {$stats['total_distance']} km\n";
            echo "  Duration: {$stats['duration']} min\n";
            echo "  Avg Speed: {$stats['avg_speed']} km/h\n";
            echo "  Max Speed: {$stats['max_speed']} km/h\n";
            echo "  Color: {$colors[$index]}\n\n";
        }

        echo "Files generated:\n";
        echo "- trips.geojson\n";
        echo "- rejects.log\n";
    }
}

// Main execution
if ($argc < 2) {
    echo "Usage: php your_script.php <gps_data_file.csv>\n";
    echo "Example: php your_script.php gps_data.csv\n";
    exit(1);
}

$filename = $argv[1];

if (!file_exists($filename)) {
    echo "Error: File '$filename' not found.\n";
    exit(1);
}

try {
    $startTime = microtime(true);
    
    $processor = new GPSProcessor();
    $processor->processFile($filename);
    
    $endTime = microtime(true);
    $executionTime = round($endTime - $startTime, 2);
    
    echo "Execution time: {$executionTime} seconds\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>