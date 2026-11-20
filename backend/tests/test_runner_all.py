import glob, subprocess, os, sys

def run_tests():
    print("="*60)
    print("RUNNING COMPLETE AUTOMATED TEST SUITE AUDIT")
    print("="*60)

    # 1. Setup MySQL disposable test database
    try:
        subprocess.run(['C:/xampp/mysql/bin/mysql.exe', '-u', 'root', '-e', 
            'DROP DATABASE IF EXISTS til_booking_test; CREATE DATABASE til_booking_test CHARACTER SET utf8mb4;'
            'DROP DATABASE IF EXISTS til_payment_test; CREATE DATABASE til_payment_test CHARACTER SET utf8mb4;'
            'DROP DATABASE IF EXISTS til_queue_test; CREATE DATABASE til_queue_test CHARACTER SET utf8mb4;'
        ], check=True, capture_output=True)
        mysql_available = True
    except Exception as e:
        print("Notice: MySQL disposable DB setup error:", e)
        mysql_available = False

    env = os.environ.copy()
    env['QUEUE_TEST_REDIS_PORT'] = '16379'
    env['BOOKING_TEST_MYSQL_DSN'] = 'mysql:host=127.0.0.1;port=3306;dbname=til_booking_test;charset=utf8mb4'
    env['BOOKING_TEST_MYSQL_USER'] = 'root'
    env['BOOKING_TEST_MYSQL_PASSWORD'] = ''
    env['PAYMENT_TEST_MYSQL_DSN'] = 'mysql:host=127.0.0.1;port=3306;dbname=til_payment_test;charset=utf8mb4'
    env['PAYMENT_TEST_MYSQL_USER'] = 'root'
    env['PAYMENT_TEST_MYSQL_PASSWORD'] = ''
    env['QUEUE_TEST_MYSQL_DSN'] = 'mysql:host=127.0.0.1;port=3306;dbname=til_queue_test;charset=utf8mb4'
    env['QUEUE_TEST_MYSQL_USER'] = 'root'
    env['QUEUE_TEST_MYSQL_PASSWORD'] = ''

    test_files = sorted(glob.glob('backend/tests/*.php'))
    passed = 0
    failed = 0
    results = []

    for f in test_files:
        res = subprocess.run(['php', f], env=env, capture_output=True, text=True)
        if res.returncode == 0:
            passed += 1
            results.append((f, 'PASS', res.stdout.strip()))
        else:
            failed += 1
            err_msg = res.stderr.strip() or res.stdout.strip()
            results.append((f, 'FAIL', err_msg))

    print(f"\nBackend Test Results: {passed} PASSED / {failed} FAILED out of {len(test_files)}")
    for f, status, msg in results:
        status_str = "[PASS]" if status == "PASS" else "[FAIL]"
        print(f" {status_str} {os.path.basename(f)}")
        if status == 'FAIL':
            print(f"       Reason: {msg.splitlines()[0] if msg else 'Unknown error'}")

    # Cleanup
    if mysql_available:
        subprocess.run(['C:/xampp/mysql/bin/mysql.exe', '-u', 'root', '-e', 
            'DROP DATABASE IF EXISTS til_booking_test;'
            'DROP DATABASE IF EXISTS til_payment_test;'
            'DROP DATABASE IF EXISTS til_queue_test;'
        ], capture_output=True)

if __name__ == '__main__':
    run_tests()
