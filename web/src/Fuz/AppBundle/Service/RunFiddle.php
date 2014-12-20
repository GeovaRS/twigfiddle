<?php

namespace Fuz\AppBundle\Service;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Process\ProcessBuilder;
use Psr\Log\LoggerInterface;
use Fuz\AppBundle\Entity\Fiddle;
use Fuz\AppBundle\Entity\Result;
use Fuz\Component\SharedMemory\Storage\StorageFile;
use Fuz\Component\SharedMemory\SharedMemory;

class RunFiddle
{

    const ENV_NAME_LENGTH = 16;

    protected $logger;
    protected $filesystem;
    protected $localConfig;
    protected $remoteConfig;
    protected $envId;
    protected $envPath;
    protected $sharedObject;
    protected $process;

    public function __construct(LoggerInterface $logger, Filesystem $filesystem,
       ProcessConfiguration $processConfiguration, array $localConfig)
    {
        $this->logger = $logger;
        $this->filesystem = $filesystem;
        $this->localConfig = $localConfig;
        $this->remoteConfig = $processConfiguration->getProcessConfig();
    }

    public function run(Fiddle $fiddle)
    {
        $result = new Result();

        $this
           ->createEnvironment()
           ->createSharedObject($fiddle)
           ->execute()
           ->fetchResult($result)
           ->clearEnvironment()
        ;

        return $result;
    }

    protected function createEnvironment()
    {
        $this->logger->debug("Creating a new environment");

        $envRoot = $this->remoteConfig['environment']['directory'];
        if (!is_dir($envRoot))
        {
            throw new IOException("Environment directory {$envRoot} does not exist.");
        }

        $letters = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ01234567890";
        mt_srand(base_convert(uniqid(), 16, 10));
        $base = strlen($letters);
        do
        {
            $env = '';
            for ($i = 0; ($i < self::ENV_NAME_LENGTH); $i++)
            {
                $env .= $letters[mt_rand(0, $base - 1)];
            }
        }
        while (is_dir("{$envRoot}/{$env}"));

        $this->envId = $env;
        $this->envPath = "{$envRoot}/{$env}";
        $this->filesystem->mkdir($this->envPath);
        return $this;
    }

    protected function createSharedObject(Fiddle $fiddle)
    {
        $sharedFile = "{$this->envPath}/{$this->remoteConfig['fiddle']['file']}";

        $storage = new StorageFile($sharedFile);

        $this->sharedObject = new SharedMemory($storage);
        $this->sharedObject->fiddle = $fiddle;

        return $this;
    }

    protected function execute()
    {
        $command = str_replace('<env_id>', $this->envId, $this->localConfig['command']);
        $builder = new ProcessBuilder(explode(' ', $command));

        $this->process = $builder->getProcess();

        $this->process
           ->disableOutput()
           ->run()
        ;

        return $this;
    }

    protected function fetchResult(Result $result)
    {
        list($start, $end) = array ($this->sharedObject->begin_tm, $this->sharedObject->finish_tm);
        if (!is_null($start) && !is_null($end))
        {
            $diff = $end - $start;
            $sec = intval($diff);
            $micro = $diff - $sec;
            $result->setDuration(strftime('%T', mktime(0, 0, $sec)) . str_replace('0.', '.', sprintf('%.3f', $micro)));
        }

        $fiddleResult = $this->sharedObject->result;
        if (is_null($fiddleResult))
        {
            $this->logger->error("Fiddle {$this->envId} did not returned any result.");
        }
        else
        {
            $result->setResult($fiddleResult);
        }

        return $this;
    }

    protected function clearEnvironment()
    {
        if (!is_null($this->envPath))
        {
            $this->filesystem->remove($this->envPath);
            list($this->envId, $this->envPath, $this->sharedObject) = null;
        }

        return $this;
    }

}