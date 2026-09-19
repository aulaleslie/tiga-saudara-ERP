<?php

namespace Tests\Unit;

use App\Constants\PaymentStatus;
use Modules\Purchase\Entities\Purchase;
use Modules\Sale\Entities\Sale;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosTransaction;
use Tests\TestCase;

class IndonesianStatusLabelsTest extends TestCase
{
    /** @test */
    public function it_provides_standardized_payment_status_labels_and_normalizes_casing()
    {
        $this->assertSame('Lunas', PaymentStatus::label('Paid'));
        $this->assertSame('Lunas', PaymentStatus::label('PAID'));
        $this->assertSame('Lunas', PaymentStatus::label('paid'));

        $this->assertSame('Dibayar Sebagian', PaymentStatus::label('Partial'));
        $this->assertSame('Dibayar Sebagian', PaymentStatus::label('PARTIAL'));
        $this->assertSame('Dibayar Sebagian', PaymentStatus::label('partial'));

        $this->assertSame('Belum Dibayar', PaymentStatus::label('Unpaid'));
        $this->assertSame('Belum Dibayar', PaymentStatus::label('UNPAID'));
        $this->assertSame('Belum Dibayar', PaymentStatus::label('unpaid'));

        $this->assertSame('', PaymentStatus::label(null));
        $this->assertSame('', PaymentStatus::label(''));
        $this->assertSame('Unknown', PaymentStatus::label('Unknown'));
    }

    /** @test */
    public function it_provides_standardized_purchase_lifecycle_labels()
    {
        $this->assertSame('Draf', Purchase::STATUS_LABELS[Purchase::STATUS_DRAFTED]);
        $this->assertSame('Menunggu Persetujuan', Purchase::STATUS_LABELS[Purchase::STATUS_WAITING_APPROVAL]);
        $this->assertSame('Disetujui', Purchase::STATUS_LABELS[Purchase::STATUS_APPROVED]);
        $this->assertSame('Ditolak', Purchase::STATUS_LABELS[Purchase::STATUS_REJECTED]);
        $this->assertSame('Diterima Sebagian', Purchase::STATUS_LABELS[Purchase::STATUS_RECEIVED_PARTIALLY]);
        $this->assertSame('Diterima', Purchase::STATUS_LABELS[Purchase::STATUS_RECEIVED]);
        $this->assertSame('Dikembalikan', Purchase::STATUS_LABELS[Purchase::STATUS_RETURNED]);
        $this->assertSame('Dikembalikan Sebagian', Purchase::STATUS_LABELS[Purchase::STATUS_RETURNED_PARTIALLY]);

        $this->assertSame('Lunas', Purchase::PAYMENT_STATUS_LABELS[Purchase::PAYMENT_STATUS_PAID]);
        $this->assertSame('Dibayar Sebagian', Purchase::PAYMENT_STATUS_LABELS[Purchase::PAYMENT_STATUS_PARTIAL]);
        $this->assertSame('Belum Dibayar', Purchase::PAYMENT_STATUS_LABELS[Purchase::PAYMENT_STATUS_UNPAID]);
    }

    /** @test */
    public function it_provides_standardized_sale_lifecycle_labels()
    {
        $this->assertSame('Draf', Sale::STATUS_LABELS[Sale::STATUS_DRAFTED]);
        $this->assertSame('Menunggu Persetujuan', Sale::STATUS_LABELS[Sale::STATUS_WAITING_APPROVAL]);
        $this->assertSame('Disetujui', Sale::STATUS_LABELS[Sale::STATUS_APPROVED]);
        $this->assertSame('Ditolak', Sale::STATUS_LABELS[Sale::STATUS_REJECTED]);
        $this->assertSame('Dikirim Sebagian', Sale::STATUS_LABELS[Sale::STATUS_DISPATCHED_PARTIALLY]);
        $this->assertSame('Dikirim', Sale::STATUS_LABELS[Sale::STATUS_DISPATCHED]);
        $this->assertSame('Dikembalikan', Sale::STATUS_LABELS[Sale::STATUS_RETURNED]);
        $this->assertSame('Dikembalikan Sebagian', Sale::STATUS_LABELS[Sale::STATUS_RETURNED_PARTIALLY]);

        $this->assertSame('Lunas', Sale::PAYMENT_STATUS_LABELS[Sale::PAYMENT_STATUS_PAID]);
        $this->assertSame('Dibayar Sebagian', Sale::PAYMENT_STATUS_LABELS[Sale::PAYMENT_STATUS_PARTIAL]);
        $this->assertSame('Belum Dibayar', Sale::PAYMENT_STATUS_LABELS[Sale::PAYMENT_STATUS_UNPAID]);
    }

