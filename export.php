<?php

use Carbon\Carbon;
use Dotenv\Dotenv;
use GuzzleHttp\Client;

require __DIR__ . '/vendor/autoload.php';

// Load secrets
Dotenv::createImmutable(__DIR__)->load();

// Determine limits
$since = Carbon::now()->startOfWeek()->format('Y-m-d');
$until = Carbon::now()->endOfWeek()->format('Y-m-d');

// Get data
echo "Retrieving all entries from {$since} until {$until}" . PHP_EOL;
$timeEntries = [];
$guzzle = new Client();
for ($page = 1; $page < 100; $page++) {  // safety stopping condition 100 pages
    // Do request
    $response = $guzzle->get('https://toggl.com/reports/api/v2/details', [
        'auth' => [$_ENV['TOGGL_API_TOKEN'], 'api_token'], // weird, but accurate
        'query' => [
            'user_agent' => 'github.com/jakobbuis/toggl-data-exporter; ' . $_ENV['USER_AGENT_EMAIL'],
            'workspace_id' => $_ENV['TOGGL_WORKSPACE_ID'],
            'since' => $since,
            'until' => $until,
            'page' => $page,
        ],
    ]);
    $data = json_decode($response->getBody())->data;

    if (empty($data)) { // actual stopping condition
        break;
    }

    $timeEntries = array_merge($timeEntries, $data);
}

$count = count($timeEntries);
echo "Got {$count} entries" . PHP_EOL;


// Format into a OLAP-cube
echo "Formatting results" . PHP_EOL;
$output = [];
foreach ($timeEntries as $entry) {
    // Create the cube entry
    $client = $entry->client;
    $project = $entry->project;
    $day = Carbon::parse($entry->start)->format('Y-m-d');
    $output[$client][$project][$day] ??= ['time' => 0, 'descriptions' => []];

    // add this new data
    $output[$client][$project][$day]['time'] += $entry->dur;
    $output[$client][$project][$day]['descriptions'][] = $entry->description;
}
// Sort the cube for easier reading comprehension
ksort($output);
foreach ($output as $client => $projectData) {
    ksort($projectData);
    $output[$client] = $projectData;
    foreach ($projectData as $project => $dayData) {
        ksort($dayData);
        $output[$client][$project] = $dayData;
    }
}

// Format entries
foreach ($output as $client => $data) {
    foreach ($data as $project => $data) {
        foreach ($data as $day => $entry) {
            // Format time
            $output[$client][$project][$day]['time'] = roundTime($entry['time']);

            // Merge all descriptions as comma-seperated entries
            $output[$client][$project][$day]['descriptions'] = implode(', ', array_unique($entry['descriptions']));
        }
    }
}

// Calculate total
$total = 0.0;
foreach ($output as $client => $data) {
    foreach ($data as $project => $data) {
        foreach ($data as $day => $entry) {
            $total += $entry['time'];
        }
    }
}

// Ouput data to memory
$out = fopen('php://memory', 'w+');
foreach ($output as $client => $data) {
    foreach ($data as $project => $data) {
        foreach ($data as $day => $entry) {
            $time = number_format($entry['time'], 2);
            fwrite($out, "{$client} - {$project} - {$day} [{$time}] {$entry['descriptions']}" . PHP_EOL);
        }
        fwrite($out, PHP_EOL);
    }
}
fwrite($out, 'Totaal uren: ' . $total . PHP_EOL);

// Flush to disk
rewind($out);
$filename = "toggl entries {$since} {$until}.txt";
file_put_contents(__DIR__ . '/' . $filename, $out);
echo "Results output to {$filename}" . PHP_EOL;

/*
 * Functions
 */

/**
 * We use a biased rounding function. Everything is rounded to quarter hours,
 * with rounding up on 5 minutes past the quarter. So 19:59 is rounded down to 0.25
 * hours. 20:00 would be rounded up to 0.5 hours.
 * @param int $time number of milliseconds
 * @return float fractional hours, max. 2 decimals
 */
function roundTime(int $time): float {
    $time = $time / 1000; // strip milliseconds
    // Never round down to zero
    if ($time < 900) {
        return 0.25;
    }
    // Round to quarters (biased)
    $frac = $time % 900;
    $time = $time / 60 / 60; // as fractional hours
    if ($frac < 300) {
        return floor($time * 4) / 4;
    }
    return ceil($time * 4) / 4;
}
