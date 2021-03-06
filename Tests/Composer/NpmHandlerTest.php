<?php

/*
 * This file is part of the NpmHandler package.
 *
 * (c) Benjamin Lazarecki <benjamin.lazarecki@gmail.com>
 *
 * For the full copyright and license information, please read the LICENSE
 * file that was distributed with this source code.
 */

namespace Scar\NpmHandler\Tests\Composer;

use Scar\NpmHandler\Composer\NpmHandler;

/**
 * Test the npm handler.
 *
 * @author Benjamin Lazarecki <benjamin.lazarecki@gmail.com>
 */
class NpmHandlerTest extends \PHPUnit_Framework_TestCase
{
    /** @var \Composer\Script\Event */
    private $event;

    /** @var \Composer\IO\IOInterface */
    private $inOut;

    /** @var string */
    private $output;

    /**
     * {@inheritdoc}
     */
    protected function setUp()
    {
        $this->output = '';
        $output = &$this->output;

        $this->inOut = $this->getMock('Composer\IO\IOInterface');
        $this->inOut
            ->expects($this->any())
            ->method('write')
            ->will($this->returnCallback(function ($message, $newLine) use (&$output) {
                $output .= $message;

                if ($newLine) {
                    $output .= PHP_EOL;
                }
            }));

        $this->event = $this->getMockBuilder('Composer\Script\Event')
            ->disableOriginalConstructor()
            ->getMock();

        $this->event
            ->expects($this->any())
            ->method('getIO')
            ->will($this->returnValue($this->inOut));
    }

    /**
     * Sets up the composer extra configuration.
     *
     * @param array $extra The composer extra configuration.
     */
    protected function setUpExtra(array $extra)
    {
        $package = $this->getMock('Composer\Package\RootPackageInterface');
        $package
            ->expects($this->once())
            ->method('getExtra')
            ->will($this->returnValue($extra));

        $composer = $this->getMock('Composer\Composer');
        $composer
            ->expects($this->once())
            ->method('getPackage')
            ->will($this->returnValue($package));

        $this->event
            ->expects($this->once())
            ->method('getComposer')
            ->will($this->returnValue($composer));
    }

    /**
     * Sets up the IO verbosity.
     *
     * @param boolean $verbose TRUE if the IO is verbose else FALSE.
     */
    protected function setUpVerbosity($verbose = false)
    {
        $this->inOut
            ->expects($this->once())
            ->method('isVerbose')
            ->will($this->returnValue($verbose));
    }

    /**
     * Sets up the Dev mode.
     *
     * @param boolean $devMode TRUE if the event is in dev mode, else FALSE.
     */
    protected function setUpDevMode($devMode = false)
    {
        $this->event
            ->expects($this->once())
            ->method('isDevMode')
            ->will($this->returnValue($devMode));
    }

    /**
     * {@inheritdoc}
     */
    protected function tearDown()
    {
        unset($this->event);
        unset($this->inOut);
        unset($this->output);
    }

    /**
     * Unlinks a directory.
     *
     * @param string $path The directory path to unlink.
     */
    protected function unlink($path)
    {
        if (($resources = @scandir($path)) === false) {
            return;
        }

        foreach ($resources as $resource) {
            if (($resource === '.') || ($resource === '..')) {
                continue;
            }

            $resourcePath = sprintf('%s%s%s', $path, DIRECTORY_SEPARATOR, $resource);

            if (is_file($resourcePath)) {
                unlink($resourcePath);
            } else {
                $this->unlink($resourcePath);
            }
        }

        rmdir($path);
    }

    /**
     * Installs the bower dependencies.
     *
     * @param string  $expectedPattern  The expected output pattern.
     * @param array   $extra            The composer extra configuration.
     * @param array   $npmPackagesPaths The npm paths.
     * @param boolean $ensureFileExist  TRUE if ensure file exist is required.
     * @param boolean $verbose          TRUE if the output is verbose else FALSE.
     * @param boolean $devMode          TRUE if the event is in dev mode else FALSE.
     */
    protected function install(
        $expectedPattern,
        array $extra = array(),
        array $npmPackagesPaths = array(),
        $ensureFileExist = true,
        $verbose = false,
        $devMode = false
    ) {
        if (empty($npmPackagesPaths)) {
            $npmPackagesPaths = array(
                __DIR__.'/Fixtures/node_modules',
                __DIR__.'/Fixtures/subdir/node_modules',
            );
        }

        foreach ($npmPackagesPaths as $npmPackagePath) {
            $this->unlink($npmPackagePath);
        }

        chdir(__DIR__.'/Fixtures');

        $this->setUpExtra($extra);
        $this->setUpVerbosity($verbose);
        $this->setUpDevMode($devMode);

        NpmHandler::install($this->event);

        foreach ($npmPackagesPaths as $npmPackagePath) {
            $this->assertSame($ensureFileExist, file_exists($npmPackagePath));
            $this->assertSame($ensureFileExist, file_exists($npmPackagePath.'/less'));
            $this->assertSame($ensureFileExist && $devMode, file_exists($npmPackagePath.'/phantomjs'));
        }

        $this->assertRegExp($expectedPattern, $this->output);
    }

    public function testInstall()
    {
        $expectedPattern = <<<EOF
#^<info>NPM Components</info>
- Installing <comment>package.json</comment>
- Installing <comment>subdir/package.json</comment>$#
EOF;

        $this->install($expectedPattern);
    }

    public function testInstallWithExcludes()
    {
        $excludes = array('subdir');

        foreach ($excludes as $exclude) {
            $this->unlink(__DIR__.'/Fixtures/'.$exclude.'/node_modules');
        }

        $extra = array('npm-handler' => array('exclude-packages' => $excludes));

        $expectedPattern = <<<EOF
#^<info>NPM Components</info>
- Installing <comment>package.json</comment>$#
EOF;

        $this->install($expectedPattern, $extra, array(__DIR__.'/Fixtures/node_modules'));

        foreach ($excludes as $exclude) {
            $this->assertFileNotExists(__DIR__.'/Fixtures/'.$exclude.'/bower_components');
        }
    }

    public function testInvalidAbsoluteNpmExecutablePath()
    {
        $expectedPattern = <<<EOF
#^<info>NPM Components</info>
<error>/foo/npm Not Found</error>$#
EOF;
        $extra = array('npm-handler' => array('npm-path' => '/foo/npm'));

        $this->install($expectedPattern, $extra, array(), false);
    }

    public function testInvalidRelativeNpmExecutablePath()
    {
        $expectedPattern = <<<EOF
#^<info>NPM Components</info>
<error>foo/npm Not Found \(Root path : (.)*/NpmHandler/Tests/Composer/Fixtures\)</error>$#
EOF;
        $extra = array('npm-handler' => array('npm-path' => 'foo/npm'));

        $this->install($expectedPattern, $extra, array(), false);
    }

    public function testInstallWithVerbosity()
    {
        $expectedPattern = <<<EOF
#^<info>NPM Components</info>
- Installing <comment>package.json</comment>
(.|\n)+
- Installing <comment>subdir/package.json</comment>
(.|\n)+$#
EOF;

        $this->install($expectedPattern, array(), array(), true, true);
    }

    public function testInstallWithDevMode()
    {
        $expectedPattern = <<<EOF
#^<info>NPM Components</info>
- Installing <comment>package.json</comment>
- Installing <comment>subdir/package.json</comment>$#
EOF;

        $this->install($expectedPattern, array(), array(), true, false, true);
    }
}
