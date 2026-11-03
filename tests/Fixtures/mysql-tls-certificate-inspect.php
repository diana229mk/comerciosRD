<?php

if (getenv('APP_ENV') !== 'testing' || getenv('MYSQL_TLS_INTEGRATION') !== '1') {
    throw new RuntimeException('Este diagnóstico requiere el perfil TLS aislado.');
}

$ca = openssl_x509_read(file_get_contents('/tls-certs/ca.pem'));
$server = openssl_x509_read(file_get_contents('/tls-certs/server.pem'));
$key = openssl_pkey_get_private(file_get_contents('/tls-certs/server.key'));
if (! $ca || ! $server || ! $key) {
    throw new RuntimeException('No se pudieron leer los artefactos TLS de pruebas.');
}

$caInfo = openssl_x509_parse($ca);
$serverInfo = openssl_x509_parse($server);
$caPublicKey = openssl_pkey_get_public($ca);
$output = [
    'ca_sha256' => hash_file('sha256', '/tls-certs/ca.pem'),
    'server_sha256' => hash_file('sha256', '/tls-certs/server.pem'),
    'ca_subject_cn' => $caInfo['subject']['CN'] ?? null,
    'server_subject_cn' => $serverInfo['subject']['CN'] ?? null,
    'server_issuer_cn' => $serverInfo['issuer']['CN'] ?? null,
    'ca_signature_algorithm' => $caInfo['signatureTypeSN'] ?? null,
    'server_signature_algorithm' => $serverInfo['signatureTypeSN'] ?? null,
    'server_san' => $serverInfo['extensions']['subjectAltName'] ?? null,
    'server_valid_from' => gmdate('c', $serverInfo['validFrom_time_t']),
    'server_valid_until' => gmdate('c', $serverInfo['validTo_time_t']),
    'ca_self_signature' => openssl_x509_verify($ca, $caPublicKey),
    'server_ca_signature' => openssl_x509_verify($server, $caPublicKey),
    'server_key_matches_certificate' => openssl_x509_check_private_key($server, $key),
    'server_chain_and_purpose_valid' => openssl_x509_checkpurpose(
        $server, X509_PURPOSE_SSL_SERVER, ['/tls-certs/ca.pem'],
    ),
];
$errors = [];
while (($error = openssl_error_string()) !== false) {
    $errors[] = $error;
}
$output['openssl_errors'] = $errors;
fwrite(STDOUT, json_encode($output, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
