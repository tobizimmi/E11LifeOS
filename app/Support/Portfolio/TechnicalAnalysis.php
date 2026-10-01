<?php

namespace App\Support\Portfolio;

/**
 * Chart analysis from daily closes: moving averages (38 and 200 days) with the trend and golden or
 * death cross, RSI, momentum, distance to the 52-week high and low, support and resistance and the
 * volatility – each signal with a short plain-language reading.
 */
class TechnicalAnalysis
{
    /**
     * @param  list<float>  $closes  oldest first
     * @return array{price: float, sma38: ?float, sma200: ?float, rsi: ?float, momentum_6m: ?float, momentum_12m: ?float, high_52w: ?float, low_52w: ?float, support: ?float, resistance: ?float, volatility: ?float, trend: string, signals: list<array{label: string, value: string, level: string, note: string}>}|null
     */
    public static function analyse(array $closes): ?array
    {
        $closes = array_values(array_filter($closes, fn (float $close) => $close > 0));
        $count = count($closes);

        if ($count < 20) {
            return null;
        }

        $price = $closes[$count - 1];
        $sma38 = self::sma($closes, 38);
        $sma200 = self::sma($closes, 200);
        $previous38 = $count > 43 ? self::sma(array_slice($closes, 0, -5), 38) : null;
        $previous200 = $count > 205 ? self::sma(array_slice($closes, 0, -5), 200) : null;
        $year = array_slice($closes, -252);
        $quarter = array_slice($closes, -63);
        $rsi = self::rsi($closes);
        $momentum6 = $count > 126 ? $price / $closes[$count - 127] - 1 : null;
        $momentum12 = $count > 252 ? $price / $closes[$count - 253] - 1 : null;
        $volatility = self::volatility(array_slice($closes, -253));

        $trend = match (true) {
            $sma200 === null => 'unknown',
            $price > $sma200 && ($sma38 === null || $sma38 > $sma200) => 'up',
            $price < $sma200 && ($sma38 === null || $sma38 < $sma200) => 'down',
            default => 'sideways',
        };

        $percent = fn (float $value) => ($value >= 0 ? '+' : '').number_format($value * 100, 1, ',', '.').' %';
        $money = fn (float $value) => number_format($value, 2, ',', '.');
        $signals = [];

        if ($sma200 !== null) {
            $distance = $price / $sma200 - 1;
            $signals[] = ['label' => __('200-day line'), 'value' => $money($sma200).' ('.$percent($distance).')',
                'level' => $distance >= 0 ? 'green' : 'red',
                'note' => $distance >= 0 ? __('The price is above the 200-day line – long-term uptrend.') : __('The price is below the 200-day line – long-term downtrend, many investors wait.')];
        }

        if ($sma38 !== null && $sma200 !== null && $previous38 !== null && $previous200 !== null) {
            if ($previous38 <= $previous200 && $sma38 > $sma200) {
                $signals[] = ['label' => __('Golden cross'), 'value' => __('in the last days'), 'level' => 'green', 'note' => __('The 38-day line crossed the 200-day line upwards – a classic buy signal.')];
            } elseif ($previous38 >= $previous200 && $sma38 < $sma200) {
                $signals[] = ['label' => __('Death cross'), 'value' => __('in the last days'), 'level' => 'red', 'note' => __('The 38-day line crossed the 200-day line downwards – a classic sell signal.')];
            }
        }

        if ($rsi !== null) {
            $signals[] = ['label' => __('RSI (14 days)'), 'value' => number_format($rsi, 0),
                'level' => $rsi >= 70 ? 'yellow' : ($rsi <= 30 ? 'yellow' : 'neutral'),
                'note' => $rsi >= 70 ? __('Overbought – after strong gains a breather is likely.') : ($rsi <= 30 ? __('Oversold – a countermovement is possible, but falling knives hurt.') : __('Neither overbought nor oversold.'))];
        }

        if ($momentum6 !== null) {
            $signals[] = ['label' => __('Momentum 6 months'), 'value' => $percent($momentum6), 'level' => $momentum6 > 0 ? 'green' : 'red',
                'note' => $momentum6 > 0 ? __('Relative strength: shares that rose tend to keep rising for a while.') : __('Weak momentum over half a year.')];
        }

        $high = max($year);
        $low = min($year);
        $signals[] = ['label' => __('52-week range'), 'value' => $money($low).' – '.$money($high),
            'level' => $price >= $high * 0.97 ? 'green' : ($price <= $low * 1.03 ? 'red' : 'neutral'),
            'note' => $price >= $high * 0.97 ? __('Close to the 52-week high – a breakout to a new high is a strength signal.') : ($price <= $low * 1.03 ? __('Close to the 52-week low.') : __(':distance below the 52-week high.', ['distance' => number_format((1 - $price / $high) * 100, 0).' %']))];

        $support = min($quarter);
        $resistance = max($quarter);
        $signals[] = ['label' => __('Support / resistance (3 months)'), 'value' => $money($support).' / '.$money($resistance), 'level' => 'neutral',
            'note' => __('Lows and highs of the last three months – prices often turn there.')];

        if ($volatility !== null) {
            $signals[] = ['label' => __('Volatility (1 year)'), 'value' => number_format($volatility * 100, 0).' %',
                'level' => $volatility > 0.4 ? 'yellow' : 'neutral',
                'note' => $volatility > 0.4 ? __('Strong swings – size the position carefully.') : __('Usual fluctuation for a share.')];
        }

        return [
            'price' => $price,
            'sma38' => $sma38,
            'sma200' => $sma200,
            'rsi' => $rsi,
            'momentum_6m' => $momentum6,
            'momentum_12m' => $momentum12,
            'high_52w' => $high,
            'low_52w' => $low,
            'support' => $support,
            'resistance' => $resistance,
            'volatility' => $volatility,
            'trend' => $trend,
            'signals' => $signals,
        ];
    }

