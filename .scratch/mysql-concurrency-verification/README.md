# MySQL concurrency verification (disposable database only)

`boot.php` refuses to run unless the connection is database `verify` on port 3399. Never point it at a real database.

```bash
docker run -d --rm --name tiga-verify-mysql -e MYSQL_ROOT_PASSWORD=verifypw -e MYSQL_DATABASE=verify -p 3399:3306 mysql:8.0
export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3399 DB_DATABASE=verify DB_USERNAME=root DB_PASSWORD=verifypw
php artisan migrate:fresh --force            # slow (several minutes)
php .scratch/mysql-concurrency-verification/seed.php   # prints ids (a,b,c,d,p,q,t1,t2,t3)
START=$(php -r 'echo microtime(true)+7;')
php .scratch/mysql-concurrency-verification/worker.php refund $START <t2 id> &   # x2 concurrently, then `wait`
php .scratch/mysql-concurrency-verification/state.php
```

Modes: `refund` (real `reduceTransferChain`, locks the refunded sale first like the lifecycle), `repairlocks` (repair lock order sales -> payments -> transfers, `DB::transaction(..., 3)`), `dupkey` (same execution key), `overalloc` (two distinct 600.00 transfers from a 1000.00 payment, inside a transaction).
Repository tests run on MySQL only through the isolated `mysql_test` connection (`DB_CONNECTION=mysql_test`; `tests/CreatesApplication.php` aborts unless the database name ends in `_test`). Never point the suite at the `verify` scratch database or any real database; this scratch harness is for lock-order/concurrency probes only.
