<?php
require_once __DIR__ . '/BookingFixture.php';
require_once __DIR__ . '/../../helpers/payment_webhooks.php';

function paymentTables(PDO $db): void
{
    $db->exec("CREATE TABLE delivery_fare_approvals (id INTEGER PRIMARY KEY, delivery_id INTEGER, version INTEGER, amount_minor INTEGER,
        currency TEXT, input_hash TEXT, approved_by INTEGER, approval_reason TEXT, approved_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE(delivery_id,version));
        CREATE TABLE delivery_payment_attempts (id TEXT PRIMARY KEY, reference TEXT UNIQUE, delivery_id INTEGER, client_id INTEGER, fare_approval_id INTEGER,
        provider TEXT, environment TEXT, amount_minor INTEGER, currency TEXT, payer_email TEXT, status TEXT DEFAULT 'created', authorization_url TEXT,
        initialization_token TEXT, initializing_until TEXT, last_provider_status TEXT, attention_reason TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE(fare_approval_id,environment));
        CREATE TABLE delivery_payment_receipts (id INTEGER PRIMARY KEY, attempt_id TEXT UNIQUE, delivery_id INTEGER, fare_approval_id INTEGER, client_id INTEGER,
        provider TEXT, environment TEXT, reference TEXT UNIQUE, provider_transaction_id TEXT, amount_minor INTEGER, currency TEXT, provider_paid_at TEXT,
        verified_at TEXT DEFAULT CURRENT_TIMESTAMP, evidence_hash TEXT, UNIQUE(provider,environment,provider_transaction_id));
        CREATE TABLE payment_webhook_events (id INTEGER PRIMARY KEY, event_hash TEXT UNIQUE, attempt_id TEXT, event_type TEXT, status TEXT DEFAULT 'pending',
        attempts INTEGER DEFAULT 0, reservation_token TEXT, reserved_until TEXT, available_at TEXT DEFAULT CURRENT_TIMESTAMP,
        last_error TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, processed_at TEXT)");
    foreach (['delivery_fare_approvals','delivery_payment_receipts'] as $table) foreach (['UPDATE','DELETE'] as $op) {
        $db->exec("CREATE TRIGGER {$table}_no_{$op} BEFORE {$op} ON {$table} BEGIN SELECT RAISE(ABORT, 'Immutable'); END");
    }
}
function paymentEnvironment(): void
{
    putenv('APP_ENV=testing'); putenv('PAYMENT_PROVIDER=paystack'); putenv('PAYSTACK_MODE=test');
    putenv('PAYSTACK_SECRET_KEY=sk_test_' . str_repeat('a', 40)); putenv('PAYMENT_RETURN_URL=http://localhost:3000/payment/return');
}
function paymentFixture(): PDO
{
    paymentEnvironment(); $db = bookingFixture(); paymentTables($db);
    $db->exec("ALTER TABLE deliveries ADD COLUMN pickup_time TEXT;
        ALTER TABLE deliveries ADD COLUMN delivery_person_id INTEGER;
        ALTER TABLE users ADD COLUMN email TEXT;
        UPDATE users SET email = 'client@example.test' WHERE id = 1");
    DeliveryPayments::transaction($db, static fn(PDO $db) => DeliveryBooking::apply($db, 1, 'payment-fixture-01', str_repeat('a', 64), 'bookingPlan'));
    $db->exec("UPDATE deliveries SET status = 'driver_en_route'");
    return $db;
}
final class TestPaymentGateway implements PaymentGateway
{
    public int $initializations = 0;
    public int $verifications = 0;
    public array $overrides = [];
    public bool $failInitialize = false;
    public bool $failVerify = false;
    public $duringInitialize = null;
    public function __construct(private PDO $db) {}
    public function mode(): string { return 'test'; }
    public function initialize(array $attempt): array
    {
        if ($this->db->inTransaction()) throw new RuntimeException('Network called with locks held.');
        $this->initializations++;
        if ($this->duringInitialize) ($this->duringInitialize)($attempt);
        if ($this->failInitialize) throw new PaymentGatewayException('Simulated timeout');
        return ['reference' => $attempt['reference'], 'authorization_url' => 'https://checkout.paystack.com/test-code'];
    }
    public function verify(string $reference): array
    {
        if ($this->db->inTransaction()) throw new RuntimeException('Network called with locks held.');
        $this->verifications++;
        if ($this->failVerify) throw new PaymentGatewayException('Simulated outage');
        $s = $this->db->prepare('SELECT * FROM delivery_payment_attempts WHERE reference = ?'); $s->execute([$reference]); $a = $s->fetch(PDO::FETCH_ASSOC);
        return array_replace(['reference' => $reference, 'domain' => 'test', 'status' => 'success', 'amount' => (int)$a['amount_minor'],
            'currency' => 'NGN', 'id' => (string)(10000 + $a['delivery_id']), 'paid_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'customer' => ['email' => $a['payer_email']], 'metadata' => PaystackGateway::metadata($a)], $this->overrides);
    }
}
function paymentStarted(PDO $db): array
{
    DeliveryPayments::approveFare($db, 1, 99, 0, '650.25', 'Verified route and parcel');
    $gateway = new TestPaymentGateway($db);
    $view = DeliveryPayments::initialize($db, 1, 1, 1, $gateway);
    return [$gateway, $view['attempt']['reference']];
}
