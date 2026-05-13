<?php

namespace Cybex\Protector\Tests\Feature;

use Cybex\Protector\Contracts\ProtectorConfiguratorContract;
use Cybex\Protector\ProtectorConfigurator;
use Cybex\Protector\Tests\TestCase;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportDumpTest extends TestCase
{
    protected Filesystem $disk;

    protected string $baseDirectory;
    protected string $filePath;
    protected string $emptyDumpPath;

    protected const string POSTGRES_CREATE = '--create';
    protected const string POSTGRES_CLEAN = '--clean';
    protected const string POSTGRES_VERBOSE = '--verbose';
    protected const string POSTGRES_SCHEMA_ONLY = '--schema-only';
    protected const string NO_TABLESPACES = '--no-tablespaces';
    protected const string MYSQL_SKIP_GTID_INFO = '--set-gtid-purged=OFF';
    protected const string MYSQL_NO_CREATE_DB = '--no-create-db';
    protected const string MYSQL_SKIP_COMMENTS = '--skip-comments';
    protected const string MYSQL_SKIP_SET_CHARSET = '--skip-set-charset';
    protected const string MYSQL_NO_DATA = '--no-data';
    protected const string PROTECTOR_WITH_CREATE_DB = 'withCreateDb';
    protected const string PROTECTOR_WITH_DROP_DB = 'withDropDb';
    protected const string PROTECTOR_WITH_COMMENTS = 'withComments';
    protected const string PROTECTOR_WITH_CHARSETS = 'withCharsets';
    protected const string PROTECTOR_WITH_DATA = 'withData';
    protected const string PROTECTOR_WITH_TABLESPACES = 'withTablespaces';
    protected const array PROTECTOR_CONFIG_BASELINE = [
        'pgsql' => [
            self::POSTGRES_CREATE => false,
            self::POSTGRES_CLEAN => false,
            self::POSTGRES_VERBOSE => false,
            self::POSTGRES_SCHEMA_ONLY => true,
            self::NO_TABLESPACES => true,
        ],
        'mysql' => [
            self::MYSQL_SKIP_GTID_INFO => true,
            self::MYSQL_NO_CREATE_DB => true,
            self::MYSQL_SKIP_COMMENTS => true,
            self::MYSQL_SKIP_SET_CHARSET => true,
            self::MYSQL_NO_DATA => true,
            self::NO_TABLESPACES => true,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->disk = $this->getFakeDumpDisk();

        $this->baseDirectory = Config::get('protector.dump.baseDirectory');
        $this->filePath = sprintf('%s/dump.sql', $this->baseDirectory);
        $this->emptyDumpPath = 'testDumps/dump.sql';
    }


    #[Test]
    public function failGeneratingDumpWhenTryingToConnectToDatabase(): void
    {
        // Provide a database connection to a non-existing database.
        Config::set('database.connections.invalid', [
            'driver' => 'mysql',
            'url' => env('DATABASE_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => 'invalid_database_name',
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
        ]);

        // Configure protector to the invalid database connection.
        $this->protector = app(ProtectorConfiguratorContract::class)->setConnectionName('invalid')->withoutData()->makeProtector();

        // Expect an exception when trying to connect and determine if the connected database is a MariaDB database.
        $this->expectException(PDOException::class);
        $this->runProtectedMethod('generateDump');
    }

    #[Test]
    public function createsStreamedFileDownloadResponse(): void
    {
        Config::set('protector.server.routeMiddleware', []);

        $response = $this->protector->generateFileDownloadResponse(new Request());

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    #[Test]
    #[DataProvider('provideForHasCorrectConfiguration')]
    public function hasCorrectConfiguration(array $protectorOptions, array $expected): void
    {
        $this->configureProtector($protectorOptions);

        $config = $this->runProtectedMethod('getConfig');

        $connection = DB::connection($config->getConnectionName());
        $schemaStateProxy = $config->getProxyForSchemaState();

        $conditionalParameters = $schemaStateProxy->getConditionalParameters();

        $this->assertEquals($expected[$connection->getDriverName()], $conditionalParameters);
    }

    public static function provideForHasCorrectConfiguration(): array
    {
        return [
            'all options on' => [
                'protectorOptions' => [
                    self::PROTECTOR_WITH_CREATE_DB,
                    self::PROTECTOR_WITH_DROP_DB,
                    self::PROTECTOR_WITH_COMMENTS,
                    self::PROTECTOR_WITH_CHARSETS,
                    self::PROTECTOR_WITH_DATA,
                    self::PROTECTOR_WITH_TABLESPACES,
                ],
                'expected' => self::getExpected([
                    'pgsql' => [
                        self::POSTGRES_CREATE => true,
                        self::POSTGRES_CLEAN => true,
                        self::POSTGRES_VERBOSE => true,
                        self::POSTGRES_SCHEMA_ONLY => false,
                        self::NO_TABLESPACES => false,
                    ],
                    'mysql' => [
                        self::MYSQL_SKIP_GTID_INFO => true,
                        self::MYSQL_NO_CREATE_DB => false,
                        self::MYSQL_SKIP_COMMENTS => false,
                        self::MYSQL_SKIP_SET_CHARSET => false,
                        self::MYSQL_NO_DATA => false,
                        self::NO_TABLESPACES => false,
                    ]
                ])
            ],
            'all options off' => [
                'protectorOptions' => [],
                'expected' => self::getExpected()
            ],
            'postgres purge db' => [
                'protectorOptions' => [
                    self::PROTECTOR_WITH_CREATE_DB,
                    self::PROTECTOR_WITH_DROP_DB
                ],
                'expected' => self::getExpected([
                    'pgsql' => [
                        self::POSTGRES_CREATE => true,
                        self::POSTGRES_CLEAN => true,
                    ],
                    'mysql' => [
                        self::MYSQL_NO_CREATE_DB => false,
                    ],
                ])
            ],
            'create db' => [
                'protectorOptions' => [
                    self::PROTECTOR_WITH_CREATE_DB
                ],
                'expected' => self::getExpected([
                    'pgsql' => [
                        self::POSTGRES_CREATE => true,
                    ],
                    'mysql' => [
                        self::MYSQL_NO_CREATE_DB => false,
                    ],
                ])
            ],
            'drop db' => [
                'protectorOptions' => [
                    self::PROTECTOR_WITH_DROP_DB
                ],
                'expected' => self::getExpected()
            ],
            'dump comments' => [
                'protectorOptions' => [
                    self::PROTECTOR_WITH_COMMENTS
                ],
                'expected' => self::getExpected([
                    'pgsql' => [
                        self::POSTGRES_VERBOSE => true,
                    ],
                    'mysql' => [
                        self::MYSQL_SKIP_COMMENTS => false,
                    ],
                ])
            ],
            'dump charsets' => [
                'protectorOptions' => [
                    self::PROTECTOR_WITH_CHARSETS
                ],
                'expected' => self::getExpected([
                    'mysql' => [
                        self::MYSQL_SKIP_SET_CHARSET => false,
                    ],
                ])
            ],
            'dump data' => [
                'protectorOptions' => [
                    self::PROTECTOR_WITH_DATA
                ],
                'expected' => self::getExpected([
                    'pgsql' => [
                        self::POSTGRES_SCHEMA_ONLY => false,
                    ],
                    'mysql' => [
                        self::MYSQL_NO_DATA => false,
                    ],
                ])
            ],
            'dump tablespaces' => [
                'protectorOptions' => [
                    self::PROTECTOR_WITH_TABLESPACES
                ],
                'expected' => self::getExpected([
                    'pgsql' => [
                        self::NO_TABLESPACES => false,
                    ],
                    'mysql' => [
                        self::NO_TABLESPACES => false,
                    ],
                ])
            ],
        ];
    }

    /**
     * Merges the baseline array (all protector config options set to 'without') with the provided deviations.
     *
     * @param array $deviations
     * @return array
     */
    protected static function getExpected(array $deviations = []): array
    {
        return array_replace_recursive(self::PROTECTOR_CONFIG_BASELINE, $deviations);
    }

    /**
     * Configures the protector with the provided options.
     *
     * @param array $protectorOptions
     * @return void
     */
    protected function configureProtector(array $protectorOptions): void
    {
        $configurator = app(ProtectorConfigurator::class)
            ->withoutCreateDb()
            ->withoutDropDb()
            ->withoutComments()
            ->withoutCharsets()
            ->withoutData()
            ->withoutTablespaces();

        foreach ($protectorOptions as $option) {
            $configurator->$option();
        }

        $this->protector = $configurator->makeProtector();
    }
}
