#!/bin/sh
set -eu

mysql --defaults-file=/tls-certs/client.cnf --no-login-paths --connect-timeout=5 \
    --protocol=TCP --host=test-mysql-tls \
    --ssl-mode=VERIFY_IDENTITY --ssl-ca=/tls-certs/ca.pem \
    --batch --skip-column-names \
    -e "SHOW SESSION STATUS LIKE 'Ssl_cipher'" |
    grep -Eq '^Ssl_cipher[[:space:]]+[^[:space:]]+'
printf '%s\n' 'PASS: Oracle MySQL verified TLS'

for failure in wrong-ca wrong-host no-tls; do
    host=test-mysql-tls
    ca=/tls-certs/ca.pem
    case "$failure" in
        wrong-ca) ca=/tls-certs/wrong-ca.pem ;;
        wrong-host) host=wrong-mysql-tls ;;
        no-tls) host=test-mysql-no-tls ;;
    esac
    if mysql --defaults-file=/tls-certs/client.cnf --no-login-paths --connect-timeout=5 \
        --protocol=TCP --host="$host" \
        --ssl-mode=VERIFY_IDENTITY --ssl-ca="$ca" \
        -e 'SELECT 1' >/dev/null 2>&1; then
        printf '%s\n' "FAIL: accepted $failure"
        exit 1
    fi
    printf '%s\n' "PASS: rejected $failure"
done
