<?php

function initiative_periods_per_year(string $periodicity): float
{
    return match ($periodicity) {
        'monthly' => 12.0, 'quarterly' => 4.0, 'annual' => 1.0, default => 0.0,
    };
}

function initiative_annualize(float $amount, string $periodicity): float
{
    return $periodicity === 'one_time' ? $amount : $amount * initiative_periods_per_year($periodicity);
}

function initiative_hours_saved_benefit(array $payload): array
{
    $before = max(0, (float) ($payload['minutes_before'] ?? 0));
    $after = max(0, (float) ($payload['minutes_after'] ?? 0));
    $occurrences = max(0, (float) ($payload['occurrences_per_month'] ?? 0));
    $people = max(0, (float) ($payload['people'] ?? 1));
    $adoption = max(0, min(100, (float) ($payload['adoption_percent'] ?? 100))) / 100;
    $hourlyCost = max(0, (float) ($payload['hourly_cost'] ?? 0));
    $hoursMonthly = max(0, $before - $after) / 60 * $occurrences * $people * $adoption;
    return ['hours_monthly' => $hoursMonthly, 'hours_annual' => $hoursMonthly * 12, 'benefit_annual' => $hoursMonthly * 12 * $hourlyCost];
}

function initiative_risk_reduction_benefit(array $payload): float
{
    $before = max(0, min(100, (float) ($payload['probability_before'] ?? 0))) / 100;
    $after = max(0, min(100, (float) ($payload['probability_after'] ?? 0))) / 100;
    return max(0, ($before - $after) * max(0, (float) ($payload['financial_impact'] ?? 0)));
}

function initiative_npv(array $cashFlows, float $annualRate): float
{
    $monthlyRate = pow(1 + ($annualRate / 100), 1 / 12) - 1;
    $npv = 0.0;
    foreach ($cashFlows as $month => $cashFlow) {
        $npv += (float) $cashFlow / pow(1 + $monthlyRate, (int) $month);
    }
    return $npv;
}

function initiative_irr(array $cashFlows): ?float
{
    $hasPositive = false; $hasNegative = false;
    foreach ($cashFlows as $flow) { $hasPositive = $hasPositive || $flow > 0; $hasNegative = $hasNegative || $flow < 0; }
    if (!$hasPositive || !$hasNegative) return null;
    $low = -0.999; $high = 10.0;
    for ($i = 0; $i < 100; $i++) {
        $mid = ($low + $high) / 2; $value = 0.0;
        foreach ($cashFlows as $period => $flow) $value += $flow / pow(1 + $mid, (int) $period);
        if (abs($value) < 0.0001) return (pow(1 + $mid, 12) - 1) * 100;
        if ($value > 0) $low = $mid; else $high = $mid;
    }
    return (pow(1 + (($low + $high) / 2), 12) - 1) * 100;
}

function initiative_financial_metrics(float $investment, float $recurringAnnual, float $benefitAnnual, int $horizonMonths, float $discountRate): array
{
    $horizonMonths = max(1, $horizonMonths);
    $monthlyNet = ($benefitAnnual - $recurringAnnual) / 12;
    $totalCost = $investment + ($recurringAnnual * $horizonMonths / 12);
    $totalBenefit = $benefitAnnual * $horizonMonths / 12;
    $netBenefit = $totalBenefit - $totalCost;
    $roi = $totalCost > 0 ? ($netBenefit / $totalCost) * 100 : null;
    $payback = $investment > 0 && $monthlyNet > 0 ? $investment / $monthlyNet : null;
    $flows = [-$investment];
    for ($month = 1; $month <= $horizonMonths; $month++) $flows[$month] = $monthlyNet;
    $discountedPayback = null; $cumulative = -$investment;
    $monthlyRate = pow(1 + ($discountRate / 100), 1 / 12) - 1;
    for ($month = 1; $month <= $horizonMonths; $month++) {
        $cumulative += $monthlyNet / pow(1 + $monthlyRate, $month);
        if ($cumulative >= 0) { $discountedPayback = (float) $month; break; }
    }
    return [
        'investment' => $investment, 'recurring_cost_annual' => $recurringAnnual,
        'benefit_annual' => $benefitAnnual, 'total_cost' => $totalCost, 'net_benefit' => $netBenefit,
        'roi_percent' => $roi, 'payback_months' => $payback,
        'discounted_payback_months' => $discountedPayback,
        'npv' => initiative_npv($flows, $discountRate), 'irr_percent' => initiative_irr($flows),
    ];
}

