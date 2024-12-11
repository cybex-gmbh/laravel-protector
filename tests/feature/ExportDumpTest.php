<?php

namespace Cybex\Protector\Tests\feature;

use Cybex\Protector\Tests\TestCase;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PDOException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportDumpTest extends TestCase
{
    protected Filesystem $disk;

    protected string $baseDirectory;
    protected string $filePath;
    protected string $emptyDumpPath;

    const POSTGRES_CREATE = '--create';
    const POSTGRES_CLEAN = '--clean';
    const POSTGRES_VERBOSE = '--verbose';
    const POSTGRES_SCHEMA_ONLY = '--schema-only';
    const NO_TABLESPACES = '--no-tablespaces';
    const MYSQL_SKIP_GTID_INFO = '--set-gtid-purged=OFF';
    const MYSQL_NO_CREATE_DB = '--no-create-db';
    const MYSQL_SKIP_COMMENTS = '--skip-comments';
    const MYSQL_SKIP_SET_CHARSET = '--skip-set-charset';
    const MYSQL_NO_DATA = '--no-data';
    const PROTECTOR_WITH_CREATE_DB = 'withCreateDb';
    const PROTECTOR_WITH_DROP_DB = 'withDropDb';
    const PROTECTOR_WITH_COMMENTS = 'withComments';
    const PROTECTOR_WITH_CHARSETS = 'withCharsets';
    const PROTECTOR_WITH_DATA = 'withData';
    const PROTECTOR_WITH_TABLESPACES = 'withTablespaces';
    const PROTECTOR_CONFIG_BASELINE = [
        'pgsql' => [
            self::POSTGRES_CREATE => false,
            self::POSTGRES_CLEAN => false,
            self::POSTGRES_VERBOSE => false,
            self::POSTGRES_SCHEMA_ONLY => false,
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

        $this->baseDirectory = Config::get('protector.baseDirectory');
        $this->filePath = sprintf('%s/dump.sql', $this->baseDirectory);
        $this->emptyDumpPath = 'testDumps/dump.sql';
    }

    /**
     * @test
     */
    public function createDestinationFilePath()
    {
        $this->disk->deleteDirectory(Config::get('protector.baseDirectory'));

        $filePath = $this->protector->createDestinationFilePath(__FUNCTION__);
        $destinationFilePath = $this->disk->path($filePath);

        $this->runProtectedMethod('createDirectory', [$filePath, $this->disk]);
        $this->assertDirectoryExists($destinationFilePath);
    }

    /**
     * @test
     */
    public function createDestinationFilePathWithSubFolder()
    {
        $this->disk->deleteDirectory(Config::get('protector.baseDirectory'));

        $filePath = $this->protector->createDestinationFilePath(__FUNCTION__, __FUNCTION__);
        $destinationFilePath = $this->disk->path($filePath);

        $this->runProtectedMethod('createDirectory', [$filePath, $this->disk]);
        $this->assertDirectoryExists($destinationFilePath);
    }

    /**
     * @test
     */
    public function canCreateDumpMetaData()
    {
        $dumpDate = now();

        Carbon::setTestNow($dumpDate);

        $metaData = $this->protector->getMetaData();

        $this->assertIsArray($metaData);
        $this->assertEquals($dumpDate, $metaData['dumpedAtDate']);
    }

    /**
     * @test
     */
    public function canCreateDumpMetaDataUsingCache()
    {
        $metaData = $this->protector->getMetaData();

        $metaData['hello'] = 'world';

        $this->setProtectedProperty('metaDataCache', $metaData);

        $cachedMetaData = $this->protector->getMetaData();

        $this->assertEquals($metaData, $cachedMetaData);

        $newMetaData = $this->protector->getMetaData(refresh: true);

        $this->assertNotEquals($metaData, $newMetaData);
    }

    /**
     * @test
     */
    public function failGeneratingDumpWhenTryingToConnectToDatabase()
    {
        // Provide an database connection to a non-existing database.
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
        $this->protector->withConnectionName('invalid');

        // Expect an exception when trying to connect and determine if the connected database is a MariaDB database.
        $this->expectException(PDOException::class);
        $this->runProtectedMethod('generateDump', [['no-data' => true]]);
    }

    /**
     * @test
     */
    public function createsStreamedFileDownloadResponse()
    {
        Config::set('protector.routeMiddleware', []);

        $response = $this->protector->generateFileDownloadResponse(new Request(), null);

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * @test
     * @dataProvider provideForHasCorrectConfiguration
     */
    public function hasCorrectConfiguration(array $protectorOptions, array $expected): void
    {
        $this->configureProtector($protectorOptions);

        $connection = DB::connection($this->protector->getConnectionName());
        $schemaState = $connection->getSchemaState();
        $schemaStateProxy = $this->runProtectedMethod('getProxyForSchemaState', [$schemaState]);

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
                        self::POSTGRES_SCHEMA_ONLY => true,
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
        $this->protector
            ->withoutCreateDb()
            ->withoutDropDb()
            ->withoutComments()
            ->withoutCharsets()
            ->withoutData()
            ->withoutTablespaces();

        foreach ($protectorOptions as $option) {
            $this->protector->$option();
        }
    }
}
