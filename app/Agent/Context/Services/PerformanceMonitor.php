<?php

namespace App\Agent\Context\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PerformanceMonitor
{
    protected array $config;

    protected string $metricsPrefix = 'agent_compression_metrics_';

    protected array $currentSessionMetrics = [];

    public function __construct()
    {
        $this->config = config('app.context_compression.performance', []);
    }

    /**
     * Record compression performance metrics
     */
    public function recordCompression(array $originalSteps, array $compressedResult, float $compressionTime): void
    {
        if (! ($this->config['enable_monitoring'] ?? true)) {
            return;
        }

        $metrics = [
            'timestamp' => time(),
            'original_step_count' => count($originalSteps),
            'original_size_estimate' => $this->estimateSize($originalSteps),
            'compressed_size_estimate' => $this->estimateSize($compressedResult),
            'compression_time_seconds' => $compressionTime,
            'compression_type' => $compressedResult['metadata']['compression_type'] ?? 'unknown',
            'information_loss_risk' => $compressedResult['metadata']['information_loss_risk'] ?? 'unknown',
        ];

        // Calculate derived metrics
        $metrics['compression_ratio'] = $this->calculateCompressionRatio($metrics);
        $metrics['throughput_steps_per_second'] = $metrics['original_step_count'] / max($compressionTime, 0.001);

        // Store metrics
        $this->storeMetrics($metrics);

        // Track in current session
        $this->currentSessionMetrics[] = $metrics;

        // Check performance thresholds
        $this->checkPerformanceThresholds($metrics);

        // Log significant events
        $this->logPerformanceEvents($metrics);
    }

    /**
     * Get performance dashboard data
     */
    public function getDashboardData(string $timeframe = '24h'): array
    {
        $metrics = $this->getMetricsInTimeframe($timeframe);

        if (empty($metrics)) {
            return $this->getEmptyDashboard();
        }

        $dashboard = [
            'summary' => $this->calculateSummaryMetrics($metrics),
            'trends' => $this->calculateTrends($metrics),
            'efficiency' => $this->calculateEfficiencyMetrics($metrics),
            'alerts' => $this->getPerformanceAlerts($metrics),
            'cost_analysis' => $this->calculateCostAnalysis($metrics),
            'recommendations' => $this->generateRecommendations($metrics),
        ];

        return $dashboard;
    }

    /**
     * Get real-time performance status
     */
    public function getCurrentPerformanceStatus(): array
    {
        $recentMetrics = array_slice($this->currentSessionMetrics, -5); // Last 5 compressions

        if (empty($recentMetrics)) {
            return [
                'status' => 'inactive',
                'message' => 'No recent compression activity',
            ];
        }

        $avgCompressionTime = array_sum(array_column($recentMetrics, 'compression_time_seconds')) / count($recentMetrics);
        $avgCompressionRatio = array_sum(array_column($recentMetrics, 'compression_ratio')) / count($recentMetrics);
        $target = $this->config['compression_ratio_target'] ?? 0.7;
        $timeLimit = $this->config['response_time_limit'] ?? 5.0;

        $status = 'optimal';
        $issues = [];

        if ($avgCompressionTime > $timeLimit) {
            $status = 'slow';
            $issues[] = sprintf('Compression time (%.2fs) exceeds limit (%.1fs)', $avgCompressionTime, $timeLimit);
        }

        if ($avgCompressionRatio < $target * 0.8) {
            $status = 'inefficient';
            $issues[] = sprintf('Compression ratio (%.2f) below target (%.1f)', $avgCompressionRatio, $target);
        }

        return [
            'status' => $status,
            'avg_compression_time' => $avgCompressionTime,
            'avg_compression_ratio' => $avgCompressionRatio,
            'issues' => $issues,
            'compressions_tracked' => count($recentMetrics),
        ];
    }

    /**
     * Generate performance report
     */
    public function generateReport(string $timeframe = '24h', string $format = 'array'): mixed
    {
        $metrics = $this->getMetricsInTimeframe($timeframe);
        $dashboard = $this->getDashboardData($timeframe);

        $report = [
            'report_generated_at' => date('Y-m-d H:i:s'),
            'timeframe' => $timeframe,
            'total_compressions' => count($metrics),
            'performance_summary' => $dashboard['summary'],
            'efficiency_analysis' => $dashboard['efficiency'],
            'cost_analysis' => $dashboard['cost_analysis'],
            'trends' => $dashboard['trends'],
            'recommendations' => $dashboard['recommendations'],
            'alerts' => $dashboard['alerts'],
        ];

        return match ($format) {
            'json' => json_encode($report, JSON_PRETTY_PRINT),
            'text' => $this->formatReportAsText($report),
            default => $report
        };
    }

