<?php

namespace Tests\Unit;

use App\Services\SaleCheckout;
use App\Services\SaleCreditService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SaleCreditContractTest extends TestCase
{
    public function test_request_identity_ignores_map_order_but_not_financial_input_or_line_order(): void
    {
        $command = ['customer' => 'a', 'items' => [['qty' => 1, 'price' => '12.30'], ['qty' => 2]]];
        $reordered = ['items' => [['price' => '12.30', 'qty' => 1], ['qty' => 2]], 'customer' => 'a'];
        $this->assertSame(SaleCheckout::fingerprint($command), SaleCheckout::fingerprint($reordered));
        $reordered['items'][0]['qty'] = 3;
        $this->assertNotSame(SaleCheckout::fingerprint($command), SaleCheckout::fingerprint($reordered));
        $reordered = $command;
        $reordered['items'] = array_reverse($command['items']);
        $this->assertNotSame(SaleCheckout::fingerprint($command), SaleCheckout::fingerprint($reordered));
    }

    public function test_principal_roundtrips_exactly_without_float_tolerances(): void
    {
        foreach (['0.00', '118.01', '999999999999.99'] as $amount) {
            $this->assertSame($amount, SaleCreditService::decimal(SaleCreditService::cents($amount)));
        }
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_principal_is_rejected(string $amount): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SaleCreditService::cents($amount);
    }

    public static function invalidAmounts(): array
    {
        return array_map(fn ($value) => [$value], ['-1.00', '1.001', '1e3', 'NaN', '', '1000000000000.00']);
    }

    public function test_non_finite_commands_cannot_share_a_request_hash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SaleCheckout::fingerprint(['amount' => INF]);
    }
}
