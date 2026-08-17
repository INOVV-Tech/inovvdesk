<?php
require_once __DIR__ . '/../includes/modules/initiatives/initiative-financial.php';

function initiative_test_close(float $actual, float $expected, float $tolerance = 0.01): void
{
    if (abs($actual - $expected) > $tolerance) {
        throw new RuntimeException("Expected {$expected}, got {$actual}");
    }
}

$hours = initiative_hours_saved_benefit([
    'minutes_before' => 60, 'minutes_after' => 15, 'occurrences_per_month' => 20,
    'people' => 2, 'adoption_percent' => 50, 'hourly_cost' => 100,
]);
initiative_test_close($hours['hours_monthly'], 15);
initiative_test_close($hours['benefit_annual'], 18000);
initiative_test_close(initiative_risk_reduction_benefit(['probability_before'=>40,'probability_after'=>10,'financial_impact'=>100000]), 30000);

$metrics = initiative_financial_metrics(12000, 1200, 13200, 24, 10);
initiative_test_close($metrics['payback_months'], 12);
initiative_test_close($metrics['total_cost'], 14400);
initiative_test_close($metrics['net_benefit'], 12000);
initiative_test_close($metrics['roi_percent'], 83.3333);
if ($metrics['npv'] <= 0 || $metrics['discounted_payback_months'] === null) throw new RuntimeException('Discounted metrics were not calculated.');

echo "initiative-financial-test: ok\n";