    /**
     * Estimate size of data structure
     */
    protected function estimateSize(mixed $data): int
    {
        return strlen(serialize($data));
    }

    /**
     * Calculate compression ratio
     */
    protected function calculateCompressionRatio(array $metrics): float
    {
        $originalSize = $metrics['original_size_estimate'];
        $compressedSize = $metrics['compressed_size_estimate'];

        if ($originalSize <= 0) {
            return 0.0;
        }

        return 1.0 - ($compressedSize / $originalSize);
    }

    /**
     * Store metrics in cache with TTL
     */
    protected function storeMetrics(array $metrics): void
    {
        $metricsKey = $this->metricsPrefix.date('Y_m_d');
        $dailyMetrics = Cache::get($metricsKey, []);
        $dailyMetrics[] = $metrics;

        // Store for 30 days
        Cache::put($metricsKey, $dailyMetrics, 30 * 24 * 60 * 60);

        // Also maintain a rolling 24-hour cache for quick access
        $rollingKey = $this->metricsPrefix.'rolling';
        $rollingMetrics = Cache::get($rollingKey, []);
        $rollingMetrics[] = $metrics;

        // Keep only last 24 hours
        $cutoff = time() - 86400;
        $rollingMetrics = array_filter($rollingMetrics, fn ($m) => $m['timestamp'] >= $cutoff);

        Cache::put($rollingKey, $rollingMetrics, 86400); // 24 hours
    }

    /**
     * Get metrics within timeframe
     */
    protected function getMetricsInTimeframe(string $timeframe): array
    {
        $seconds = match ($timeframe) {
            '1h' => 3600,
            '24h' => 86400,
            '7d' => 604800,
            '30d' => 2592000,
            default => 86400
        };

        $cutoff = time() - $seconds;

        if ($seconds <= 86400) {
            // Use rolling cache for recent data
            $metrics = Cache::get($this->metricsPrefix.'rolling', []);
        } else {
            // Aggregate from daily caches
            $metrics = [];
            $days = ceil($seconds / 86400);

            for ($i = 0; $i < $days; $i++) {
                $date = date('Y_m_d', time() - ($i * 86400));
                $dailyMetrics = Cache::get($this->metricsPrefix.$date, []);
                $metrics = array_merge($metrics, $dailyMetrics);
            }
        }

        // Filter by cutoff time
        return array_filter($metrics, fn ($m) => $m['timestamp'] >= $cutoff);
    }

    /**
     * Calculate summary metrics
     */
    protected function calculateSummaryMetrics(array $metrics): array
    {
        if (empty($metrics)) {
            return [];
        }

        $compressionTimes = array_column($metrics, 'compression_time_seconds');
        $compressionRatios = array_column($metrics, 'compression_ratio');
        $stepCounts = array_column($metrics, 'original_step_count');

        return [
            'total_compressions' => count($metrics),
            'avg_compression_time' => array_sum($compressionTimes) / count($compressionTimes),
            'max_compression_time' => max($compressionTimes),
            'min_compression_time' => min($compressionTimes),
            'avg_compression_ratio' => array_sum($compressionRatios) / count($compressionRatios),
            'best_compression_ratio' => max($compressionRatios),
            'worst_compression_ratio' => min($compressionRatios),
            'avg_steps_processed' => array_sum($stepCounts) / count($stepCounts),
            'total_steps_processed' => array_sum($stepCounts),
            'target_compression_ratio' => $this->config['compression_ratio_target'] ?? 0.7,
            'time_limit' => $this->config['response_time_limit'] ?? 5.0,
        ];
    }

    /**
     * Calculate performance trends
     */
    protected function calculateTrends(array $metrics): array
    {
        if (count($metrics) < 2) {
            return [];
        }

        // Sort by timestamp
        usort($metrics, fn ($a, $b) => $a['timestamp'] <=> $b['timestamp']);

        $firstHalf = array_slice($metrics, 0, count($metrics) / 2);
        $secondHalf = array_slice($metrics, count($metrics) / 2);

        $firstAvgTime = array_sum(array_column($firstHalf, 'compression_time_seconds')) / count($firstHalf);
        $secondAvgTime = array_sum(array_column($secondHalf, 'compression_time_seconds')) / count($secondHalf);

        $firstAvgRatio = array_sum(array_column($firstHalf, 'compression_ratio')) / count($firstHalf);
        $secondAvgRatio = array_sum(array_column($secondHalf, 'compression_ratio')) / count($secondHalf);

        return [
            'compression_time_trend' => $this->calculateTrendDirection($firstAvgTime, $secondAvgTime),
            'compression_ratio_trend' => $this->calculateTrendDirection($firstAvgRatio, $secondAvgRatio, true),
            'time_change_percent' => (($secondAvgTime - $firstAvgTime) / $firstAvgTime) * 100,
            'ratio_change_percent' => (($secondAvgRatio - $firstAvgRatio) / $firstAvgRatio) * 100,
        ];
    }