    /**
     * Moving average of the last $days closes (null when there are too few).
     *
     * @param  list<float>  $closes
     */
    public static function sma(array $closes, int $days): ?float
    {
        return count($closes) >= $days ? array_sum(array_slice($closes, -$days)) / $days : null;
    }

    /**
     * Moving average for every day (null until enough closes) – for the chart line.
     *
     * @param  list<float>  $closes
     * @return list<?float>
     */
    public static function smaSeries(array $closes, int $days): array
    {
        $series = [];
        $sum = 0.0;

        foreach ($closes as $index => $close) {
            $sum += $close;

            if ($index >= $days) {
                $sum -= $closes[$index - $days];
            }

            $series[] = $index >= $days - 1 ? round($sum / $days, 4) : null;
        }

        return $series;
    }

    /**
     * Relative strength index after Wilder.
     *
     * @param  list<float>  $closes
     */
    public static function rsi(array $closes, int $days = 14): ?float
    {
        if (count($closes) <= $days) {
            return null;
        }

        $gain = 0.0;
        $loss = 0.0;

        for ($i = 1; $i <= $days; $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            $gain += max(0, $change);
            $loss += max(0, -$change);
        }

        $gain /= $days;
        $loss /= $days;

        for ($i = $days + 1, $count = count($closes); $i < $count; $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            $gain = ($gain * ($days - 1) + max(0, $change)) / $days;
            $loss = ($loss * ($days - 1) + max(0, -$change)) / $days;
        }

        return $loss == 0.0 ? 100.0 : round(100 - 100 / (1 + $gain / $loss), 1);
    }

    /**
     * Annualised volatility of the daily returns.
     *
     * @param  list<float>  $closes
     */
    private static function volatility(array $closes): ?float
    {
        if (count($closes) < 30) {
            return null;
        }

        $returns = [];

        for ($i = 1, $count = count($closes); $i < $count; $i++) {
            $returns[] = log($closes[$i] / $closes[$i - 1]);
        }

        $mean = array_sum($returns) / count($returns);
        $variance = array_sum(array_map(fn (float $value) => ($value - $mean) ** 2, $returns)) / (count($returns) - 1);

        return round(sqrt($variance) * sqrt(252), 4);
    }
}
