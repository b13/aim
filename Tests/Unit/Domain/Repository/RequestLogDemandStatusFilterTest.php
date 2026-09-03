<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Domain\Repository;

use B13\Aim\Domain\Repository\RequestLogDemand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3Fluid\Fluid\Core\Variables\StandardVariableProvider;

/**
 * Resolved through Fluid's own variable provider, since the filter select is
 * what these values feed.
 */
final class RequestLogDemandStatusFilterTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function statusFilterStates(): array
    {
        return [
            'no filter posted at all' => [[], ''],
            'the "All" option posts an empty value' => [['success' => ''], ''],
            'successful only' => [['success' => '1'], '1'],
            'failed only' => [['success' => '0'], '0'],
        ];
    }

    #[Test]
    #[DataProvider('statusFilterStates')]
    public function theSelectedOptionValueIsReachableFromFluid(array $demandArray, string $expected): void
    {
        $provider = new StandardVariableProvider(['demand' => $this->demandFrom($demandArray)]);

        self::assertSame($expected, $provider->getByPath('demand.successFilter'));
    }

    #[Test]
    public function hasSuccessIsNotReachableFromFluidAtAll(): void
    {
        $demand = $this->demandFrom(['success' => '1']);
        $provider = new StandardVariableProvider(['demand' => $demand]);

        self::assertTrue($demand->hasSuccess());
        self::assertNull($provider->getByPath('demand.hasSuccess'), 'Fluid can see hasSuccess() now - re-check the template.');
    }

    #[Test]
    public function theNoFilterValueMatchesNeitherOption(): void
    {
        $noFilter = $this->demandFrom([])->getSuccessFilter();

        self::assertFalse($noFilter == '1');
        self::assertFalse($noFilter == '0');
    }

    /**
     * @param array<string, string> $demandArray
     */
    private function demandFrom(array $demandArray): RequestLogDemand
    {
        $request = (new ServerRequest('https://example.com/typo3/', 'POST'))
            ->withParsedBody($demandArray === [] ? [] : ['demand' => $demandArray]);

        return RequestLogDemand::fromRequest($request);
    }
}
