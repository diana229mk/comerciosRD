<?php

namespace Tests\Unit;

use App\Services\SaleFinancialIntegrity;
use Illuminate\Database\Query\Expression;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SaleFinancialIntegrityTest extends TestCase
{
    #[DataProvider('financialChanges')]
    public function test_financial_validation_preserves_decimal_equivalence_and_rejects_source_changes(array $values, bool $accepted): void
    {
        $row = (object) [
            'id' => 'sale-id', 'business_id' => 'business-id', 'status' => 'completada',
            'total' => '59.00', 'discount' => '0.00', 'payment_method' => 'efectivo',
            'sale_number' => 'VTA-1', 'sale_date' => '2026-10-31 12:00:00', 'notes' => null,
        ];
        $actual = true;
        try {
            app(SaleFinancialIntegrity::class)->assertSaleUpdate($row, $values, true);
        } catch (ValidationException) {
            $actual = false;
        }
        $this->assertSame($accepted, $actual);
    }

    public static function financialChanges(): array
    {
        return [
            'same decimal' => [['total' => 59, 'notes' => 'Administrativa'], true],
            'different cents' => [['total' => '59.01'], false],
            'case-insensitive SQL column' => [['TOTAL' => '1.00'], false],
            'different method' => [['payment_method' => 'transferencia'], false],
            'reference' => [['sale_number' => 'VTA-2'], false],
            'date' => [['sale_date' => '2026-11-01 12:00:00'], false],
            'business' => [['business_id' => 'other-business'], false],
            'SQL expression' => [['total' => new Expression('total + 1')], false],
            'downgrade' => [['status' => null], false],
            'cancel only' => [['status' => 'anulada'], true],
            'cancel and change' => [['status' => 'anulada', 'discount' => 1], false],
        ];
    }
}
