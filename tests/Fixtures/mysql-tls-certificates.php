<?php

if (getenv('APP_ENV') !== 'testing' || getenv('MYSQL_TLS_INTEGRATION') !== '1') {
    throw new RuntimeException('Solo se permite generar certificados desechables del perfil TLS aislado.');
}
$directory = '/tls-certs';
foreach (['ca.pem', 'wrong-ca.pem', 'server.pem', 'server.key', 'client.cnf'] as $name) {
    if (file_exists($directory.'/'.$name)) {
        throw new RuntimeException('Ya existen certificados; no sobrescribir artefactos sin revisión.');
    }
}
$config = <<<'CONFIG'
[req]
distinguished_name = dn
[dn]
[ca]
basicConstraints = critical,CA:TRUE
keyUsage = critical,keyCertSign,cRLSign
[server]
basicConstraints = critical,CA:FALSE
keyUsage = critical,digitalSignature,keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName = DNS:test-mysql-tls
CONFIG;
$configPath = $directory.'/openssl.cnf';
if (file_put_contents($configPath, $config) !== strlen($config)) {
    throw new RuntimeException('No se pudo escribir la configuración desechable de certificados.');
}
$keyOptions = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
$signingOptions = ['config' => $configPath, 'digest_alg' => 'sha256'];
$caKey = openssl_pkey_new($keyOptions);
$caRequest = openssl_csr_new(['commonName' => 'Comercios RD disposable TLS test CA'], $caKey, ['digest_alg' => 'sha256']);
$ca = openssl_csr_sign($caRequest, null, $caKey, 7, $signingOptions + ['x509_extensions' => 'ca']);
$wrongKey = openssl_pkey_new($keyOptions);
$wrongRequest = openssl_csr_new(['commonName' => 'Untrusted disposable test CA'], $wrongKey, ['digest_alg' => 'sha256']);
$wrongCa = openssl_csr_sign($wrongRequest, null, $wrongKey, 7, $signingOptions + ['x509_extensions' => 'ca']);
$serverKey = openssl_pkey_new($keyOptions);
$serverRequest = openssl_csr_new(['commonName' => 'test-mysql-tls'], $serverKey, ['digest_alg' => 'sha256']);
$server = openssl_csr_sign($serverRequest, $ca, $caKey, 7, $signingOptions + ['x509_extensions' => 'server'], 2);
foreach ([$ca, $wrongCa, $server] as $certificate) {
    if (! $certificate || (openssl_x509_parse($certificate)['signatureTypeSN'] ?? '') !== 'RSA-SHA256') {
        throw new RuntimeException('La emisión debe producir certificados RSA-SHA256 válidos.');
    }
}
$caPublicKey = openssl_pkey_get_public($ca);
if (openssl_x509_verify($ca, $caPublicKey) !== 1
    || openssl_x509_verify($wrongCa, openssl_pkey_get_public($wrongCa)) !== 1
    || openssl_x509_verify($server, $caPublicKey) !== 1
    || ! openssl_x509_check_private_key($server, $serverKey)) {
    throw new RuntimeException('Firmas o correspondencia de clave inválidas; no se publican certificados.');
}
foreach (['ca.pem' => $ca, 'wrong-ca.pem' => $wrongCa, 'server.pem' => $server] as $name => $certificate) {
    if (! $certificate || ! openssl_x509_export_to_file($certificate, $directory.'/'.$name)) {
        throw new RuntimeException('No se pudo emitir el certificado desechable.');
    }
}
if (! openssl_pkey_export_to_file($serverKey, $directory.'/server.key')) {
    throw new RuntimeException('No se pudo escribir la clave desechable.');
}
// Solo contiene una clave de servidor de pruebas, sin acceso a recursos reales.
if (! chmod($directory.'/server.key', 0644)) {
    throw new RuntimeException('No se pudieron establecer permisos de la clave desechable.');
}
$password = (string) getenv('DB_TEST_PASSWORD');
if ($password === '' || strpbrk($password, "\r\n\0") !== false) {
    throw new RuntimeException('Falta la contraseña del usuario aislado.');
}
$quoted = str_replace(['\\', '"'], ['\\\\', '\"'], $password);
$contents = "[client]\nuser=comercios_storage_test\npassword=\"{$quoted}\"\n";
$mask = umask(0077);
try {
    if (file_put_contents($directory.'/client.cnf', $contents) !== strlen($contents)) {
        throw new RuntimeException('No se pudo escribir el archivo privado del cliente de pruebas.');
    }
} finally {
    umask($mask);
}
fwrite(STDOUT, "TLS_TEST_CERTIFICATES_CREATED\n");
