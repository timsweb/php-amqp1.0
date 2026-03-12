<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use AMQP10\Connection\Session;
use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\PerformativeEncoder;
use AMQP10\Tests\Mocks\TransportMock;

function benchmarkPipelinedReads(int $frameCount, int $iterations = 5): float
{
    $times = [];

    for ($iter = 0; $iter < $iterations; $iter++) {
        $transport = new TransportMock();
        $transport->connect('tcp://localhost:5672');

        $session = new Session($transport, channel: 1, incomingWindow: 2048, outgoingWindow: 2048);

        for ($i = 0; $i < $frameCount; $i++) {
            $attachFrame = PerformativeEncoder::attach(
                channel: 1,
                name: "link-{$i}",
                handle: $i,
                role: PerformativeEncoder::ROLE_RECEIVER,
                source: "source-{$i}",
                target: "target-{$i}"
            );
            $transport->queueIncoming($attachFrame);
        }

        $startTime = microtime(true);

        for ($i = 0; $i < $frameCount; $i++) {
            $session->readFrameOfType(Descriptor::ATTACH);
        }

        $endTime = microtime(true);

        $times[] = ($endTime - $startTime) * 1000;
    }

    sort($times);
    $medianIndex = (int)(count($times) / 2);
    return $times[$medianIndex];
}

function analyzeGrowth(array $results): void
{
    echo "\n=== Growth Analysis ===\n";

    $previousFrames = null;
    $previousTime = null;

    foreach ($results as $frames => $time) {
        if ($previousFrames !== null && $previousTime !== null) {
            $frameRatio = $frames / $previousFrames;
            $timeRatio = $time / $previousTime;

            $expectedRatio = $frameRatio;
            $isLinear = abs($timeRatio - $expectedRatio) < ($expectedRatio * 0.6);
            $status = $isLinear ? '✓ LINEAR' : '✗ NON-LINEAR';

            echo sprintf(
                "%d → %d frames: %.2fx time growth (%.2fx expected) %s\n",
                $previousFrames,
                $frames,
                $timeRatio,
                $expectedRatio,
                $status
            );
        }

        $previousFrames = $frames;
        $previousTime = $time;
    }
}

$frameCounts = [10, 50, 100, 200];

echo "=== Pending Frames Performance Benchmark ===\n";
echo "Validating O(n) behavior for readFrameOfType()\n";
echo "Expected: Linear growth (2x frames = ~2x time, not 4x)\n\n";

$results = [];

foreach ($frameCounts as $count) {
    $time = benchmarkPipelinedReads($count);
    $results[$count] = $time;

    echo sprintf("%3d frames: %7.3f ms (%.6f ms/frame)\n", $count, $time, $time / $count);
}

analyzeGrowth($results);

echo "\n=== Verification ===\n";
$totalGrowth = $results[200] / $results[50];
$expectedGrowth = 200 / 50;
$linearStatus = abs($totalGrowth - $expectedGrowth) < ($expectedGrowth * 0.6) ? 'PASS' : 'FAIL';

echo sprintf(
    "50 → 200 frames: %.2fx total growth (%.2fx expected) [%s]\n",
    $totalGrowth,
    $expectedGrowth,
    $linearStatus
);

echo "\nNote: 10-frame result may include initialization overhead.\n";
echo "      Focusing on 50-200 frame range for O(n) verification.\n";

if ($linearStatus === 'PASS') {
    echo "\n✓ Benchmark confirms O(n) behavior - descriptor caching optimization working correctly.\n";
    exit(0);
} else {
    echo "\n✗ Benchmark suggests non-linear behavior - possible performance issue.\n";
    exit(1);
}
