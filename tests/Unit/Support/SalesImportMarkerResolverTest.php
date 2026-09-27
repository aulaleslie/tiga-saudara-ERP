<?php

namespace Tests\Unit\Support;

use App\Support\SalesImportMarkerResolver;
use PHPUnit\Framework\TestCase;

class SalesImportMarkerResolverTest extends TestCase
{
    protected SalesImportMarkerResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new SalesImportMarkerResolver();
    }

    public function test_it_identifies_asterisk_prefix()
    {
        $parsed = $this->resolver->parseProductName('* BATERAI CMOS');
        
        $this->assertEquals('BATERAI CMOS', $parsed['clean_name']);
        $this->assertEquals('asterisk', $parsed['marker']);
        
        $this->assertEquals('CV TIGA NUSA COMPUTER', $this->resolver->resolveOwnerCompanyName('* BATERAI CMOS'));
        $this->assertEquals('marker:asterisk', $this->resolver->resolveEffectiveOwnerKey('* BATERAI CMOS'));
    }

    public function test_it_identifies_tp_suffix()
    {
        $parsed = $this->resolver->parseProductName('ADAPTOR ACER 19V 3.42A TP');
        
        $this->assertEquals('ADAPTOR ACER 19V 3.42A', $parsed['clean_name']);
        $this->assertEquals('tp', $parsed['marker']);
        
        $this->assertEquals('CV TOP IT INTERNUSA', $this->resolver->resolveOwnerCompanyName('ADAPTOR ACER 19V 3.42A TP'));
        $this->assertEquals('marker:tp', $this->resolver->resolveEffectiveOwnerKey('ADAPTOR ACER 19V 3.42A TP'));
    }

    public function test_it_identifies_daizu_keywords()
    {
        $this->assertTrue($this->resolver->isDaizuProduct('KEDELAI BOLA'));
        $this->assertTrue($this->resolver->isDaizuProduct('KEDELE LOKAL'));
        $this->assertTrue($this->resolver->isDaizuProduct('RAGI TEMPE'));
        
        $this->assertFalse($this->resolver->isDaizuProduct('RAGINAM')); // Part of word
        $this->assertFalse($this->resolver->isDaizuProduct('BATERAI CMOS'));
        
        $this->assertEquals('DAIZU', $this->resolver->resolveOwnerCompanyName('KEDELAI BOLA'));
        $this->assertEquals('daizu', $this->resolver->resolveEffectiveOwnerKey('KEDELAI BOLA'));
    }

    public function test_it_identifies_unmarked_products()
    {
        $parsed = $this->resolver->parseProductName('MOUSE LOGITECH M170');
        
        $this->assertEquals('MOUSE LOGITECH M170', $parsed['clean_name']);
        $this->assertEquals('default', $parsed['marker']);
        
        $this->assertEquals('PERDANA', $this->resolver->resolveOwnerCompanyName('MOUSE LOGITECH M170'));
        $this->assertEquals('marker:default', $this->resolver->resolveEffectiveOwnerKey('MOUSE LOGITECH M170'));
    }

    public function test_daizu_takes_precedence_over_markers()
    {
        // Even if a Daizu product has an asterisk, it routes to Daizu (per resolveEffectiveOwnerKey priority)
        $this->assertEquals('DAIZU', $this->resolver->resolveOwnerCompanyName('* KEDELAI BOLA'));
        $this->assertEquals('daizu', $this->resolver->resolveEffectiveOwnerKey('* KEDELAI BOLA'));
    }
}
