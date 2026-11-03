#!/bin/sh
set -eu

for file in ca.pem server.pem server.key client.cnf; do
    if [ ! -r "/tls-certs/$file" ] || [ ! -s "/tls-certs/$file" ]; then
        printf '%s\n' "Missing readable TLS fixture: $file; refusing to start." >&2
        exit 1
    fi
done

exec /usr/local/bin/docker-entrypoint.sh "$@"
