<?php

namespace Modules\Pos\Tests\Feature;

use Tests\TestCase;
use Modules\Pos\Support\PosPermissionMatrix;

class POSReturnPermissionMatrixTest extends TestCase
{
    /** @test */
    public function it_has_pos_return_permissions_in_capability_clusters()
    {
        $clusters = PosPermissionMatrix::capabilityClusters();
        
        $this->assertArrayHasKey('returns', $clusters);
        $this->assertContains('pos.returns.view', $clusters['returns']['permissions']);
        $this->assertContains('pos.returns.create', $clusters['returns']['permissions']);
    }

    /** @test */
    public function it_has_pos_return_permissions_in_bundles()
    {
        $bundles = PosPermissionMatrix::supportedBundles();
        
        $this->assertContains('pos.returns.view', $bundles['manager']['permissions']);
        $this->assertContains('pos.returns.approve', $bundles['manager']['permissions']);
        
        $this->assertContains('pos.returns.view', $bundles['cashier']['permissions']);
        $this->assertNotContains('pos.returns.approve', $bundles['cashier']['permissions']);
    }
}
