<?php

namespace Tests\Unit;

use App\Services\MysqlTlsPolicy;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MysqlTlsPolicyTest extends TestCase
{
    private $previousApplication;

    private Container $previousContainer;

    private string $ca;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApplication = Facade::getFacadeApplication();
        $this->previousContainer = Container::getInstance();
        $app = new Container;
        $app->instance('config', new Repository(['app' => ['env' => 'testing']]));
        Facade::setFacadeApplication($app);
        Container::setInstance($app);
        $this->ca = tempnam(sys_get_temp_dir(), 'tls-test-ca-');
        file_put_contents($this->ca, 'policy test only; not a certificate');
    }

    protected function tearDown(): void
    {
        unlink($this->ca);
        Facade::setFacadeApplication($this->previousApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_production_tcp_refuses_a_missing_ca_even_when_the_flag_is_false(): void
    {
        Facade::getFacadeApplication()['config']->set('app.env', 'production');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CA legible');
        MysqlTlsPolicy::pdoOptions(['host' => 'mysql.example.test', 'ssl_required' => false]);
    }

    public function test_local_explicit_socket_without_tls_remains_supported(): void
    {
        $this->assertSame([], MysqlTlsPolicy::pdoOptions([
            'host' => 'localhost', 'unix_socket' => '/run/mysqld/mysqld.sock',
        ]));
    }

    #[DataProvider('invalidEndpoints')]
    public function test_required_tls_refuses_socket_fallbacks(array $endpoint): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TCP explícito');
        MysqlTlsPolicy::pdoOptions($endpoint + ['ssl_required' => true, 'ssl_ca' => $this->ca]);
    }

    public static function invalidEndpoints(): array
    {
        return [
            [['host' => 'localhost']],
            [['host' => 'mysql.example.test', 'socket' => '/run/mysqld/mysqld.sock']],
        ];
    }

    public function test_a_configured_ca_enforces_verification_even_if_false_was_requested(): void
    {
        $options = MysqlTlsPolicy::pdoOptions([
            'host' => 'mysql.example.test',
            'options' => [PDO::MYSQL_ATTR_SSL_CA => $this->ca, PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false],
        ]);
        $this->assertTrue($options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]);
    }

    public function test_client_credentials_cannot_inject_an_option_line(): void
    {
        $this->expectException(RuntimeException::class);
        MysqlTlsPolicy::quote("host\nssl-mode=DISABLED");
    }
}
