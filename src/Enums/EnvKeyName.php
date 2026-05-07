<?php

namespace Cybex\Protector\Enums;

enum EnvKeyName: string
{
    case AUTH_TOKEN = 'PROTECTOR_CLIENT_AUTH_TOKEN';
    case DUMP_ENDPOINT_URL = 'PROTECTOR_CLIENT_DUMP_ENDPOINT_URL';
    case PRIVATE_KEY = 'PROTECTOR_CLIENT_PRIVATE_KEY';
}
