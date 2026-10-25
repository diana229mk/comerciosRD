<?php

namespace Tests\Unit;

use App\Support\TenantStorage;
use PHPUnit\Framework\TestCase;

class TenantStorageTest extends TestCase
{
    public function test_directory_without_business_keeps_base(): void
    {
        $this->assertSame('products', TenantStorage::directory('products', ''));
        $this->assertSame('products', TenantStorage::directory('/products/', ''));
    }

    public function test_directory_appends_safe_business_id(): void
    {
        $this->assertSame(
            'products/01a0e09a-00ea-7198-86af-3e37497e7b60',
            TenantStorage::directory('products', '01a0e09a-00ea-7198-86af-3e37497e7b60')
        );
    }

    public function test_directory_rejects_path_traversal(): void
    {
        $this->assertSame('products', TenantStorage::directory('products', '../etc/passwd'));
        $this->assertSame('products', TenantStorage::directory('products', 'abc/../x'));
    }
}
