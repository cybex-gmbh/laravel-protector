<?php

namespace Cybex\Protector\Enums;

enum ProtectorEnv: string
{
    case AUTH_TOKEN = 'PROTECTOR_CLIENT_AUTH_TOKEN';
    case BASE_DIRECTORY = 'PROTECTOR_DUMP_BASE_DIRECTORY';
    case BASIC_AUTH = 'PROTECTOR_CLIENT_BASIC_AUTH_CREDENTIALS';
    case CHUNK_SIZE = 'PROTECTOR_SERVER_CHUNK_SIZE';
    case DISK_NAME = 'PROTECTOR_DUMP_DISK_NAME';
    case DUMP_ENDPOINT_ROUTE = 'PROTECTOR_SERVER_DUMP_ENDPOINT_ROUTE';
    case DUMP_ENDPOINT_URL = 'PROTECTOR_CLIENT_DUMP_ENDPOINT_URL';
    case HTTP_TIMEOUT = 'PROTECTOR_CLIENT_HTTP_TIMEOUT';
    case MAX_PACKET_LENGTH = 'PROTECTOR_DUMP_MAX_PACKET_LENGTH';
    case METADATA = 'PROTECTOR_DUMP_METADATA';
    case METADATA_JSON_FILE_PATH = 'PROTECTOR_DUMP_METADATA_JSON_FILE_PATH';
    case PRIVATE_KEY = 'PROTECTOR_CLIENT_PRIVATE_KEY';

    public function key(): string
    {
        return $this->value;
    }

    /**
     * May only be used inside config files.
     *
     * @param string|int|float|bool|null $default
     * @return string|int|float|bool|null
     */
    public function get(string|int|float|bool|null $default = null): string|int|float|bool|null
    {
        return env($this->value, $default);
    }
}
