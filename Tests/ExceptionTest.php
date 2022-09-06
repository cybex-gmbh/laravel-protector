<?php

namespace Cybex\Protector\Tests;

use BaseTest;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class ExceptionTest extends BaseTest
{
    /**
     * @test
     */
    public function ensureExternalExceptionsWork()
    {
        try {
            throw new HttpException(200);
        } catch (HttpException) {
        }

        try {
            throw new UnauthorizedHttpException('');
        } catch (UnauthorizedHttpException) {
        }

        try {
            throw new NotFoundHttpException();
        } catch (NotFoundHttpException) {
        }

        try {
            throw new LogicException();
        } catch (LogicException) {
        }

        $this->expectNotToPerformAssertions();
    }
}