    /**
     * Calculate trend direction
     */
    protected function calculateTrendDirection(float $first, float $second, bool $higherIsBetter = false): string
    {
        $threshold = 0.05; // 5% threshold for "stable"
        $change = ($second - $first) / $first;

        if (abs($change) < $threshold) {
            return 'stable';
        }

        if ($change > 0) {
            return $higherIsBetter ? 'improving' : 'declining';
        } else {
            return $higherIsBetter ? 'declining' : 'improving';
        }
    }

    /**
     * Calculate efficiency metrics
     */
    protected function calculateEfficiencyMetrics(array $metrics): array
    {
        if (empty($metrics)) {
            return [];
        }

        $target = $this->config['compression_ratio_target'] ?? 0.7;
        $timeLimit = $this->config['response_time_limit'] ?? 5.0;

        $meetingRatioTarget = count(array_filter($metrics, fn ($m) => $m['compression_ratio'] >= $target));
        $meetingTimeLimit = count(array_filter($metrics, fn ($m) => $m['compression_time_seconds'] <= $timeLimit));

        $totalMemorySaved = 0;
        foreach ($metrics as $metric) {
            $saved = $metric['original_size_estimate'] - $metric['compressed_size_estimate'];
            $totalMemorySaved += max(0, $saved);
        }

        return [
            'ratio_target_compliance' => ($meetingRatioTarget / count($metrics)) * 100,
            'time_limit_compliance' => ($meetingTimeLimit / count($metrics)) * 100,
            'total_memory_saved_bytes' => $totalMemorySaved,
            'total_memory_saved_mb' => round($totalMemorySaved / 1024 / 1024, 2),
            'avg_memory_saved_per_compression' => round($totalMemorySaved / count($metrics)),
        ];
    }

    /**
     * Get performance alerts
     */
    protected function getPerformanceAlerts(array $metrics): array
    {
        $alerts = [];
        $recent = array_slice($metrics, -5); // Last 5 compressions

        if (empty($recent)) {
            return $alerts;
        }

        $target = $this->config['compression_ratio_target'] ?? 0.7;
        $timeLimit = $this->config['response_time_limit'] ?? 5.0;

        $avgRecentTime = array_sum(array_column($recent, 'compression_time_seconds')) / count($recent);
        $avgRecentRatio = array_sum(array_column($recent, 'compression_ratio')) / count($recent);

        if ($avgRecentTime > $timeLimit) {
            $alerts[] = [
                'type' => 'performance',
                'severity' => 'warning',
                'message' => sprintf('Average compression time (%.2fs) exceeds limit (%.1fs)', $avgRecentTime, $timeLimit),
                'recommendation' => 'Consider reducing context size or switching to simpler compression',
            ];
        }

        if ($avgRecentRatio < $target * 0.8) {
            $alerts[] = [
                'type' => 'efficiency',
                'severity' => 'info',
                'message' => sprintf('Compression ratio (%.2f) below target (%.1f)', $avgRecentRatio, $target),
                'recommendation' => 'Review compression strategies or adjust target ratio',
            ];
        }

        // Check for high information loss risk
        $highRiskCount = count(array_filter($recent, fn ($m) => ($m['information_loss_risk'] ?? 'low') === 'high'));
        if ($highRiskCount > 2) {
            $alerts[] = [
                'type' => 'quality',
                'severity' => 'warning',
                'message' => "{$highRiskCount} recent compressions marked as high information loss risk",
                'recommendation' => 'Review compression prompts and preservation rules',
            ];
        }

        return $alerts;
    }

    /**
     * Calculate cost analysis
     */
    protected function calculateCostAnalysis(array $metrics): array
    {
        // Rough estimate of LLM API costs
        $avgTokensPerCompression = 500; // Estimated
        $costPerToken = 0.002 / 1000; // Rough estimate

        $llmCompressions = count(array_filter($metrics, fn ($m) => in_array($m['compression_type'] ?? '', ['llm_enhanced', 'unknown'])
        ));

        $estimatedCost = $llmCompressions * $avgTokensPerCompression * $costPerToken;

        return [
            'llm_compressions_count' => $llmCompressions,
            'simple_compressions_count' => count($metrics) - $llmCompressions,
            'estimated_api_cost' => round($estimatedCost, 4),
            'cost_per_compression' => count($metrics) > 0 ? round($estimatedCost / count($metrics), 6) : 0,
            'monthly_projection' => round($estimatedCost * 30, 2), // Rough monthly projection
        ];
    }

