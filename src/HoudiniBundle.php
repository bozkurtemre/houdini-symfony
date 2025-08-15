<?php

declare(strict_types=1);

namespace Houdini\HoudiniBundle;

use Houdini\HoudiniBundle\DependencyInjection\HoudiniExtension;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class HoudiniBundle extends Bundle
{
    public function getContainerExtension(): HoudiniExtension
    {
        if (null === $this->extension) {
            $this->extension = new HoudiniExtension();
        }

        return $this->extension;
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
