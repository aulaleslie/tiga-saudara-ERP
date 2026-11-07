# POS replacement review probes

Run from the repository root. Each class uses its own `/tmp/*testing.sqlite` file; these probes do not use the development database. They require the existing fixture and test base in this checkout.

```bash
vendor/bin/phpunit -c phpunit.xml .scratch/pos-replacement-review-probes/LifecycleRefundReviewTest.php --filter '(full_replacement_refund|mixed_incoming_direct_refund|chain_refund)_probe$'
vendor/bin/phpunit -c phpunit.xml .scratch/pos-replacement-review-probes/ReplacementChainReviewTest.php
vendor/bin/phpunit -c phpunit.xml .scratch/pos-replacement-review-probes/ReplacementPartialChainReviewTest.php
```

The refund probes create the initial replacement through the real lifecycle, then exercise the actual cash recalculation using persisted synthetic refund evidence. They test accounting, not the entire stock/serial return UI. The chain probe checks owner A Rp45,000, B Rp0, C Rp1,725,000 after a Rp500,000 refund.

Historical probes create synthetic completed A→B→C replacement chains and call real preview/apply. The partial fixture uses quantity 0.5 to isolate monetary chain replay; it is not a serial-entry eligibility test. They assert repairable preview, successful apply, and preserved Rp2,600,000 group total. Unlike the original diagnostic scripts, these copies fail on blocked preview or an apply exception.

Expected current results: the three original refund probes pass; full historical chain passes; partial historical chain fails because intermediate payment validation ignores the planned incoming allocation.

An additional sibling-payment refund probe demonstrates a newly found failure: the root reconciler can invalidate an unrelated newer payment backing another owner's transfer. Run it separately:

```bash
vendor/bin/phpunit -c phpunit.xml .scratch/pos-replacement-review-probes/LifecycleRefundReviewTest.php --filter test_sibling_payment_refund_probe
```

Expected after correction: source A Rp45,000, refunded owner B Rp1,725,000, unaffected sibling D Rp100,000. Current result: A Rp145,000, B Rp1,725,000, D Rp0.