    /**
     * Generate performance recommendations
     */
    protected function generateRecommendations(array $metrics): array
    {
        if (empty($metrics)) {
            return ['Insufficient data for recommendations'];
        }

        $recommendations = [];
        $summary = $this->calculateSummaryMetrics($metrics);
        $target = $this->config['compression_ratio_target'] ?? 0.7;
        $timeLimit = $this->config['response_time_limit'] ?? 5.0;

        if ($summary['avg_compression_time'] > $timeLimit) {
            $recommendations[] = 'Consider increasing simple compression threshold or reducing LLM compression usage';
        }

        if ($summary['avg_compression_ratio'] < $target) {
            $recommendations[] = 'Review compression prompts to improve efficiency';
            $recommendations[] = 'Consider adjusting preservation rules to compress more aggressively';
        }

        if ($summary['avg_compression_ratio'] > 0.9) {
            $recommendations[] = 'Compression ratio very high - verify important information is not being lost';
        }

        $costAnalysis = $this->calculateCostAnalysis($metrics);
        if ($costAnalysis['estimated_api_cost'] > 0.1) { // More than $0.10
            $recommendations[] = 'Consider using intelligent compression more often to reduce LLM costs';
        }

        if (empty($recommendations)) {
            $recommendations[] = 'Performance within acceptable parameters';
        }

        return $recommendations;
    }

    /**
     * Check performance thresholds and trigger alerts
     */
    protected function checkPerformanceThresholds(array $metrics): void
    {
        $timeLimit = $this->config['response_time_limit'] ?? 5.0;

        if ($metrics['compression_time_seconds'] > $timeLimit * 2) {
            Log::warning('Context compression performance alert', [
                'compression_time' => $metrics['compression_time_seconds'],
                'time_limit' => $timeLimit,
                'compression_type' => $metrics['compression_type'],
                'step_count' => $metrics['original_step_count'],
            ]);
        }
    }

    /**
     * Log significant performance events
     */
    protected function logPerformanceEvents(array $metrics): void
    {
        // Log every 10th compression for tracking
        static $compressionCount = 0;
        $compressionCount++;

        if ($compressionCount % 10 === 0) {
            Log::info('Context compression metrics', [
                'total_compressions' => $compressionCount,
                'avg_time' => $metrics['compression_time_seconds'],
                'compression_ratio' => $metrics['compression_ratio'],
                'type' => $metrics['compression_type'],
            ]);
        }
    }

    /**
     * Get empty dashboard for no data scenarios
     */
    protected function getEmptyDashboard(): array
    {
        return [
            'summary' => ['total_compressions' => 0],
            'trends' => [],
            'efficiency' => ['total_memory_saved_bytes' => 0],
            'alerts' => [],
            'cost_analysis' => ['estimated_api_cost' => 0],
            'recommendations' => ['No compression activity to analyze'],
        ];
    }

    /**
     * Format report as human-readable text
     */
    protected function formatReportAsText(array $report): string
    {
        $text = "Context Compression Performance Report\n";
        $text .= "Generated: {$report['report_generated_at']}\n";
        $text .= "Timeframe: {$report['timeframe']}\n";
        $text .= str_repeat('=', 50)."\n\n";

        $text .= "Summary:\n";
        $text .= "- Total compressions: {$report['total_compressions']}\n";
        if (! empty($report['performance_summary'])) {
            $summary = $report['performance_summary'];
            $text .= '- Average compression time: '.round($summary['avg_compression_time'], 2)."s\n";
            $text .= '- Average compression ratio: '.round($summary['avg_compression_ratio'], 2)."\n";
            $text .= "- Total steps processed: {$summary['total_steps_processed']}\n\n";
        }

        if (! empty($report['efficiency_analysis']['total_memory_saved_mb'])) {
            $text .= "Efficiency:\n";
            $text .= "- Memory saved: {$report['efficiency_analysis']['total_memory_saved_mb']} MB\n\n";
        }

        if (! empty($report['recommendations'])) {
            $text .= "Recommendations:\n";
            foreach ($report['recommendations'] as $rec) {
                $text .= "- {$rec}\n";
            }
        }

        return $text;
    }
}