    /** @test */
    public function it_provides_standardized_sales_return_status_labels()
    {
        $this->assertSame('Draf', SaleReturn::statusLabel('draft'));
        $this->assertSame('Draf', SaleReturn::statusLabel('Draft'));
        $this->assertSame('Menunggu', SaleReturn::statusLabel('pending'));
        $this->assertSame('Menunggu', SaleReturn::statusLabel('Pending'));
        $this->assertSame('Menunggu Persetujuan', SaleReturn::statusLabel('pending approval'));
        $this->assertSame('Menunggu Penerimaan', SaleReturn::statusLabel('awaiting receiving'));
        $this->assertSame('Menunggu Penyelesaian', SaleReturn::statusLabel('awaiting settlement'));
        $this->assertSame('Ditolak', SaleReturn::statusLabel('rejected'));
        $this->assertSame('Selesai', SaleReturn::statusLabel('completed'));
        $this->assertSame('Dibatalkan', SaleReturn::statusLabel('cancelled'));

        $this->assertSame('Draf', SaleReturn::approvalStatusLabel('draft'));
        $this->assertSame('Menunggu', SaleReturn::approvalStatusLabel('pending'));
        $this->assertSame('Disetujui', SaleReturn::approvalStatusLabel('approved'));
        $this->assertSame('Ditolak', SaleReturn::approvalStatusLabel('rejected'));
    }

    /** @test */
    public function it_provides_standardized_purchase_return_unified_status_labels()
    {
        $labels = PurchaseReturn::unifiedStatusLabels();

        $this->assertSame('Draf', $labels[PurchaseReturn::STATUS_DRAFT]);
        $this->assertSame('Menunggu Persetujuan', $labels[PurchaseReturn::STATUS_PENDING_APPROVAL]);
        $this->assertSame('Ditolak', $labels[PurchaseReturn::STATUS_REJECTED]);
        $this->assertSame('Menunggu Pengiriman Retur', $labels[PurchaseReturn::STATUS_AWAITING_DISPATCH]);
        $this->assertSame('Menunggu Persetujuan Pengiriman', $labels[PurchaseReturn::STATUS_DISPATCH_PENDING_APPROVAL]);
        $this->assertSame('Sedang Dalam Retur, Menunggu Input Penyelesaian', $labels[PurchaseReturn::STATUS_IN_RETURN]);
        $this->assertSame('Menunggu Konfirmasi Penyelesaian', $labels[PurchaseReturn::STATUS_SETTLEMENT_CONFIRMATION_PENDING]);
        $this->assertSame('Menunggu Barang Pengganti', $labels[PurchaseReturn::STATUS_WAITING_REPLACEMENT_GOODS]);
        $this->assertSame('Penyelesaian Disetujui Sebagian', $labels[PurchaseReturn::STATUS_PARTIAL_SETTLEMENT]);
        $this->assertSame('Selesai', $labels[PurchaseReturn::STATUS_COMPLETED]);
    }

    /** @test */
    public function it_provides_standardized_pos_status_labels()
    {
        $this->assertSame('Draf', PosReturn::STATUS_LABELS[PosReturn::STATUS_DRAFT]);
        $this->assertSame('Menunggu Persetujuan', PosReturn::STATUS_LABELS[PosReturn::STATUS_PENDING_APPROVAL]);
        $this->assertSame('Disetujui', PosReturn::STATUS_LABELS[PosReturn::STATUS_APPROVED]);
        $this->assertSame('Ditolak', PosReturn::STATUS_LABELS[PosReturn::STATUS_REJECTED]);
        $this->assertSame('Menunggu Penerimaan', PosReturn::STATUS_LABELS[PosReturn::STATUS_AWAITING_RECEIVING]);
        $this->assertSame('Menunggu Penyelesaian', PosReturn::STATUS_LABELS[PosReturn::STATUS_AWAITING_SETTLEMENT]);
        $this->assertSame('Menunggu Pengiriman', PosReturn::STATUS_LABELS[PosReturn::STATUS_AWAITING_DISPATCH]);
        $this->assertSame('Koreksi Manual Diperlukan', PosReturn::STATUS_LABELS[PosReturn::STATUS_MANUAL_CORRECTION_REQUIRED]);
        $this->assertSame('Selesai', PosReturn::STATUS_LABELS[PosReturn::STATUS_COMPLETED]);
        $this->assertSame('Diarsipkan', PosReturn::STATUS_LABELS[PosReturn::STATUS_ARCHIVED]);
        $this->assertSame('Dibatalkan', PosReturn::STATUS_LABELS[PosReturn::STATUS_CANCELLED]);

        $this->assertSame('Draf', PosTransaction::STATUS_LABELS[PosTransaction::STATUS_DRAFT]);
        $this->assertSame('Dimuat', PosTransaction::STATUS_LABELS[PosTransaction::STATUS_LOADED]);
        $this->assertSame('Selesai', PosTransaction::STATUS_LABELS[PosTransaction::STATUS_COMPLETED]);
        $this->assertSame('Dibatalkan', PosTransaction::STATUS_LABELS[PosTransaction::STATUS_CANCELLED]);
    }

