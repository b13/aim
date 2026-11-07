<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Footprint;

use B13\Aim\Footprint\Format;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class FormatTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    #[Test]
    public function co2IsScaledToTheLargerValueWithTwoSignificantDigits(): void
    {
        self::assertSame('1.6–2.0 mg CO₂e', Format::co2(0.0016380514, 0.0019532425));
        self::assertSame('0.11–0.19 g CO₂e', Format::co2(0.11272506, 0.18668990));
        self::assertSame('0.95 mg CO₂e', Format::co2(0.0009506, 0.0009506));
        self::assertSame('1.2–2.3 kg CO₂e', Format::co2(1234.5, 2345.6));
        self::assertSame('1.0 g CO₂e', Format::co2(0.99996, 0.99996));
    }

    #[Test]
    public function energyIsScaledToTheLargerValue(): void
    {
        self::assertSame('4.3–5.1 mWh', Format::energy(0.0042613, 0.0050812));
        self::assertSame('0.29–0.49 Wh', Format::energy(0.2932, 0.4857));
        self::assertSame('20 mWh', Format::energy(0.02, 0.02));
    }

    #[Test]
    public function smallValueInAWideRangeNeverRendersAsZero(): void
    {
        self::assertSame('< 0.01–0.19 kg CO₂e', Format::co2(0.00001, 187.0));
        self::assertSame('0.000010 µg CO₂e', Format::co2(0.00000000001, 0.00000000001));
    }

    #[Test]
    public function routingChangeSaysWhetherSmartRoutingAvoidedOrAddedCo2e(): void
    {
        self::assertSame(['key' => 'avoided', 'values' => ['0.20–0.39 g CO₂e']], Format::routingChange(2, 0.196, 0.394));
        self::assertSame(['key' => 'avoided', 'values' => ['0.20–0.39 g CO₂e']], Format::routingChange(2, 0.394, 0.196));
        self::assertSame(['key' => 'added', 'values' => ['0.01–0.10 g CO₂e']], Format::routingChange(3, -0.1, -0.01));
        self::assertSame(['key' => 'mixed', 'values' => ['10 mg CO₂e', '0.20 g CO₂e']], Format::routingChange(3, -0.01, 0.2));
        self::assertNull(Format::routingChange(0, 0.0, 0.0));
        self::assertNull(Format::routingChange(2, 0.0, 0.0));
    }

    #[Test]
    public function summaryOfAnAggregateWithoutEstimatesHasNoData(): void
    {
        $summary = Format::summary(['requests' => 3, 'estimated_requests' => 0, 'co2_min' => 0.0, 'co2_max' => 0.0, 'energy_min' => 0.0, 'energy_max' => 0.0]);

        self::assertFalse($summary['hasData']);
        self::assertSame('', $summary['co2']);
    }

    #[Test]
    public function summaryReportsCoverageAndPageViews(): void
    {
        $summary = Format::summary(['requests' => 4, 'estimated_requests' => 2, 'co2_min' => 0.36, 'co2_max' => 0.72, 'energy_min' => 1.0, 'energy_max' => 2.0]);

        self::assertTrue($summary['hasData']);
        self::assertSame('0.36–0.72 g CO₂e', $summary['co2']);
        self::assertSame('1.0–2.0', $summary['pageViews']);
        self::assertSame('2', $summary['estimatedRequests']);
        self::assertSame('4', $summary['requests']);
    }
}
