<?php declare(strict_types=1);

namespace UnzerPayment6\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class RegisterBundlePathCompilerPass implements CompilerPassInterface
{
    private string $pluginPath;

    public function __construct(string $pluginPath)
    {
        $this->pluginPath = $pluginPath;
    }

    public function process(ContainerBuilder $container): void
    {
        // register the plugin path for the @UnzerPayment6 alias
        $container->setParameter('UnzerPayment6.plugin_directory', $this->pluginPath);
    }
}