    /** @test */
    public function it_provides_standardized_payment_record_and_settlement_labels()
    {
        $this->assertSame('Aktif', \Modules\Purchase\Entities\PurchasePayment::STATUS_LABELS[\Modules\Purchase\Entities\PurchasePayment::STATUS_ACTIVE]);
        $this->assertSame('Dibatalkan', \Modules\Purchase\Entities\PurchasePayment::STATUS_LABELS[\Modules\Purchase\Entities\PurchasePayment::STATUS_INVALIDATED]);

        $this->assertSame('Aktif', \Modules\Sale\Entities\SalePayment::STATUS_LABELS[\Modules\Sale\Entities\SalePayment::STATUS_ACTIVE]);
        $this->assertSame('Dibatalkan', \Modules\Sale\Entities\SalePayment::STATUS_LABELS[\Modules\Sale\Entities\SalePayment::STATUS_INVALIDATED]);

        $this->assertSame('Menunggu Persetujuan', \Modules\PurchasesReturn\Entities\PurchaseReturnSettlement::STATUS_LABELS[\Modules\PurchasesReturn\Entities\PurchaseReturnSettlement::STATUS_PENDING]);
        $this->assertSame('Disetujui', \Modules\PurchasesReturn\Entities\PurchaseReturnSettlement::STATUS_LABELS[\Modules\PurchasesReturn\Entities\PurchaseReturnSettlement::STATUS_APPROVED]);
        $this->assertSame('Ditolak', \Modules\PurchasesReturn\Entities\PurchaseReturnSettlement::STATUS_LABELS[\Modules\PurchasesReturn\Entities\PurchaseReturnSettlement::STATUS_REJECTED]);
        $this->assertSame('Sedang Diproses', \Modules\PurchasesReturn\Entities\PurchaseReturnSettlement::STATUS_LABELS[\Modules\PurchasesReturn\Entities\PurchaseReturnSettlement::STATUS_EXECUTING]);
        $this->assertSame('Selesai', \Modules\PurchasesReturn\Entities\PurchaseReturnSettlement::STATUS_LABELS[\Modules\PurchasesReturn\Entities\PurchaseReturnSettlement::STATUS_COMPLETED]);
    }

    /** @test */
    public function it_renders_pos_return_status_partial_in_indonesian()
    {
        $view = $this->blade('@include("pos::returns.partials.status", ["data" => (object)["status" => "pending_approval"]])');
        $this->assertStringContainsString('Menunggu Persetujuan', (string) $view);
        $this->assertStringNotContainsString('Pending Approval', (string) $view);

        $view = $this->blade('@include("pos::returns.partials.status", ["data" => (object)["status" => "awaiting_receiving"]])');
        $this->assertStringContainsString('Menunggu Penerimaan', (string) $view);
        $this->assertStringNotContainsString('Awaiting Receiving', (string) $view);

        $view = $this->blade('@include("pos::returns.partials.status", ["data" => (object)["status" => "awaiting_settlement"]])');
        $this->assertStringContainsString('Menunggu Penyelesaian', (string) $view);
        $this->assertStringNotContainsString('Awaiting Settlement', (string) $view);

        $view = $this->blade('@include("pos::returns.partials.status", ["data" => (object)["status" => "awaiting_dispatch"]])');
        $this->assertStringContainsString('Menunggu Pengiriman', (string) $view);
        $this->assertStringNotContainsString('Awaiting Dispatch', (string) $view);

        $view = $this->blade('@include("pos::returns.partials.status", ["data" => (object)["status" => "manual_correction_required"]])');
        $this->assertStringContainsString('Koreksi Manual Diperlukan', (string) $view);
        $this->assertStringNotContainsString('Manual Correction Required', (string) $view);

        $view = $this->blade('@include("pos::returns.partials.status", ["data" => (object)["status" => "approved"]])');
        $this->assertStringContainsString('Disetujui', (string) $view);

        $view = $this->blade('@include("pos::returns.partials.status", ["data" => (object)["status" => "completed"]])');
        $this->assertStringContainsString('Selesai', (string) $view);

        $view = $this->blade('@include("pos::returns.partials.status", ["data" => (object)["status" => "draft"]])');
        $this->assertStringContainsString('Draf', (string) $view);
        $this->assertStringNotContainsString('Draft', (string) $view);
    }
}
