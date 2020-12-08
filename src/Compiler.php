<?php

declare(strict_types=1);

namespace TimurFlush\PhinxToPhar;

use Symfony\Component\Process\Process;
use Phar;
use Symfony\Component\Finder\Finder;

class Compiler
{
    protected $repository = 'https://github.com/cakephp/phinx.git';

    protected $version = 'v0.11.0';

    protected $buildDir;

    protected $distDir;

    /**
     * Compiler constructor.
     * @param string $phinxVersion
     * @param string $buildDirectory
     * @param string $distDirectory
     */
    public function __construct(string $phinxVersion, string $buildDirectory, string $distDirectory)
    {
        $this->version = $phinxVersion;
        $this->buildDir = $buildDirectory;
        $this->distDir = $distDirectory;
    }

    /**
     * @param string $pharFile
     * @throws Exception
     */
    public function compile(string $pharFile = 'phinx.phar')
    {
        $this->clearDist();
        $this->clearBuild();

        $this->cloneRepository();
        $this->switchVersion();
        $this->initComposer();
        $this->makePhar($pharFile);

        $this->clearBuild();
    }

    /**
     * @throws Exception
     */
    protected function cloneRepository()
    {
        $this->writeln('Cloning the Phinx repository...');

        $clone = new Process(
            [
                'git', 'clone', $this->repository, '.'
            ],
            $this->buildDir
        );

        if ($clone->run() !== 0) {
            throw new Exception($clone->getErrorOutput());
        }

        $this->writeln('Cloning has been completed.');
    }

    /**
     * @throws Exception
     */
    protected function switchVersion()
    {
        $checkout = new Process(
            [
                'git', 'checkout', 'tags/' . $this->version
            ],
            $this->buildDir
        );

        if ($checkout->run() !== 0) {
            throw new Exception($checkout->getErrorOutput());
        }

        $this->writeln('Switched to ' . $this->version);
    }

    /**
     * @throws Exception
     */
    protected function initComposer()
    {
        $this->writeln('Installation of Phinx dependencies...');

        $proc = new Process(['composer', 'update'], $this->buildDir);

        if ($proc->run() !== 0) {
            throw new Exception($proc->getErrorOutput());
        }

        $this->writeln('Dependency installation completed');
    }

    /**
     * @param string $pharFile
     */
    protected function makePhar(string $pharFile)
    {
        $this->writeln('Compilation...');

        $phar = new Phar($this->distDir . '/' . $pharFile, 0, 'phinx.phar');
        $phar->setSignatureAlgorithm(Phar::SHA1);

        $phar->startBuffering();

        $finderSort = function (\SplFileInfo $a, \SplFileInfo $b) {
            return strcmp(strtr($a->getRealPath(), '\\', '/'), strtr($b->getRealPath(), '\\', '/'));
        };
        $finderFilter = function (\SplFileInfo $file) {
            return stripos($file->__toString(), 'tests') === false;
        };

        // templates
        $finder = new Finder();
        $finder->files()
            ->ignoreVCS(true)
            ->name('phinx.*.dist')
            ->in($this->buildDir . '/data')
            ->sort($finderSort);

        foreach ($finder as $file) {
            $this->addFile($phar, $file);
        }

        // dependencies
        $finder = new Finder();
        $finder->files()
            ->ignoreVCS(true)
            ->name('*.php')
            ->in($this->buildDir . '/vendor')
            ->sort($finderSort)
            ->filter($finderFilter);

        foreach ($finder as $file) {
            $this->addFile($phar, $file);
        }

        // phinx source
        $finder = new Finder();
        $finder->files()
            ->ignoreVCS(true)
            ->name('*.php')
            ->in($this->buildDir . '/src/Phinx')
            ->sort($finderSort);

        foreach ($finder as $file) {
            $this->addFile($phar, $file);
        }

        // single files
        $files = [
            '/src/composer_autoloader.php',
            '/app/phinx.php',
            '/bin/phinx',
            '/composer.json'
        ];

        foreach ($files as $file) {
            $this->addFile($phar, new \SplFileInfo($this->buildDir . $file));
        }

        $phar->setStub($this->getStub());

        $phar->stopBuffering();

        // phinx license
        $this->addFile($phar, new \SplFileInfo($this->buildDir . '/LICENSE'), false);

        foreach ($finder as $file) {
            $this->addFile($phar, $file,false);
        }

        unset($phar);

        $this->writeln('Successful compilation. File: ' . $this->distDir . '/' . $pharFile);
    }

    /**
     * @param  \SplFileInfo $file
     * @return string
     */
    protected function getRelativeFilePath(\SplFileInfo $file)
    {
        $realPath = $file->getRealPath();
        $pathPrefix = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'build'. DIRECTORY_SEPARATOR;

        $pos = strpos($realPath, $pathPrefix);
        $relativePath = ($pos !== false) ? substr_replace($realPath, '', $pos, strlen($pathPrefix)) : $realPath;

        return strtr($relativePath, '\\', '/');
    }

    /**
     * @param Phar $phar
     * @param \SplFileInfo $file
     * @param bool $strip
     */
    protected function addFile(Phar $phar, \SplFileInfo $file, bool $strip = true)
    {
        $path = $this->getRelativeFilePath($file);
        $content = file_get_contents($file->__toString());

        if ($strip) {
            $content = $this->stripWhitespace($content);
        }

        $phar->addFromString($path, $content);
    }

    /**
     * Removes whitespace from a PHP source string while preserving line numbers.
     *
     * @param  string $source A PHP string
     * @return string The PHP string with the whitespace removed
     */
    public function stripWhitespace($source)
    {
        if (!function_exists('token_get_all')) {
            return $source;
        }

        $output = '';
        foreach (token_get_all($source) as $token) {
            if (is_string($token)) {
                $output .= $token;
            } elseif (in_array($token[0], array(T_COMMENT, T_DOC_COMMENT))) {
                $output .= str_repeat("\n", substr_count($token[1], "\n"));
            } elseif (T_WHITESPACE === $token[0]) {
                // reduce wide spaces
                $whitespace = preg_replace('{[ \t]+}', ' ', $token[1]);
                // normalize newlines to \n
                $whitespace = preg_replace('{(?:\r\n|\r|\n)}', "\n", $whitespace);
                // trim leading spaces
                $whitespace = preg_replace('{\n +}', "\n", $whitespace);
                $output .= $whitespace;
            } else {
                $output .= $token[1];
            }
        }

        return $output;
    }

    /**
     * @return string
     */
    protected function getStub(): string
    {
        return <<<'EOF'
#!/usr/bin/env php
<?php
Phar::mapPhar('phinx.phar');
require 'phar://phinx.phar/bin/phinx';
__HALT_COMPILER();
EOF;
    }

    /**
     * @param string $msg
     */
    protected function writeln(string $msg): void
    {
        fwrite(
            STDOUT,
            date('[d.m.Y H:i:s] ') . $msg . PHP_EOL
        );
    }

    /**
     * Remove build files.
     */
    protected function clearBuild()
    {
        $proc = new Process(['rm', '-rf', $this->buildDir]);
        $proc->run();

        $proc = new Process(['mkdir', $this->buildDir]);
        $proc->run();
    }

    /**
     * Remove dist files.
     */
    protected function clearDist()
    {
        $proc = new Process(['rm', '-rf', $this->distDir]);
        $proc->run();

        $proc = new Process(['mkdir', $this->distDir]);
        $proc->run();
    }
}
