# POS Requirements Questionnaire

Choose one option per question unless marked as `multi-select`.

Reply example: `G1-B, M1-D, R3-A`

## Global Scope

1. `G1` Rollout scope
   - A. Single store pilot
   - B. Multi-store phased
   - C. All stores go-live
   - D. HQ dashboard only first
2. `G2` Main business goal
   - A. Faster checkout
   - B. Better control/compliance
   - C. Better reporting visibility
   - D. Reduce cash leakage
3. `G3` Primary users (`multi-select`)
   - A. Cashier
   - B. Store manager
   - C. Finance/audit
   - D. Operations HQ
4. `G4` Permission strictness
   - A. Basic role-based
   - B. Role + store-level restriction
   - C. Role + maker-checker approvals
   - D. Full granular permission matrix
5. `G5` Data freshness target
   - A. Real-time (<5s)
   - B. Near real-time (15-60s)
   - C. Hourly
   - D. End-of-day
6. `G6` MVP timeline style
   - A. 2-4 weeks thin MVP
   - B. 6-8 weeks balanced
   - C. 10-12 weeks full controls
   - D. No fixed deadline

## `/pos/monitor`

1. `M1` Monitor level
   - A. Per terminal
   - B. Per store
   - C. Multi-store summary + drilldown
   - D. Region/company-wide only
2. `M2` Must-show widgets (`multi-select`)
   - A. Live transactions
   - B. Queue/wait time
   - C. Terminal online/offline
   - D. Suspicious events
3. `M3` Alert behavior
   - A. Visual only
   - B. Visual + sound
   - C. Visual + email/WA/Slack
   - D. No alerts in MVP
4. `M4` Operator actions from monitor
   - A. View-only
   - B. Remote lock terminal
   - C. Force logout cashier
   - D. Reassign terminal
5. `M5` Refresh method
   - A. WebSocket live stream
   - B. Auto-polling
   - C. Manual refresh
   - D. Hybrid

## `/pos/reports`

1. `R1` Report granularity (`multi-select`)
   - A. Hourly
   - B. Shift
   - C. Daily
   - D. Weekly/monthly
2. `R2` Core report pack (`multi-select`)
   - A. Sales by item/category
   - B. Cashier performance
   - C. Payment method mix
   - D. Discount/void/refund analysis
3. `R3` Comparison need
   - A. vs previous period
   - B. vs target budget
   - C. vs other stores
   - D. No comparisons in MVP
4. `R4` Delivery format
   - A. On-screen only
   - B. CSV/XLS export
   - C. PDF export
   - D. Scheduled email
5. `R5` Financial basis
   - A. Calendar date
   - B. Shift close date
   - C. Fiscal period
   - D. Configurable per store

## `/pos/reconciliation`

1. `C1` Reconciliation frequency
   - A. Per shift
   - B. Daily EOD
   - C. Weekly
   - D. Ad-hoc only
2. `C2` Reconcile sources
   - A. POS vs cash count
   - B. POS vs payment gateway
   - C. POS vs bank settlement
   - D. All three
3. `C3` Variance tolerance rule
   - A. Zero tolerance
   - B. Fixed amount threshold
   - C. Percentage threshold
   - D. By payment type
4. `C4` Closing policy on variance
   - A. Block close
   - B. Allow with reason
   - C. Allow with manager approval
   - D. Auto-post to suspense
5. `C5` Approval workflow
   - A. None
   - B. Store manager only
   - C. Store + finance dual approval
   - D. Risk-based conditional approval
6. `C6` Evidence attachment
   - A. Not required
   - B. Optional note
   - C. Mandatory note + file upload
   - D. Mandatory for variance only

## `/pos/terminals`

1. `T1` Terminal ownership model
   - A. Fixed per cashier
   - B. Shared pool
   - C. Fixed per lane/counter
   - D. Hybrid
2. `T2` Provisioning method
   - A. Manual registration
   - B. QR/self-enroll
   - C. MDM auto-enroll
   - D. Vendor-managed
3. `T3` Lifecycle controls needed (`multi-select`)
   - A. Activate/deactivate
   - B. Reset device token
   - C. Remote logout
   - D. Remote config push
4. `T4` Health monitoring
   - A. Online/offline only
   - B. Last heartbeat + app version
   - C. Battery/network/peripheral status
   - D. Full diagnostics
5. `T5` Offline mode policy
   - A. No offline sales
   - B. Offline with limited SKU list
   - C. Offline full cart with sync
   - D. Configurable by store

## Security, Audit, Compliance

1. `S1` Audit trail depth
   - A. Critical actions only
   - B. All user actions
   - C. All actions + before/after values
   - D. Compliance-grade immutable log
2. `S2` Retention period
   - A. 3 months
   - B. 1 year
   - C. 3 years
   - D. 7 years
3. `S3` Sensitive data policy
   - A. Basic masking
   - B. Tokenize payment refs
   - C. Encrypt at rest + in transit
   - D. PCI-aligned full controls

## Next Step

After you send your selected options, I can convert them into:

1. MVP scope per URL
2. User stories + acceptance criteria
3. Phase 1/2 implementation roadmap