function initiative_financial_setting(string $key, string $default): string
{
    $row = db_fetch_one('SELECT setting_value FROM initiative_settings WHERE setting_key = ?', [$key]);
    return (string) ($row['setting_value'] ?? $default);
}

function initiative_recalculate_financials(int $initiativeId, string $scenario): array
{
    $initiative = db_fetch_one('SELECT discount_rate, analysis_horizon_months FROM initiatives WHERE id = ?', [$initiativeId]);
    if (!$initiative) throw new InvalidArgumentException('Initiative not found.');
    $costs = db_fetch_all('SELECT amount, periodicity FROM initiative_costs WHERE initiative_id = ? AND scenario = ?', [$initiativeId, $scenario]);
    $benefits = db_fetch_all('SELECT amount, periodicity, benefit_type, payload_json FROM initiative_benefits WHERE initiative_id = ? AND scenario = ?', [$initiativeId, $scenario]);
    $investment = 0.0; $recurring = 0.0;
    foreach ($costs as $cost) {
        if ($cost['periodicity'] === 'one_time') $investment += (float) $cost['amount'];
        else $recurring += initiative_annualize((float) $cost['amount'], (string) $cost['periodicity']);
    }
    $annualBenefit = 0.0;
    foreach ($benefits as $benefit) {
        $amount = (float) $benefit['amount'];
        $payload = json_decode((string) ($benefit['payload_json'] ?? ''), true) ?: [];
        if ($benefit['benefit_type'] === 'hours_saved' && $payload) $amount = initiative_hours_saved_benefit($payload)['benefit_annual'];
        elseif ($benefit['benefit_type'] === 'risk_reduction' && $payload) $amount = initiative_risk_reduction_benefit($payload);
        elseif ($benefit['benefit_type'] === 'revenue' && isset($payload['margin_percent'])) $amount = initiative_annualize($amount, (string) $benefit['periodicity']) * max(0,min(100,(float)$payload['margin_percent'])) / 100;
        else $amount = initiative_annualize($amount, (string) $benefit['periodicity']);
        $annualBenefit += $amount;
    }
    if ($scenario === 'actual') {
        $ticketCost = initiative_ticket_actuals($initiativeId);
        $investment += $ticketCost['cost'];
    }
    $rate = $initiative['discount_rate'] !== null ? (float) $initiative['discount_rate'] : (float) initiative_financial_setting('discount_rate', '10');
    $horizon = $initiative['analysis_horizon_months'] ?: (int) initiative_financial_setting('analysis_horizon_months', '36');
    $metrics = initiative_financial_metrics($investment, $recurring, $annualBenefit, (int) $horizon, $rate);
    db_query("INSERT INTO initiative_financial_snapshots
        (initiative_id, scenario, investment, recurring_cost_annual, benefit_annual, total_cost, net_benefit, roi_percent, payback_months, discounted_payback_months, npv, irr_percent, calculated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE
        investment=VALUES(investment), recurring_cost_annual=VALUES(recurring_cost_annual), benefit_annual=VALUES(benefit_annual), total_cost=VALUES(total_cost),
        net_benefit=VALUES(net_benefit), roi_percent=VALUES(roi_percent), payback_months=VALUES(payback_months),
        discounted_payback_months=VALUES(discounted_payback_months), npv=VALUES(npv), irr_percent=VALUES(irr_percent), calculated_at=NOW()",
        [$initiativeId, $scenario, $metrics['investment'], $metrics['recurring_cost_annual'], $metrics['benefit_annual'], $metrics['total_cost'],
         $metrics['net_benefit'], $metrics['roi_percent'], $metrics['payback_months'], $metrics['discounted_payback_months'], $metrics['npv'], $metrics['irr_percent']]);
    return $metrics;
}
